<?php

namespace App\Services\OnlineAudio\Import;

use App\Services\Calculation\Decimal;

/**
 * PO-CALC-TKP-1A: Decimal-Import ohne binäre Floats für decimal(16,4).
 *
 * Kanonisch: optionale Ganzzahl + max. 4 Nachkommastellen, Punkt-Trenner.
 * Keine wissenschaftliche Notation, kein Locale-Komma, kein stilles Runden.
 */
final class OaTkpImportDecimal
{
    public const SCALE = Decimal::PRICE_SCALE;

    /** Maximaler positiver Wert für MySQL decimal(16,4). */
    public const MAX = '999999999999.9999';

    /**
     * Positiver Preis (> 0), kanonisch mit 4 NK.
     */
    public static function normalizePositive(mixed $value): ?string
    {
        $normalized = self::normalize($value, allowZero: false);

        return $normalized;
    }

    /**
     * Nicht-negativer Betrag (≥ 0), kanonisch mit 4 NK (z. B. Targeting-Aufschlag).
     */
    public static function normalizeNonNegative(mixed $value): ?string
    {
        return self::normalize($value, allowZero: true);
    }

    public static function compare(string $left, string $right): int
    {
        return Decimal::cmp($left, $right, self::SCALE);
    }

    private static function normalize(mixed $value, bool $allowZero): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        // JSON-Zahlen als float sind bereits binär verfälscht → ablehnen.
        if (is_float($value)) {
            return null;
        }

        if (is_int($value)) {
            $raw = (string) $value;
        } elseif (is_string($value)) {
            $raw = trim($value);
        } else {
            return null;
        }

        if ($raw === '' || preg_match('/[eE]/', $raw) === 1 || str_contains($raw, ',')) {
            return null;
        }

        // Genau decimal(16,4): bis 12 Vorkomma-Ziffern, max. 4 NK, kein stilles Runden.
        if (preg_match('/^(\d+)(?:\.(\d+))?$/', $raw, $matches) !== 1) {
            return null;
        }

        $whole = $matches[1];
        $fraction = $matches[2] ?? '';
        if (strlen($fraction) > self::SCALE) {
            return null;
        }

        $wholeSignificant = ltrim($whole, '0');
        if ($wholeSignificant === '') {
            $wholeSignificant = '0';
        }
        if (strlen($wholeSignificant) > 12) {
            return null;
        }

        $canonical = $wholeSignificant.'.'.str_pad($fraction, self::SCALE, '0');

        if (Decimal::cmp($canonical, self::MAX, self::SCALE) > 0) {
            return null;
        }

        if ($allowZero) {
            if (Decimal::cmp($canonical, '0', self::SCALE) < 0) {
                return null;
            }
        } elseif (Decimal::cmp($canonical, '0', self::SCALE) <= 0) {
            return null;
        }

        return $canonical;
    }
}
