<?php

namespace Tests\Unit\Calculation\FreePosition;

use App\Enums\DiscountType;
use App\Enums\PricingSettlementMode;
use App\Services\Calculation\DiscountInput;
use App\Services\Calculation\FreePosition\FreePositionEngine;
use App\Services\Calculation\FreePosition\FreePositionInput;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * NATIVE-1: FreePositionEngine – free_price → Commercial → N/N.
 */
class FreePositionEngineTest extends TestCase
{
    private FreePositionEngine $engine;

    protected function setUp(): void
    {
        parent::setUp();
        $this->engine = new FreePositionEngine;
    }

    #[Test]
    public function case_a_without_discount_or_ae(): void
    {
        $result = $this->engine->calculatePosition(
            $this->input(freePrice: '5000', aePercent: '0', isAeEligible: false),
            aeEnabled: true,
        );

        $this->assertSame('5000.00', $result->freePrice);
        $this->assertSame('5000.00', $result->mediaGross);
        $this->assertSame('0.00', $result->positionDiscountAmount);
        $this->assertSame('5000.00', $result->afterPositionDiscount);
        $this->assertSame('0.00', $result->orderDiscountAmount);
        $this->assertSame('5000.00', $result->afterOrderDiscount);
        $this->assertSame('0.00', $result->aeAmount);
        $this->assertSame('5000.00', $result->nnInvest);
        $this->assertSame(PricingSettlementMode::Normal, $result->pricingSettlementMode);
        $this->assertNull($result->fixedPriceNn);
        $this->assertSame(0, $result->lengthSeconds);
        $this->assertSame(0, $result->totalSpotCount);
        $this->assertNull($result->impressions);
    }

    #[Test]
    public function case_b_ten_percent_position_discount(): void
    {
        $result = $this->engine->calculatePosition(
            $this->input(freePrice: '5000', positionDiscountPercent: '10', isAeEligible: false, aePercent: '0'),
        );

        $this->assertSame('5000.00', $result->mediaGross);
        $this->assertSame('500.00', $result->positionDiscountAmount);
        $this->assertSame('4500.00', $result->afterPositionDiscount);
        $this->assertSame('4500.00', $result->nnInvest);
    }

    #[Test]
    public function case_c_position_and_order_sequential_not_summed(): void
    {
        $result = $this->engine->calculatePosition(
            $this->input(freePrice: '5000', positionDiscountPercent: '10', isAeEligible: false, aePercent: '0'),
            orderDiscounts: [new DiscountInput(DiscountType::Quantity, '20')],
            orderDiscountPercent: '20',
        );

        // 5000 → −10% = 4500 → −20% = 3600 (nicht −30% = 3500)
        $this->assertSame('4500.00', $result->afterPositionDiscount);
        $this->assertSame('900.00', $result->orderDiscountAmount);
        $this->assertSame('3600.00', $result->afterOrderDiscount);
        $this->assertSame('3600.00', $result->nnInvest);
        $this->assertNotSame('3500.00', $result->nnInvest);
    }

    #[Test]
    public function case_d_ae_fifteen_percent_forward(): void
    {
        $result = $this->engine->calculatePosition(
            $this->input(freePrice: '5000', aePercent: '15', isAeEligible: true),
            aeEnabled: true,
        );

        $this->assertSame('5000.00', $result->mediaGross);
        $this->assertSame('5000.00', $result->afterOrderDiscount);
        $this->assertSame('750.00', $result->aeAmount);
        $this->assertSame('4250.00', $result->nnInvest);
        $this->assertSame('85.0000', $result->effectivePayFactorPercent);
    }

    #[Test]
    public function case_e_not_discountable_ignores_discounts(): void
    {
        $result = $this->engine->calculatePosition(
            $this->input(
                freePrice: '5000',
                positionDiscountPercent: '10',
                isDiscountable: false,
                aePercent: '0',
                isAeEligible: false,
            ),
            orderDiscounts: [new DiscountInput(DiscountType::Quantity, '20')],
            orderDiscountPercent: '20',
        );

        $this->assertSame('0.00', $result->positionDiscountAmount);
        $this->assertSame('0.00', $result->orderDiscountAmount);
        $this->assertSame('5000.00', $result->nnInvest);
        $this->assertSame([], $result->appliedPositionDiscounts);
        $this->assertSame([], $result->appliedOrderDiscounts);
    }

    #[Test]
    public function case_f_not_ae_eligible_ignores_ae(): void
    {
        $result = $this->engine->calculatePosition(
            $this->input(freePrice: '5000', aePercent: '15', isAeEligible: false),
            aeEnabled: true,
        );

        $this->assertSame('0.00', $result->aeAmount);
        $this->assertSame('5000.00', $result->nnInvest);
        $this->assertSame('0', $result->aePercent);
    }

    #[Test]
    public function ae_disabled_on_calculation_ignores_ae(): void
    {
        $result = $this->engine->calculatePosition(
            $this->input(freePrice: '5000', aePercent: '15', isAeEligible: true),
            aeEnabled: false,
        );

        $this->assertSame('0.00', $result->aeAmount);
        $this->assertSame('5000.00', $result->nnInvest);
    }

    #[Test]
    #[DataProvider('invalidFreePrices')]
    public function case_g_invalid_free_prices_are_rejected(string $freePrice): void
    {
        $this->expectException(ValidationException::class);
        $this->engine->calculatePosition($this->input(freePrice: $freePrice));
    }

    /**
     * @return list<array{0: string}>
     */
    public static function invalidFreePrices(): array
    {
        return [
            ['0'],
            ['-1'],
            ['abc'],
            ['5000.123'],
            [''],
            ['  '],
        ];
    }

    #[Test]
    public function accepts_two_decimal_and_integer_forms(): void
    {
        foreach (['5000', '5000.00', '2499.95'] as $price) {
            $result = $this->engine->calculatePosition(
                $this->input(freePrice: $price, aePercent: '0', isAeEligible: false),
            );
            $this->assertSame(
                $price === '2499.95' ? '2499.95' : '5000.00',
                $result->mediaGross,
            );
        }
    }

    #[Test]
    public function length_zero_and_null_quantity_fields_are_stable_contract(): void
    {
        $result = $this->engine->calculatePosition(
            $this->input(freePrice: '100.00', aePercent: '0', isAeEligible: false),
        );

        $this->assertSame(0, $result->lengthSeconds);
        $this->assertSame(0, $result->totalSpotCount);
        $this->assertNull($result->impressions);
        $this->assertSame(PricingSettlementMode::Normal, $result->pricingSettlementMode);
        $this->assertNull($result->fixedPriceNn);
    }

    private function input(
        string $freePrice,
        string $positionDiscountPercent = '0',
        string $aePercent = '0',
        bool $isDiscountable = true,
        bool $isAeEligible = true,
    ): FreePositionInput {
        return new FreePositionInput(
            freePrice: $freePrice,
            positionDiscountPercent: $positionDiscountPercent,
            aePercent: $aePercent,
            isDiscountable: $isDiscountable,
            isAeEligible: $isAeEligible,
            positionKey: 'fp-1',
        );
    }
}
