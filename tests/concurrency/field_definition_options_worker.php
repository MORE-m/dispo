<?php

declare(strict_types=1);

/**
 * DF-3-REST-A MySQL-Parallelitätsworker: Options Desired-State auf einer Definition.
 */

use App\Exceptions\FieldDefinitionConflictException;
use App\Models\FieldDefinition;
use App\Models\User;
use App\Services\DynamicField\Admin\FieldDefinitionOptionsWriter;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

$runDir = $argv[1] ?? '';
$workerId = (int) ($argv[2] ?? -1);
$action = (string) ($argv[3] ?? '');
$payloadJson = (string) ($argv[4] ?? '{}');

if ($runDir === '' || $workerId < 0 || $action === '') {
    fwrite(STDERR, "Usage: field_definition_options_worker.php <run_dir> <worker_id> <action> <payload_json>\n");
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

/** @var array<string, mixed> $orch */
$orch = [];

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

try {
    $actorId = (int) ($payload['actor_id'] ?? 0);
    $actor = $actorId > 0 ? User::query()->findOrFail($actorId) : null;

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
        if ($useOuter && isset($orch['prelock']) && is_array($orch['prelock'])) {
            if (isset($orch['prelock']['definition_id'])) {
                FieldDefinition::query()
                    ->whereKey((int) $orch['prelock']['definition_id'])
                    ->lockForUpdate()
                    ->firstOrFail();
            }
            if (isset($orch['signal_after_prelock']) && is_string($orch['signal_after_prelock']) && $orch['signal_after_prelock'] !== '') {
                $signal($orch['signal_after_prelock']);
            }
            if (isset($orch['wait_after_prelock']) && is_array($orch['wait_after_prelock'])) {
                $waitForFiles(array_map('strval', $orch['wait_after_prelock']));
            }
        }

        $result = match ($action) {
            'replace_options' => (function () use ($app, $actor, $payload): string {
                $definition = FieldDefinition::query()->findOrFail((int) $payload['definition_id']);
                $writer = $app->make(FieldDefinitionOptionsWriter::class);
                $updated = $writer->replace($definition, [
                    'lock_version' => (int) ($payload['lock_version'] ?? $definition->lock_version),
                    'options' => is_array($payload['options'] ?? null) ? $payload['options'] : [],
                ], $actor);

                return 'OK:replace_options|'.$updated['definition']->id.'|'
                    .($updated['has_changes'] ? '1' : '0').'|'
                    .$updated['definition']->lock_version;
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
    $class = $exception::class;
    $message = Str::limit($exception->getMessage(), 240);
    if ($exception instanceof ValidationException) {
        $message = Str::limit(json_encode($exception->errors(), JSON_UNESCAPED_UNICODE) ?: $message, 240);
    }
    if ($exception instanceof FieldDefinitionConflictException) {
        $message = 'Conflict|'.$message;
    }
    if (isset($orch['signal_after_error']) && is_string($orch['signal_after_error']) && $orch['signal_after_error'] !== '') {
        $signal($orch['signal_after_error']);
    }
    file_put_contents($resultFile, 'ERROR:'.$class.'|'.$message);
}
