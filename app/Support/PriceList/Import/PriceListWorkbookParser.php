<?php

namespace App\Support\PriceList\Import;

use App\Enums\DayGroup;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Reader\IReader;
use Throwable;

/**
 * BL-P4-01b: kanonischer Workbook-Parser (kein erfundenes MORE-Layout).
 *
 * Vertrag:
 * - Flat-Sheet mit Spalten inventory_code|inventory, hour, day_group, second_price [, year]
 * - oder Sheet-Name = Inventar (Code/Name/Alias) + Spalten hour, day_group, second_price [, year]
 * - Abgeleitete Tagesgruppen: Fehler
 * - Nur gelesene Zellenwerte (dataOnly), keine Formelausführung
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
        $issues = [];
        $rows = [];

        try {
            $reader = $this->makeReader($extension);
            $reader->setReadDataOnly(true);
            $reader->setReadEmptyCells(false);
            $spreadsheet = $reader->load($absolutePath);
        } catch (Throwable $exception) {
            $message = $exception->getMessage();
            if (stripos($message, 'password') !== false || stripos($message, 'encrypted') !== false) {
                return [
                    'sheet_count' => 0,
                    'issues' => [[
                        'severity' => 'error',
                        'code' => 'encrypted',
                        'message' => 'Die Datei ist passwortgeschützt oder verschlüsselt und kann nicht gelesen werden.',
                        'sheet' => null,
                        'row' => null,
                        'column' => null,
                    ]],
                    'rows' => [],
                ];
            }

            return [
                'sheet_count' => 0,
                'issues' => [[
                    'severity' => 'error',
                    'code' => 'corrupt',
                    'message' => 'Die Datei ist beschädigt oder kein gültiges Excel-Workbook.',
                    'sheet' => null,
                    'row' => null,
                    'column' => null,
                ]],
                'rows' => [],
            ];
        }

        $sheetCount = $spreadsheet->getSheetCount();
        if ($sheetCount > PriceListImportLimits::MAX_SHEETS) {
            $spreadsheet->disconnectWorksheets();

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
                'rows' => [],
            ];
        }

        foreach ($spreadsheet->getAllSheets() as $sheet) {
            $title = trim((string) $sheet->getTitle());
            $matrix = $sheet->toArray(null, true, true, false);
            if ($matrix === []) {
                $issues[] = [
                    'severity' => 'warning',
                    'code' => 'empty_sheet',
                    'message' => 'Leeres Arbeitsblatt wird übersprungen.',
                    'sheet' => $title,
                    'row' => null,
                    'column' => null,
                ];

                continue;
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

                continue;
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

                continue;
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
                    break 2;
                }

                /** @var array<int, mixed> $line */
                $line = $matrix[$i];
                if ($this->rowIsEmpty($line)) {
                    continue;
                }

                $inventoryRaw = $hasInventoryColumn
                    ? $this->cellString($line[(int) $headerMap['inventory']] ?? null)
                    : $title;

                $rows[] = [
                    'inventory_raw' => $inventoryRaw,
                    'hour_raw' => $line[(int) $headerMap['hour']] ?? null,
                    'day_group_raw' => $line[(int) $headerMap['day_group']] ?? null,
                    'second_price_raw' => $line[(int) $headerMap['second_price']] ?? null,
                    'year_raw' => isset($headerMap['year']) ? ($line[(int) $headerMap['year']] ?? null) : null,
                    'sheet' => $title,
                    'source_row' => $i + 1,
                ];
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

    private function makeReader(string $extension): IReader
    {
        $type = strtolower($extension) === 'xls' ? 'Xls' : 'Xlsx';

        return IOFactory::createReader($type);
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
     * @param  list<mixed>|array<int, mixed>  $line
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
