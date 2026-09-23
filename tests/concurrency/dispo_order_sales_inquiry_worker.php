<?php

declare(strict_types=1);

use App\Models\DispoOrder;
use App\Models\DispoOrderComment;
use App\Models\User;
use App\Services\DispoOrder\DispoOrderSalesInquiryService;
use Illuminate\Foundation\Application;
use Illuminate\Support\Str;

$runDir = $argv[1] ?? '';
$workerId = (int) ($argv[2] ?? -1);
$mode = (string) ($argv[3] ?? '');
$orderId = (int) ($argv[4] ?? 0);
$userId = (int) ($argv[5] ?? 0);
$lockVersion = (int) ($argv[6] ?? 0);
$text = (string) ($argv[7] ?? '');
$inquiryId = (int) ($argv[8] ?? 0);

if (
    $runDir === ''
    || $workerId < 0
    || ! in_array($mode, ['ask', 'answer'], true)
    || $orderId <= 0
    || $userId <= 0
    || $lockVersion <= 0
    || $text === ''
) {
    fwrite(STDERR, "Usage: dispo_order_sales_inquiry_worker.php <run_dir> <worker_id> <ask|answer> <order_id> <user_id> <lock_version> <text> [inquiry_id]\n");
    exit(1);
}

if ($mode === 'answer' && $inquiryId <= 0) {
    fwrite(STDERR, "answer mode requires inquiry_id\n");
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
    $service = $app->make(DispoOrderSalesInquiryService::class);

    if ($mode === 'ask') {
        $result = $service->ask($order, $user, $lockVersion, $text);
    } else {
        $inquiry = DispoOrderComment::query()->findOrFail($inquiryId);
        $result = $service->answer($order, $inquiry, $user, $lockVersion, $text);
    }

    file_put_contents(
        $resultFile,
        'OK:'.$result->status->value.'|'.$result->lock_version,
    );
} catch (Throwable $exception) {
    file_put_contents(
        $resultFile,
        'ERROR:'.get_class($exception).'|'.Str::limit($exception->getMessage(), 200),
    );
}

exit(0);
