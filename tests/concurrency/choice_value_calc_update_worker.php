<?php

declare(strict_types=1);

/**
 * DF-3-REST-C1: parallele Calc-Updates mit Choice-Werten (lock_version).
 *
 * choice_value darf ein Select-Key oder ein JSON-Array (Multi) sein.
 */

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
$fieldKey = (string) ($argv[5] ?? '');
$rawValue = (string) ($argv[6] ?? '');
$readyCount = (int) ($argv[7] ?? 2);

if ($runDir === '' || $workerId < 0 || $calculationId <= 0 || $lockVersion <= 0 || $fieldKey === '' || $rawValue === '') {
    fwrite(STDERR, "Usage: choice_value_calc_update_worker.php <run_dir> <worker_id> <calculation_id> <lock_version> <field_key> <choice_value_or_json_array> [ready_count]\n");
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
    $decoded = json_decode($rawValue, true);
    $choiceValue = is_array($decoded) ? $decoded : $rawValue;

    $user = User::query()->where('role', Role::Sales->value)->firstOrFail();
    $writer = $app->make(CalculationWriter::class);
    $calculation = Calculation::query()->with([
        'positions.planRows',
        'positions.timeRanges',
        'positions.discounts',
        'orderDiscounts',
        'configurationSnapshot.fieldDefinitions',
        'fieldValues',
    ])->findOrFail($calculationId);

    $payload = $writer->payloadFromCalculation($calculation);
    $payload['lock_version'] = $lockVersion;
    $payload['dynamic_field_values'][$fieldKey] = $choiceValue;

    $writer->update($calculation, $payload, $user);
    $encoded = is_array($choiceValue)
        ? json_encode($choiceValue, JSON_THROW_ON_ERROR)
        : (string) $choiceValue;
    file_put_contents($resultFile, 'ok:'.$encoded);
    exit(0);
} catch (ValidationException $exception) {
    $messages = $exception->errors()['lock_version'] ?? array_values($exception->errors())[0] ?? [];
    $prefix = isset($exception->errors()['lock_version']) ? 'conflict:' : 'validation:';
    file_put_contents($resultFile, $prefix.implode('|', is_array($messages) ? $messages : [(string) $messages]));
    exit(0);
} catch (Throwable $exception) {
    fwrite(STDERR, $exception->getMessage()."\n");
    file_put_contents($resultFile, 'error:'.$exception->getMessage());
    exit(3);
}
