<?php

declare(strict_types=1);

use App\Enums\Role;
use App\Models\Calculation;
use App\Models\DispoOrder;
use App\Models\User;
use App\Services\DispoOrder\DispoOrderWriter;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Application;
use Illuminate\Support\Str;

$runDir = $argv[1] ?? '';
$workerId = (int) ($argv[2] ?? -1);
$calculationId = (int) ($argv[3] ?? 0);
$positionId = (int) ($argv[4] ?? 0);

if ($runDir === '' || $workerId < 0 || $calculationId <= 0 || $positionId <= 0) {
    fwrite(STDERR, "Usage: dispo_order_create_worker.php <run_dir> <worker_id> <calculation_id> <position_id>\n");
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
    $calculation = Calculation::query()->findOrFail($calculationId);
    $writer = $app->make(DispoOrderWriter::class);

    $result = $writer->createFromCalculation($calculation, [$positionId], $user);
    $persisted = DispoOrder::query()->whereKey($result->order->id)->firstOrFail();

    file_put_contents(
        $resultFile,
        $persisted->number.'|'.$persisted->number_calc_seq.'|'.$persisted->positions()->count(),
    );
} catch (Throwable $exception) {
    file_put_contents($resultFile, 'ERROR:'.Str::limit($exception->getMessage(), 200));

    exit(3);
}

exit(0);
