<?php

namespace Tests\Support;

use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xls;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

final class PriceListImportWorkbookFactory
{
    /**
     * @param  list<list<mixed>>  $rows  inkl. Header
     */
    public static function writeXlsx(string $path, array $rows, string $sheetTitle = 'prices'): void
    {
        $spreadsheet = self::spreadsheetFromRows($rows, $sheetTitle);
        (new Xlsx($spreadsheet))->save($path);
        $spreadsheet->disconnectWorksheets();
    }

    /**
     * @param  list<list<mixed>>  $rows  inkl. Header
     */
    public static function writeXls(string $path, array $rows, string $sheetTitle = 'prices'): void
    {
        $spreadsheet = self::spreadsheetFromRows($rows, $sheetTitle);
        (new Xls($spreadsheet))->save($path);
        $spreadsheet->disconnectWorksheets();
    }

    /**
     * @param  list<array{0: string, 1: int|string, 2: string, 3: string|float|int}>  $dataRows
     */
    public static function canonicalFlat(string $path, array $dataRows, ?int $year = null, string $format = 'xlsx'): void
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

        if ($format === 'xls') {
            self::writeXls($path, $rows);

            return;
        }

        self::writeXlsx($path, $rows);
    }

    /**
     * Kleines XLSX mit echten Formelzellen (nicht berechnen).
     */
    public static function withFormulas(string $path): void
    {
        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('prices');
        $sheet->fromArray([
            ['inventory_code', 'hour', 'day_group', 'second_price'],
            ['RH', null, 'mo_fr', null],
        ], null, 'A1', true);
        $sheet->setCellValueExplicit('B2', '=8', DataType::TYPE_FORMULA);
        $sheet->setCellValueExplicit('D2', '=1+1', DataType::TYPE_FORMULA);
        (new Xlsx($spreadsheet))->save($path);
        $spreadsheet->disconnectWorksheets();
    }

    /**
     * Used-Range überschreitet Zeilenlimit ohne alle Zellen zu materialisieren.
     */
    public static function oversizedRows(string $path, int $lastRow): void
    {
        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('prices');
        $sheet->fromArray([
            ['inventory_code', 'hour', 'day_group', 'second_price'],
            ['RH', 8, 'mo_fr', '1.0000'],
        ], null, 'A1', true);
        $sheet->setCellValue('A'.$lastRow, 'marker');
        (new Xlsx($spreadsheet))->save($path);
        $spreadsheet->disconnectWorksheets();
    }

    public static function oversizedColumns(string $path, int $lastColumnIndex): void
    {
        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('prices');
        $sheet->fromArray([
            ['inventory_code', 'hour', 'day_group', 'second_price'],
            ['RH', 8, 'mo_fr', '1.0000'],
        ], null, 'A1', true);
        $sheet->setCellValue([$lastColumnIndex, 1], 'wide');
        (new Xlsx($spreadsheet))->save($path);
        $spreadsheet->disconnectWorksheets();
    }

    public static function tooManySheets(string $path, int $sheetCount): void
    {
        $spreadsheet = new Spreadsheet;
        $spreadsheet->getActiveSheet()->setTitle('sheet1');
        $spreadsheet->getActiveSheet()->fromArray([
            ['inventory_code', 'hour', 'day_group', 'second_price'],
            ['RH', 8, 'mo_fr', '1.0000'],
        ], null, 'A1', true);
        for ($i = 2; $i <= $sheetCount; $i++) {
            $spreadsheet->createSheet()->setTitle('sheet'.$i);
        }
        (new Xlsx($spreadsheet))->save($path);
        $spreadsheet->disconnectWorksheets();
    }

    /**
     * @param  list<list<mixed>>  $rows
     */
    private static function spreadsheetFromRows(array $rows, string $sheetTitle): Spreadsheet
    {
        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle($sheetTitle);
        foreach ($rows as $r => $line) {
            foreach ($line as $c => $value) {
                $sheet->setCellValue([$c + 1, $r + 1], $value);
            }
        }

        return $spreadsheet;
    }
}
