<?php

namespace App\Support\DispoOrder;

/**
 * V1-Vertrag „Rechnung per Ende“ (INV-001–003 / PO-BLP802D-1).
 * Speichert nur Kalendermonate 1–12, keine Beträge.
 */
final class InvoiceEndMonthsContract
{
    public const int MIN_MONTH = 1;

    public const int MAX_MONTH = 12;

    /**
     * @return array<int, string>
     */
    public static function monthLabels(): array
    {
        return [
            1 => 'Januar',
            2 => 'Februar',
            3 => 'März',
            4 => 'April',
            5 => 'Mai',
            6 => 'Juni',
            7 => 'Juli',
            8 => 'August',
            9 => 'September',
            10 => 'Oktober',
            11 => 'November',
            12 => 'Dezember',
        ];
    }

    public static function monthLabel(int $month): string
    {
        return self::monthLabels()[$month] ?? (string) $month;
    }

    /**
     * @param  list<int>|null  $months
     * @return list<string>
     */
    public static function labelsFor(?array $months): array
    {
        if ($months === null || $months === []) {
            return [];
        }

        $labels = [];
        foreach ($months as $month) {
            $labels[] = self::monthLabel((int) $month);
        }

        return $labels;
    }

    /**
     * Kanonisiert auf eindeutige Integers 1–12, aufsteigend sortiert.
     * Leere Auswahl → null.
     *
     * @param  list<mixed>  $months
     * @return list<int>|null
     */
    public static function canonicalize(array $months): ?array
    {
        $normalized = [];
        foreach ($months as $month) {
            if (! is_int($month) && ! (is_string($month) && ctype_digit($month))) {
                continue;
            }
            $value = (int) $month;
            if ($value < self::MIN_MONTH || $value > self::MAX_MONTH) {
                continue;
            }
            $normalized[$value] = $value;
        }

        if ($normalized === []) {
            return null;
        }

        ksort($normalized);

        return array_values($normalized);
    }

    /**
     * @param  list<int>|null  $months
     */
    public static function isFilled(?array $months): bool
    {
        $canonical = self::canonicalize($months ?? []);

        return $canonical !== null && $canonical !== [];
    }
}
