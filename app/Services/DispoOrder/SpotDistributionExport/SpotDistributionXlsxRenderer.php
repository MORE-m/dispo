<?php

namespace App\Services\DispoOrder\SpotDistributionExport;

use App\Support\Spreadsheet\SpreadsheetText;
use DateTimeImmutable;
use DateTimeZone;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use RuntimeException;

/**
 * SPT-008: XLSX-Renderer für Spotverteilung (PhpSpreadsheet).
 */
final class SpotDistributionXlsxRenderer
{
    /**
     * @return string Binärer XLSX-Inhalt
     */
    public function render(SpotDistributionExportDocument $document): string
    {
        $spreadsheet = new Spreadsheet;
        $tmpPath = null;

        try {
            $sheet = $spreadsheet->getActiveSheet();
            $sheet->setTitle(SpotDistributionExportDocument::SHEET_TITLE);

            $headers = $document->headers;
            $lastColumn = $this->columnLetter(count($headers));

            foreach ($headers as $columnIndex => $header) {
                $sheet->setCellValueExplicit(
                    $this->coordinate($columnIndex + 1, 1),
                    SpreadsheetText::neutralize($header),
                    DataType::TYPE_STRING,
                );
            }

            $sheet->getStyle('A1:'.$lastColumn.'1')->getFont()->setBold(true);
            $sheet->freezePane('A2');
            $lastDataRow = max(1, count($document->rows) + 1);
            $sheet->setAutoFilter('A1:'.$lastColumn.$lastDataRow);

            foreach ($document->rows as $rowOffset => $row) {
                $this->writeDataRow($sheet, $rowOffset + 2, $row);
            }

            $this->applyColumnWidths($sheet);

            $tmpPath = tempnam(sys_get_temp_dir(), 'spt008xlsx');
            if ($tmpPath === false) {
                throw new RuntimeException('Temporäre XLSX-Datei konnte nicht angelegt werden.');
            }

            $writer = new Xlsx($spreadsheet);
            $writer->setUseDiskCaching(true, sys_get_temp_dir());
            $writer->save($tmpPath);

            $spreadsheet->disconnectWorksheets();
            unset($spreadsheet, $writer);

            $binary = file_get_contents($tmpPath);
            if ($binary === false || $binary === '') {
                throw new RuntimeException('XLSX-Inhalt konnte nicht gelesen werden.');
            }

            return $binary;
        } finally {
            if (isset($spreadsheet)) {
                $spreadsheet->disconnectWorksheets();
            }
            if (is_string($tmpPath) && is_file($tmpPath)) {
                @unlink($tmpPath);
            }
        }
    }

    private function writeDataRow(
        Worksheet $sheet,
        int $excelRow,
        SpotDistributionExportRow $row,
    ): void {
        $visibleDate = (new DateTimeImmutable($row->dateIso, new DateTimeZone('Europe/Berlin')))
            ->format('d.m.Y');

        $values = [
            1 => $row->dispoOrderNumber,
            2 => $row->customerName,
            3 => $row->positionLabel,
            4 => $row->inventoryName,
            5 => $row->advertisingMediumName,
            6 => $visibleDate,
            7 => $row->weekdayLabel,
            8 => $row->hourLabel,
            9 => $row->dayGroupLabel,
            10 => (string) $row->quantity,
            11 => $row->quantityUnit,
            12 => (string) $row->totalLengthSeconds,
            13 => $row->componentsLabel,
            14 => (string) $row->componentAirings,
        ];

        foreach ($values as $column => $value) {
            $dataType = in_array($column, [10, 12, 14], true)
                ? DataType::TYPE_NUMERIC
                : DataType::TYPE_STRING;

            $sheet->setCellValueExplicit(
                $this->coordinate($column, $excelRow),
                $dataType === DataType::TYPE_STRING
                    ? SpreadsheetText::neutralize($value)
                    : $value,
                $dataType,
            );
        }
    }

    private function applyColumnWidths(Worksheet $sheet): void
    {
        $widths = [
            'A' => 22,
            'B' => 28,
            'C' => 14,
            'D' => 22,
            'E' => 22,
            'F' => 12,
            'G' => 12,
            'H' => 10,
            'I' => 12,
            'J' => 10,
            'K' => 18,
            'L' => 14,
            'M' => 42,
            'N' => 18,
        ];

        foreach ($widths as $column => $width) {
            $sheet->getColumnDimension($column)->setWidth($width);
        }
    }

    private function coordinate(int $column, int $row): string
    {
        return $this->columnLetter($column).$row;
    }

    private function columnLetter(int $column): string
    {
        return Coordinate::stringFromColumnIndex($column);
    }
}
