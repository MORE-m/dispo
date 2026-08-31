<?php

namespace Tests\Unit\Calculation;

use App\Enums\DayGroup;
use App\Enums\SpotCalculationMethod;
use App\Services\Calculation\CalculationEngine;
use App\Services\Calculation\PlanRowInput;
use App\Services\Calculation\PositionInput;
use App\Services\Calculation\TimeRangeInput;
use Tests\TestCase;

class TimeRangeCalculationTest extends TestCase
{
    public function test_range_0800_1800_uses_hours_8_to_17_not_18(): void
    {
        $hours = [];
        for ($hour = 8; $hour <= 18; $hour++) {
            $hours[] = new PlanRowInput($hour, DayGroup::MoFr, 0, $hour === 18 ? '9.0000' : '1.0000');
        }

        $result = (new CalculationEngine)->calculatePosition($this->position([
            new TimeRangeInput(8, 18, DayGroup::MoFr, 10, array_slice($hours, 0, 10)),
        ]), '0');

        $this->assertSame(range(8, 17), $result->timeRanges[0]['hours']);
        $this->assertSame('1.0000', $result->timeRanges[0]['average_second_price']);
        $this->assertNotContains(18, $result->timeRanges[0]['hours']);
    }

    public function test_single_hour_range_matches_legacy_hour(): void
    {
        $engine = new CalculationEngine;
        $legacy = $this->legacyPosition([new PlanRowInput(8, DayGroup::MoFr, 0, '1.0000')], 10);
        $range = $this->position([
            new TimeRangeInput(8, 9, DayGroup::MoFr, 10, [
                new PlanRowInput(8, DayGroup::MoFr, 0, '1.0000'),
            ]),
        ]);

        $this->assertSame(
            $engine->calculatePosition($legacy, '0')->mediaGross,
            $engine->calculatePosition($range, '0')->mediaGross,
        );
    }

    public function test_two_ranges_sum_spots_and_are_calculated_separately(): void
    {
        $result = (new CalculationEngine)->calculatePosition($this->position([
            new TimeRangeInput(8, 9, DayGroup::MoFr, 10, [
                new PlanRowInput(8, DayGroup::MoFr, 0, '1.0000'),
            ]),
            new TimeRangeInput(14, 15, DayGroup::MoFr, 20, [
                new PlanRowInput(14, DayGroup::MoFr, 0, '1.5000'),
            ]),
        ]), '0');

        $this->assertSame(30, $result->spotCount);
        $this->assertSame('300.00', $result->timeRanges[0]['range_gross']);
        $this->assertSame('900.00', $result->timeRanges[1]['range_gross']);
        $this->assertSame('1200.00', $result->mediaGross);
    }

    public function test_unweighted_average_across_ranges_is_not_used(): void
    {
        $result = (new CalculationEngine)->calculatePosition($this->position([
            new TimeRangeInput(8, 9, DayGroup::MoFr, 10, [
                new PlanRowInput(8, DayGroup::MoFr, 0, '1.0000'),
            ]),
            new TimeRangeInput(14, 15, DayGroup::MoFr, 20, [
                new PlanRowInput(14, DayGroup::MoFr, 0, '1.5000'),
            ]),
        ]), '0');

        $this->assertNotSame('1125.00', $result->mediaGross);
        $this->assertSame('1200.00', $result->mediaGross);
    }

    public function test_same_clock_times_in_different_day_groups_are_allowed(): void
    {
        $result = (new CalculationEngine)->calculatePosition($this->position([
            new TimeRangeInput(8, 12, DayGroup::MoFr, 10, $this->hours(8, 12, DayGroup::MoFr, '1.0000')),
            new TimeRangeInput(8, 12, DayGroup::Sa, 5, $this->hours(8, 12, DayGroup::Sa, '2.0000')),
        ]), '0');

        $this->assertSame(15, $result->spotCount);
        $this->assertSame('300.00', $result->timeRanges[0]['range_gross']);
        $this->assertSame('300.00', $result->timeRanges[1]['range_gross']);
        $this->assertSame('600.00', $result->mediaGross);
    }

    public function test_removing_a_range_updates_spots_and_gross(): void
    {
        $engine = new CalculationEngine;
        $two = $this->position([
            new TimeRangeInput(8, 9, DayGroup::MoFr, 10, [
                new PlanRowInput(8, DayGroup::MoFr, 0, '1.0000'),
            ]),
            new TimeRangeInput(14, 15, DayGroup::MoFr, 20, [
                new PlanRowInput(14, DayGroup::MoFr, 0, '1.5000'),
            ]),
        ]);
        $one = $this->position([
            new TimeRangeInput(8, 9, DayGroup::MoFr, 10, [
                new PlanRowInput(8, DayGroup::MoFr, 0, '1.0000'),
            ]),
        ]);

        $this->assertSame(30, $engine->calculatePosition($two, '0')->spotCount);
        $this->assertSame(10, $engine->calculatePosition($one, '0')->spotCount);
        $this->assertSame('300.00', $engine->calculatePosition($one, '0')->mediaGross);
    }

    public function test_legacy_multi_hour_average_is_unchanged_without_ranges(): void
    {
        $result = (new CalculationEngine)->calculatePosition(
            $this->legacyPosition([
                new PlanRowInput(6, DayGroup::MoFr, 0, '1.0000'),
                new PlanRowInput(8, DayGroup::MoFr, 0, '1.2000'),
            ], 10, 20),
            '0',
        );

        $this->assertSame('1.1000', $result->averageSecondPrice);
        $this->assertSame('231.00', $result->mediaGross);
    }

    /**
     * @param  list<TimeRangeInput>  $ranges
     */
    private function position(array $ranges, int $lengthSeconds = 30): PositionInput
    {
        $rows = [];
        $spots = 0;
        foreach ($ranges as $range) {
            foreach ($range->hours as $hour) {
                $rows[] = $hour;
            }
            $spots += $range->spotCount;
        }

        return new PositionInput(
            inventoryId: 1,
            inventoryName: 'Radio Hamburg',
            positionKey: 'id:1',
            lengthSeconds: $lengthSeconds,
            surchargePercent: '0',
            positionDiscountPercent: '0',
            aePercent: '0',
            isDiscountable: true,
            isAeEligible: false,
            totalSpotCount: $spots,
            spotMethod: SpotCalculationMethod::Average,
            rows: $rows,
            timeRanges: $ranges,
        );
    }

    /**
     * @param  list<PlanRowInput>  $rows
     */
    private function legacyPosition(array $rows, int $totalSpotCount, int $lengthSeconds = 30): PositionInput
    {
        return new PositionInput(
            inventoryId: 1,
            inventoryName: 'Radio Hamburg',
            positionKey: 'id:1',
            lengthSeconds: $lengthSeconds,
            surchargePercent: '0',
            positionDiscountPercent: '0',
            aePercent: '0',
            isDiscountable: true,
            isAeEligible: false,
            totalSpotCount: $totalSpotCount,
            spotMethod: SpotCalculationMethod::Average,
            rows: $rows,
        );
    }

    /**
     * @return list<PlanRowInput>
     */
    private function hours(int $start, int $endExclusive, DayGroup $group, string $price): array
    {
        $rows = [];
        for ($hour = $start; $hour < $endExclusive; $hour++) {
            $rows[] = new PlanRowInput($hour, $group, 0, $price);
        }

        return $rows;
    }
}
