<?php

declare(strict_types=1);

/**
 * ADV-001b MySQL-Parallelitätsworker für Katalog-Lifecycle-Races.
 *
 * Orchestrierung über Datei-Signale (Lock-Zustand), nicht über Sleeps.
 */

use App\Enums\CalculationKind;
use App\Models\AdvertisingCategory;
use App\Models\AdvertisingMedium;
use App\Models\FieldSetAssignment;
use App\Models\User;
use App\Services\Advertising\Admin\AdvertisingCategoryAdminWriter;
use App\Services\Advertising\Admin\AdvertisingMediumAdminWriter;
use App\Services\Advertising\Admin\CatalogImpactPreviewService;
use App\Services\DynamicField\Assignment\AssignmentConfigurationLockCoordinator;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

$runDir = $argv[1] ?? '';
$workerId = (int) ($argv[2] ?? -1);
$action = (string) ($argv[3] ?? '');
$payloadJson = (string) ($argv[4] ?? '{}');

if ($runDir === '' || $workerId < 0 || $action === '') {
    fwrite(STDERR, "Usage: catalog_lifecycle_worker.php <run_dir> <worker_id> <action> <payload_json>\n");
    exit(1);
}

if (! is_dir($runDir) && ! mkdir($runDir, 0700, true) && ! is_dir($runDir)) {
    fwrite(STDERR, "Run directory could not be created: {$runDir}\n");
    exit(1);
}

/** @var array<string, mixed> $payload */
$payload = json_decode($payloadJson, true, 512, JSON_THROW_ON_ERROR);

require __DIR__.'/../../vendor/autoload.php';

/** @var Application $app */
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

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

    if (isset($orch['wait_before']) && is_array($orch['wait_before'])) {
        $waitForFiles(array_map('strval', $orch['wait_before']));
    }
    if (isset($orch['signal_before']) && is_string($orch['signal_before']) && $orch['signal_before'] !== '') {
        $signal($orch['signal_before']);
    }

    $result = match ($action) {
        'create_medium' => (function () use ($app, $actor, $payload): string {
            $writer = $app->make(AdvertisingMediumAdminWriter::class);
            $medium = $writer->create([
                'code' => (string) $payload['code'],
                'name' => (string) ($payload['name'] ?? $payload['code']),
                'kind' => (string) ($payload['kind'] ?? CalculationKind::SpotClassic->value),
                'category_id' => (int) $payload['category_id'],
                'default_length_seconds' => (int) ($payload['default_length_seconds'] ?? 30),
                'is_active' => true,
            ], $actor);

            return 'OK:create_medium|'.$medium->id.'|'.$medium->category_id.'|'.($medium->is_active ? '1' : '0');
        })(),
        'deactivate_category' => (function () use ($app, $actor, $payload): string {
            $category = AdvertisingCategory::query()->findOrFail((int) $payload['category_id']);
            $impact = $app->make(CatalogImpactPreviewService::class);
            // Fingerprint vor dem Lock nur als Client-Vorschau; Writer berechnet neu.
            $preview = $impact->previewCategoryDeactivate($category->fresh());
            $writer = $app->make(AdvertisingCategoryAdminWriter::class);
            $updated = $writer->deactivate($category, [
                'lock_version' => (int) ($payload['lock_version'] ?? $category->lock_version),
                'fingerprint' => (string) ($payload['fingerprint'] ?? $preview['fingerprint']),
            ], $actor);

            return 'OK:deactivate_category|'.$updated->id.'|'.($updated->is_active ? '1' : '0');
        })(),
        'reactivate_medium' => (function () use ($app, $actor, $payload): string {
            $medium = AdvertisingMedium::query()->findOrFail((int) $payload['medium_id']);
            $writer = $app->make(AdvertisingMediumAdminWriter::class);
            $updated = $writer->reactivate($medium, [
                'lock_version' => (int) ($payload['lock_version'] ?? $medium->lock_version),
            ], $actor);

            return 'OK:reactivate_medium|'.$updated->id.'|'.$updated->category_id.'|'.($updated->is_active ? '1' : '0');
        })(),
        'change_category' => (function () use ($app, $actor, $payload): string {
            $medium = AdvertisingMedium::query()->findOrFail((int) $payload['medium_id']);
            $writer = $app->make(AdvertisingMediumAdminWriter::class);
            $updated = $writer->changeCategory($medium, [
                'category_id' => (int) $payload['target_category_id'],
                'lock_version' => (int) ($payload['lock_version'] ?? $medium->lock_version),
                'fingerprint' => (string) $payload['fingerprint'],
            ], $actor);

            return 'OK:change_category|'.$updated->id.'|'.$updated->category_id.'|'.($updated->is_active ? '1' : '0');
        })(),
        'assignment_lock_hold' => (function () use ($app, $payload, $waitForFiles, $signal): string {
            $assignment = FieldSetAssignment::query()->findOrFail((int) $payload['assignment_id']);
            $coordinator = $app->make(AssignmentConfigurationLockCoordinator::class);

            DB::beginTransaction();
            try {
                $coordinator->lockForAssignment($assignment);
                $signal('assignment_full_locks_held');
                if (isset($payload['wait_after_full_lock']) && is_array($payload['wait_after_full_lock'])) {
                    $waitForFiles(array_map('strval', $payload['wait_after_full_lock']));
                }
                DB::commit();
            } catch (Throwable $exception) {
                DB::rollBack();
                throw $exception;
            }

            return 'OK:assignment_lock_hold|'.$assignment->id;
        })(),
        default => throw new InvalidArgumentException('Unknown action: '.$action),
    };

    if (isset($orch['signal_after']) && is_string($orch['signal_after']) && $orch['signal_after'] !== '') {
        $signal($orch['signal_after']);
    }

    file_put_contents($resultFile, $result);
} catch (Throwable $exception) {
    $class = $exception::class;
    $message = Str::limit($exception->getMessage(), 240);
    if ($exception instanceof ValidationException) {
        $message = Str::limit(json_encode($exception->errors(), JSON_UNESCAPED_UNICODE) ?: $message, 240);
    }
    if (isset($orch['signal_after_error']) && is_string($orch['signal_after_error']) && $orch['signal_after_error'] !== '') {
        file_put_contents($runDir.'/'.$orch['signal_after_error'], '1');
    }
    file_put_contents($resultFile, 'ERROR:'.$class.'|'.$message);
}

exit(0);
