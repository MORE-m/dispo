<?php

namespace Tests\Unit\MasterDataTransfer;

use App\Services\MasterDataTransfer\MasterDataTransferException;
use App\Services\MasterDataTransfer\Support\MasterDataExportDecimal;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class MasterDataExportDecimalTest extends TestCase
{
    #[Test]
    #[DataProvider('priceScaleProvider')]
    public function canonicalizes_price_scale_without_floats(mixed $input, string $expected): void
    {
        $this->assertSame(
            $expected,
            MasterDataExportDecimal::canonicalize($input, MasterDataExportDecimal::PRICE_SCALE),
        );
    }

    /**
     * @return array<string, array{0: mixed, 1: string}>
     */
    public static function priceScaleProvider(): array
    {
        return [
            'int zero' => [0, '0.0000'],
            'string zero' => ['0', '0.0000'],
            'short fraction' => ['2.5', '2.5000'],
            'exact scale' => ['12.5000', '12.5000'],
            'trailing zeros artifact' => ['12.500000', '12.5000'],
            'negative' => ['-1.25', '-1.2500'],
            'leading zeros' => ['0007.1', '7.1000'],
        ];
    }

    #[Test]
    #[DataProvider('moneyScaleProvider')]
    public function canonicalizes_money_scale_for_unit_price(mixed $input, string $expected): void
    {
        $this->assertSame(
            $expected,
            MasterDataExportDecimal::canonicalize($input, MasterDataExportDecimal::MONEY_SCALE),
        );
    }

    /**
     * @return array<string, array{0: mixed, 1: string}>
     */
    public static function moneyScaleProvider(): array
    {
        return [
            'int' => [600, '600.00'],
            'two decimals' => ['600.00', '600.00'],
            'driver trailing zeros' => ['600.0000', '600.00'],
            'one decimal' => ['400.5', '400.50'],
        ];
    }

    #[Test]
    public function rejects_php_floats(): void
    {
        $this->expectException(MasterDataTransferException::class);
        $this->expectExceptionMessage('PHP-Float');
        MasterDataExportDecimal::canonicalize(1.25);
    }

    #[Test]
    public function rejects_non_zero_digits_beyond_scale(): void
    {
        $this->expectException(MasterDataTransferException::class);
        $this->expectExceptionMessage('überschreitet Skala');
        MasterDataExportDecimal::canonicalize('1.23456', MasterDataExportDecimal::PRICE_SCALE);
    }

    #[Test]
    public function nullable_helper_keeps_null(): void
    {
        $this->assertNull(MasterDataExportDecimal::canonicalizeNullable(null));
        $this->assertNull(MasterDataExportDecimal::canonicalizeNullable(''));
        $this->assertSame('1.5000', MasterDataExportDecimal::canonicalizeNullable('1.5'));
    }
}
