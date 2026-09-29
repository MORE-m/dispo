<?php

declare(strict_types=1);

/**
 * MySQL-Parallelworker für Ask/Answer-Outbox-Dispatch (BL-P9-02c).
 *
 * Deterministische Dateibarriere zwischen Laden derselben fälligen pending-Zeile
 * und dem konkurrierenden claimAndDispatch() – ohne Sleeps und ohne App-Test-Hooks.
 */

use App\Enums\NotificationOutboxStatus;
use App\Models\NotificationOutbox;
use App\Services\Notification\NotificationOutboxDeliveryService;
use Illuminate\Foundation\Application;
use Illuminate\Support\Str;

$runDir = $argv[1] ?? '';
$workerId = (int) ($argv[2] ?? -1);
$outboxId = (int) ($argv[3] ?? 0);

if ($runDir === '' || $workerId < 0 || $outboxId <= 0) {
    fwrite(STDERR, "Usage: sales_inquiry_outbox_dispatch_worker.php <run_dir> <worker_id> <outbox_id>\n");
    exit(1);
}

if (! is_dir($runDir) && ! mkdir($runDir, 0700, true) && ! is_dir($runDir)) {
    fwrite(STDERR, "Run directory could not be created: {$runDir}\n");
    exit(1);
}

/** @var Application $app */
$app = require __DIR__.'/bootstrap_mysql_worker.php';

$loadedFile = $runDir.'/worker-'.$workerId.'.loaded';
$resultFile = $runDir.'/worker-'.$workerId.'.result';
$expectedToken = 'pending:'.$outboxId;

try {
    $row = NotificationOutbox::query()->findOrFail($outboxId);

    if ($row->status !== NotificationOutboxStatus::Pending) {
        throw new RuntimeException(
            'Outbox '.$outboxId.' ist nicht pending (Status: '.$row->status->value.').',
        );
    }

    // Nachweis: diese Instanz hat die fällige pending-Zeile geladen.
    file_put_contents($loadedFile, $expectedToken);

    $deadline = microtime(true) + 30.0;
    while (true) {
        $loadedFiles = glob($runDir.'/worker-*.loaded') ?: [];
        if (count($loadedFiles) >= 2) {
            $tokens = [];
            foreach ($loadedFiles as $file) {
                $tokens[] = trim((string) file_get_contents($file));
            }

            foreach ($tokens as $token) {
                if ($token !== $expectedToken) {
                    throw new RuntimeException(
                        'Barrier-Inhalt inkonsistent: erwartet '.$expectedToken
                        .', got '.implode('|', $tokens),
                    );
                }
            }

            break;
        }

        if (microtime(true) > $deadline) {
            throw new RuntimeException(
                'Barrier timeout: weniger als 2 Worker haben pending:'.$outboxId.' geladen.',
            );
        }

        usleep(5_000);
    }

    // Beide haben pending geladen → konkurrierender Produktions-Claim/Dispatch.
    $won = $app->make(NotificationOutboxDeliveryService::class)->claimAndDispatch($row);
    file_put_contents($resultFile, 'OK:'.($won ? '1' : '0').':'.$outboxId);
} catch (Throwable $exception) {
    file_put_contents(
        $resultFile,
        'ERROR:'.get_class($exception).'|'.Str::limit($exception->getMessage(), 200),
    );
    exit(1);
}

exit(0);
