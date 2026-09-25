#!/usr/bin/env php
<?php

/**
 * Ein einzelner XLSX-Render in einem frischen PHP-Prozess.
 *
 * Usage: php spt008-diag-cli-one-render.php <fixtureKey>
 */

declare(strict_types=1);

use App\Models\DispoOrder;
use App\Services\DispoOrder\SpotDistributionExport\SpotDistributionExportBuilder;
use App\Services\DispoOrder\SpotDistributionExport\SpotDistributionXlsxRenderer;
use Illuminate\Contracts\Console\Kernel;

require dirname(__DIR__, 3).'/vendor/autoload.php';

$key = $argv[1] ?? '';
if ($key === '') {
    fwrite(STDERR, "Usage: php spt008-diag-cli-one-render.php <fixtureKey>\n");
    exit(2);
}

$app = require dirname(__DIR__, 3).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$ordersPath = dirname(__DIR__, 3).'/database/e2e-spt008-orders.json';
/** @var array<string, array{id:int}> $fixture */
$fixture = json_decode((string) file_get_contents($ordersPath), true, 512, JSON_THROW_ON_ERROR);

if (! isset($fixture[$key]['id'])) {
    fwrite(STDERR, "Unknown fixture key: {$key}\n");
    exit(2);
}

$orderId = (int) $fixture[$key]['id'];
$order = DispoOrder::query()->with(['positions'])->findOrFail($orderId);
$document = (new SpotDistributionExportBuilder)->build($order);
$binary = (new SpotDistributionXlsxRenderer)->render($document);

echo sprintf(
    "SPT008_CLI_ONE key=%s order_id=%d bytes=%d mem=%d peak=%d\n",
    $key,
    $orderId,
    strlen($binary),
    memory_get_usage(true),
    memory_get_peak_usage(true),
);

exit(0);
