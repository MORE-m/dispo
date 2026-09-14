<?php

declare(strict_types=1);

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

require dirname(__DIR__, 3).'/vendor/autoload.php';

$target = $argv[1] ?? null;
$mode = $argv[2] ?? 'valid';
if ($target === null) {
    fwrite(STDERR, "Usage: write-price-list-xlsx.php <path> [valid|invalid|sparse]\n");
    exit(1);
}

$rows = match ($mode) {
    'invalid' => [
        ['inventory_code', 'hour', 'day_group', 'second_price'],
        ['RH', 8, 'mo_fr', '-1'],
    ],
    'sparse' => [
        ['inventory_code', 'hour', 'day_group', 'second_price'],
        ['RH', 6, 'mo_fr', '1.0000'],
    ],
    default => [
        ['inventory_code', 'hour', 'day_group', 'second_price'],
        ['RH', 8, 'mo_fr', '1.2500'],
        ['RH', 8, 'sa', '1.5000'],
    ],
};

$spreadsheet = new Spreadsheet;
$sheet = $spreadsheet->getActiveSheet();
$sheet->setTitle('prices');
foreach ($rows as $r => $line) {
    foreach ($line as $c => $value) {
        $sheet->setCellValue([$c + 1, $r + 1], $value);
    }
}
(new Xlsx($spreadsheet))->save($target);
$spreadsheet->disconnectWorksheets();
