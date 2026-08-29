<?php

namespace App\Services\Calculation;

use InvalidArgumentException;

/**
 * Kaufmännische Dezimalarithmetik ohne binäre Floats (GEN-002).
 */
final class Decimal
{
    public const INTERNAL_SCALE = 8;

    public const PRICE_SCALE = 4;

    public const MONEY_SCALE = 2;

    /**
     * @return numeric-string
     */
    public static function of(int|string $value): string
    {
        if (is_int($value)) {
            return sprintf('%d', $value);
        }

        $normalized = str_replace(',', '.', trim($value));

        if (preg_match('/^(-?)(\d+)(?:\.(\d+))?$/', $normalized, $matches) !== 1) {
            throw new InvalidArgumentException('Ungültiger Dezimalwert.');
        }

        $fraction = str_pad(substr($matches[3] ?? '', 0, self::INTERNAL_SCALE), self::INTERNAL_SCALE, '0');
        $whole = ltrim($matches[2], '0');
        if ($whole === '') {
            $whole = '0';
        }

        $result = $matches[1].$whole.'.'.$fraction;

        if (! is_numeric($result)) {
            throw new InvalidArgumentException('Ungültiger Dezimalwert.');
        }

        return $result;
    }

    /**
     * @return numeric-string
     */
    public static function add(string $left, string $right, int $scale = self::INTERNAL_SCALE): string
    {
        return self::of(bcadd(self::of($left), self::of($right), $scale + 2));
    }

    /**
     * @return numeric-string
     */
    public static function sub(string $left, string $right, int $scale = self::INTERNAL_SCALE): string
    {
        return self::of(bcsub(self::of($left), self::of($right), $scale + 2));
    }

    /**
     * @return numeric-string
     */
    public static function mul(string $left, string $right, int $scale = self::INTERNAL_SCALE): string
    {
        return self::of(bcmul(self::of($left), self::of($right), $scale + 2));
    }

    /**
     * @param  list<string>  $factors
     */
    public static function mulMany(array $factors, int $scale = self::INTERNAL_SCALE): string
    {
        $result = '1';

        foreach ($factors as $factor) {
            $result = self::mul($result, $factor, $scale);
        }

        return $result;
    }

    public static function div(string $left, string $right, int $scale = self::INTERNAL_SCALE): string
    {
        if (bccomp(self::of($right), '0', $scale) === 0) {
            throw new InvalidArgumentException('Division durch 0.');
        }

        return self::of(bcdiv(self::of($left), self::of($right), $scale + 2));
    }

    public static function cmp(string $left, string $right, int $scale = self::INTERNAL_SCALE): int
    {
        return bccomp(self::of($left), self::of($right), $scale);
    }

    public static function roundMoney(string $value): string
    {
        return self::roundHalfUp($value, self::MONEY_SCALE);
    }

    public static function roundPrice(string $value): string
    {
        return self::roundHalfUp($value, self::PRICE_SCALE);
    }

    public static function percentFactor(string $percent): string
    {
        return self::div($percent, '100');
    }

    public static function oneMinusPercent(string $percent): string
    {
        return self::sub('1', self::percentFactor($percent));
    }

    private static function roundHalfUp(string $value, int $scale): string
    {
        $normalized = self::of($value);
        $negative = self::cmp($normalized, '0') < 0;
        $absolute = $negative ? self::of(substr($normalized, 1)) : $normalized;
        $factor = bcpow('10', (string) ($scale + 1), 0);
        $shifted = bcmul($absolute, $factor, 0);
        $last = (int) substr($shifted, -1);
        $truncated = substr($shifted, 0, -1);

        if ($truncated === '') {
            $truncated = '0';
        }

        if ($last >= 5) {
            $truncated = bcadd(self::of($truncated), '1', 0);
        }

        $divisor = bcpow('10', (string) $scale, 0);
        $rounded = bcdiv(self::of($truncated), $divisor, $scale);

        return $negative && self::cmp($rounded, '0') !== 0
            ? '-'.$rounded
            : $rounded;
    }
}
