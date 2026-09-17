<?php

namespace Tests\Unit\Calculation;

use App\Enums\DayGroup;
use App\Enums\PricingSettlementMode;
use App\Enums\SpotCalculationMethod;
use App\Services\Calculation\CalculationEngine;
use App\Services\Calculation\PlanRowInput;
use App\Services\Calculation\PositionInput;
use Tests\TestCase;

class FixedPriceSettlementTest extends TestCase
{
    public function test_fixed_price_uses_nn_directly_and_skips_discounts(): void
    {
        $engine = new CalculationEngine;
        $position = new PositionInput(
            inventoryId: 1,
            inventoryName: 'Radio Hamburg',
            positionKey: 'id:1',
            lengthSeconds: 30,
            surchargePercent: '0',
            positionDiscountPercent: '10',
            aePercent: '15',
            isDiscountable: true,
            isAeEligible: true,
            totalSpotCount: 10,
            spotMethod: SpotCalculationMethod::Average,
            rows: [new PlanRowInput(8, DayGroup::MoFr, 0, '1.0000')],
            pricingSettlementMode: PricingSettlementMode::FixedPrice,
            fixedPriceNn: '250.00',
        );

        $result = $engine->calculatePosition($position, '10');

        $this->assertSame('300.00', $result->mediaGross);
        $this->assertSame('250.00', $result->nnInvest);
        $this->assertSame('0.00', $result->positionDiscountAmount);
        $this->assertSame('0.00', $result->orderDiscountAmount);
        $this->assertSame('0.00', $result->aeAmount);
        $this->assertSame([], $result->positionDiscounts);
        $this->assertSame([], $result->orderDiscounts);
        $this->assertSame('83.3333', $result->effectivePayFactorPercent);
        $this->assertSame('16.6667', $result->effectiveDiscountPercent);
        $this->assertSame(PricingSettlementMode::FixedPrice, $result->pricingSettlementMode);
        $this->assertSame('250.00', $result->fixedPriceNn);
    }

    public function test_fixed_price_allows_nn_above_media_gross(): void
    {
        $engine = new CalculationEngine;
        $position = new PositionInput(
            inventoryId: 1,
            inventoryName: 'Radio Hamburg',
            positionKey: 'id:1',
            lengthSeconds: 30,
            surchargePercent: '0',
            positionDiscountPercent: '0',
            aePercent: '0',
            isDiscountable: true,
            isAeEligible: false,
            totalSpotCount: 10,
            spotMethod: SpotCalculationMethod::Average,
            rows: [new PlanRowInput(8, DayGroup::MoFr, 0, '1.0000')],
            pricingSettlementMode: PricingSettlementMode::FixedPrice,
            fixedPriceNn: '350.00',
        );

        $result = $engine->calculatePosition($position, '0');

        $this->assertSame('350.00', $result->nnInvest);
        $this->assertSame('-16.6667', $result->effectiveDiscountPercent);
        $this->assertSame('116.6667', $result->effectivePayFactorPercent);
    }

    public function test_fixed_price_without_media_throws(): void
    {
        $engine = new CalculationEngine;
        $position = new PositionInput(
            inventoryId: 1,
            inventoryName: 'Radio Hamburg',
            positionKey: 'id:1',
            lengthSeconds: 30,
            surchargePercent: '0',
            positionDiscountPercent: '0',
            aePercent: '0',
            isDiscountable: true,
            isAeEligible: false,
            totalSpotCount: 10,
            spotMethod: SpotCalculationMethod::Average,
            rows: [],
            pricingSettlementMode: PricingSettlementMode::FixedPrice,
            fixedPriceNn: '100.00',
        );

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Festpreis erfordert ein berechenbares Mediabrutto größer 0.');

        $engine->calculatePosition($position, '0');
    }

    public function test_spot_method_fixed_price_still_rejected(): void
    {
        $engine = new CalculationEngine;
        $position = new PositionInput(
            inventoryId: 1,
            inventoryName: 'Radio Hamburg',
            positionKey: 'id:1',
            lengthSeconds: 30,
            surchargePercent: '0',
            positionDiscountPercent: '0',
            aePercent: '0',
            isDiscountable: true,
            isAeEligible: false,
            totalSpotCount: 10,
            spotMethod: SpotCalculationMethod::FixedPrice,
            rows: [new PlanRowInput(8, DayGroup::MoFr, 0, '1.0000')],
            pricingSettlementMode: PricingSettlementMode::FixedPrice,
            fixedPriceNn: '100.00',
        );

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('fixed_price');

        $engine->calculatePosition($position, '0');
    }

    public function test_normal_mode_sets_effective_pay_factor(): void
    {
        $engine = new CalculationEngine;
        $position = new PositionInput(
            inventoryId: 1,
            inventoryName: 'Radio Hamburg',
            positionKey: 'id:1',
            lengthSeconds: 30,
            surchargePercent: '0',
            positionDiscountPercent: '0',
            aePercent: '0',
            isDiscountable: true,
            isAeEligible: false,
            totalSpotCount: 10,
            spotMethod: SpotCalculationMethod::Average,
            rows: [new PlanRowInput(8, DayGroup::MoFr, 0, '1.0000')],
        );

        $result = $engine->calculatePosition($position, '0');

        $this->assertSame('300.00', $result->nnInvest);
        $this->assertSame('100.0000', $result->effectivePayFactorPercent);
    }
}
