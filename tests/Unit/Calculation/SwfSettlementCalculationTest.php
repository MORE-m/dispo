<?php

namespace Tests\Unit\Calculation;

use App\Enums\DayGroup;
use App\Enums\PricingSettlementMode;
use App\Enums\SpotCalculationMethod;
use App\Services\Calculation\CalculationEngine;
use App\Services\Calculation\PlanRowInput;
use App\Services\Calculation\PlannerEntryInput;
use App\Services\Calculation\PositionInput;
use App\Services\Calculation\TimeRangeInput;
use App\Support\Calculation\EngineProfileRegistry;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * PO-SWF-SETTLEMENT-1: Fixed Settlement auf SWF-Baseline (Index 100, surcharge, Länge).
 *
 * Baseline Promo: 10 × 2,00 × 20 × 1,50 = 600,00 → fixed_nn 450 → Pay 75 %, Abschlag 25 %.
 */
class SwfSettlementCalculationTest extends TestCase
{
    public function test_promo_average_fixed_settlement_uses_baseline_and_nn(): void
    {
        $result = (new CalculationEngine)->calculatePosition(
            $this->swfAverage(
                EngineProfileRegistry::PROFILE_SWF_PROMO,
                20,
                '50',
                10,
                '2.0000',
                settlement: PricingSettlementMode::FixedPrice,
                fixedNn: '450.00',
                lengthIndex: 110,
            ),
            '0',
        );

        $this->assertSame('600.00', $result->mediaGross);
        $this->assertSame(100, $result->lengthIndex);
        $this->assertSame('450.00', $result->nnInvest);
        $this->assertSame('450.00', $result->fixedPriceNn);
        $this->assertSame('0.00', $result->positionDiscountAmount);
        $this->assertSame('0.00', $result->orderDiscountAmount);
        $this->assertSame('0.00', $result->aeAmount);
        $this->assertSame('75.0000', $result->effectivePayFactorPercent);
        $this->assertSame('25.0000', $result->effectiveDiscountPercent);
        $this->assertSame(PricingSettlementMode::FixedPrice, $result->pricingSettlementMode);
    }

    public function test_promo_calendar_fixed_settlement_keeps_planner_and_index_100(): void
    {
        $result = (new CalculationEngine)->calculatePosition(
            $this->swfCalendar(
                EngineProfileRegistry::PROFILE_SWF_PROMO,
                20,
                '50',
                [
                    new PlannerEntryInput('2026-09-14', 8, 10, DayGroup::MoFr, '2.0000'),
                    new PlannerEntryInput('2026-09-14', 14, 5, DayGroup::MoFr, '2.0000'),
                ],
                settlement: PricingSettlementMode::FixedPrice,
                fixedNn: '500.00',
                lengthIndex: 105,
            ),
            '0',
        );

        // 10×2×20×1,5 + 5×2×20×1,5 = 900
        $this->assertSame('900.00', $result->mediaGross);
        $this->assertSame(100, $result->lengthIndex);
        $this->assertSame('500.00', $result->nnInvest);
        $this->assertCount(2, $result->plannerEntries);
        $this->assertSame('600.00', $result->plannerEntries[0]['line_gross']);
        $this->assertSame('300.00', $result->plannerEntries[1]['line_gross']);
        $this->assertSame('55.5556', $result->effectivePayFactorPercent);
    }

    public function test_fixed_settlement_ae_reverses_from_nn(): void
    {
        $result = (new CalculationEngine)->calculatePosition(
            $this->swfAverage(
                EngineProfileRegistry::PROFILE_SWF_PROMO,
                20,
                '50',
                10,
                '2.0000',
                aePercent: '15',
                settlement: PricingSettlementMode::FixedPrice,
                fixedNn: '450.00',
            ),
            '0',
        );

        // Netto vor AE = 450 / 0,85 = 529,411… → 529,41; AE = 79,41
        $this->assertSame('600.00', $result->mediaGross);
        $this->assertSame('450.00', $result->nnInvest);
        $this->assertSame('529.41', $result->afterOrderDiscount);
        $this->assertSame('79.41', $result->aeAmount);
    }

    #[DataProvider('swfProfiles')]
    public function test_all_swf_profiles_accept_fixed_settlement(string $profile): void
    {
        $result = (new CalculationEngine)->calculatePosition(
            $this->swfAverage($profile, 20, '30', 10, '2.0000', settlement: PricingSettlementMode::FixedPrice, fixedNn: '400.00'),
            '0',
        );

        // 10 × 2 × 20 × 1,3 = 520
        $this->assertSame('520.00', $result->mediaGross);
        $this->assertSame(100, $result->lengthIndex);
        $this->assertSame('400.00', $result->nnInvest);
    }

    public function test_spot_classic_settlement_still_uses_length_index(): void
    {
        $result = (new CalculationEngine)->calculatePosition(
            $this->swfAverage(
                EngineProfileRegistry::PROFILE_SPOT_CLASSIC,
                20,
                '0',
                10,
                '1.0000',
                settlement: PricingSettlementMode::FixedPrice,
                fixedNn: '180.00',
                lengthIndex: 105,
            ),
            '0',
        );

        // 10 × 1 × 20 × 1,05 = 210 (Spotindex)
        $this->assertSame('210.00', $result->mediaGross);
        $this->assertSame(105, $result->lengthIndex);
        $this->assertSame('180.00', $result->nnInvest);
    }

    public function test_method_key_fixed_price_still_throws(): void
    {
        $this->expectException(InvalidArgumentException::class);

        (new CalculationEngine)->calculatePosition(
            new PositionInput(
                inventoryId: 1,
                inventoryName: 'Test',
                positionKey: 'p',
                lengthSeconds: 20,
                surchargePercent: '50',
                positionDiscountPercent: '0',
                aePercent: '0',
                isDiscountable: true,
                isAeEligible: true,
                totalSpotCount: 10,
                spotMethod: SpotCalculationMethod::FixedPrice,
                rows: [],
                timeRanges: [new TimeRangeInput(8, 9, DayGroup::MoFr, 10, [
                    new PlanRowInput(8, DayGroup::MoFr, 0, '2.0000'),
                ])],
                engineProfileKey: EngineProfileRegistry::PROFILE_SWF_PROMO,
            ),
            '0',
        );
    }

    /**
     * @return list<array{0: string}>
     */
    public static function swfProfiles(): array
    {
        return [
            [EngineProfileRegistry::PROFILE_SWF_TRAILER],
            [EngineProfileRegistry::PROFILE_SWF_ALLONGE],
            [EngineProfileRegistry::PROFILE_SWF_PROMO],
            [EngineProfileRegistry::PROFILE_SWF_CONTEST],
        ];
    }

    private function swfAverage(
        string $profile,
        int $length,
        string $surcharge,
        int $spots,
        string $secondPrice,
        string $aePercent = '0',
        PricingSettlementMode $settlement = PricingSettlementMode::Normal,
        ?string $fixedNn = null,
        ?int $lengthIndex = null,
    ): PositionInput {
        return new PositionInput(
            inventoryId: 1,
            inventoryName: 'Test',
            positionKey: 'p',
            lengthSeconds: $length,
            surchargePercent: $surcharge,
            positionDiscountPercent: '0',
            aePercent: $aePercent,
            isDiscountable: true,
            isAeEligible: true,
            totalSpotCount: $spots,
            spotMethod: SpotCalculationMethod::Average,
            rows: [],
            lengthIndex: $lengthIndex,
            timeRanges: [new TimeRangeInput(8, 9, DayGroup::MoFr, $spots, [
                new PlanRowInput(8, DayGroup::MoFr, 0, $secondPrice),
            ])],
            pricingSettlementMode: $settlement,
            fixedPriceNn: $fixedNn,
            engineProfileKey: $profile,
        );
    }

    /**
     * @param  list<PlannerEntryInput>  $entries
     */
    private function swfCalendar(
        string $profile,
        int $length,
        string $surcharge,
        array $entries,
        PricingSettlementMode $settlement = PricingSettlementMode::Normal,
        ?string $fixedNn = null,
        ?int $lengthIndex = null,
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
            totalSpotCount: array_sum(array_map(fn (PlannerEntryInput $e): int => $e->spotCount, $entries)),
            spotMethod: SpotCalculationMethod::Calendar,
            rows: [],
            lengthIndex: $lengthIndex,
            plannerEntries: $entries,
            pricingSettlementMode: $settlement,
            fixedPriceNn: $fixedNn,
            engineProfileKey: $profile,
        );
    }
}
