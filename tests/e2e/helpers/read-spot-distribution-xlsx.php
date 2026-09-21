<?php

/**
 * Liest SPT-008 XLSX und gibt JSON-Zusammenfassung aus.
 * Aufruf: php read-spot-distribution-xlsx.php /path/to/file.xlsx
 */

declare(strict_types=1);

use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\IOFactory;

require dirname(__DIR__, 3).'/vendor/autoload.php';

$path = $argv[1] ?? '';
if ($path === '' || ! is_file($path)) {
    fwrite(STDERR, "Usage: php read-spot-distribution-xlsx.php <file.xlsx>\n");
    exit(1);
}

$spreadsheet = IOFactory::load($path);
$sheetNames = $spreadsheet->getSheetNames();
$sheet = $spreadsheet->getActiveSheet();
$headers = [];
$highestColumnIndex = Coordinate::columnIndexFromString($sheet->getHighestColumn());
for ($col = 1; $col <= $highestColumnIndex; $col++) {
    $coordinate = Coordinate::stringFromColumnIndex($col).'1';
    $headers[] = (string) $sheet->getCell($coordinate)->getValue();
}

$rows = [];
$highestRow = (int) $sheet->getHighestDataRow();
for ($row = 2; $row <= $highestRow; $row++) {
    $line = [];
    for ($col = 1; $col <= count($headers); $col++) {
        $coordinate = Coordinate::stringFromColumnIndex($col).$row;
        $line[] = (string) $sheet->getCell($coordinate)->getCalculatedValue();
    }
    $rows[] = $line;
}

echo json_encode([
    'sheet_names' => $sheetNames,
    'active_title' => $sheet->getTitle(),
    'headers' => $headers,
    'row_count' => count($rows),
    'rows' => $rows,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), "\n";
