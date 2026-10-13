<?php

declare(strict_types=1);

/**
 * MySQL-Parallelitätsworker: konkurrierender Meridian-Nachtrag mit Order-Mismatch.
 */

use App\Models\CrmAccount;
use App\Models\User;
use App\Services\Crm\CrmMeridianSupplementService;
use Illuminate\Foundation\Application;

$runDir = $argv[1] ?? '';
$workerId = (int) ($argv[2] ?? -1);
$payloadJson = (string) ($argv[3] ?? '');

if ($runDir === '' || $workerId < 0 || $payloadJson === '') {
    fwrite(STDERR, "Usage: crm_order_meridian_conflict_worker.php <run_dir> <worker_id> <payload_json>\n");
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
    /** @var array{account_id: int, meridian: string, actor_id: int, import_id: ?int} $data */
    $data = json_decode($payloadJson, true, 512, JSON_THROW_ON_ERROR);

    $account = CrmAccount::query()->findOrFail((int) $data['account_id']);
    $actor = User::query()->findOrFail((int) $data['actor_id']);
    $importId = array_key_exists('import_id', $data) && $data['import_id'] !== null
        ? (int) $data['import_id']
        : null;

    $result = $app->make(CrmMeridianSupplementService::class)->supplementMissing(
        $account,
        (string) $data['meridian'],
        $actor,
        $importId,
    );

    file_put_contents(
        $resultFile,
        'OK:'.$result['conflicts'].':'.$result['conflicts_created'],
    );
} catch (Throwable $exception) {
    file_put_contents(
        $resultFile,
        'ERROR:'.get_class($exception).'|'.mb_substr($exception->getMessage(), 0, 200),
    );
}

exit(0);
