<?php

/**
 * Liest SPT-008 XLSX (beide Blätter) und gibt JSON-Zusammenfassung aus.
 * Aufruf: php read-spot-distribution-xlsx.php /path/to/file.xlsx
 */

declare(strict_types=1);

use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

require dirname(__DIR__, 3).'/vendor/autoload.php';

$path = $argv[1] ?? '';
if ($path === '' || ! is_file($path)) {
    fwrite(STDERR, "Usage: php read-spot-distribution-xlsx.php <file.xlsx>\n");
    exit(1);
}

$spreadsheet = IOFactory::load($path);
$sheetNames = $spreadsheet->getSheetNames();

/**
 * @return array{
 *     title: string,
 *     notice: string|null,
 *     empty_message: string|null,
 *     headers: list<string>,
 *     row_count: int,
 *     rows: list<list<string>>
 * }
 */
function summarizeSheet(Worksheet $sheet, bool $hasNoticeRow): array
{
    $a1 = (string) $sheet->getCell('A1')->getValue();
    $highestRow = (int) $sheet->getHighestDataRow();

    if ($highestRow <= 1 && $sheet->getCell('A2')->getValue() === null) {
        $looksLikeMessage = $a1 !== '' && ! in_array($a1, ['Dispoauftrag'], true);

        return [
            'title' => $sheet->getTitle(),
            'notice' => null,
            'empty_message' => $looksLikeMessage ? $a1 : null,
            'headers' => $looksLikeMessage ? [] : [$a1],
            'row_count' => 0,
            'rows' => [],
        ];
    }

    $notice = null;
    $headerRow = 1;
    if ($hasNoticeRow && str_contains($a1, 'Unverbindlicher Planungsvorschlag')) {
        $notice = $a1;
        $headerRow = 2;
    }

    $headers = [];
    $highestColumnIndex = Coordinate::columnIndexFromString($sheet->getHighestColumn());
    for ($col = 1; $col <= $highestColumnIndex; $col++) {
        $coordinate = Coordinate::stringFromColumnIndex($col).$headerRow;
        $value = (string) $sheet->getCell($coordinate)->getValue();
        if ($value === '' && $col > 1) {
            break;
        }
        $headers[] = $value;
    }

    $rows = [];
    for ($row = $headerRow + 1; $row <= $highestRow; $row++) {
        $line = [];
        for ($col = 1; $col <= count($headers); $col++) {
            $coordinate = Coordinate::stringFromColumnIndex($col).$row;
            $line[] = (string) $sheet->getCell($coordinate)->getCalculatedValue();
        }
        if (implode('', $line) === '') {
            continue;
        }
        $rows[] = $line;
    }

    return [
        'title' => $sheet->getTitle(),
        'notice' => $notice,
        'empty_message' => null,
        'headers' => $headers,
        'row_count' => count($rows),
        'rows' => $rows,
    ];
}

$sheets = [];
foreach ($spreadsheet->getAllSheets() as $index => $sheet) {
    $sheets[] = summarizeSheet($sheet, $index === 1);
}

$active = $spreadsheet->getActiveSheet();
$activeSummary = summarizeSheet($active, $active->getTitle() === 'Planungsvorschlag');

echo json_encode([
    'sheet_names' => $sheetNames,
    'active_title' => $active->getTitle(),
    'headers' => $activeSummary['headers'],
    'row_count' => $activeSummary['row_count'],
    'rows' => $activeSummary['rows'],
    'sheets' => $sheets,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), "\n";
