<?php

declare(strict_types=1);

/**
 * PO-CALC-TKP-1D4c-2: Parallelworker für oa:activate-price-list / ActivationService.
 *
 * argv: runDir, workerId, barrierPeers, year, revision, archivePrevious(0|1)
 */

use App\Services\OnlineAudio\Import\OaTkpPriceListActivationService;
use App\Services\OnlineAudio\Import\OaTkpStammdatenImportException;
use Illuminate\Foundation\Application;

/** @var Application $app */
$app = require __DIR__.'/bootstrap_mysql_worker.php';

$runDir = $argv[1] ?? '';
$workerId = $argv[2] ?? '0';
$barrierPeers = (int) ($argv[3] ?? 2);
$year = (int) ($argv[4] ?? 0);
$revision = (int) ($argv[5] ?? 0);
$archivePrevious = (($argv[6] ?? '0') === '1');

if ($runDir === '' || $year < 1 || $revision < 1) {
    fwrite(STDERR, "Usage: oa_tkp_activate_worker.php runDir workerId peers year revision archivePrevious\n");
    exit(1);
}

$readyFile = $runDir.'/ready-'.$workerId;
$goFile = $runDir.'/go';
$resultFile = $runDir.'/worker-'.$workerId.'.result';

file_put_contents($readyFile, '1');

$deadline = microtime(true) + 20;
while (! is_file($goFile) && microtime(true) < $deadline) {
    $ready = glob($runDir.'/ready-*') ?: [];
    if (count($ready) >= $barrierPeers) {
        // Host schreibt go; Worker warten.
    }
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
    /** @var OaTkpPriceListActivationService $service */
    $service = $app->make(OaTkpPriceListActivationService::class);
    $result = $service->activate($year, $revision, $archivePrevious, false);
    $flag = $result['already_active'] ? 'already' : 'activated';
    file_put_contents(
        $resultFile,
        sprintf(
            'OK:%s|year=%d|revision=%d|id=%s|archived=%d',
            $flag,
            $result['year'],
            $result['revision_number'],
            (string) $result['price_list_id'],
            count($result['archived_previous_ids']),
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
