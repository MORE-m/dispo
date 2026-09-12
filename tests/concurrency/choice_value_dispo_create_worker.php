<?php

declare(strict_types=1);

/**
 * DF-3-REST-C1: Dispo-Create aus Kalkulation (Barrier mit Calc-Update).
 */

use App\Enums\Role;
use App\Models\Calculation;
use App\Models\DispoOrder;
use App\Models\User;
use App\Services\DispoOrder\DispoOrderWriter;
use Illuminate\Foundation\Application;

$runDir = $argv[1] ?? '';
$workerId = (int) ($argv[2] ?? -1);
$calculationId = (int) ($argv[3] ?? 0);
$positionId = (int) ($argv[4] ?? 0);
$readyCount = (int) ($argv[5] ?? 2);

if ($runDir === '' || $workerId < 0 || $calculationId <= 0 || $positionId <= 0) {
    fwrite(STDERR, "Usage: choice_value_dispo_create_worker.php <run_dir> <worker_id> <calculation_id> <position_id> [ready_count]\n");
    exit(1);
}

if (! is_dir($runDir) && ! mkdir($runDir, 0700, true) && ! is_dir($runDir)) {
    fwrite(STDERR, "Run directory could not be created: {$runDir}\n");
    exit(1);
}

/** @var Application $app */
$app = require __DIR__.'/bootstrap_mysql_worker.php';

$readyFile = $runDir.'/worker-'.$workerId.'.ready';
$resultFile = $runDir.'/worker-'.$workerId.'.result';

file_put_contents($readyFile, '1');

$deadline = microtime(true) + 30.0;
while (count(glob($runDir.'/worker-*.ready')) < $readyCount) {
    if (microtime(true) > $deadline) {
        fwrite(STDERR, "Barrier timeout for worker {$workerId}\n");
        exit(2);
    }
    usleep(10_000);
}

try {
    $user = User::query()->where('role', Role::Sales->value)->firstOrFail();
    $calculation = Calculation::query()->findOrFail($calculationId);
    $result = $app->make(DispoOrderWriter::class)
        ->createFromCalculation($calculation, [$positionId], $user);
    $order = DispoOrder::query()->whereKey($result->order->id)->firstOrFail();

    file_put_contents(
        $resultFile,
        'ok:'.$order->id.'|'.$order->positions()->count().'|'.$order->configuration_snapshot_id,
    );
    exit(0);
} catch (Throwable $exception) {
    fwrite(STDERR, $exception->getMessage()."\n");
    file_put_contents($resultFile, 'error:'.$exception->getMessage());
    exit(3);
}
