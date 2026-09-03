<?php

declare(strict_types=1);

use App\Models\DispoOrder;
use App\Models\User;
use App\Services\DispoOrder\DispoOrderApprovalService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Application;
use Illuminate\Support\Str;

$runDir = $argv[1] ?? '';
$workerId = (int) ($argv[2] ?? -1);
$orderId = (int) ($argv[3] ?? 0);
$userId = (int) ($argv[4] ?? 0);
$action = (string) ($argv[5] ?? '');
$lockVersion = (int) ($argv[6] ?? 0);

if ($runDir === '' || $workerId < 0 || $orderId <= 0 || $userId <= 0 || $action === '' || $lockVersion <= 0) {
    fwrite(STDERR, "Usage: dispo_order_approval_worker.php <run_dir> <worker_id> <order_id> <user_id> <approve|reject|submit> <lock_version>\n");
    exit(1);
}

if (! is_dir($runDir) && ! mkdir($runDir, 0700, true) && ! is_dir($runDir)) {
    fwrite(STDERR, "Run directory could not be created: {$runDir}\n");
    exit(1);
}

require __DIR__.'/../../vendor/autoload.php';

/** @var Application $app */
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

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
    $service = $app->make(DispoOrderApprovalService::class);

    $result = match ($action) {
        'submit' => $service->submit($order, $user, $lockVersion),
        'approve' => $service->approve($order, $user, $lockVersion, null),
        'reject' => $service->reject($order, $user, $lockVersion, 'Parallele Ablehnung'),
        default => throw new InvalidArgumentException('Unknown action'),
    };

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
