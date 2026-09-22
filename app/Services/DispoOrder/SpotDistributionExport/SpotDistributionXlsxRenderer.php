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
 * SPT-008: XLSX-Renderer für Spotplanung (zwei Blätter).
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
            $calendarSheet = $spreadsheet->getActiveSheet();
            $calendarSheet->setTitle(SpotDistributionExportDocument::CALENDAR_SHEET_TITLE);
            $this->writeCalendarSheet($calendarSheet, $document);

            $averageSheet = $spreadsheet->createSheet();
            $averageSheet->setTitle(SpotDistributionExportDocument::AVERAGE_SHEET_TITLE);
            $this->writeAverageSheet($averageSheet, $document);

            $spreadsheet->setActiveSheetIndex(0);

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

    private function writeCalendarSheet(Worksheet $sheet, SpotDistributionExportDocument $document): void
    {
        if ($document->calendarRows === []) {
            $this->writeEmptyMessage($sheet, SpotDistributionExportDocument::CALENDAR_EMPTY_MESSAGE);

            return;
        }

        $headers = $document->calendarHeaders;
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
        $lastDataRow = count($document->calendarRows) + 1;
        $sheet->setAutoFilter('A1:'.$lastColumn.$lastDataRow);

        foreach ($document->calendarRows as $rowOffset => $row) {
            $this->writeCalendarDataRow($sheet, $rowOffset + 2, $row);
        }

        $this->applyCalendarColumnWidths($sheet);
    }

    private function writeAverageSheet(Worksheet $sheet, SpotDistributionExportDocument $document): void
    {
        if ($document->averageRows === []) {
            $this->writeEmptyMessage($sheet, SpotDistributionExportDocument::AVERAGE_EMPTY_MESSAGE);

            return;
        }

        $sheet->setCellValueExplicit(
            'A1',
            SpreadsheetText::neutralize(SpotDistributionExportDocument::AVERAGE_NOTICE),
            DataType::TYPE_STRING,
        );
        $sheet->mergeCells('A1:O1');
        $noticeFont = $sheet->getStyle('A1')->getFont();
        $noticeFont->setBold(true);
        $noticeFont->setItalic(true);
        $noticeFont->setSize(11);
        $sheet->getStyle('A1')->getAlignment()->setWrapText(true);
        $sheet->getRowDimension(1)->setRowHeight(28);

        $headers = $document->averageHeaders;
        $headerRow = 2;
        $lastColumn = $this->columnLetter(count($headers));

        foreach ($headers as $columnIndex => $header) {
            $sheet->setCellValueExplicit(
                $this->coordinate($columnIndex + 1, $headerRow),
                SpreadsheetText::neutralize($header),
                DataType::TYPE_STRING,
            );
        }

        $sheet->getStyle('A'.$headerRow.':'.$lastColumn.$headerRow)->getFont()->setBold(true);
        $sheet->freezePane('A3');
        $lastDataRow = $headerRow + count($document->averageRows);
        $sheet->setAutoFilter('A'.$headerRow.':'.$lastColumn.$lastDataRow);

        foreach ($document->averageRows as $rowOffset => $row) {
            $this->writeAverageDataRow($sheet, $headerRow + 1 + $rowOffset, $row);
        }

        $this->applyAverageColumnWidths($sheet);
    }

    private function writeEmptyMessage(Worksheet $sheet, string $message): void
    {
        $sheet->setCellValueExplicit(
            'A1',
            SpreadsheetText::neutralize($message),
            DataType::TYPE_STRING,
        );
        $sheet->getStyle('A1')->getFont()->setBold(true);
        $sheet->getStyle('A1')->getAlignment()->setWrapText(true);
        $sheet->getColumnDimension('A')->setWidth(80);
    }

    private function writeCalendarDataRow(
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

    private function writeAverageDataRow(
        Worksheet $sheet,
        int $excelRow,
        SpotPlanningProposalExportRow $row,
    ): void {
        $values = [
            1 => $row->dispoOrderNumber,
            2 => $row->customerName,
            3 => $row->positionLabel,
            4 => $row->inventoryName,
            5 => $row->advertisingMediumName,
            6 => $row->periodFromDisplay,
            7 => $row->periodToDisplay,
            8 => $row->dayGroupLabel,
            9 => $row->hourConstraintLabel,
            10 => (string) $row->quantity,
            11 => $row->quantityUnit,
            12 => (string) $row->totalLengthSeconds,
            13 => $row->componentsLabel,
            14 => (string) $row->componentAirings,
            15 => $row->planningStatus,
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

    private function applyCalendarColumnWidths(Worksheet $sheet): void
    {
        $widths = [
            'A' => 22, 'B' => 28, 'C' => 14, 'D' => 22, 'E' => 22,
            'F' => 12, 'G' => 12, 'H' => 10, 'I' => 12, 'J' => 10,
            'K' => 18, 'L' => 14, 'M' => 42, 'N' => 18,
        ];
        foreach ($widths as $column => $width) {
            $sheet->getColumnDimension($column)->setWidth($width);
        }
    }

    private function applyAverageColumnWidths(Worksheet $sheet): void
    {
        $widths = [
            'A' => 22, 'B' => 28, 'C' => 14, 'D' => 22, 'E' => 22,
            'F' => 14, 'G' => 14, 'H' => 12, 'I' => 18, 'J' => 10,
            'K' => 18, 'L' => 14, 'M' => 42, 'N' => 18, 'O' => 14,
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
