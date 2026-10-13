<?php

declare(strict_types=1);

/**
 * MySQL-Parallelitätsworker: ältere Alias-ID ∩ manueller Link.
 *
 * Orchestrierung nur hier (tests/): äußere Transaktion, Identitäts-Prelock
 * und Dateibarrieren an den Sperrstellen – keine Test-Hooks unter app/.
 */

use App\Models\CrmAccount;
use App\Models\User;
use App\Services\Crm\CrmAccountIdentityLocker;
use App\Services\Crm\CrmAccountService;
use App\Services\Crm\CrmMeridianSupplementService;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

$runDir = $argv[1] ?? '';
$workerId = (int) ($argv[2] ?? -1);
$payloadJson = (string) ($argv[3] ?? '');

if ($runDir === '' || $workerId < 0 || $payloadJson === '') {
    fwrite(STDERR, "Usage: crm_alias_link_overlap_worker.php <run_dir> <worker_id> <payload_json>\n");
    exit(1);
}

if (! is_dir($runDir) && ! mkdir($runDir, 0700, true) && ! is_dir($runDir)) {
    fwrite(STDERR, "Run directory could not be created: {$runDir}\n");
    exit(1);
}

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
    file_put_contents($runDir.'/'.$file, (string) microtime(true));
};

try {
    /** @var array{
     *     role: string,
     *     account_id?: int,
     *     meridian?: string,
     *     provisional_id?: int,
     *     salesforce_id?: int,
     *     actor_id: int,
     *     prelock_account_ids?: list<int>,
     *     orchestration?: array<string, mixed>
     * } $data */
    $data = json_decode($payloadJson, true, 512, JSON_THROW_ON_ERROR);
    $orch = is_array($data['orchestration'] ?? null) ? $data['orchestration'] : [];
    $actor = User::query()->findOrFail((int) $data['actor_id']);

    if (isset($orch['wait_before']) && is_array($orch['wait_before'])) {
        $waitForFiles(array_map('strval', $orch['wait_before']));
    }
    if (isset($orch['signal_before']) && is_string($orch['signal_before']) && $orch['signal_before'] !== '') {
        $signal($orch['signal_before']);
    }

    $role = (string) $data['role'];

    if ($role === 'supplement_holder') {
        DB::beginTransaction();
        try {
            $locker = $app->make(CrmAccountIdentityLocker::class);
            $prelockIds = array_map('intval', $data['prelock_account_ids'] ?? []);
            // Identitäts-Prelock in aufsteigender ID-Reihenfolge (wie Produktion).
            $locker->lockIdsAscending($prelockIds);

            if (isset($orch['signal_after_prelock']) && is_string($orch['signal_after_prelock'])) {
                $signal($orch['signal_after_prelock']);
            }
            if (isset($orch['wait_after_prelock']) && is_array($orch['wait_after_prelock'])) {
                $waitForFiles(array_map('strval', $orch['wait_after_prelock']));
            }
            // Kurz warten, damit der Link-Worker die blockierende Sperranforderung
            // erreichen kann (echte Überschneidung, nicht nur Startbarriere).
            $pauseMs = (int) ($orch['pause_ms_after_prelock_wait'] ?? 0);
            if ($pauseMs > 0) {
                usleep($pauseMs * 1000);
            }

            $account = CrmAccount::query()->findOrFail((int) $data['account_id']);
            $result = $app->make(CrmMeridianSupplementService::class)->supplementMissing(
                $account,
                (string) $data['meridian'],
                $actor,
                null,
            );

            DB::commit();
            file_put_contents(
                $resultFile,
                'OK:supplement:'.$result['conflicts'].':'.$result['conflicts_created'],
            );
        } catch (Throwable $inner) {
            if (DB::transactionLevel() > 0) {
                DB::rollBack();
            }
            throw $inner;
        }
    } elseif ($role === 'manual_link_waiter') {
        if (isset($orch['signal_entering_link']) && is_string($orch['signal_entering_link'])) {
            $signal($orch['signal_entering_link']);
        }

        $provisional = CrmAccount::query()->findOrFail((int) $data['provisional_id']);
        $salesforce = CrmAccount::query()->findOrFail((int) $data['salesforce_id']);

        $linked = $app->make(CrmAccountService::class)
            ->linkProvisionalToSalesforce($provisional, $salesforce, $actor)['account'];

        if (isset($orch['signal_after_link']) && is_string($orch['signal_after_link'])) {
            $signal($orch['signal_after_link']);
        }

        file_put_contents($resultFile, 'OK:link:'.$linked->id);
    } else {
        throw new RuntimeException('Unknown worker role: '.$role);
    }
} catch (ValidationException $exception) {
    file_put_contents(
        $resultFile,
        'ERROR:ValidationException|'.Str::limit($exception->getMessage(), 200),
    );
} catch (Throwable $exception) {
    file_put_contents(
        $resultFile,
        'ERROR:'.get_class($exception).'|'.Str::limit($exception->getMessage(), 200),
    );
}

exit(0);
