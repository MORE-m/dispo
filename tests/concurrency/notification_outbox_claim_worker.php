<?php

declare(strict_types=1);

use App\Models\NotificationOutbox;
use App\Services\Notification\NotificationOutboxStateMachine;
use Illuminate\Foundation\Application;
use Illuminate\Support\Str;

$runDir = $argv[1] ?? '';
$workerId = (int) ($argv[2] ?? -1);
$outboxId = (int) ($argv[3] ?? 0);

if ($runDir === '' || $workerId < 0 || $outboxId <= 0) {
    fwrite(STDERR, "Usage: notification_outbox_claim_worker.php <run_dir> <worker_id> <outbox_id>\n");
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
    // Kurze künstliche Last, damit beide Worker den pending-Status sehen.
    usleep(20_000 + ($workerId * 5_000));

    $row = NotificationOutbox::query()->findOrFail($outboxId);
    $claimed = $app->make(NotificationOutboxStateMachine::class)->tryClaimPending($row);
    file_put_contents($resultFile, 'OK:'.$claimed->status->value);
} catch (Throwable $exception) {
    file_put_contents(
        $resultFile,
        'ERROR:'.get_class($exception).'|'.Str::limit($exception->getMessage(), 200),
    );
}

exit(0);
