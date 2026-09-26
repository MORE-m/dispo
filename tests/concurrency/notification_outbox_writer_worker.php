<?php

declare(strict_types=1);

use App\Enums\NotificationOutboxChannel;
use App\Services\Notification\NotificationOutboxIntent;
use App\Services\Notification\NotificationOutboxWriter;
use Illuminate\Foundation\Application;
use Illuminate\Support\Str;

$runDir = $argv[1] ?? '';
$workerId = (int) ($argv[2] ?? -1);
$payloadJson = (string) ($argv[3] ?? '');

if ($runDir === '' || $workerId < 0 || $payloadJson === '') {
    fwrite(STDERR, "Usage: notification_outbox_writer_worker.php <run_dir> <worker_id> <payload_json>\n");
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
    /** @var array{
     *     eventType: string,
     *     sourceType: string,
     *     sourceId: int,
     *     channel: string,
     *     recipientUserId: int|null,
     *     recipientEmail: string,
     *     recipientName: string,
     *     payload: array<string, mixed>
     * } $data
     */
    $data = json_decode($payloadJson, true, 512, JSON_THROW_ON_ERROR);

    $intent = new NotificationOutboxIntent(
        eventType: $data['eventType'],
        sourceType: $data['sourceType'],
        sourceId: (int) $data['sourceId'],
        channel: NotificationOutboxChannel::from($data['channel']),
        recipientUserId: $data['recipientUserId'],
        recipientEmail: $data['recipientEmail'],
        recipientName: $data['recipientName'],
        payload: $data['payload'],
    );

    $row = $app->make(NotificationOutboxWriter::class)->enqueue($intent);
    file_put_contents($resultFile, 'OK:'.$row->id);
} catch (Throwable $exception) {
    file_put_contents(
        $resultFile,
        'ERROR:'.get_class($exception).'|'.Str::limit($exception->getMessage(), 200),
    );
}

exit(0);
