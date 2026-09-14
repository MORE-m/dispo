<?php

namespace Tests\Support;

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

final class PriceListImportWorkbookFactory
{
    /**
     * @param  list<list<mixed>>  $rows  inkl. Header
     */
    public static function writeXlsx(string $path, array $rows, string $sheetTitle = 'prices'): void
    {
        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle($sheetTitle);
        foreach ($rows as $r => $line) {
            foreach ($line as $c => $value) {
                $sheet->setCellValue([$c + 1, $r + 1], $value);
            }
        }
        (new Xlsx($spreadsheet))->save($path);
        $spreadsheet->disconnectWorksheets();
    }

    /**
     * @param  list<array{0: string, 1: int|string, 2: string, 3: string|float|int}>  $dataRows
     */
    public static function canonicalFlat(string $path, array $dataRows, ?int $year = null): void
    {
        $header = ['inventory_code', 'hour', 'day_group', 'second_price'];
        if ($year !== null) {
            $header[] = 'year';
        }
        $rows = [$header];
        foreach ($dataRows as $row) {
            $line = [$row[0], $row[1], $row[2], $row[3]];
            if ($year !== null) {
                $line[] = $year;
            }
            $rows[] = $line;
        }
        self::writeXlsx($path, $rows);
    }
}
