<?php

namespace Tests\Unit\Calculation;

use App\Enums\DayGroup;
use App\Enums\PricingSettlementMode;
use App\Enums\SpotCalculationMethod;
use App\Services\Calculation\CalculationEngine;
use App\Services\Calculation\PlannerEntryInput;
use App\Services\Calculation\PositionInput;
use App\Support\Calculation\EngineProfileRegistry;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * PO-SWF-CALENDAR-1: generischer Calendar-Kern für SWF-Längenprofile ohne Spotlängenindex.
 *
 * Formel je Slot: Sekundenpreis × Länge × Anzahl × (1 + surcharge/100), Index = 100.
 */
class SwfCalendarCalculationTest extends TestCase
{
    public function test_single_slot_without_index(): void
    {
        // 10 × 2,00 × 20 × 1,50 = 600,00 (Index 100, nicht 105 für 20 s)
        $result = (new CalculationEngine)->calculatePosition(
            $this->swfCalendar(EngineProfileRegistry::PROFILE_SWF_PROMO, 20, '50', [
                new PlannerEntryInput('2026-09-14', 8, 10, DayGroup::MoFr, '2.0000'),
            ], lengthIndex: 105),
            '0',
        );

        $this->assertSame('600.00', $result->mediaGross);
        $this->assertSame(100, $result->lengthIndex);
        $this->assertSame(10, $result->spotCount);
        $this->assertCount(1, $result->plannerEntries);
        $this->assertSame('600.00', $result->plannerEntries[0]['line_gross']);
    }

    public function test_two_slots_different_prices_aggregate(): void
    {
        // Slot1: 10 × 2 × 20 × 1,5 = 600; Slot2: 5 × 3 × 20 × 1,5 = 450 → 1050
        $result = (new CalculationEngine)->calculatePosition(
            $this->swfCalendar(EngineProfileRegistry::PROFILE_SWF_PROMO, 20, '50', [
                new PlannerEntryInput('2026-09-14', 8, 10, DayGroup::MoFr, '2.0000'),
                new PlannerEntryInput('2026-09-14', 14, 5, DayGroup::MoFr, '3.0000'),
            ]),
            '0',
        );

        $this->assertSame('1050.00', $result->mediaGross);
        $this->assertSame(15, $result->spotCount);
        $this->assertSame('600.00', $result->plannerEntries[0]['line_gross']);
        $this->assertSame('450.00', $result->plannerEntries[1]['line_gross']);
    }

    public function test_multiple_day_groups(): void
    {
        $result = (new CalculationEngine)->calculatePosition(
            $this->swfCalendar(EngineProfileRegistry::PROFILE_SWF_TRAILER, 20, '30', [
                new PlannerEntryInput('2026-09-14', 8, 4, DayGroup::MoFr, '2.0000'),
                new PlannerEntryInput('2026-09-19', 10, 2, DayGroup::Sa, '2.5000'),
                new PlannerEntryInput('2026-09-20', 12, 1, DayGroup::So, '3.0000'),
            ]),
            '0',
        );

        // 4×2×20×1,3=208; 2×2,5×20×1,3=130; 1×3×20×1,3=78 → 416
        $this->assertSame('416.00', $result->mediaGross);
        $this->assertSame(7, $result->spotCount);
        $this->assertSame(DayGroup::MoFr->value, $result->plannerEntries[0]['day_group']);
        $this->assertSame(DayGroup::Sa->value, $result->plannerEntries[1]['day_group']);
        $this->assertSame(DayGroup::So->value, $result->plannerEntries[2]['day_group']);
    }

    public function test_discount_and_ae_pipeline(): void
    {
        $result = (new CalculationEngine)->calculatePosition(
            $this->swfCalendar(EngineProfileRegistry::PROFILE_SWF_CONTEST, 25, '50', [
                new PlannerEntryInput('2026-09-14', 8, 10, DayGroup::MoFr, '2.0000'),
            ], positionDiscount: '10', aePercent: '15'),
            '5',
        );

        // Brutto 750 → −10 % = 675 → −5 % = 641,25 → AE 15 % = 96,1875 → N/N 545,0625
        $this->assertSame('750.00', $result->mediaGross);
        $this->assertSame('641.25', $result->afterOrderDiscount);
        $this->assertSame('96.19', $result->aeAmount);
        $this->assertSame('545.06', $result->nnInvest);
    }

    #[DataProvider('swfProfiles')]
    public function test_all_swf_profiles_use_index_100(string $profile): void
    {
        $result = (new CalculationEngine)->calculatePosition(
            $this->swfCalendar($profile, 20, '30', [
                new PlannerEntryInput('2026-09-14', 8, 10, DayGroup::MoFr, '2.0000'),
            ], lengthIndex: 110),
            '0',
        );

        // 10 × 2 × 20 × 1,3 = 520 (ohne Index 110)
        $this->assertSame('520.00', $result->mediaGross);
        $this->assertSame(100, $result->lengthIndex);
    }

    #[DataProvider('swfProfiles')]
    public function test_settlement_accepted_for_calendar(string $profile): void
    {
        $result = (new CalculationEngine)->calculatePosition(
            $this->swfCalendar($profile, 20, '30', [
                new PlannerEntryInput('2026-09-14', 8, 10, DayGroup::MoFr, '2.0000'),
            ], settlement: PricingSettlementMode::FixedPrice),
            '0',
        );

        // 10 × 2 × 20 × 1,3 = 520 → fixed_nn 100
        $this->assertSame('520.00', $result->mediaGross);
        $this->assertSame('100.00', $result->nnInvest);
        $this->assertSame(100, $result->lengthIndex);
        $this->assertSame(PricingSettlementMode::FixedPrice, $result->pricingSettlementMode);
    }

    public function test_spot_classic_calendar_still_applies_length_index(): void
    {
        $result = (new CalculationEngine)->calculatePosition(
            $this->swfCalendar(EngineProfileRegistry::PROFILE_SPOT_CLASSIC, 20, '10', [
                new PlannerEntryInput('2026-09-14', 8, 5, DayGroup::MoFr, '1.0000'),
            ], lengthIndex: 105),
            '0',
        );

        // 5 × 1 × 20 × 1,05 × 1,1 = 115,50 (Spot-Index bleibt)
        $this->assertSame('115.50', $result->mediaGross);
        $this->assertSame(105, $result->lengthIndex);
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

    /**
     * @param  list<PlannerEntryInput>  $entries
     */
    private function swfCalendar(
        string $profile,
        int $length,
        string $surcharge,
        array $entries,
        ?int $lengthIndex = null,
        string $positionDiscount = '0',
        string $aePercent = '0',
        PricingSettlementMode $settlement = PricingSettlementMode::Normal,
    ): PositionInput {
        return new PositionInput(
            inventoryId: 1,
            inventoryName: 'Test',
            positionKey: 'p',
            lengthSeconds: $length,
            surchargePercent: $surcharge,
            positionDiscountPercent: $positionDiscount,
            aePercent: $aePercent,
            isDiscountable: true,
            isAeEligible: true,
            totalSpotCount: array_sum(array_map(fn (PlannerEntryInput $e): int => $e->spotCount, $entries)),
            spotMethod: SpotCalculationMethod::Calendar,
            rows: [],
            lengthIndex: $lengthIndex,
            plannerEntries: $entries,
            pricingSettlementMode: $settlement,
            fixedPriceNn: $settlement === PricingSettlementMode::FixedPrice ? '100.00' : null,
            engineProfileKey: $profile,
        );
    }
}
