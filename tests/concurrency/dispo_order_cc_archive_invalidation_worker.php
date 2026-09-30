<?php

declare(strict_types=1);

use App\Enums\DispoOrderStatus;
use App\Models\DispoOrder;
use App\Models\DispoOrderUpload;
use App\Models\User;
use App\Services\DispoOrder\DispoOrderOperationalStatusService;
use App\Services\DispoOrder\DispoOrderUploadService;
use Illuminate\Foundation\Application;
use Illuminate\Support\Str;

$runDir = $argv[1] ?? '';
$workerId = (int) ($argv[2] ?? -1);
$orderId = (int) ($argv[3] ?? 0);
$userId = (int) ($argv[4] ?? 0);
$lockVersion = (int) ($argv[5] ?? 0);
$action = (string) ($argv[6] ?? '');
$payload = (string) ($argv[7] ?? '');

if (
    $runDir === ''
    || $workerId < 0
    || $orderId <= 0
    || $userId <= 0
    || $lockVersion <= 0
    || ($action !== 'archive_cc' && $action !== 'transition')
    || $payload === ''
) {
    fwrite(
        STDERR,
        "Usage: dispo_order_cc_archive_invalidation_worker.php <run_dir> <worker_id> <order_id> <user_id> <lock_version> <archive_cc|transition> <upload_id|target_status>\n",
    );
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
    $user = User::query()->findOrFail($userId);
    $order = DispoOrder::query()->findOrFail($orderId);

    if ($action === 'archive_cc') {
        $upload = DispoOrderUpload::query()->findOrFail((int) $payload);
        $result = $app->make(DispoOrderUploadService::class)->archive(
            $order,
            $upload,
            $user,
            $lockVersion,
        );

        file_put_contents(
            $resultFile,
            'OK:'.$result->order->status->value
                .'|'.$result->order->lock_version
                .'|'.($result->approvalInvalidated ? '1' : '0'),
        );
    } else {
        $target = DispoOrderStatus::from($payload);
        $result = $app->make(DispoOrderOperationalStatusService::class)->transition(
            $order,
            $user,
            $lockVersion,
            $target,
            null,
        );

        file_put_contents(
            $resultFile,
            'OK:'.$result->status->value.'|'.$result->lock_version,
        );
    }
} catch (Throwable $exception) {
    file_put_contents(
        $resultFile,
        'ERROR:'.get_class($exception).'|'.Str::limit($exception->getMessage(), 200),
    );
}

exit(0);
