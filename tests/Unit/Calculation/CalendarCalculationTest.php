<?php

namespace Tests\Unit\Calculation;

use App\Enums\DayGroup;
use App\Enums\SpotCalculationMethod;
use App\Services\Calculation\CalculationEngine;
use App\Services\Calculation\PlannerEntryInput;
use App\Services\Calculation\PositionInput;
use Tests\TestCase;

class CalendarCalculationTest extends TestCase
{
    public function test_single_entry_matches_spot_formula(): void
    {
        $result = (new CalculationEngine)->calculatePosition($this->calendarPosition([
            new PlannerEntryInput('2026-09-14', 8, 10, DayGroup::MoFr, '2.0000'),
        ]), '0');

        // 10 × 2.0000 × 30s × (100/100) × 1 = 600.00
        $this->assertSame('600.00', $result->mediaGross);
        $this->assertSame(10, $result->spotCount);
        $this->assertCount(1, $result->plannerEntries);
        $this->assertSame('600.00', $result->plannerEntries[0]['line_gross']);
    }

    public function test_two_entries_with_different_second_prices_sum_lines(): void
    {
        $result = (new CalculationEngine)->calculatePosition($this->calendarPosition([
            new PlannerEntryInput('2026-09-14', 8, 10, DayGroup::MoFr, '2.0000'),
            new PlannerEntryInput('2026-09-14', 14, 5, DayGroup::MoFr, '3.0000'),
        ]), '0');

        $this->assertSame('1050.00', $result->mediaGross);
        $this->assertSame(15, $result->spotCount);
        $this->assertSame('600.00', $result->plannerEntries[0]['line_gross']);
        $this->assertSame('450.00', $result->plannerEntries[1]['line_gross']);
    }

    public function test_surcharge_and_length_index_apply(): void
    {
        $position = new PositionInput(
            inventoryId: 1,
            inventoryName: 'Test',
            positionKey: 'k',
            lengthSeconds: 20,
            surchargePercent: '10',
            positionDiscountPercent: '0',
            aePercent: '0',
            isDiscountable: true,
            isAeEligible: false,
            totalSpotCount: 5,
            spotMethod: SpotCalculationMethod::Calendar,
            rows: [],
            lengthIndex: 105,
            plannerEntries: [
                new PlannerEntryInput('2026-09-14', 8, 5, DayGroup::MoFr, '1.0000'),
            ],
        );

        $result = (new CalculationEngine)->calculatePosition($position, '0');

        // 5 × 1 × 20 × 1.05 × 1.1 = 115.50
        $this->assertSame('115.50', $result->mediaGross);
    }

    /**
     * @param  list<PlannerEntryInput>  $entries
     */
    private function calendarPosition(array $entries): PositionInput
    {
        return new PositionInput(
            inventoryId: 1,
            inventoryName: 'Radio Hamburg',
            positionKey: 'k',
            lengthSeconds: 30,
            surchargePercent: '0',
            positionDiscountPercent: '0',
            aePercent: '0',
            isDiscountable: true,
            isAeEligible: false,
            totalSpotCount: array_sum(array_map(fn (PlannerEntryInput $e): int => $e->spotCount, $entries)),
            spotMethod: SpotCalculationMethod::Calendar,
            rows: [],
            plannerEntries: $entries,
        );
    }
}
