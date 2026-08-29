<?php

declare(strict_types=1);

use App\Services\Calculation\CalculationNumberSequencer;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

$runId = $argv[1] ?? '';
$workerId = (int) ($argv[2] ?? -1);

if ($runId === '' || $workerId < 0) {
    fwrite(STDERR, "Usage: calculation_number_worker.php <run_id> <worker_id>\n");
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
    [, , $number] = $app->make(CalculationNumberSequencer::class)->next();

    DB::table('calc_number_concurrency_results')->insert([
        'run_id' => $runId,
        'worker_id' => $workerId,
        'number' => $number,
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
