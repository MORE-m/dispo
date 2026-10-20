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
use Tests\TestCase;

/**
 * PO-SWF-CONTEST-1: Contest-Formel ohne Spotlängenindex.
 * 10 × 2,00 × 25 × 1,50 = 750,00.
 */
class SwfContestCalculationTest extends TestCase
{
    public function test_initial_25_and_50_match_formula(): void
    {
        $result = (new CalculationEngine)->calculatePosition($this->contest(25, '50', 10, '2.0000', lengthIndex: 120), '0');

        $this->assertSame('750.00', $result->mediaGross);
        $this->assertSame(100, $result->lengthIndex);
    }

    public function test_edited_length_uses_actual_seconds(): void
    {
        $result = (new CalculationEngine)->calculatePosition($this->contest(30, '50', 10, '2.0000'), '0');

        $this->assertSame('900.00', $result->mediaGross);
    }

    public function test_admin_surcharge_55_is_used(): void
    {
        $result = (new CalculationEngine)->calculatePosition($this->contest(25, '55', 10, '2.0000'), '0');

        // 10 × 2,00 × 25 × 1,55 = 775,00
        $this->assertSame('775.00', $result->mediaGross);
    }

    public function test_contest_accepts_fixed_price_settlement(): void
    {
        $result = (new CalculationEngine)->calculatePosition(
            $this->contest(25, '50', 10, '2.0000', settlement: PricingSettlementMode::FixedPrice),
            '0',
        );

        $this->assertSame('750.00', $result->mediaGross);
        $this->assertSame('100.00', $result->nnInvest);
        $this->assertSame(100, $result->lengthIndex);
    }

    private function contest(
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
            engineProfileKey: EngineProfileRegistry::PROFILE_SWF_CONTEST,
        );
    }
}
