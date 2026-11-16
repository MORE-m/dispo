<?php

namespace Tests\Support;

use App\Support\PriceList\Import\PriceListDeliveryWorkbookContract;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

final class PriceListDeliveryWorkbookFactory
{
    /**
     * @param  list<array{
     *     name: string,
     *     prices?: array<int, array{mo_fr?: mixed, sa?: mixed, so?: mixed}>|null
     * }>  $sheets
     */
    public static function write(string $path, array $sheets): void
    {
        $spreadsheet = new Spreadsheet;
        $spreadsheet->removeSheetByIndex(0);

        foreach ($sheets as $definition) {
            $sheet = $spreadsheet->createSheet();
            $sheet->setTitle($definition['name']);
            foreach (PriceListDeliveryWorkbookContract::headerValues() as $index => $header) {
                $sheet->setCellValue([$index + 1, 1], $header);
            }

            $prices = $definition['prices'] ?? null;
            for ($hour = 0; $hour <= 23; $hour++) {
                $row = PriceListDeliveryWorkbookContract::rowForHour($hour);
                $sheet->setCellValue([1, $row], PriceListDeliveryWorkbookContract::hourLabel($hour));
                if ($prices === null) {
                    continue;
                }
                $cell = $prices[$hour] ?? [];
                if (array_key_exists('mo_fr', $cell)) {
                    $sheet->setCellValue([2, $row], $cell['mo_fr']);
                }
                if (array_key_exists('sa', $cell)) {
                    $sheet->setCellValue([3, $row], $cell['sa']);
                }
                if (array_key_exists('so', $cell)) {
                    $sheet->setCellValue([4, $row], $cell['so']);
                }
            }
        }

        $spreadsheet->setActiveSheetIndex(0);
        (new Xlsx($spreadsheet))->save($path);
        $spreadsheet->disconnectWorksheets();
    }

    /**
     * Vollständig gefülltes Blatt (72 Preise).
     */
    public static function writeFullInventory(
        string $path,
        string $inventoryName,
        string|float|int $price = '1.2500',
    ): void {
        $prices = [];
        for ($hour = 0; $hour <= 23; $hour++) {
            $prices[$hour] = [
                'mo_fr' => $price,
                'sa' => $price,
                'so' => $price,
            ];
        }
        self::write($path, [['name' => $inventoryName, 'prices' => $prices]]);
    }

    public static function writeEmptyTemplate(string $path, string $inventoryName): void
    {
        self::write($path, [['name' => $inventoryName, 'prices' => null]]);
    }

    public static function writeWithFormula(string $path, string $inventoryName): void
    {
        self::writeFullInventory($path, $inventoryName, '1.0000');
        $spreadsheet = IOFactory::load($path);
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setCellValueExplicit('B2', '=1+1', DataType::TYPE_FORMULA);
        (new Xlsx($spreadsheet))->save($path);
        $spreadsheet->disconnectWorksheets();
    }
}
