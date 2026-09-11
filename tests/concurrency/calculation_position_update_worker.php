<?php

declare(strict_types=1);

use App\Enums\Role;
use App\Models\Calculation;
use App\Models\User;
use App\Services\Calculation\CalculationWriter;
use Illuminate\Foundation\Application;
use Illuminate\Validation\ValidationException;

$runDir = $argv[1] ?? '';
$workerId = (int) ($argv[2] ?? -1);
$calculationId = (int) ($argv[3] ?? 0);
$lockVersion = (int) ($argv[4] ?? 0);
$campaign = (string) ($argv[5] ?? '');

if ($runDir === '' || $workerId < 0 || $calculationId <= 0 || $lockVersion <= 0 || $campaign === '') {
    fwrite(STDERR, "Usage: calculation_position_update_worker.php <run_dir> <worker_id> <calculation_id> <lock_version> <campaign>\n");
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
    $calculation = Calculation::query()->with([
        'positions.planRows',
        'positions.timeRanges',
        'positions.discounts',
        'orderDiscounts',
        'configurationSnapshot',
        'fieldValues',
    ])->findOrFail($calculationId);

    $payload = $writer->payloadFromCalculation($calculation);
    $payload['lock_version'] = $lockVersion;
    $payload['campaign'] = $campaign;
    $payload['positions'][0]['calculation_method_key'] = 'average';
    $payload['positions'][0]['length_seconds'] = 20 + $workerId;

    $writer->update($calculation, $payload, $user);
    file_put_contents($resultFile, "ok:{$campaign}");
    exit(0);
} catch (ValidationException $exception) {
    $messages = $exception->errors()['lock_version'] ?? [];
    file_put_contents($resultFile, 'conflict:'.implode('|', $messages));
    exit(0);
} catch (Throwable $exception) {
    fwrite(STDERR, $exception->getMessage()."\n");
    file_put_contents($resultFile, 'error:'.$exception->getMessage());
    exit(3);
}
