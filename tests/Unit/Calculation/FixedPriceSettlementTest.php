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
    public function test_fixed_price_without_ae_uses_nn_and_skips_discounts(): void
    {
        $engine = new CalculationEngine;
        $position = new PositionInput(
            inventoryId: 1,
            inventoryName: 'Radio Hamburg',
            positionKey: 'id:1',
            lengthSeconds: 30,
            surchargePercent: '0',
            positionDiscountPercent: '10',
            aePercent: '0',
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
        $this->assertSame('250.00', $result->afterOrderDiscount);
        $this->assertSame('0.00', $result->positionDiscountAmount);
        $this->assertSame('0.00', $result->orderDiscountAmount);
        $this->assertSame('0.00', $result->aeAmount);
        $this->assertSame([], $result->positionDiscounts);
        $this->assertSame([], $result->orderDiscounts);
        $this->assertSame('83.3333', $result->effectivePayFactorPercent);
        $this->assertSame('16.6667', $result->effectiveDiscountPercent);
        $this->assertSame('16.6667', $result->effectiveDiscountBeforeAePercent);
        $this->assertSame(PricingSettlementMode::FixedPrice, $result->pricingSettlementMode);
        $this->assertSame('250.00', $result->fixedPriceNn);
    }

    public function test_fixed_price_with_ae_reverses_from_nn_without_changing_nn(): void
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
            fixedPriceNn: '10000.00',
        );

        $result = $engine->calculatePosition($position, '10');

        $this->assertSame('300.00', $result->mediaGross);
        $this->assertSame('10000.00', $result->nnInvest);
        $this->assertSame('10000.00', $result->fixedPriceNn);
        $this->assertSame('11764.71', $result->afterOrderDiscount);
        $this->assertSame('1764.71', $result->aeAmount);
        $this->assertSame('0.00', $result->positionDiscountAmount);
        $this->assertSame('0.00', $result->orderDiscountAmount);
        $this->assertSame([], $result->positionDiscounts);
        $this->assertSame([], $result->orderDiscounts);

        // Gesamtabschlag Mediabrutto → N/N-Festpreis
        $this->assertSame('-3233.3333', $result->effectiveDiscountPercent);
        $this->assertSame('3333.3333', $result->effectivePayFactorPercent);
        // Rabatt vor AE: Mediabrutto → Netto vor AE
        $this->assertSame('-3821.5700', $result->effectiveDiscountBeforeAePercent);
    }

    public function test_fixed_price_ae_toggle_is_reproducible(): void
    {
        $engine = new CalculationEngine;
        $base = [
            'inventoryId' => 1,
            'inventoryName' => 'Radio Hamburg',
            'positionKey' => 'id:1',
            'lengthSeconds' => 30,
            'surchargePercent' => '0',
            'positionDiscountPercent' => '0',
            'isDiscountable' => true,
            'totalSpotCount' => 10,
            'spotMethod' => SpotCalculationMethod::Average,
            'rows' => [new PlanRowInput(8, DayGroup::MoFr, 0, '1.0000')],
            'pricingSettlementMode' => PricingSettlementMode::FixedPrice,
            'fixedPriceNn' => '10000.00',
        ];

        $off = $engine->calculatePosition(new PositionInput(
            ...$base,
            aePercent: '0',
            isAeEligible: true,
        ), '0');
        $on = $engine->calculatePosition(new PositionInput(
            ...$base,
            aePercent: '15',
            isAeEligible: true,
        ), '0');
        $offAgain = $engine->calculatePosition(new PositionInput(
            ...$base,
            aePercent: '0',
            isAeEligible: true,
        ), '0');
        $onAgain = $engine->calculatePosition(new PositionInput(
            ...$base,
            aePercent: '15',
            isAeEligible: true,
        ), '0');

        $this->assertSame('10000.00', $off->nnInvest);
        $this->assertSame('0.00', $off->aeAmount);
        $this->assertSame('10000.00', $on->nnInvest);
        $this->assertSame('1764.71', $on->aeAmount);
        $this->assertSame('0.00', $offAgain->aeAmount);
        $this->assertSame('1764.71', $onAgain->aeAmount);
        $this->assertSame($on->aeAmount, $onAgain->aeAmount);
        $this->assertSame($on->afterOrderDiscount, $onAgain->afterOrderDiscount);
    }

    public function test_fixed_price_rejects_negative_and_hundred_percent_ae(): void
    {
        $engine = new CalculationEngine;

        try {
            $engine->calculatePosition($this->fixedPosition(aePercent: '-1'), '0');
            $this->fail('Expected negative AE rejection');
        } catch (\InvalidArgumentException $exception) {
            $this->assertStringContainsString('nicht negativ', $exception->getMessage());
        }

        try {
            $engine->calculatePosition($this->fixedPosition(aePercent: '100'), '0');
            $this->fail('Expected AE >= 100 rejection');
        } catch (\InvalidArgumentException $exception) {
            $this->assertStringContainsString('kleiner als 100', $exception->getMessage());
        }
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

    public function test_normal_mode_sets_effective_pay_factor_and_forward_ae_unchanged(): void
    {
        $engine = new CalculationEngine;
        $position = new PositionInput(
            inventoryId: 1,
            inventoryName: 'Radio Hamburg',
            positionKey: 'id:1',
            lengthSeconds: 30,
            surchargePercent: '0',
            positionDiscountPercent: '0',
            aePercent: '15',
            isDiscountable: true,
            isAeEligible: true,
            totalSpotCount: 10,
            spotMethod: SpotCalculationMethod::Average,
            rows: [new PlanRowInput(8, DayGroup::MoFr, 0, '1.0000')],
        );

        $result = $engine->calculatePosition($position, '0');

        $this->assertSame('300.00', $result->mediaGross);
        $this->assertSame('300.00', $result->afterOrderDiscount);
        $this->assertSame('45.00', $result->aeAmount);
        $this->assertSame('255.00', $result->nnInvest);
        $this->assertSame('85.0000', $result->effectivePayFactorPercent);
        $this->assertNull($result->effectiveDiscountBeforeAePercent);
    }

    private function fixedPosition(string $aePercent): PositionInput
    {
        return new PositionInput(
            inventoryId: 1,
            inventoryName: 'Radio Hamburg',
            positionKey: 'id:1',
            lengthSeconds: 30,
            surchargePercent: '0',
            positionDiscountPercent: '0',
            aePercent: $aePercent,
            isDiscountable: true,
            isAeEligible: true,
            totalSpotCount: 10,
            spotMethod: SpotCalculationMethod::Average,
            rows: [new PlanRowInput(8, DayGroup::MoFr, 0, '1.0000')],
            pricingSettlementMode: PricingSettlementMode::FixedPrice,
            fixedPriceNn: '100.00',
        );
    }
}
