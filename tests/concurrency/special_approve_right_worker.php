<?php

declare(strict_types=1);

/**
 * PO-AUTH-SPECIAL-APPROVE-1: paralleler Entzug vs. Freigabeentscheidung.
 * Orchestrierung über Barrier-Dateien; echte MySQL-Locks, keine Sleeps als Sync.
 */

use App\Models\DispoOrder;
use App\Models\User;
use App\Services\DispoOrder\DispoOrderApprovalService;
use App\Services\User\Admin\SpecialApproveRightAdminWriter;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

$runDir = $argv[1] ?? '';
$workerId = (int) ($argv[2] ?? -1);
$action = (string) ($argv[3] ?? '');
$payloadJson = (string) ($argv[4] ?? '{}');

if ($runDir === '' || $workerId < 0 || $action === '') {
    fwrite(STDERR, "Usage: special_approve_right_worker.php <run_dir> <worker_id> <action> <payload_json>\n");
    exit(1);
}

if (! is_dir($runDir) && ! mkdir($runDir, 0700, true) && ! is_dir($runDir)) {
    fwrite(STDERR, "Run directory could not be created: {$runDir}\n");
    exit(1);
}

/** @var array<string, mixed> $payload */
$payload = json_decode($payloadJson, true, 512, JSON_THROW_ON_ERROR);

/** @var Application $app */
$app = require __DIR__.'/bootstrap_mysql_worker.php';

$resultFile = $runDir.'/worker-'.$workerId.'.result';

/**
 * @param  list<string>  $files
 */
$waitForFiles = static function (array $files, float $seconds = 45.0) use ($runDir): void {
    $deadline = microtime(true) + $seconds;
    foreach ($files as $file) {
        while (! is_file($runDir.'/'.$file)) {
            if (microtime(true) > $deadline) {
                throw new RuntimeException('Barrier timeout waiting for '.$file);
            }
            usleep(5_000);
        }
    }
};

$signal = static function (string $file) use ($runDir): void {
    file_put_contents($runDir.'/'.$file, '1');
};

$assertWaiterBlockedWhileHolding = static function (
    float $seconds,
    string $waiterWorkerId,
) use ($runDir): void {
    $peerResult = $runDir.'/worker-'.$waiterWorkerId.'.result';
    $deadline = microtime(true) + $seconds;
    while (microtime(true) < $deadline) {
        if (is_file($peerResult)) {
            throw new RuntimeException(
                'Waiter finished while target user lock was still held ('.$peerResult.')',
            );
        }
        usleep(50_000);
    }
};

try {
    /** @var array<string, mixed> $orch */
    $orch = is_array($payload['orchestration'] ?? null) ? $payload['orchestration'] : [];
    $useOuter = (bool) ($orch['outer_transaction'] ?? false);

    if (isset($orch['wait_before']) && is_array($orch['wait_before'])) {
        $waitForFiles(array_map('strval', $orch['wait_before']));
    }
    if (isset($orch['signal_before']) && is_string($orch['signal_before']) && $orch['signal_before'] !== '') {
        $signal($orch['signal_before']);
    }

    if ($useOuter) {
        DB::beginTransaction();
    }

    try {
        if ($useOuter && isset($orch['prelock']['user_id'])) {
            User::query()
                ->whereKey((int) $orch['prelock']['user_id'])
                ->lockForUpdate()
                ->firstOrFail();
        }
        if (isset($orch['signal_after_prelock']) && is_string($orch['signal_after_prelock']) && $orch['signal_after_prelock'] !== '') {
            $signal($orch['signal_after_prelock']);
        }
        if (isset($orch['wait_after_prelock']) && is_array($orch['wait_after_prelock'])) {
            $waitForFiles(array_map('strval', $orch['wait_after_prelock']));
        }
        if (isset($orch['assert_waiter_blocked_seconds'])) {
            $assertWaiterBlockedWhileHolding(
                (float) $orch['assert_waiter_blocked_seconds'],
                (string) ($orch['waiter_worker_id'] ?? '1'),
            );
            $signal('waiter_blocked_while_lock_held');
        }

        $result = match ($action) {
            'revoke' => (function () use ($app, $payload): string {
                $actor = User::query()->findOrFail((int) $payload['actor_id']);
                $target = User::query()->findOrFail((int) $payload['target_user_id']);
                $fresh = $app->make(SpecialApproveRightAdminWriter::class)
                    ->update($target, false, $actor);

                return 'OK:revoke|'.$fresh->id.'|'.((int) $fresh->can_special_approve);
            })(),
            'approve' => (function () use ($app, $payload): string {
                $actor = User::query()->findOrFail((int) $payload['actor_id']);
                $order = DispoOrder::query()->findOrFail((int) $payload['order_id']);
                $fresh = $app->make(DispoOrderApprovalService::class)->approve(
                    $order,
                    $actor,
                    (int) $payload['lock_version'],
                    null,
                    true,
                );

                return 'OK:approve|'.$fresh->status->value.'|'.$fresh->lock_version;
            })(),
            'reject' => (function () use ($app, $payload): string {
                $actor = User::query()->findOrFail((int) $payload['actor_id']);
                $order = DispoOrder::query()->findOrFail((int) $payload['order_id']);
                $fresh = $app->make(DispoOrderApprovalService::class)->reject(
                    $order,
                    $actor,
                    (int) $payload['lock_version'],
                    'Concurrency Reject',
                );

                return 'OK:reject|'.$fresh->status->value.'|'.$fresh->lock_version;
            })(),
            default => throw new InvalidArgumentException('Unknown action: '.$action),
        };

        if ($useOuter) {
            DB::commit();
        }

        file_put_contents($resultFile, $result);
    } catch (Throwable $inner) {
        if ($useOuter && DB::transactionLevel() > 0) {
            DB::rollBack();
        }
        throw $inner;
    }
} catch (Throwable $exception) {
    $message = Str::limit($exception->getMessage(), 240);
    if ($exception instanceof ValidationException) {
        $message = Str::limit(json_encode($exception->errors(), JSON_UNESCAPED_UNICODE) ?: $message, 240);
    }
    file_put_contents(
        $resultFile,
        'ERROR:'.$exception::class.'|'.$message,
    );
}

exit(0);
