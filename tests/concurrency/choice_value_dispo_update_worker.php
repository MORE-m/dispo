<?php

declare(strict_types=1);

/**
 * DF-3-REST-C1: paralleler nativer Dispo-Header-Choice-Save (lock_version).
 */

use App\Enums\Role;
use App\Exceptions\DispoOrderConflictException;
use App\Models\DispoOrder;
use App\Models\User;
use App\Services\DynamicField\DispoOrderDynamicFieldWriter;
use Illuminate\Foundation\Application;

$runDir = $argv[1] ?? '';
$workerId = (int) ($argv[2] ?? -1);
$orderId = (int) ($argv[3] ?? 0);
$lockVersion = (int) ($argv[4] ?? 0);
$fieldKey = (string) ($argv[5] ?? '');
$rawValue = (string) ($argv[6] ?? '');

if ($runDir === '' || $workerId < 0 || $orderId <= 0 || $lockVersion <= 0 || $fieldKey === '' || $rawValue === '') {
    fwrite(STDERR, "Usage: choice_value_dispo_update_worker.php <run_dir> <worker_id> <order_id> <lock_version> <field_key> <choice_value_or_json_array>\n");
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
    $order = DispoOrder::query()->findOrFail($orderId);
    $value = json_decode($rawValue, true);
    if (! is_string($value) && ! is_array($value) && $rawValue !== 'null') {
        $value = $rawValue;
    }
    if ($rawValue === 'null') {
        $value = null;
    }

    $app->make(DispoOrderDynamicFieldWriter::class)->updateDraftTexts(
        $order,
        $user,
        $lockVersion,
        [$fieldKey => $value],
    );

    $encoded = is_array($value) ? json_encode($value, JSON_THROW_ON_ERROR) : (string) $value;
    file_put_contents($resultFile, 'ok:'.$encoded);
    exit(0);
} catch (DispoOrderConflictException $exception) {
    file_put_contents($resultFile, 'conflict:'.$exception->getMessage());
    exit(0);
} catch (Throwable $exception) {
    fwrite(STDERR, $exception->getMessage()."\n");
    file_put_contents($resultFile, 'error:'.$exception->getMessage());
    exit(3);
}
