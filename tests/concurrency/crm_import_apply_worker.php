<?php

declare(strict_types=1);

/**
 * MySQL-Parallelitätsworker: CRM-Import anwenden (Account-Sperre → Nachtrag).
 */

use App\Models\CrmImport;
use App\Models\User;
use App\Services\Crm\CrmImportService;
use Illuminate\Foundation\Application;

$runDir = $argv[1] ?? '';
$workerId = (int) ($argv[2] ?? -1);
$payloadJson = (string) ($argv[3] ?? '');

if ($runDir === '' || $workerId < 0 || $payloadJson === '') {
    fwrite(STDERR, "Usage: crm_import_apply_worker.php <run_dir> <worker_id> <payload_json>\n");
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
    /** @var array{import_id: int, actor_id: int, fingerprint: string, catalog_fingerprint: string} $data */
    $data = json_decode($payloadJson, true, 512, JSON_THROW_ON_ERROR);

    $import = CrmImport::query()->findOrFail((int) $data['import_id']);
    $actor = User::query()->findOrFail((int) $data['actor_id']);

    $applied = $app->make(CrmImportService::class)->apply(
        $import,
        $actor,
        (string) $data['fingerprint'],
        (string) $data['catalog_fingerprint'],
    );

    $sup = $applied->report['supplements'] ?? [];
    file_put_contents(
        $resultFile,
        'OK:'.$applied->id.':'.($sup['conflicts'] ?? 0).':'.($sup['conflicts_created'] ?? 0),
    );
} catch (Throwable $exception) {
    file_put_contents(
        $resultFile,
        'ERROR:'.get_class($exception).'|'.mb_substr($exception->getMessage(), 0, 200),
    );
}

exit(0);
