<?php

declare(strict_types=1);

/**
 * ADV-001b MySQL-Parallelitätsworker für Katalog-Lifecycle-Races.
 *
 * Barrier: wartet bis zwei Worker `.ready` geschrieben haben, dann Aktion.
 */

use App\Enums\CalculationKind;
use App\Models\AdvertisingCategory;
use App\Models\AdvertisingMedium;
use App\Models\User;
use App\Services\Advertising\Admin\AdvertisingCategoryAdminWriter;
use App\Services\Advertising\Admin\AdvertisingMediumAdminWriter;
use App\Services\Advertising\Admin\CatalogImpactPreviewService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Application;
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

$readyFile = $runDir.'/worker-'.$workerId.'.ready';
$resultFile = $runDir.'/worker-'.$workerId.'.result';

file_put_contents($readyFile, '1');

$deadline = microtime(true) + 30.0;
while (count(glob($runDir.'/worker-*.ready')) < 2) {
    if (microtime(true) > $deadline) {
        fwrite(STDERR, "Barrier timeout for worker {$workerId}\n");
        exit(2);
    }
    usleep(10_000);
}

try {
    $actorId = (int) ($payload['actor_id'] ?? 0);
    $actor = User::query()->findOrFail($actorId);

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
            $preview = $impact->previewCategoryDeactivate($category);
            $writer = $app->make(AdvertisingCategoryAdminWriter::class);
            $updated = $writer->deactivate($category, [
                'lock_version' => (int) ($payload['lock_version'] ?? $category->lock_version),
                'fingerprint' => $preview['fingerprint'],
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
        default => throw new InvalidArgumentException('Unknown action: '.$action),
    };

    file_put_contents($resultFile, $result);
} catch (Throwable $exception) {
    $class = $exception::class;
    $message = Str::limit($exception->getMessage(), 240);
    if ($exception instanceof ValidationException) {
        $message = Str::limit(json_encode($exception->errors(), JSON_UNESCAPED_UNICODE) ?: $message, 240);
    }
    file_put_contents($resultFile, 'ERROR:'.$class.'|'.$message);
}

exit(0);
