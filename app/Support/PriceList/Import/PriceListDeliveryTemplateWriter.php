<?php

namespace App\Support\PriceList\Import;

use App\Models\Inventory;
use Illuminate\Support\Collection;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Leeres Preisanlieferungs-Template gemäß Contract v1.
 */
final class PriceListDeliveryTemplateWriter
{
    /**
     * Aktive, klassische Stundenpreislisten-Inventare (dynamisch aus DB).
     *
     * @return Collection<int, Inventory>
     */
    public function eligibleInventories(): Collection
    {
        return Inventory::query()
            ->where('is_active', true)
            ->whereIn('code', PriceListDeliveryWorkbookContract::CLASSIC_HOUR_INVENTORY_CODES)
            ->orderBy('sort')
            ->orderBy('id')
            ->get(['id', 'name', 'code', 'sort', 'is_active']);
    }

    /**
     * @param  Collection<int, Inventory>|null  $inventories
     */
    public function buildSpreadsheet(?Collection $inventories = null): Spreadsheet
    {
        $inventories ??= $this->eligibleInventories();
        $spreadsheet = new Spreadsheet;
        $spreadsheet->removeSheetByIndex(0);

        if ($inventories->isEmpty()) {
            $sheet = $spreadsheet->createSheet();
            $sheet->setTitle('Hinweis');
            $sheet->setCellValue('A1', 'Keine aktiven preislistenfähigen Inventare vorhanden.');

            return $spreadsheet;
        }

        foreach ($inventories as $inventory) {
            $title = $this->sheetTitleFor((string) $inventory->name);
            $sheet = $spreadsheet->createSheet();
            $sheet->setTitle($title);
            $this->fillSheet($sheet);
        }

        $spreadsheet->setActiveSheetIndex(0);

        return $spreadsheet;
    }

    /**
     * @param  Collection<int, Inventory>|null  $inventories
     */
    public function download(?Collection $inventories = null): StreamedResponse
    {
        $spreadsheet = $this->buildSpreadsheet($inventories);
        $filename = PriceListDeliveryWorkbookContract::DOWNLOAD_FILENAME;

        return response()->streamDownload(function () use ($spreadsheet): void {
            $writer = new Xlsx($spreadsheet);
            $writer->save('php://output');
            $spreadsheet->disconnectWorksheets();
        }, $filename, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ]);
    }

    /**
     * Excel-Blattnamen: max. 31 Zeichen, keine Sonderzeichen \ / ? * [ ].
     */
    public function sheetTitleFor(string $inventoryName): string
    {
        $title = trim($inventoryName);
        $title = str_replace(['\\', '/', '?', '*', '[', ']'], '-', $title);
        if ($title === '') {
            $title = 'Inventar';
        }
        if (mb_strlen($title) > 31) {
            $title = mb_substr($title, 0, 31);
        }

        return $title;
    }

    private function fillSheet(Worksheet $sheet): void
    {
        $headers = PriceListDeliveryWorkbookContract::headerValues();
        foreach ($headers as $index => $header) {
            $column = $index + 1;
            $cell = $sheet->getCell([$column, PriceListDeliveryWorkbookContract::HEADER_ROW]);
            $cell->setValue($header);
            $cell->getStyle()->getAlignment()->setWrapText($column > 1);
            $cell->getStyle()->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
            $cell->getStyle()->getFont()->setName($column === 1 ? 'Aptos Narrow' : 'Arial');
            $cell->getStyle()->getFont()->setSize($column === 1 ? 11 : 10);
        }

        $sheet->getRowDimension(PriceListDeliveryWorkbookContract::HEADER_ROW)->setRowHeight(26.25);

        for (
            $hour = PriceListDeliveryWorkbookContract::HOUR_MIN;
            $hour <= PriceListDeliveryWorkbookContract::HOUR_MAX;
            $hour++
        ) {
            $row = PriceListDeliveryWorkbookContract::rowForHour($hour);
            $timeCell = $sheet->getCell([1, $row]);
            $timeCell->setValue(PriceListDeliveryWorkbookContract::hourLabel($hour));
            $timeCell->getStyle()->getNumberFormat()->setFormatCode(PriceListDeliveryWorkbookContract::TIME_NUMBER_FORMAT);
            $timeCell->getStyle()->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
            $timeCell->getStyle()->getFont()->setName('Aptos Narrow');
            $timeCell->getStyle()->getFont()->setSize(11);

            foreach (array_keys(PriceListDeliveryWorkbookContract::PRICE_COLUMNS) as $columnIndex) {
                $priceCell = $sheet->getCell([$columnIndex, $row]);
                $priceCell->setValue(null);
                $priceCell->getStyle()->getNumberFormat()->setFormatCode(PriceListDeliveryWorkbookContract::PRICE_NUMBER_FORMAT);
                $priceCell->getStyle()->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
                $priceCell->getStyle()->getFont()->setName('Arial');
                $priceCell->getStyle()->getFont()->setSize(10);
            }

            if ($row === PriceListDeliveryWorkbookContract::HOUR_FIRST_ROW) {
                $sheet->getRowDimension($row)->setRowHeight(15.75);
            }
        }

        $sheet->getColumnDimension('A')->setWidth(12);
        $sheet->getColumnDimension('B')->setWidth(14);
        $sheet->getColumnDimension('C')->setWidth(14);
        $sheet->getColumnDimension('D')->setWidth(14);
    }
}
