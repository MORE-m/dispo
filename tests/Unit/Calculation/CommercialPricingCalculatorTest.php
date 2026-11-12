<?php

namespace Tests\Unit\Calculation;

use App\Enums\DiscountType;
use App\Services\Calculation\CommercialPricingCalculator;
use App\Services\Calculation\DiscountInput;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * NATIVE-1: gemeinsame Commercial-Pipeline – Referenzfälle Spot/OA/Free.
 */
class CommercialPricingCalculatorTest extends TestCase
{
    private CommercialPricingCalculator $calculator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->calculator = new CommercialPricingCalculator;
    }

    #[Test]
    public function free_position_reference_sequential_discounts_and_ae(): void
    {
        $result = $this->calculator->calculate(
            mediaGross: '5000',
            legacyPositionDiscountPercent: '10',
            orderDiscounts: [new DiscountInput(DiscountType::Quantity, '20')],
            aePercent: '15',
            isAeEligible: true,
            aeEnabled: true,
        );

        $this->assertSame('5000.00', $result->mediaGross);
        $this->assertSame('4500.00', $result->afterPositionDiscount);
        $this->assertSame('3600.00', $result->afterOrderDiscount);
        $this->assertSame('540.00', $result->aeAmount);
        $this->assertSame('3060.00', $result->nnInvest);
    }

    #[Test]
    public function oa_normal_commercial_reference_remains_bit_exact(): void
    {
        // Spiegel des OaTkpNormalEngineTest-Referenzfalls auf dem Commercial-Kern:
        // media_gross 1350 → −10% Pos → −5% Order → −15% AE
        $result = $this->calculator->calculate(
            mediaGross: '1350',
            legacyPositionDiscountPercent: '10',
            orderDiscounts: [new DiscountInput(DiscountType::Quantity, '5')],
            aePercent: '15',
            isAeEligible: true,
            aeEnabled: true,
        );

        $this->assertSame('1350.00', $result->mediaGross);
        $this->assertSame('135.00', $result->positionDiscountAmount);
        $this->assertSame('1215.00', $result->afterPositionDiscount);
        $this->assertSame('60.75', $result->orderDiscountAmount);
        $this->assertSame('1154.25', $result->afterOrderDiscount);
        $this->assertSame('173.14', $result->aeAmount);
        $this->assertSame('981.11', $result->nnInvest);
        $this->assertSame('72.6750', $result->effectivePayFactorPercent);
    }

    #[Test]
    public function spot_style_resolved_order_list_without_legacy_reresolve(): void
    {
        // Spot resolve't Order-Rabatte upstream; Legacy-Order-% darf nicht doppelt greifen.
        $result = $this->calculator->calculate(
            mediaGross: '1000',
            legacyPositionDiscountPercent: '10',
            orderDiscounts: [new DiscountInput(DiscountType::Quantity, '5')],
            legacyOrderDiscountPercent: '99',
            isDiscountable: true,
            aePercent: '0',
            isAeEligible: false,
            aeEnabled: true,
            resolveOrderLegacyPercent: false,
        );

        $this->assertSame('900.00', $result->afterPositionDiscount);
        $this->assertSame('45.00', $result->orderDiscountAmount);
        $this->assertSame('855.00', $result->nnInvest);
    }

    #[Test]
    public function not_discountable_and_not_ae_eligible_leave_gross_intact(): void
    {
        $result = $this->calculator->calculate(
            mediaGross: '5000',
            legacyPositionDiscountPercent: '10',
            orderDiscounts: [new DiscountInput(DiscountType::Quantity, '20')],
            isDiscountable: false,
            aePercent: '15',
            isAeEligible: false,
            aeEnabled: true,
        );

        $this->assertSame('5000.00', $result->nnInvest);
        $this->assertSame('0.00', $result->aeAmount);
        $this->assertSame([], $result->appliedPositionDiscounts);
    }
}
