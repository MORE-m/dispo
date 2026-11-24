<?php

namespace App\Services\MasterDataTransfer\Support;

use App\Services\Calculation\Decimal;
use App\Services\MasterDataTransfer\MasterDataTransferException;

/**
 * Kanonische Dezimaldarstellung für Export 2.0.0 ohne PHP-Floats.
 *
 * Skalen entsprechen den SQL-Spalten:
 * - Spotpreis / TKP / Targeting / Adserver / surcharge_percent: 4 NK
 * - Produktions-unit_price: 2 NK
 *
 * Kein stilles Runden: überzählige Nachkommastellen sind ein Fehler.
 */
final class MasterDataExportDecimal
{
    public const PRICE_SCALE = Decimal::PRICE_SCALE;

    public const MONEY_SCALE = Decimal::MONEY_SCALE;

    public static function canonicalize(mixed $value, int $scale = self::PRICE_SCALE): string
    {
        if ($scale < 0 || $scale > Decimal::INTERNAL_SCALE) {
            throw new MasterDataTransferException('Ungültige Dezimalskala: '.$scale);
        }

        // Binäre Floats sind bereits präzisionsverfälscht.
        if (is_float($value)) {
            throw new MasterDataTransferException('Dezimalwert darf kein PHP-Float sein.');
        }

        if ($value === null || $value === '') {
            throw new MasterDataTransferException('Dezimalwert fehlt.');
        }

        if (is_int($value)) {
            $raw = (string) $value;
        } elseif (is_string($value)) {
            $raw = trim($value);
        } else {
            throw new MasterDataTransferException('Dezimalwert hat ungültigen Typ.');
        }

        if ($raw === '' || preg_match('/[eE]/', $raw) === 1 || str_contains($raw, ',')) {
            throw new MasterDataTransferException('Dezimalwert ist nicht kanonisierbar: '.$raw);
        }

        if (preg_match('/^(-?)(\d+)(?:\.(\d+))?$/', $raw, $matches) !== 1) {
            throw new MasterDataTransferException('Dezimalwert ist nicht kanonisierbar: '.$raw);
        }

        $sign = $matches[1];
        $wholeDigits = $matches[2];
        $fraction = $matches[3] ?? '';
        if (strlen($fraction) > $scale) {
            $extra = substr($fraction, $scale);
            // Nur reine Trailing-Zeros hinter der SQL-Skala kürzen (Driver-Artefakte).
            if (rtrim($extra, '0') !== '') {
                throw new MasterDataTransferException(
                    sprintf('Dezimalwert überschreitet Skala %d: %s', $scale, $raw),
                );
            }
            $fraction = substr($fraction, 0, $scale);
        }

        $whole = ltrim($wholeDigits, '0');
        if ($whole === '') {
            $whole = '0';
        }

        $paddedFraction = str_pad($fraction, $scale, '0');
        if ($sign === '-' && $whole === '0' && rtrim($paddedFraction, '0') === '') {
            return '0.'.str_repeat('0', $scale);
        }

        return $sign.$whole.'.'.$paddedFraction;
    }

    public static function canonicalizeNullable(mixed $value, int $scale = self::PRICE_SCALE): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return self::canonicalize($value, $scale);
    }
}
