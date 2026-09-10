<?php

declare(strict_types=1);

/**
 * ADV-001b MySQL-Parallelitätsworker für Katalog-Lifecycle-Races.
 *
 * Orchestrierung ausschließlich hier (tests/): äußere Transaktionen,
 * explizite vorbereitende Zeilenlocks und Dateibarrieren.
 */

use App\Models\AdvertisingCategory;
use App\Models\AdvertisingCategoryCalculationMethod;
use App\Models\AdvertisingMedium;
use App\Models\CalculationMethod;
use App\Models\FieldSetAssignment;
use App\Models\User;
use App\Services\Advertising\Admin\AdvertisingCategoryAdminWriter;
use App\Services\Advertising\Admin\AdvertisingMediumAdminWriter;
use App\Services\Advertising\Admin\CalculationMethodAdminWriter;
use App\Services\Advertising\Admin\CalculationMethodImpactPreviewService;
use App\Services\Advertising\Admin\CatalogImpactPreviewService;
use App\Services\DynamicField\Assignment\AssignmentConfigurationLockCoordinator;
use App\Support\Advertising\CalculationMethodAssignmentActivationGuard;
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

$applyPrelock = static function (array $prelock): void {
    if (isset($prelock['category_id'])) {
        AdvertisingCategory::query()
            ->whereKey((int) $prelock['category_id'])
            ->lockForUpdate()
            ->firstOrFail();
    }
    if (isset($prelock['medium_id'])) {
        AdvertisingMedium::query()
            ->whereKey((int) $prelock['medium_id'])
            ->lockForUpdate()
            ->firstOrFail();
    }
    if (isset($prelock['method_id'])) {
        CalculationMethod::query()
            ->whereKey((int) $prelock['method_id'])
            ->lockForUpdate()
            ->firstOrFail();
    }
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
            $applyPrelock($orch['prelock']);
            if (isset($orch['signal_after_prelock']) && is_string($orch['signal_after_prelock']) && $orch['signal_after_prelock'] !== '') {
                $signal($orch['signal_after_prelock']);
            }
            if (isset($orch['wait_after_prelock']) && is_array($orch['wait_after_prelock'])) {
                $waitForFiles(array_map('strval', $orch['wait_after_prelock']));
            }
        }

        $result = match ($action) {
            'create_medium' => (function () use ($app, $actor, $payload): string {
                $writer = $app->make(AdvertisingMediumAdminWriter::class);
                $medium = $writer->create([
                    'code' => (string) $payload['code'],
                    'name' => (string) ($payload['name'] ?? $payload['code']),
                    'category_id' => (int) $payload['category_id'],
                    'default_length_seconds' => (int) ($payload['default_length_seconds'] ?? 30),
                    'is_active' => true,
                ], $actor);

                return 'OK:create_medium|'.$medium->id.'|'.$medium->category_id.'|'.($medium->is_active ? '1' : '0');
            })(),
            'deactivate_category' => (function () use ($app, $actor, $payload): string {
                $category = AdvertisingCategory::query()->findOrFail((int) $payload['category_id']);
                $impact = $app->make(CatalogImpactPreviewService::class);
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
            'assignment_lock_hold' => (function () use ($app, $payload): string {
                $assignment = FieldSetAssignment::query()->findOrFail((int) $payload['assignment_id']);
                $coordinator = $app->make(AssignmentConfigurationLockCoordinator::class);
                $coordinator->lockForAssignment($assignment);

                return 'OK:assignment_lock_hold|'.$assignment->id;
            })(),
            'update_method_metadata' => (function () use ($app, $actor, $payload): string {
                $method = CalculationMethod::query()->findOrFail((int) $payload['method_id']);
                $writer = $app->make(CalculationMethodAdminWriter::class);
                $updated = $writer->update($method, [
                    'name' => (string) $payload['name'],
                    'help_text' => $payload['help_text'] ?? $method->help_text,
                    'sort' => (int) ($payload['sort'] ?? $method->sort),
                    'lock_version' => (int) ($payload['lock_version'] ?? $method->lock_version),
                ], $actor);

                return 'OK:update_method_metadata|'.$updated->id.'|'.$updated->lock_version.'|'.($updated->is_active ? '1' : '0');
            })(),
            'deactivate_method' => (function () use ($app, $actor, $payload): string {
                $method = CalculationMethod::query()->findOrFail((int) $payload['method_id']);
                $impact = $app->make(CalculationMethodImpactPreviewService::class);
                $preview = $impact->previewDeactivate($method->fresh());
                $writer = $app->make(CalculationMethodAdminWriter::class);
                $updated = $writer->deactivate($method, [
                    'lock_version' => (int) ($payload['lock_version'] ?? $method->lock_version),
                    'fingerprint' => (string) ($payload['fingerprint'] ?? $preview['fingerprint']),
                ], $actor);

                return 'OK:deactivate_method|'.$updated->id.'|'.($updated->is_active ? '1' : '0').'|'.$updated->lock_version;
            })(),
            'activate_category_assignment_guarded' => (function () use ($payload): string {
                // Künftiger c3b2-Vertrag: Method zuerst sperren und auf aktiv prüfen.
                return DB::transaction(function () use ($payload): string {
                    $guard = new CalculationMethodAssignmentActivationGuard;
                    $method = $guard->lockActiveMethod((int) $payload['method_id']);

                    /** @var AdvertisingCategoryCalculationMethod $assignment */
                    $assignment = AdvertisingCategoryCalculationMethod::query()
                        ->whereKey((int) $payload['assignment_id'])
                        ->lockForUpdate()
                        ->firstOrFail();

                    if ((int) $assignment->calculation_method_id !== (int) $method->id) {
                        throw new InvalidArgumentException('Assignment gehört nicht zur Methode.');
                    }

                    $assignment->is_active = true;
                    $assignment->lock_version = (int) $assignment->lock_version + 1;
                    $assignment->save();

                    return 'OK:activate_category_assignment_guarded|'.$assignment->id.'|'.$method->id;
                });
            })(),
            'update_medium_metadata' => (function () use ($app, $actor, $payload): string {
                $medium = AdvertisingMedium::query()->findOrFail((int) $payload['medium_id']);
                $writer = $app->make(AdvertisingMediumAdminWriter::class);
                $updated = $writer->update($medium, [
                    'name' => (string) $payload['name'],
                    'default_length_seconds' => (int) $medium->default_length_seconds,
                    'is_discountable' => (bool) $medium->is_discountable,
                    'is_ae_eligible' => (bool) $medium->is_ae_eligible,
                    'sort' => (int) $medium->sort,
                    'lock_version' => (int) ($payload['lock_version'] ?? $medium->lock_version),
                ], $actor);

                return 'OK:update_medium_metadata|'.$updated->id.'|'.$updated->lock_version;
            })(),
            default => throw new InvalidArgumentException('Unknown action: '.$action),
        };

        if (isset($orch['wait_after_action']) && is_array($orch['wait_after_action'])) {
            $waitForFiles(array_map('strval', $orch['wait_after_action']));
        }

        if ($useOuter) {
            DB::commit();
        }

        if (isset($orch['signal_after']) && is_string($orch['signal_after']) && $orch['signal_after'] !== '') {
            $signal($orch['signal_after']);
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
    if (isset($orch['signal_after_error']) && is_string($orch['signal_after_error']) && $orch['signal_after_error'] !== '') {
        file_put_contents($runDir.'/'.$orch['signal_after_error'], '1');
    }
    file_put_contents($resultFile, 'ERROR:'.$class.'|'.$message);
}

exit(0);
