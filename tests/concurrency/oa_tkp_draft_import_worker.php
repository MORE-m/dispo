<?php

declare(strict_types=1);

/**
 * PO-CALC-TKP-1D4c-2: Parallelworker für Draft-Reimport derselben Revision.
 *
 * argv: runDir, workerId, barrierPeers, year, revision, listTkp, minimumTkp
 */

use App\Enums\PriceListStatus;
use App\Services\OnlineAudio\Import\OaTkpPriceListDraftImportGuard;
use App\Services\OnlineAudio\Import\OaTkpStammdatenImportException;
use Illuminate\Foundation\Application;

/** @var Application $app */
$app = require __DIR__.'/bootstrap_mysql_worker.php';

$runDir = $argv[1] ?? '';
$workerId = $argv[2] ?? '0';
$barrierPeers = (int) ($argv[3] ?? 2);
$year = (int) ($argv[4] ?? 0);
$revision = (int) ($argv[5] ?? 0);
$listTkp = (string) ($argv[6] ?? '99.0000');
$minimumTkp = (string) ($argv[7] ?? '50.0000');

if ($runDir === '' || $year < 1 || $revision < 1) {
    fwrite(STDERR, "Usage: oa_tkp_draft_import_worker.php runDir workerId peers year revision listTkp minimumTkp\n");
    exit(1);
}

$readyFile = $runDir.'/ready-'.$workerId;
$goFile = $runDir.'/go';
$resultFile = $runDir.'/worker-'.$workerId.'.result';

file_put_contents($readyFile, '1');

$deadline = microtime(true) + 20;
while (! is_file($goFile) && microtime(true) < $deadline) {
    usleep(10_000);
}

$deadline = microtime(true) + 20;
while (! is_file($goFile) && microtime(true) < $deadline) {
    usleep(5_000);
}

if (! is_file($goFile)) {
    file_put_contents($resultFile, 'ERROR:barrier_timeout');
    exit(1);
}

try {
    /** @var OaTkpPriceListDraftImportGuard $guard */
    $guard = $app->make(OaTkpPriceListDraftImportGuard::class);
    $result = $guard->importDraftOnly([
        'year' => $year,
        'revision_number' => $revision,
        'status' => PriceListStatus::Draft->value,
        'name' => "Race Import {$year}.{$revision}",
        'version' => "{$year}.{$revision}",
        'items' => [[
            'medium_code' => 'pre_stream',
            'inventory_code' => 'inv_more_kombi_online_audio',
            'list_tkp' => $listTkp,
            'minimum_tkp' => $minimumTkp,
        ]],
    ], false);

    file_put_contents(
        $resultFile,
        sprintf(
            'OK:imported|year=%d|revision=%d|id=%s|items=%d|list_tkp=%s',
            $year,
            $revision,
            (string) $result['price_list_id'],
            (int) $result['items_written'],
            $listTkp,
        ),
    );
    exit(0);
} catch (OaTkpStammdatenImportException $exception) {
    file_put_contents(
        $resultFile,
        'ERROR:import|'.str_replace(["\n", '|'], [' ', '/'], $exception->getMessage().' '.implode('; ', $exception->errors)),
    );
    exit(0);
} catch (Throwable $exception) {
    file_put_contents(
        $resultFile,
        'ERROR:exception|'.str_replace(["\n", '|'], [' ', '/'], $exception->getMessage()),
    );
    exit(0);
}
