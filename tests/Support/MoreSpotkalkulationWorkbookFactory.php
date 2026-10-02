<?php

namespace Tests\Support;

use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/**
 * Minimale MORE-Spotkalkulation-Fixture für PRI-OPS-1-Tests.
 */
final class MoreSpotkalkulationWorkbookFactory
{
    public static function writeMinimal(string $path): void
    {
        $spreadsheet = new Spreadsheet;
        $spreadsheet->removeSheetByIndex(0);

        self::addPriceSheet($spreadsheet, 'Preise RHH', [
            0 => ['mo_fr' => 1.5, 'sa' => 1.0, 'so' => 0.8],
            6 => ['mo_fr' => 48.0, 'sa' => 13.0, 'so' => 6.0],
            // hour 7 empty so → missing cell
            7 => ['mo_fr' => 67.0, 'sa' => 40.0, 'so' => null],
        ], withAverageRow: true, withDerivedFormulas: true);

        self::addPriceSheet($spreadsheet, 'Preise MOREHHKombi+', [
            6 => ['mo_fr' => 10.0, 'sa' => 5.0, 'so' => 4.0],
            // hours 0–5 intentionally absent
        ], withAverageRow: true, withDerivedFormulas: false);

        // Non-price sheet must be ignored
        $planer = $spreadsheet->createSheet();
        $planer->setTitle('PlanerRHH');
        $planer->setCellValue('A1', 'Planer');

        (new Xlsx($spreadsheet))->save($path);
        $spreadsheet->disconnectWorksheets();
    }

    /**
     * @param  array<int, array{mo_fr: float|null, sa: float|null, so: float|null}>  $hours
     */
    private static function addPriceSheet(
        Spreadsheet $spreadsheet,
        string $title,
        array $hours,
        bool $withAverageRow,
        bool $withDerivedFormulas,
    ): void {
        $sheet = $spreadsheet->createSheet();
        $sheet->setTitle($title);
        $sheet->setCellValue('A1', 'Preise 2026 (gültig ab 1. Januar 2026)');
        $sheet->setCellValue('B11', 'Mo-Fr');
        $sheet->setCellValue('D11', 'Sa');
        $sheet->setCellValue('F11', 'So');
        $sheet->setCellValue('H11', 'MO-SA');
        $sheet->setCellValue('J11', 'MO-SO');
        $sheet->setCellValue('B12', '€/SEK. (Index 100)');
        $sheet->setCellValue('C12', '€/SPOT');
        $sheet->setCellValue('D12', '€/SEK. (Index 100)');
        $sheet->setCellValue('F12', '€/SEK. (Index 100)');

        for ($hour = 0; $hour <= 23; $hour++) {
            $row = 13 + $hour;
            $sheet->setCellValue('A'.$row, $hour.'-'.($hour + 1));
            $values = $hours[$hour] ?? null;
            if ($values === null) {
                continue;
            }
            if ($values['mo_fr'] !== null) {
                $sheet->setCellValue('B'.$row, $values['mo_fr']);
            }
            if ($values['sa'] !== null) {
                $sheet->setCellValue('D'.$row, $values['sa']);
            }
            if ($values['so'] !== null) {
                $sheet->setCellValue('F'.$row, $values['so']);
            }
            if ($withDerivedFormulas) {
                $sheet->setCellValueExplicit('H'.$row, '=((B'.$row.'*5)+D'.$row.')/6', DataType::TYPE_FORMULA);
                $sheet->setCellValueExplicit('J'.$row, '=((B'.$row.'*5)+D'.$row.'+F'.$row.')/7', DataType::TYPE_FORMULA);
            }
        }

        if ($withAverageRow) {
            $sheet->setCellValue('A37', 'ø 06-18');
            $sheet->setCellValueExplicit('B37', '=SUM(B19:B30)/12', DataType::TYPE_FORMULA);
        }
    }
}
