#!/usr/bin/env php
<?php

/**
 * Diagnose A: Same-process XLSX-Renderer (kein HTTP, kein Browser).
 *
 * Usage:
 *   php spt008-diag-cli-same-process.php [--rounds=20] [--keys=calendar,tandem,average,mixed,multi]
 *
 * Exit 139 = SIGSEGV (Prozess stirbt nativ).
 */

declare(strict_types=1);

use App\Models\DispoOrder;
use App\Services\DispoOrder\SpotDistributionExport\SpotDistributionExportBuilder;
use App\Services\DispoOrder\SpotDistributionExport\SpotDistributionXlsxRenderer;
use Illuminate\Contracts\Console\Kernel;

require dirname(__DIR__, 3).'/vendor/autoload.php';

$app = require dirname(__DIR__, 3).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$rounds = 20;
$keys = ['calendar', 'tandem', 'average', 'mixed', 'multi'];

foreach (array_slice($argv, 1) as $arg) {
    if (str_starts_with($arg, '--rounds=')) {
        $rounds = max(1, (int) substr($arg, 9));
    }
    if (str_starts_with($arg, '--keys=')) {
        $keys = array_values(array_filter(explode(',', substr($arg, 7))));
    }
}

$ordersPath = dirname(__DIR__, 3).'/database/e2e-spt008-orders.json';
if (! is_file($ordersPath)) {
    fwrite(STDERR, "Missing {$ordersPath}\n");
    exit(2);
}

/** @var array<string, array{id:int}> $fixture */
$fixture = json_decode((string) file_get_contents($ordersPath), true, 512, JSON_THROW_ON_ERROR);

$builder = new SpotDistributionExportBuilder;
$renderer = new SpotDistributionXlsxRenderer;

echo "SPT008_CLI_SAME_PROCESS start rounds={$rounds} keys=".implode(',', $keys).PHP_EOL;
echo 'memory_start='.memory_get_usage(true).' peak_start='.memory_get_peak_usage(true).PHP_EOL;

$iteration = 0;
for ($round = 1; $round <= $rounds; $round++) {
    foreach ($keys as $key) {
        $iteration++;
        if (! isset($fixture[$key]['id'])) {
            fwrite(STDERR, "Unknown fixture key: {$key}\n");
            exit(2);
        }

        $orderId = (int) $fixture[$key]['id'];
        $order = DispoOrder::query()->with(['positions'])->findOrFail($orderId);

        $beforeMem = memory_get_usage(true);
        $beforePeak = memory_get_peak_usage(true);
        $started = hrtime(true);

        $document = $builder->build($order);
        $binary = $renderer->render($document);
        $bytes = strlen($binary);

        $elapsedMs = (int) ((hrtime(true) - $started) / 1_000_000);
        unset($binary, $document, $order);

        echo sprintf(
            "SPT008_CLI_SAME iteration=%d round=%d key=%s order_id=%d bytes=%d elapsed_ms=%d mem=%d peak=%d before_mem=%d before_peak=%d\n",
            $iteration,
            $round,
            $key,
            $orderId,
            $bytes,
            $elapsedMs,
            memory_get_usage(true),
            memory_get_peak_usage(true),
            $beforeMem,
            $beforePeak,
        );
    }
}

echo 'SPT008_CLI_SAME_PROCESS done iterations='.$iteration.
    ' memory_end='.memory_get_usage(true).
    ' peak_end='.memory_get_peak_usage(true).PHP_EOL;
exit(0);
