<?php

declare(strict_types=1);

use App\Models\DispoOrder;
use App\Models\User;
use App\Services\DispoOrder\DispoOrderUploadService;
use Illuminate\Foundation\Application;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;

$runDir = $argv[1] ?? '';
$workerId = (int) ($argv[2] ?? -1);
$orderId = (int) ($argv[3] ?? 0);
$userId = (int) ($argv[4] ?? 0);
$lockVersion = (int) ($argv[5] ?? 0);
$payload = (string) ($argv[6] ?? '');
$fieldKey = (string) ($argv[7] ?? '');
$positionIdRaw = $argv[8] ?? '';
$positionId = $positionIdRaw === '' || $positionIdRaw === '0' ? null : (int) $positionIdRaw;

if (
    $runDir === ''
    || $workerId < 0
    || $orderId <= 0
    || $userId <= 0
    || $lockVersion <= 0
    || $payload === ''
    || $fieldKey === ''
) {
    fwrite(
        STDERR,
        "Usage: dispo_order_dynamic_field_upload_worker.php <run_dir> <worker_id> <order_id> <user_id> <lock_version> <file_path> <field_key> [position_id]\n",
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
    if (! is_file($payload)) {
        throw new RuntimeException('Upload fixture missing: '.$payload);
    }

    $user = User::query()->findOrFail($userId);
    $order = DispoOrder::query()->findOrFail($orderId);

    $file = new UploadedFile(
        $payload,
        'worker-'.$workerId.'-dynamic.pdf',
        'application/pdf',
        null,
        true,
    );

    $upload = $app->make(DispoOrderUploadService::class)->uploadDynamicFieldFile(
        $order,
        $user,
        $lockVersion,
        $file,
        $fieldKey,
        $positionId,
    );

    file_put_contents(
        $resultFile,
        'OK:upload|'.$upload->id.'|'.$order->fresh()->lock_version,
    );
} catch (Throwable $exception) {
    file_put_contents(
        $resultFile,
        'ERROR:'.get_class($exception).'|'.Str::limit($exception->getMessage(), 200),
    );
}

exit(0);
