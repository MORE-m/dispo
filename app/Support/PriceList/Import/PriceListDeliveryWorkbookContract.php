<?php

namespace App\Support\PriceList\Import;

use App\Enums\DayGroup;

/**
 * Verbindlicher Vertrag Preisanlieferung XLSX (v1).
 *
 * Referenz: docs/readiness/import-templates/Template_Preisanlieferung.xlsx
 * Gemeinsam für Template-Download und Import-Parser.
 */
final class PriceListDeliveryWorkbookContract
{
    public const VERSION = '1';

    public const FORMAT_LABEL = 'preisanlieferung_xlsx_v1';

    public const HEADER_ROW = 1;

    public const HOUR_FIRST_ROW = 2;

    public const HOUR_LAST_ROW = 25;

    public const HOUR_MIN = 0;

    public const HOUR_MAX = 23;

    public const COLUMN_COUNT = 4;

    public const EXPECTED_BASE_CELLS_PER_INVENTORY = 72;

    public const HEADER_TIME = 'Uhrzeit';

    public const HEADER_MO_FR = "Mo - Fr\n€/1 Sek";

    public const HEADER_SA = "Samstag\n€/1 Sek";

    public const HEADER_SO = "Sonntag\n€/1 Sek";

    public const TIME_NUMBER_FORMAT = '@';

    public const PRICE_NUMBER_FORMAT = '#,##0.00';

    public const DOWNLOAD_FILENAME = 'Preisanlieferung-Template.xlsx';

    /**
     * Klassische Stundenpreislisten-Inventare (PO: acht Inventare).
     *
     * @var list<string>
     */
    public const CLASSIC_HOUR_INVENTORY_CODES = [
        'inv_more_hamburg_kombi',
        'inv_more_hamburg_kombi_plus',
        'inv_radio_hamburg',
        'inv_rock_antenne_hamburg',
        'inv_80er_90er_oldie_antenne_hamburg',
        'inv_caravan_fm',
        'inv_ffn_hamburg_plus',
        'inv_radio_bollerwagen_dab_plus_hamburg',
    ];

    /**
     * 1-basierte Spaltenindexe → Basis-Tagesgruppe.
     *
     * @var array<int, string>
     */
    public const PRICE_COLUMNS = [
        2 => DayGroup::MoFr->value,
        3 => DayGroup::Sa->value,
        4 => DayGroup::So->value,
    ];

    /**
     * @return list<string>
     */
    public static function headerValues(): array
    {
        return [
            self::HEADER_TIME,
            self::HEADER_MO_FR,
            self::HEADER_SA,
            self::HEADER_SO,
        ];
    }

    public static function hourLabel(int $hour): string
    {
        return sprintf('%02d:00', $hour);
    }

    /**
     * Excel-Zeile (2–25) → Stunde (0–23).
     */
    public static function hourForRow(int $row): ?int
    {
        if ($row < self::HOUR_FIRST_ROW || $row > self::HOUR_LAST_ROW) {
            return null;
        }

        return $row - self::HOUR_FIRST_ROW;
    }

    public static function rowForHour(int $hour): int
    {
        return self::HOUR_FIRST_ROW + $hour;
    }

    public static function normalizeHeader(string $value): string
    {
        $value = str_replace(["\r\n", "\r", "\n"], ' ', $value);
        $value = str_replace(['–', '—'], '-', $value);
        $value = mb_strtolower(trim($value));
        $value = preg_replace('/\s+/u', ' ', $value) ?? '';

        return $value;
    }

    public static function isTimeHeader(string $value): bool
    {
        $normalized = self::normalizeHeader($value);

        return in_array($normalized, ['uhrzeit', 'hour', 'stunde'], true);
    }

    public static function matchPriceHeader(string $value): ?string
    {
        $normalized = self::normalizeHeader($value);
        $normalized = str_replace(['€', 'eur'], 'eur', $normalized);

        $hasSek = str_contains($normalized, '1 sek')
            || str_contains($normalized, 'eur/1 sek')
            || str_contains($normalized, '/1 sek');

        if (! $hasSek) {
            return null;
        }

        if (str_contains($normalized, '30')) {
            return null;
        }

        if (
            str_contains($normalized, 'mo - fr')
            || str_contains($normalized, 'mo-fr')
            || str_contains($normalized, 'montag-freitag')
            || str_contains($normalized, 'montag - freitag')
        ) {
            return DayGroup::MoFr->value;
        }

        if (str_contains($normalized, 'samstag') || preg_match('/\bsa\b/u', $normalized) === 1) {
            return DayGroup::Sa->value;
        }

        if (str_contains($normalized, 'sonntag') || preg_match('/\bso\b/u', $normalized) === 1) {
            return DayGroup::So->value;
        }

        return null;
    }

    /**
     * @param  array<int, mixed>  $headerCells  0-basierte Zellen A–D
     */
    public static function looksLikeHeaderRow(array $headerCells): bool
    {
        $time = self::isTimeHeader((string) ($headerCells[0] ?? ''));
        if (! $time) {
            return false;
        }

        $groups = [];
        foreach ([1, 2, 3] as $index) {
            $group = self::matchPriceHeader((string) ($headerCells[$index] ?? ''));
            if ($group === null) {
                return false;
            }
            $groups[$group] = true;
        }

        return isset($groups[DayGroup::MoFr->value], $groups[DayGroup::Sa->value], $groups[DayGroup::So->value])
            && count($groups) === 3;
    }
}
