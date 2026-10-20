<?php

namespace Tests\Unit\OnlineAudio;

use App\Services\OnlineAudio\Import\OaTkpImportDecimal;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * PO-CALC-TKP-1A Nachzug: Decimal-Import ohne Float für decimal(16,4).
 */
class OaTkpImportDecimalTest extends TestCase
{
    #[Test]
    #[DataProvider('validPositiveProvider')]
    public function normalize_positive_accepts_canonical_values(mixed $input, string $expected): void
    {
        $this->assertSame($expected, OaTkpImportDecimal::normalizePositive($input));
    }

    /**
     * @return list<array{0: mixed, 1: string}>
     */
    public static function validPositiveProvider(): array
    {
        return [
            [1, '1.0000'],
            ['1', '1.0000'],
            ['1.2', '1.2000'],
            ['1.2345', '1.2345'],
            ['12.5', '12.5000'],
            ['999999999999.9999', '999999999999.9999'],
        ];
    }

    #[Test]
    #[DataProvider('invalidPositiveProvider')]
    public function normalize_positive_rejects_invalid_values(mixed $input): void
    {
        $this->assertNull(OaTkpImportDecimal::normalizePositive($input));
    }

    /**
     * @return list<array{0: mixed}>
     */
    public static function invalidPositiveProvider(): array
    {
        return [
            [0],
            ['0'],
            ['0.0000'],
            [-1],
            ['-1'],
            ['0.00005'],
            ['1.23456'],
            ['1000000000000'],
            ['1000000000000.0000'],
            ['1e3'],
            ['12,50'],
            ['abc'],
            [12.5],
            [null],
            [''],
        ];
    }

    #[Test]
    public function compare_is_decimal_safe_for_minimum_rule(): void
    {
        $this->assertSame(0, OaTkpImportDecimal::compare('8.0000', '8.0000'));
        $this->assertSame(-1, OaTkpImportDecimal::compare('8.0000', '8.0001'));
        $this->assertSame(1, OaTkpImportDecimal::compare('8.0001', '8.0000'));
    }

    #[Test]
    public function normalize_non_negative_allows_zero(): void
    {
        $this->assertSame('0.0000', OaTkpImportDecimal::normalizeNonNegative('0'));
        $this->assertSame('0.0000', OaTkpImportDecimal::normalizeNonNegative(0));
        $this->assertNull(OaTkpImportDecimal::normalizeNonNegative('-0.0001'));
        $this->assertNull(OaTkpImportDecimal::normalizeNonNegative('0.00005'));
    }
}
