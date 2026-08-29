<?php

declare(strict_types=1);

use App\Enums\Role;
use App\Models\Calculation;
use App\Models\User;
use App\Services\Calculation\CalculationWriter;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

$runId = $argv[1] ?? '';
$workerId = (int) ($argv[2] ?? -1);
$hamburgId = (int) ($argv[3] ?? 0);
$mediumId = (int) ($argv[4] ?? 0);

if ($runId === '' || $workerId < 0 || $hamburgId <= 0 || $mediumId <= 0) {
    fwrite(STDERR, "Usage: calculation_create_worker.php <run_id> <worker_id> <hamburg_id> <medium_id>\n");
    exit(1);
}

require __DIR__.'/../../vendor/autoload.php';

/** @var Application $app */
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

DB::table('calc_number_concurrency_barrier')->updateOrInsert(
    ['run_id' => $runId, 'worker_id' => $workerId],
    ['status' => 'ready', 'updated_at' => now()],
);

$deadline = microtime(true) + 30.0;
while (DB::table('calc_number_concurrency_barrier')->where('run_id', $runId)->where('status', 'ready')->count() < 2) {
    if (microtime(true) > $deadline) {
        fwrite(STDERR, "Barrier timeout for worker {$workerId}\n");
        exit(2);
    }

    usleep(10_000);
}

try {
    $user = User::query()->where('role', Role::Sales->value)->firstOrFail();
    $writer = $app->make(CalculationWriter::class);

    $calculation = $writer->create([
        'planning_mode' => 'manual',
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

    DB::table('calc_number_concurrency_results')->insert([
        'run_id' => $runId,
        'worker_id' => $workerId,
        'number' => $persisted->number,
        'created_at' => now(),
    ]);
} catch (Throwable $exception) {
    DB::table('calc_number_concurrency_results')->insert([
        'run_id' => $runId,
        'worker_id' => $workerId,
        'number' => 'ERROR:'.Str::limit($exception->getMessage(), 200),
        'created_at' => now(),
    ]);

    exit(3);
}

exit(0);
