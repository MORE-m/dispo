<?php

namespace App\Support\PriceList\Import;

use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * Preisanlieferung XLSX v1 → kanonische Importzeilen.
 *
 * Wide-Format: Uhrzeit | Mo–Fr €/1 Sek | Samstag €/1 Sek | Sonntag €/1 Sek
 * Zeilen 2–25 = Stunden 00:00–23:00. Leere Preise = Warnung, nicht 0.
 */
final class PriceListDeliveryWorkbookParser
{
    public static function looksLikeDeliverySpreadsheet(Spreadsheet $spreadsheet): bool
    {
        foreach ($spreadsheet->getAllSheets() as $sheet) {
            if (self::sheetHasDeliveryHeader($sheet)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array{
     *     sheet_count: int,
     *     issues: list<array{severity: string, code: string, message: string, sheet: ?string, row: ?int, column: ?string}>,
     *     rows: list<array{
     *         inventory_raw: string,
     *         hour_raw: mixed,
     *         day_group_raw: mixed,
     *         second_price_raw: mixed,
     *         year_raw: mixed,
     *         sheet: string,
     *         source_row: int
     *     }>
     * }
     */
    public function extract(Spreadsheet $spreadsheet): array
    {
        $issues = [];
        $rows = [];
        $sheetCount = $spreadsheet->getSheetCount();
        $parsedSheets = 0;

        foreach ($spreadsheet->getAllSheets() as $sheet) {
            $title = trim((string) $sheet->getTitle());
            if ($title === '') {
                $issues[] = $this->issue(
                    'error',
                    'missing_inventory',
                    'Arbeitsblatt ohne Namen – Inventarzuordnung nicht möglich.',
                    '',
                    null,
                    null,
                );

                continue;
            }

            if (! self::sheetHasDeliveryHeader($sheet)) {
                $issues[] = $this->issue(
                    'error',
                    'delivery_header_invalid',
                    'Arbeitsblatt entspricht nicht dem Preisanlieferungs-Vertrag (Uhrzeit + Mo–Fr/Samstag/Sonntag €/1 Sek).',
                    $title,
                    PriceListDeliveryWorkbookContract::HEADER_ROW,
                    'A',
                );

                continue;
            }

            $parsedSheets++;
            $extracted = $this->extractSheet($sheet, $title, $issues, $rows);
            $issues = $extracted['issues'];
            $rows = $extracted['rows'];
            if ($extracted['stop']) {
                break;
            }
        }

        if ($parsedSheets === 0 && ! $this->hasError($issues)) {
            $issues[] = $this->issue(
                'error',
                'no_delivery_sheets',
                'Keine gültigen Preisanlieferungs-Arbeitsblätter gefunden.',
                null,
                null,
                null,
            );
        }

        return [
            'sheet_count' => $sheetCount,
            'issues' => $issues,
            'rows' => $rows,
        ];
    }

    private static function sheetHasDeliveryHeader(Worksheet $sheet): bool
    {
        $cells = [];
        for ($column = 1; $column <= PriceListDeliveryWorkbookContract::COLUMN_COUNT; $column++) {
            $cells[] = $sheet->getCell([$column, PriceListDeliveryWorkbookContract::HEADER_ROW])->getValue();
        }

        return PriceListDeliveryWorkbookContract::looksLikeHeaderRow($cells);
    }

    /**
     * @param  list<array{severity: string, code: string, message: string, sheet: ?string, row: ?int, column: ?string}>  $issues
     * @param  list<array{inventory_raw: string, hour_raw: mixed, day_group_raw: mixed, second_price_raw: mixed, year_raw: mixed, sheet: string, source_row: int}>  $rows
     * @return array{
     *     issues: list<array{severity: string, code: string, message: string, sheet: ?string, row: ?int, column: ?string}>,
     *     rows: list<array{inventory_raw: string, hour_raw: mixed, day_group_raw: mixed, second_price_raw: mixed, year_raw: mixed, sheet: string, source_row: int}>,
     *     stop: bool
     * }
     */
    private function extractSheet(Worksheet $sheet, string $title, array $issues, array $rows): array
    {
        /** @var array<int, true> $seenHours */
        $seenHours = [];

        for (
            $row = PriceListDeliveryWorkbookContract::HOUR_FIRST_ROW;
            $row <= PriceListDeliveryWorkbookContract::HOUR_LAST_ROW;
            $row++
        ) {
            $expectedHour = PriceListDeliveryWorkbookContract::hourForRow($row);
            $hourCell = $sheet->getCell([1, $row]);
            if ($hourCell->getDataType() === DataType::TYPE_FORMULA) {
                $issues[] = $this->issue(
                    'error',
                    'formula_not_allowed',
                    'Formeln sind im Import nicht erlaubt.',
                    $title,
                    $row,
                    'A',
                );

                continue;
            }

            $hourParsed = PriceListWorkbookParser::parseHour($hourCell->getValue());
            if ($hourParsed === 'missing') {
                $issues[] = $this->issue(
                    'error',
                    'hour_missing',
                    'Stunde fehlt (erwartet '.PriceListDeliveryWorkbookContract::hourLabel((int) $expectedHour).').',
                    $title,
                    $row,
                    'A',
                );

                continue;
            }
            if ($hourParsed === 'invalid' || $hourParsed < 0 || $hourParsed > 23) {
                $issues[] = $this->issue(
                    'error',
                    'hour_invalid',
                    'Stunde muss zwischen 0 und 23 liegen.',
                    $title,
                    $row,
                    'A',
                );

                continue;
            }
            if (isset($seenHours[$hourParsed])) {
                $issues[] = $this->issue(
                    'error',
                    'hour_duplicate',
                    'Doppelte Stunde '.PriceListDeliveryWorkbookContract::hourLabel($hourParsed).'.',
                    $title,
                    $row,
                    'A',
                );

                continue;
            }
            if ($expectedHour !== null && $hourParsed !== $expectedHour) {
                $issues[] = $this->issue(
                    'error',
                    'hour_sequence_invalid',
                    'Erwartet wurde '.PriceListDeliveryWorkbookContract::hourLabel($expectedHour)
                        .', gefunden wurde '.PriceListDeliveryWorkbookContract::hourLabel($hourParsed).'.',
                    $title,
                    $row,
                    'A',
                );

                continue;
            }
            $seenHours[$hourParsed] = true;

            foreach (PriceListDeliveryWorkbookContract::PRICE_COLUMNS as $columnIndex => $dayGroup) {
                if (count($rows) >= PriceListImportLimits::MAX_DATA_ROWS) {
                    $issues[] = $this->issue(
                        'error',
                        'too_many_rows',
                        'Zu viele Datenzeilen (Maximum '.PriceListImportLimits::MAX_DATA_ROWS.').',
                        $title,
                        $row,
                        Coordinate::stringFromColumnIndex($columnIndex),
                    );

                    return ['issues' => $issues, 'rows' => $rows, 'stop' => true];
                }

                $columnLetter = Coordinate::stringFromColumnIndex($columnIndex);
                $cell = $sheet->getCell([$columnIndex, $row]);
                if ($cell->getDataType() === DataType::TYPE_FORMULA) {
                    $issues[] = $this->issue(
                        'error',
                        'formula_not_allowed',
                        'Formeln sind im Import nicht erlaubt.',
                        $title,
                        $row,
                        $columnLetter,
                    );

                    continue;
                }

                $raw = $cell->getValue();
                if ($raw === null || (is_string($raw) && trim($raw) === '')) {
                    $issues[] = $this->issue(
                        'warning',
                        'price_missing',
                        'Leerer Preis – Zelle wird nicht importiert (nicht buchbar, nicht 0).',
                        $title,
                        $row,
                        $columnLetter,
                    );

                    continue;
                }

                $rows[] = [
                    'inventory_raw' => $title,
                    'hour_raw' => $hourParsed,
                    'day_group_raw' => $dayGroup,
                    'second_price_raw' => $raw,
                    'year_raw' => null,
                    'sheet' => $title,
                    'source_row' => $row,
                ];
            }
        }

        for ($hour = PriceListDeliveryWorkbookContract::HOUR_MIN; $hour <= PriceListDeliveryWorkbookContract::HOUR_MAX; $hour++) {
            if (! isset($seenHours[$hour])) {
                $issues[] = $this->issue(
                    'error',
                    'hour_incomplete',
                    'Stunde '.PriceListDeliveryWorkbookContract::hourLabel($hour).' fehlt im Arbeitsblatt.',
                    $title,
                    PriceListDeliveryWorkbookContract::rowForHour($hour),
                    'A',
                );
            }
        }

        return ['issues' => $issues, 'rows' => $rows, 'stop' => false];
    }

    /**
     * @return array{severity: string, code: string, message: string, sheet: ?string, row: ?int, column: ?string}
     */
    private function issue(
        string $severity,
        string $code,
        string $message,
        ?string $sheet,
        ?int $row,
        ?string $column,
    ): array {
        return [
            'severity' => $severity,
            'code' => $code,
            'message' => $message,
            'sheet' => $sheet,
            'row' => $row,
            'column' => $column,
        ];
    }

    /**
     * @param  list<array{severity: string}>  $issues
     */
    private function hasError(array $issues): bool
    {
        foreach ($issues as $issue) {
            if ($issue['severity'] === 'error') {
                return true;
            }
        }

        return false;
    }
}
