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
 * PO-ALLONGE-1: Allonge-Formel ohne Spotlängenindex.
 * Synthetisch: 10 × 2,00 × 15 × 1,30 = 390,00. Kein operativer Startwert.
 */
class SwfAllongeCalculationTest extends TestCase
{
    public function test_user_length_and_admin_surcharge_match_synthetic_fixture(): void
    {
        $result = (new CalculationEngine)->calculatePosition($this->allonge(15, '30', 10, '2.0000', lengthIndex: 120), '0');

        $this->assertSame('390.00', $result->mediaGross);
        $this->assertSame(100, $result->lengthIndex);
    }

    public function test_allonge_rejects_fixed_price_settlement(): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new CalculationEngine)->calculatePosition(
            $this->allonge(15, '30', 10, '2.0000', settlement: PricingSettlementMode::FixedPrice),
            '0',
        );
    }

    private function allonge(
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
            engineProfileKey: EngineProfileRegistry::PROFILE_SWF_ALLONGE,
        );
    }
}
