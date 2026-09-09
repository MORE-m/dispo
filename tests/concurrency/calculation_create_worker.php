<?php

declare(strict_types=1);

use App\Enums\Role;
use App\Models\Calculation;
use App\Models\User;
use App\Services\Calculation\CalculationWriter;
use App\Services\DynamicField\ConfigurationSnapshotFreezeService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Application;
use Illuminate\Support\Str;

$runDir = $argv[1] ?? '';
$workerId = (int) ($argv[2] ?? -1);
$hamburgId = (int) ($argv[3] ?? 0);
$mediumId = (int) ($argv[4] ?? 0);

if ($runDir === '' || $workerId < 0 || $hamburgId <= 0 || $mediumId <= 0) {
    fwrite(STDERR, "Usage: calculation_create_worker.php <run_dir> <worker_id> <hamburg_id> <medium_id>\n");
    exit(1);
}

if (! is_dir($runDir) && ! mkdir($runDir, 0700, true) && ! is_dir($runDir)) {
    fwrite(STDERR, "Run directory could not be created: {$runDir}\n");
    exit(1);
}

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
    $user = User::query()->where('role', Role::Sales->value)->firstOrFail();
    $writer = $app->make(CalculationWriter::class);
    $fingerprint = $app->make(ConfigurationSnapshotFreezeService::class)
        ->resolveLiveSchemaForCalculationV3()['schema_fingerprint'];

    $calculation = $writer->create([
        'planning_mode' => 'manual',
        'schema_fingerprint' => $fingerprint,
        'order_discount_percent' => '0',
        'positions' => [[
            'inventory_id' => $hamburgId,
            'advertising_medium_id' => $mediumId,
            'spot_method' => 'average',
            'length_seconds' => 30,
            'total_spot_count' => 1,
            'position_discount_percent' => '0',
            'ae_percent' => '0',
            'plan_rows' => [['hour' => 8, 'day_group' => 'mo_fr']],
        ]],
    ], $user);

    $persisted = Calculation::query()->whereKey($calculation->id)->firstOrFail();

    file_put_contents($resultFile, $persisted->number);
} catch (Throwable $exception) {
    file_put_contents($resultFile, 'ERROR:'.Str::limit($exception->getMessage(), 200));

    exit(3);
}

exit(0);
