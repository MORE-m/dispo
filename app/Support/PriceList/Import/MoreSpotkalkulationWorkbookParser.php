<?php

namespace App\Support\PriceList\Import;

use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Reader\BaseReader;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use Throwable;

/**
 * PRI-OPS-1 / BL-P4-01d: MORE Spotkalkulation-Workbook → kanonische Importzeilen.
 *
 * Nur Einzelstunden (Zeilen 13–36), nur Basis-€/SEK Mo–Fr/Sa/So (Spalten B/D/F).
 * Durchschnittszeiträume ab Zeile 37 und Formelspalten H/J (Mo–Sa/Mo–So) werden
 * nicht importiert – Ableitung erfolgt in DayGroupPrice.
 */
final class MoreSpotkalkulationWorkbookParser
{
    public const HOUR_FIRST_ROW = 13;

    public const HOUR_LAST_ROW = 36;

    public const AVERAGE_FIRST_ROW = 37;

    /** @var array<string, string> sheet title => inventory_code */
    public const SHEET_TO_INVENTORY_CODE = [
        'Preise RHH' => 'inv_radio_hamburg',
        'Preise 80er 90er OAHH' => 'inv_80er_90er_oldie_antenne_hamburg',
        'Preise MOREHHKombi' => 'inv_more_hamburg_kombi',
        'Preise MOREHHKombi+' => 'inv_more_hamburg_kombi_plus',
        'Preise RAHH' => 'inv_rock_antenne_hamburg',
        'Preise CARAVANFM' => 'inv_caravan_fm',
        'Preise BOLLERWAGEN' => 'inv_radio_bollerwagen_dab_plus_hamburg',
        'Preise radio ffn' => 'inv_ffn_hamburg_plus',
    ];

    /**
     * Spaltenindex (1-basiert) → Basis-Tagesgruppe. Nur €/SEK-Basis, keine Formeln.
     *
     * @var array<int, string>
     */
    public const SEK_BASE_COLUMNS = [
        2 => 'mo_fr',
        4 => 'sa',
        6 => 'so',
    ];

    /**
     * @param  list<array{worksheetName: string, lastColumnLetter: string, lastColumnIndex: int, totalRows: int, totalColumns: int}>  $info
     */
    public static function looksLikeMoreWorkbook(array $info): bool
    {
        foreach ($info as $sheet) {
            $title = trim($sheet['worksheetName']);
            if (array_key_exists($title, self::SHEET_TO_INVENTORY_CODE)) {
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
     *     }>,
     *     meta: array{
     *         year: int|null,
     *         imported_prices: int,
     *         skipped_average_rows: int,
     *         skipped_empty_cells: int,
     *         skipped_formula_base_cells: int,
     *         sheets_parsed: list<string>
     *     }
     * }
     */
    public function parse(string $absolutePath, string $extension): array
    {
        try {
            $reader = $this->makeReader($extension);
            $preflight = $this->preflight($reader, $absolutePath);
            if ($preflight['issues'] !== []) {
                return [
                    'sheet_count' => $preflight['sheet_count'],
                    'issues' => $preflight['issues'],
                    'rows' => [],
                    'meta' => $this->emptyMeta(),
                ];
            }

            $reader->setReadDataOnly(false);
            $reader->setReadEmptyCells(true);
            $reader->setReadFilter(new PriceListImportReadFilter(
                PriceListImportLimits::MAX_ROWS_PER_SHEET,
                PriceListImportLimits::MAX_COLUMNS,
            ));
            $spreadsheet = $reader->load($absolutePath);
        } catch (Throwable $exception) {
            $message = $exception->getMessage();
            if (stripos($message, 'password') !== false || stripos($message, 'encrypted') !== false) {
                return $this->fatal('encrypted', 'Die Datei ist passwortgeschützt oder verschlüsselt und kann nicht gelesen werden.');
            }

            return $this->fatal('corrupt', 'Die Datei ist beschädigt oder kein gültiges Excel-Workbook.');
        }

        $issues = [];
        $rows = [];
        $year = null;
        $skippedAverages = 0;
        $skippedEmpty = 0;
        $skippedFormulaBase = 0;
        $sheetsParsed = [];
        $sheetCount = $spreadsheet->getSheetCount();

        foreach ($spreadsheet->getAllSheets() as $sheet) {
            $title = trim((string) $sheet->getTitle());
            $inventoryCode = self::SHEET_TO_INVENTORY_CODE[$title] ?? null;
            if ($inventoryCode === null) {
                continue;
            }

            $sheetsParsed[] = $title;
            $sheetYear = $this->detectYear($sheet);
            if ($sheetYear !== null) {
                if ($year !== null && $year !== $sheetYear) {
                    $issues[] = [
                        'severity' => 'error',
                        'code' => 'year_mismatch_sheets',
                        'message' => 'Unterschiedliche Preisjahre in den Blättern ('.$year.' vs. '.$sheetYear.').',
                        'sheet' => $title,
                        'row' => 1,
                        'column' => 'A',
                    ];
                }
                $year ??= $sheetYear;
            }

            $extracted = $this->extractPriceSheet(
                $sheet,
                $title,
                $inventoryCode,
                $year,
                $issues,
                $rows,
                $skippedAverages,
                $skippedEmpty,
                $skippedFormulaBase,
            );
            $issues = $extracted['issues'];
            $rows = $extracted['rows'];
            $skippedAverages = $extracted['skipped_average_rows'];
            $skippedEmpty = $extracted['skipped_empty_cells'];
            $skippedFormulaBase = $extracted['skipped_formula_base_cells'];
            if ($extracted['stop']) {
                break;
            }
        }

        $spreadsheet->disconnectWorksheets();
        unset($spreadsheet);

        if ($sheetsParsed === []) {
            $issues[] = [
                'severity' => 'error',
                'code' => 'no_price_sheets',
                'message' => 'Keine bekannten MORE-Preisblätter gefunden.',
                'sheet' => null,
                'row' => null,
                'column' => null,
            ];
        }

        return [
            'sheet_count' => $sheetCount,
            'issues' => $issues,
            'rows' => $rows,
            'meta' => [
                'year' => $year,
                'imported_prices' => count($rows),
                'skipped_average_rows' => $skippedAverages,
                'skipped_empty_cells' => $skippedEmpty,
                'skipped_formula_base_cells' => $skippedFormulaBase,
                'sheets_parsed' => $sheetsParsed,
            ],
        ];
    }

    /**
     * @param  list<array{severity: string, code: string, message: string, sheet: ?string, row: ?int, column: ?string}>  $issues
     * @param  list<array{inventory_raw: string, hour_raw: mixed, day_group_raw: mixed, second_price_raw: mixed, year_raw: mixed, sheet: string, source_row: int}>  $rows
     * @return array{
     *     issues: list<array{severity: string, code: string, message: string, sheet: ?string, row: ?int, column: ?string}>,
     *     rows: list<array{inventory_raw: string, hour_raw: mixed, day_group_raw: mixed, second_price_raw: mixed, year_raw: mixed, sheet: string, source_row: int}>,
     *     skipped_average_rows: int,
     *     skipped_empty_cells: int,
     *     skipped_formula_base_cells: int,
     *     stop: bool
     * }
     */
    private function extractPriceSheet(
        Worksheet $sheet,
        string $title,
        string $inventoryCode,
        ?int $year,
        array $issues,
        array $rows,
        int $skippedAverages,
        int $skippedEmpty,
        int $skippedFormulaBase,
    ): array {
        $highestRow = (int) $sheet->getHighestDataRow();
        for ($row = self::AVERAGE_FIRST_ROW; $row <= min($highestRow, self::AVERAGE_FIRST_ROW + 50); $row++) {
            $label = trim((string) $sheet->getCell('A'.$row)->getValue());
            if ($label !== '' && str_starts_with($label, 'ø')) {
                $skippedAverages++;
            }
        }

        for ($row = self::HOUR_FIRST_ROW; $row <= self::HOUR_LAST_ROW; $row++) {
            $hour = $this->parseHourLabel($sheet->getCell('A'.$row)->getValue());
            if ($hour === null) {
                $issues[] = [
                    'severity' => 'error',
                    'code' => 'hour_label_invalid',
                    'message' => 'Stundenlabel in Spalte A muss „0-1“ … „23-24“ sein.',
                    'sheet' => $title,
                    'row' => $row,
                    'column' => 'A',
                ];

                continue;
            }

            foreach (self::SEK_BASE_COLUMNS as $columnIndex => $dayGroup) {
                if (count($rows) >= PriceListImportLimits::MAX_DATA_ROWS) {
                    $issues[] = [
                        'severity' => 'error',
                        'code' => 'too_many_rows',
                        'message' => 'Zu viele Datenzeilen (Maximum '.PriceListImportLimits::MAX_DATA_ROWS.').',
                        'sheet' => $title,
                        'row' => $row,
                        'column' => Coordinate::stringFromColumnIndex($columnIndex),
                    ];

                    return [
                        'issues' => $issues,
                        'rows' => $rows,
                        'skipped_average_rows' => $skippedAverages,
                        'skipped_empty_cells' => $skippedEmpty,
                        'skipped_formula_base_cells' => $skippedFormulaBase,
                        'stop' => true,
                    ];
                }

                $coordinate = Coordinate::stringFromColumnIndex($columnIndex).$row;
                $cell = $sheet->getCell($coordinate);
                if ($cell->getDataType() === DataType::TYPE_FORMULA) {
                    $skippedFormulaBase++;
                    $issues[] = [
                        'severity' => 'error',
                        'code' => 'formula_not_allowed',
                        'message' => 'Basis-€/SEK-Zelle enthält eine Formel und wird nicht importiert.',
                        'sheet' => $title,
                        'row' => $row,
                        'column' => Coordinate::stringFromColumnIndex($columnIndex),
                    ];

                    continue;
                }

                $raw = $cell->getValue();
                if ($raw === null || (is_string($raw) && trim($raw) === '')) {
                    $skippedEmpty++;
                    $issues[] = [
                        'severity' => 'warning',
                        'code' => 'price_missing',
                        'message' => 'Leerer Preis – Zelle wird nicht importiert (nicht buchbar, nicht 0).',
                        'sheet' => $title,
                        'row' => $row,
                        'column' => Coordinate::stringFromColumnIndex($columnIndex),
                    ];

                    continue;
                }

                $rows[] = [
                    'inventory_raw' => $inventoryCode,
                    'hour_raw' => $hour,
                    'day_group_raw' => $dayGroup,
                    'second_price_raw' => $raw,
                    'year_raw' => $year,
                    'sheet' => $title,
                    'source_row' => $row,
                ];
            }
        }

        return [
            'issues' => $issues,
            'rows' => $rows,
            'skipped_average_rows' => $skippedAverages,
            'skipped_empty_cells' => $skippedEmpty,
            'skipped_formula_base_cells' => $skippedFormulaBase,
            'stop' => false,
        ];
    }

    private function parseHourLabel(mixed $raw): ?int
    {
        $label = trim((string) ($raw ?? ''));
        if (preg_match('/^(\d{1,2})\s*-\s*(\d{1,2})$/', $label, $matches) !== 1) {
            return null;
        }

        $start = (int) $matches[1];
        $end = (int) $matches[2];
        if ($start < 0 || $start > 23) {
            return null;
        }
        if ($end !== $start + 1 && ! ($start === 23 && $end === 24)) {
            return null;
        }

        return $start;
    }

    private function detectYear(Worksheet $sheet): ?int
    {
        $title = trim((string) $sheet->getCell('A1')->getValue());
        if (preg_match('/\b(20\d{2})\b/', $title, $matches) === 1) {
            return (int) $matches[1];
        }

        return null;
    }

    /**
     * @return array{sheet_count: int, issues: list<array{severity: string, code: string, message: string, sheet: ?string, row: ?int, column: ?string}>}
     */
    private function preflight(BaseReader $reader, string $absolutePath): array
    {
        /** @var list<array{worksheetName: string, lastColumnLetter: string, lastColumnIndex: int, totalRows: int, totalColumns: int}> $info */
        $info = $reader->listWorksheetInfo($absolutePath);
        $sheetCount = count($info);

        if ($sheetCount > PriceListImportLimits::MAX_SHEETS) {
            return [
                'sheet_count' => $sheetCount,
                'issues' => [[
                    'severity' => 'error',
                    'code' => 'too_many_sheets',
                    'message' => 'Zu viele Arbeitsblätter (Maximum '.PriceListImportLimits::MAX_SHEETS.').',
                    'sheet' => null,
                    'row' => null,
                    'column' => null,
                ]],
            ];
        }

        if (! self::looksLikeMoreWorkbook($info)) {
            return [
                'sheet_count' => $sheetCount,
                'issues' => [[
                    'severity' => 'error',
                    'code' => 'not_more_format',
                    'message' => 'Kein MORE-Spotkalkulation-Workbook (erwartete Blätter „Preise …“ fehlen).',
                    'sheet' => null,
                    'row' => null,
                    'column' => null,
                ]],
            ];
        }

        return ['sheet_count' => $sheetCount, 'issues' => []];
    }

    private function makeReader(string $extension): BaseReader
    {
        $type = match (strtolower($extension)) {
            'xlsx' => 'Xlsx',
            'xls' => 'Xls',
            default => throw new \InvalidArgumentException('Unsupported extension'),
        };

        /** @var BaseReader $reader */
        $reader = IOFactory::createReader($type);

        return $reader;
    }

    /**
     * @return array{
     *     year: null,
     *     imported_prices: int,
     *     skipped_average_rows: int,
     *     skipped_empty_cells: int,
     *     skipped_formula_base_cells: int,
     *     sheets_parsed: list<string>
     * }
     */
    private function emptyMeta(): array
    {
        return [
            'year' => null,
            'imported_prices' => 0,
            'skipped_average_rows' => 0,
            'skipped_empty_cells' => 0,
            'skipped_formula_base_cells' => 0,
            'sheets_parsed' => [],
        ];
    }

    /**
     * @return array{
     *     sheet_count: int,
     *     issues: list<array{severity: string, code: string, message: string, sheet: ?string, row: ?int, column: ?string}>,
     *     rows: list<array{inventory_raw: string, hour_raw: mixed, day_group_raw: mixed, second_price_raw: mixed, year_raw: mixed, sheet: string, source_row: int}>,
     *     meta: array{
     *         year: null,
     *         imported_prices: int,
     *         skipped_average_rows: int,
     *         skipped_empty_cells: int,
     *         skipped_formula_base_cells: int,
     *         sheets_parsed: list<string>
     *     }
     * }
     */
    private function fatal(string $code, string $message): array
    {
        return [
            'sheet_count' => 0,
            'issues' => [[
                'severity' => 'error',
                'code' => $code,
                'message' => $message,
                'sheet' => null,
                'row' => null,
                'column' => null,
            ]],
            'rows' => [],
            'meta' => $this->emptyMeta(),
        ];
    }
}
