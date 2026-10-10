<?php

declare(strict_types=1);

/**
 * MySQL-Parallelitätsworker: konkurrierende manuelle CRM-Verknüpfung.
 */

use App\Models\CrmAccount;
use App\Models\User;
use App\Services\Crm\CrmAccountService;
use Illuminate\Foundation\Application;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

$runDir = $argv[1] ?? '';
$workerId = (int) ($argv[2] ?? -1);
$payloadJson = (string) ($argv[3] ?? '');

if ($runDir === '' || $workerId < 0 || $payloadJson === '') {
    fwrite(STDERR, "Usage: crm_manual_link_worker.php <run_dir> <worker_id> <payload_json>\n");
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
    /** @var array{provisional_id: int, salesforce_id: int, actor_id: int} $data */
    $data = json_decode($payloadJson, true, 512, JSON_THROW_ON_ERROR);

    $provisional = CrmAccount::query()->findOrFail((int) $data['provisional_id']);
    $salesforce = CrmAccount::query()->findOrFail((int) $data['salesforce_id']);
    $actor = User::query()->findOrFail((int) $data['actor_id']);

    $linked = $app->make(CrmAccountService::class)
        ->linkProvisionalToSalesforce($provisional, $salesforce, $actor);

    file_put_contents($resultFile, 'OK:'.$linked->id);
} catch (ValidationException $exception) {
    file_put_contents(
        $resultFile,
        'ERROR:ValidationException|'.Str::limit($exception->getMessage(), 200),
    );
} catch (Throwable $exception) {
    file_put_contents(
        $resultFile,
        'ERROR:'.get_class($exception).'|'.Str::limit($exception->getMessage(), 200),
    );
}

exit(0);
