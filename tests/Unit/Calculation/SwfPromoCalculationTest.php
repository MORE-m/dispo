<?php

namespace Tests\Unit\Calculation;

use App\Enums\DayGroup;
use App\Enums\PricingSettlementMode;
use App\Enums\SpotCalculationMethod;
use App\Services\Calculation\CalculationEngine;
use App\Services\Calculation\PlanRowInput;
use App\Services\Calculation\PositionInput;
use App\Services\Calculation\TimeRangeInput;
use App\Support\Calculation\EngineProfileRegistry;
use InvalidArgumentException;
use Tests\TestCase;

/**
 * PO-SWF-PROMO-1: Promo-Formel ohne Spotlängenindex.
 * 10 × 2,00 × 20 × 1,50 = 600,00.
 */
class SwfPromoCalculationTest extends TestCase
{
    public function test_initial_20_and_50_match_formula(): void
    {
        $result = (new CalculationEngine)->calculatePosition($this->promo(20, '50', 10, '2.0000', lengthIndex: 120), '0');

        $this->assertSame('600.00', $result->mediaGross);
        $this->assertSame(100, $result->lengthIndex);
    }

    public function test_edited_length_uses_actual_seconds(): void
    {
        $result = (new CalculationEngine)->calculatePosition($this->promo(15, '50', 10, '2.0000'), '0');

        $this->assertSame('450.00', $result->mediaGross);
    }

    public function test_promo_rejects_calendar_and_fixed_price(): void
    {
        $engine = new CalculationEngine;

        try {
            $engine->calculatePosition($this->promo(20, '50', 10, '2.0000', method: SpotCalculationMethod::Calendar), '0');
            $this->fail('Calendar hätte abgelehnt werden müssen.');
        } catch (InvalidArgumentException) {
            $this->assertTrue(true);
        }

        $this->expectException(InvalidArgumentException::class);
        $engine->calculatePosition($this->promo(20, '50', 10, '2.0000', settlement: PricingSettlementMode::FixedPrice), '0');
    }

    private function promo(
        int $length,
        string $surcharge,
        int $spots,
        string $secondPrice,
        ?int $lengthIndex = null,
        SpotCalculationMethod $method = SpotCalculationMethod::Average,
        PricingSettlementMode $settlement = PricingSettlementMode::Normal,
    ): PositionInput {
        return new PositionInput(
            inventoryId: 1,
            inventoryName: 'Test',
            positionKey: 'p',
            lengthSeconds: $length,
            surchargePercent: $surcharge,
            positionDiscountPercent: '0',
            aePercent: '0',
            isDiscountable: true,
            isAeEligible: true,
            totalSpotCount: $spots,
            spotMethod: $method,
            rows: [],
            lengthIndex: $lengthIndex,
            timeRanges: [new TimeRangeInput(8, 9, DayGroup::MoFr, $spots, [
                new PlanRowInput(8, DayGroup::MoFr, 0, $secondPrice),
            ])],
            pricingSettlementMode: $settlement,
            fixedPriceNn: $settlement === PricingSettlementMode::FixedPrice ? '100.00' : null,
            engineProfileKey: EngineProfileRegistry::PROFILE_SWF_PROMO,
        );
    }
}
