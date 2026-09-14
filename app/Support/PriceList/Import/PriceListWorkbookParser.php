<?php

namespace App\Support\PriceList\Import;

use App\Enums\DayGroup;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Reader\BaseReader;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use Throwable;

/**
 * BL-P4-01b: kanonischer Workbook-Parser (kein erfundenes MORE-Layout).
 *
 * - Preflight über listWorksheetInfo vor Load/toArray
 * - ReadFilter begrenzt Zeilen/Spalten
 * - keine Formelberechnung (calculateFormulas=false)
 * - Formelzellen fail-closed (formula_not_allowed)
 */
final class PriceListWorkbookParser
{
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
    public function parse(string $absolutePath, string $extension): array
    {
        try {
            $reader = $this->makeReader($extension);
            $preflight = $this->preflightStructure($reader, $absolutePath);
            if ($preflight['issues'] !== []) {
                return [
                    'sheet_count' => $preflight['sheet_count'],
                    'issues' => $preflight['issues'],
                    'rows' => [],
                ];
            }

            $reader->setReadDataOnly(true);
            $reader->setReadEmptyCells(false);
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
        $sheetCount = $spreadsheet->getSheetCount();

        foreach ($spreadsheet->getAllSheets() as $sheet) {
            $title = trim((string) $sheet->getTitle());
            $extracted = $this->extractSheet($sheet, $title, $issues, $rows);
            $issues = $extracted['issues'];
            $rows = $extracted['rows'];
            if ($extracted['stop']) {
                break;
            }
        }

        $spreadsheet->disconnectWorksheets();
        unset($spreadsheet);

        return [
            'sheet_count' => $sheetCount,
            'issues' => $issues,
            'rows' => $rows,
        ];
    }

    /**
     * @return array{sheet_count: int, issues: list<array{severity: string, code: string, message: string, sheet: ?string, row: ?int, column: ?string}>}
     */
    private function preflightStructure(BaseReader $reader, string $absolutePath): array
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
                    'message' => 'Zu viele Arbeitsblätter (Maximum '.PriceListImportLimits::MAX_SHEETS.'). Die Datei wurde vor dem Einlesen abgelehnt.',
                    'sheet' => null,
                    'row' => null,
                    'column' => null,
                ]],
            ];
        }

        $issues = [];
        foreach ($info as $sheetInfo) {
            $name = $sheetInfo['worksheetName'];
            $totalRows = $sheetInfo['totalRows'];
            $totalColumns = $sheetInfo['totalColumns'];

            if ($totalRows > PriceListImportLimits::MAX_ROWS_PER_SHEET) {
                $issues[] = [
                    'severity' => 'error',
                    'code' => 'too_many_rows',
                    'message' => 'Arbeitsblatt „'.$name.'“ überschreitet das Zeilenlimit (Maximum '
                        .PriceListImportLimits::MAX_ROWS_PER_SHEET.'). Die Datei wurde vor dem Einlesen abgelehnt.',
                    'sheet' => $name,
                    'row' => null,
                    'column' => null,
                ];
            }

            if ($totalColumns > PriceListImportLimits::MAX_COLUMNS) {
                $issues[] = [
                    'severity' => 'error',
                    'code' => 'too_many_columns',
                    'message' => 'Arbeitsblatt „'.$name.'“ überschreitet das Spaltenlimit (Maximum '
                        .PriceListImportLimits::MAX_COLUMNS.'). Die Datei wurde vor dem Einlesen abgelehnt.',
                    'sheet' => $name,
                    'row' => null,
                    'column' => null,
                ];
            }
        }

        return [
            'sheet_count' => $sheetCount,
            'issues' => $issues,
        ];
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
        $highestRow = min((int) $sheet->getHighestDataRow(), PriceListImportLimits::MAX_ROWS_PER_SHEET);
        $highestColumnIndex = min(
            Coordinate::columnIndexFromString($sheet->getHighestDataColumn()),
            PriceListImportLimits::MAX_COLUMNS,
        );

        if ($highestRow < 1 || $highestColumnIndex < 1) {
            $issues[] = [
                'severity' => 'warning',
                'code' => 'empty_sheet',
                'message' => 'Leeres Arbeitsblatt wird übersprungen.',
                'sheet' => $title,
                'row' => null,
                'column' => null,
            ];

            return ['issues' => $issues, 'rows' => $rows, 'stop' => false];
        }

        // calculateFormulas=false, formatData=false – keine Formelauswertung
        $matrix = $sheet->rangeToArray(
            'A1:'.Coordinate::stringFromColumnIndex($highestColumnIndex).$highestRow,
            null,
            false,
            false,
            false,
        );

        if ($matrix === []) {
            $issues[] = [
                'severity' => 'warning',
                'code' => 'empty_sheet',
                'message' => 'Leeres Arbeitsblatt wird übersprungen.',
                'sheet' => $title,
                'row' => null,
                'column' => null,
            ];

            return ['issues' => $issues, 'rows' => $rows, 'stop' => false];
        }

        $headerRowIndex = $this->findHeaderRow($matrix);
        if ($headerRowIndex === null) {
            $issues[] = [
                'severity' => 'warning',
                'code' => 'no_header',
                'message' => 'Kein kanonischer Spaltenkopf gefunden; Blatt wird übersprungen.',
                'sheet' => $title,
                'row' => null,
                'column' => null,
            ];

            return ['issues' => $issues, 'rows' => $rows, 'stop' => false];
        }

        $headerMap = $this->mapHeaders($matrix[$headerRowIndex]);
        $hasInventoryColumn = isset($headerMap['inventory']);
        if (! $hasInventoryColumn && $title === '') {
            $issues[] = [
                'severity' => 'error',
                'code' => 'missing_inventory',
                'message' => 'Weder Inventar-Spalte noch Blattname vorhanden.',
                'sheet' => $title,
                'row' => $headerRowIndex + 1,
                'column' => null,
            ];

            return ['issues' => $issues, 'rows' => $rows, 'stop' => false];
        }

        if (isset($headerMap['derived'])) {
            $issues[] = [
                'severity' => 'error',
                'code' => 'derived_column',
                'message' => 'Abgeleitete Tagesgruppen (Mo–Sa/Mo–So) dürfen nicht importiert werden.',
                'sheet' => $title,
                'row' => $headerRowIndex + 1,
                'column' => (string) $headerMap['derived'],
            ];
        }

        $relevantIndexes = [];
        foreach (['inventory', 'hour', 'day_group', 'second_price', 'year'] as $key) {
            if (isset($headerMap[$key]) && is_int($headerMap[$key])) {
                $relevantIndexes[$key] = $headerMap[$key];
            }
        }

        for ($i = $headerRowIndex + 1; $i < count($matrix); $i++) {
            if (count($rows) >= PriceListImportLimits::MAX_DATA_ROWS) {
                $issues[] = [
                    'severity' => 'error',
                    'code' => 'too_many_rows',
                    'message' => 'Zu viele Datenzeilen (Maximum '.PriceListImportLimits::MAX_DATA_ROWS.').',
                    'sheet' => $title,
                    'row' => $i + 1,
                    'column' => null,
                ];

                return ['issues' => $issues, 'rows' => $rows, 'stop' => true];
            }

            /** @var array<int, mixed> $line */
            $line = $matrix[$i];
            if ($this->rowIsEmpty($line)) {
                continue;
            }

            $sourceRow = $i + 1;
            $formulaIssue = $this->detectFormulasInRow($sheet, $sourceRow, $relevantIndexes, $title);
            if ($formulaIssue !== null) {
                $issues[] = $formulaIssue;

                continue;
            }

            $inventoryRaw = $hasInventoryColumn
                ? $this->cellString($line[(int) $headerMap['inventory']] ?? null)
                : $title;

            if ($this->looksLikeFormula($inventoryRaw)) {
                $issues[] = [
                    'severity' => 'error',
                    'code' => 'formula_not_allowed',
                    'message' => 'Formeln sind im Import nicht erlaubt.',
                    'sheet' => $title,
                    'row' => $sourceRow,
                    'column' => 'inventory',
                ];

                continue;
            }

            $hourRaw = $line[(int) $headerMap['hour']] ?? null;
            $dayRaw = $line[(int) $headerMap['day_group']] ?? null;
            $priceRaw = $line[(int) $headerMap['second_price']] ?? null;
            $yearRaw = isset($headerMap['year']) ? ($line[(int) $headerMap['year']] ?? null) : null;

            foreach ([
                'hour' => $hourRaw,
                'day_group' => $dayRaw,
                'second_price' => $priceRaw,
                'year' => $yearRaw,
            ] as $column => $value) {
                if ($this->looksLikeFormula($value)) {
                    $issues[] = [
                        'severity' => 'error',
                        'code' => 'formula_not_allowed',
                        'message' => 'Formeln sind im Import nicht erlaubt.',
                        'sheet' => $title,
                        'row' => $sourceRow,
                        'column' => $column,
                    ];

                    continue 2;
                }
            }

            $rows[] = [
                'inventory_raw' => $inventoryRaw,
                'hour_raw' => $hourRaw,
                'day_group_raw' => $dayRaw,
                'second_price_raw' => $priceRaw,
                'year_raw' => $yearRaw,
                'sheet' => $title,
                'source_row' => $sourceRow,
            ];
        }

        return ['issues' => $issues, 'rows' => $rows, 'stop' => false];
    }

    /**
     * @param  array<string, int>  $relevantIndexes
     * @return array{severity: string, code: string, message: string, sheet: ?string, row: ?int, column: ?string}|null
     */
    private function detectFormulasInRow(Worksheet $sheet, int $row, array $relevantIndexes, string $title): ?array
    {
        foreach ($relevantIndexes as $columnName => $zeroBasedIndex) {
            $coordinate = Coordinate::stringFromColumnIndex($zeroBasedIndex + 1).$row;
            $cell = $sheet->getCell($coordinate);
            if ($cell->getDataType() === DataType::TYPE_FORMULA) {
                return [
                    'severity' => 'error',
                    'code' => 'formula_not_allowed',
                    'message' => 'Formeln sind im Import nicht erlaubt.',
                    'sheet' => $title,
                    'row' => $row,
                    'column' => $columnName,
                ];
            }
        }

        return null;
    }

    private function looksLikeFormula(mixed $value): bool
    {
        if (! is_string($value)) {
            return false;
        }

        return str_starts_with(ltrim($value), '=');
    }

    private function makeReader(string $extension): BaseReader
    {
        $type = strtolower($extension) === 'xls' ? 'Xls' : 'Xlsx';
        $reader = IOFactory::createReader($type);
        if (! $reader instanceof BaseReader) {
            throw new \RuntimeException('Unsupported spreadsheet reader.');
        }

        return $reader;
    }

    /**
     * @param  array<int, array<int, mixed>>  $matrix
     */
    private function findHeaderRow(array $matrix): ?int
    {
        $limit = min(20, count($matrix));
        for ($i = 0; $i < $limit; $i++) {
            /** @var array<int, mixed> $headerCells */
            $headerCells = $matrix[$i];
            $map = $this->mapHeaders($headerCells);
            if (isset($map['hour'], $map['day_group'], $map['second_price'])) {
                return $i;
            }
        }

        return null;
    }

    /**
     * @param  array<int, mixed>  $headerCells
     * @return array<string, int|string>
     */
    private function mapHeaders(array $headerCells): array
    {
        $map = [];
        foreach ($headerCells as $index => $cell) {
            $key = $this->normalizeHeader((string) ($cell ?? ''));
            if ($key === '') {
                continue;
            }

            if (in_array($key, ['inventory_code', 'inventory', 'inventar', 'sender', 'code'], true)) {
                $map['inventory'] = (int) $index;
            } elseif (in_array($key, ['hour', 'stunde', 'uhrzeit'], true)) {
                $map['hour'] = (int) $index;
            } elseif (in_array($key, ['day_group', 'tagesgruppe', 'daygroup'], true)) {
                $map['day_group'] = (int) $index;
            } elseif (in_array($key, ['second_price', 'sekundenpreis', 'price', 'preis'], true)) {
                $map['second_price'] = (int) $index;
            } elseif (in_array($key, ['year', 'jahr'], true)) {
                $map['year'] = (int) $index;
            } elseif (in_array($key, ['mo_sa', 'mo-sa', 'mosa', 'mo_so', 'mo-so', 'moso'], true)) {
                $map['derived'] = Coordinate::stringFromColumnIndex(((int) $index) + 1);
            }
        }

        return $map;
    }

    private function normalizeHeader(string $value): string
    {
        $value = trim(mb_strtolower($value));
        $value = str_replace(['–', '—'], '-', $value);
        $value = preg_replace('/\s+/u', '_', $value) ?? '';

        return $value;
    }

    /**
     * @param  array<int, mixed>  $line
     */
    private function rowIsEmpty(array $line): bool
    {
        foreach ($line as $cell) {
            if ($cell !== null && trim((string) $cell) !== '') {
                return false;
            }
        }

        return true;
    }

    private function cellString(mixed $value): string
    {
        if ($value === null) {
            return '';
        }

        return trim((string) $value);
    }

    /**
     * @return array{
     *     sheet_count: int,
     *     issues: list<array{severity: string, code: string, message: string, sheet: ?string, row: ?int, column: ?string}>,
     *     rows: list<array{inventory_raw: string, hour_raw: mixed, day_group_raw: mixed, second_price_raw: mixed, year_raw: mixed, sheet: string, source_row: int}>
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
        ];
    }

    public static function parseHour(mixed $raw): int|string
    {
        if ($raw === null || (is_string($raw) && trim($raw) === '')) {
            return 'missing';
        }

        if (is_int($raw) || (is_float($raw) && floor($raw) === $raw)) {
            return (int) $raw;
        }

        $text = trim((string) $raw);
        if (preg_match('/^(\d{1,2})(?::00)?(?::00)?$/', $text, $m) === 1) {
            $hour = (int) $m[1];

            return ($hour >= 0 && $hour <= 23) ? $hour : 'invalid';
        }
        if (preg_match('/^(\d{1,2})\s*uhr$/iu', $text, $m) === 1) {
            $hour = (int) $m[1];

            return ($hour >= 0 && $hour <= 23) ? $hour : 'invalid';
        }

        return 'invalid';
    }

    public static function parseDayGroup(mixed $raw): DayGroup|string
    {
        if ($raw === null || (is_string($raw) && trim($raw) === '')) {
            return 'missing';
        }

        $text = trim((string) $raw);
        $normalized = mb_strtolower(str_replace(['–', '—', ' '], ['-', '-', ''], $text));
        $normalized = str_replace('_', '-', $normalized);

        return match ($normalized) {
            'mo_fr', 'mo-fr', 'mofr', 'mo-fr.', 'montag-freitag', 'montag–freitag' => DayGroup::MoFr,
            'sa', 'samstag', 'saturday' => DayGroup::Sa,
            'so', 'sonntag', 'sunday' => DayGroup::So,
            'mo_sa', 'mo-sa', 'mosa' => 'derived',
            'mo_so', 'mo-so', 'moso' => 'derived',
            default => DayGroup::tryFrom(str_replace('-', '_', $normalized)) ?? 'invalid',
        };
    }
}
