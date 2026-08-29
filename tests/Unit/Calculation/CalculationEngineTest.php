<?php

namespace Tests\Unit\Calculation;

use App\Enums\DayGroup;
use App\Enums\SpotCalculationMethod;
use App\Services\Calculation\CalculationEngine;
use App\Services\Calculation\PlanRowInput;
use App\Services\Calculation\PositionInput;
use App\Services\Calculation\SpotLengthIndex;
use Tests\TestCase;

class CalculationEngineTest extends TestCase
{
    public function test_spt_009_length_index_staffel(): void
    {
        $this->assertSame(110, SpotLengthIndex::forSeconds(15));
        $this->assertSame(105, SpotLengthIndex::forSeconds(20));
        $this->assertSame(100, SpotLengthIndex::forSeconds(30));
        $this->assertSame(95, SpotLengthIndex::forSeconds(46));
        $this->assertSame(95, SpotLengthIndex::forSeconds(100));
    }

    public function test_com_001_consecutive_discounts_are_nineteen_percent(): void
    {
        $engine = new CalculationEngine;
        $position = $this->position([
            new PlanRowInput(8, DayGroup::MoFr, 0, '1.0000'),
        ], totalSpotCount: 10, positionDiscount: '10');

        $result = $engine->calculatePosition($position, '10');

        $this->assertSame('300.00', $result->mediaGross);
        $this->assertSame('19.0000', $result->effectiveDiscountPercent);
        $this->assertSame('243.00', $result->afterOrderDiscount);
    }

    public function test_com_007_ae_applies_after_discount_on_eligible_base(): void
    {
        $engine = new CalculationEngine;
        $eligible = $this->position([
            new PlanRowInput(8, DayGroup::MoFr, 0, '1.0000'),
        ], totalSpotCount: 10, aePercent: '15');
        $blocked = new PositionInput(
            inventoryId: 2,
            inventoryName: 'Zusatz',
            positionKey: 'id:2',
            lengthSeconds: 30,
            surchargePercent: '0',
            positionDiscountPercent: '0',
            aePercent: '15',
            isDiscountable: false,
            isAeEligible: false,
            totalSpotCount: 10,
            spotMethod: SpotCalculationMethod::Average,
            rows: [new PlanRowInput(8, DayGroup::MoFr, 0, '1.0000')],
        );

        $totals = $engine->calculate([$eligible, $blocked], '0', null, null);

        $this->assertSame('45.00', $totals->aeTotal);
        $this->assertSame('555.00', $totals->nnInvest);
    }

    public function test_cal_001_and_cal_005_multi_sender_live_sum(): void
    {
        $engine = new CalculationEngine;
        $hamburg = $this->position([
            new PlanRowInput(8, DayGroup::MoFr, 0, '1.0000'),
        ], inventoryId: 1, inventoryName: 'Radio Hamburg', lengthSeconds: 30, totalSpotCount: 10);
        $rock = new PositionInput(
            inventoryId: 2,
            inventoryName: 'ROCK ANTENNE Hamburg',
            positionKey: 'id:2',
            lengthSeconds: 20,
            surchargePercent: '0',
            positionDiscountPercent: '0',
            aePercent: '0',
            isDiscountable: true,
            isAeEligible: false,
            totalSpotCount: 5,
            spotMethod: SpotCalculationMethod::Average,
            rows: [new PlanRowInput(10, DayGroup::MoFr, 0, '0.8000')],
        );

        $totals = $engine->calculate([$hamburg, $rock], '0', '400.00', '5');

        $this->assertSame('384.00', $totals->mediaGross);
        $this->assertSame('384.00', $totals->nnInvest);
        $this->assertSame('16.00', $totals->budgetDelta);
        $this->assertFalse($totals->requiresSpecialApproval);
        $this->assertSame(10, $totals->positions[0]->spotCount);
        $this->assertSame(5, $totals->positions[1]->spotCount);
    }

    public function test_com_002_discount_limit_marks_special_approval(): void
    {
        $engine = new CalculationEngine;
        $position = $this->position([
            new PlanRowInput(8, DayGroup::MoFr, 0, '1.0000'),
        ], totalSpotCount: 1, positionDiscount: '20');

        $totals = $engine->calculate([$position], '0', null, '10');

        $this->assertTrue($totals->requiresSpecialApproval);
    }

    public function test_at_01_spt_001_to_004_average_from_two_hours(): void
    {
        $engine = new CalculationEngine;
        $position = $this->position([
            new PlanRowInput(6, DayGroup::MoFr, 0, '1.0000'),
            new PlanRowInput(8, DayGroup::MoFr, 0, '1.2000'),
        ], lengthSeconds: 20, totalSpotCount: 10);

        $result = $engine->calculatePosition($position, '0');

        $this->assertSame('1.1000', $result->averageSecondPrice);
        $this->assertSame('231.00', $result->mediaGross);
        $this->assertSame(105, $result->lengthIndex);
    }

    public function test_spt_003_overlapping_hours_count_once(): void
    {
        $engine = new CalculationEngine;
        $rows = [
            new PlanRowInput(8, DayGroup::MoFr, 0, '1.0000'),
            new PlanRowInput(8, DayGroup::MoFr, 0, '1.0000'),
            new PlanRowInput(10, DayGroup::MoFr, 0, '2.0000'),
        ];

        $unique = $engine->uniqueHourRows($rows);
        $this->assertCount(2, $unique);
        $this->assertSame('1.5000', $engine->averageSecondPrice($unique));
    }

    public function test_pri_006_internal_precision_and_commercial_money_rounding(): void
    {
        $engine = new CalculationEngine;
        $position = $this->position([
            new PlanRowInput(8, DayGroup::MoFr, 0, '0.3333'),
        ], totalSpotCount: 9, lengthSeconds: 30);

        $result = $engine->calculatePosition($position, '0');

        $this->assertMatchesRegularExpression('/^\d+\.\d{2}$/', $result->mediaGross);
        $this->assertMatchesRegularExpression('/^\d+\.\d{4}$/', $result->averageSecondPrice);
        $this->assertSame('89.99', $result->mediaGross);
    }

    public function test_spt_009_uses_snapshot_length_index_when_provided(): void
    {
        $engine = new CalculationEngine;
        $rows = [new PlanRowInput(8, DayGroup::MoFr, 0, '1.0000')];
        $withSnapshot = $this->position($rows, lengthSeconds: 30, lengthIndex: 110);
        $withoutSnapshot = $this->position($rows, lengthSeconds: 30, lengthIndex: null);

        $snapshotResult = $engine->calculatePosition($withSnapshot, '0');
        $freshResult = $engine->calculatePosition($withoutSnapshot, '0');

        $this->assertSame(110, $snapshotResult->lengthIndex);
        $this->assertSame(100, $freshResult->lengthIndex);
        $this->assertNotSame($snapshotResult->mediaGross, $freshResult->mediaGross);
    }

    /**
     * @param  list<PlanRowInput>  $rows
     */
    private function position(
        array $rows,
        int $inventoryId = 1,
        string $inventoryName = 'Radio Hamburg',
        int $lengthSeconds = 30,
        int $totalSpotCount = 10,
        string $positionDiscount = '0',
        string $aePercent = '0',
        ?int $lengthIndex = null,
    ): PositionInput {
        return new PositionInput(
            inventoryId: $inventoryId,
            inventoryName: $inventoryName,
            positionKey: 'id:'.$inventoryId,
            lengthSeconds: $lengthSeconds,
            surchargePercent: '0',
            positionDiscountPercent: $positionDiscount,
            aePercent: $aePercent,
            isDiscountable: true,
            isAeEligible: $aePercent !== '0',
            totalSpotCount: $totalSpotCount,
            spotMethod: SpotCalculationMethod::Average,
            rows: $rows,
            lengthIndex: $lengthIndex,
        );
    }
}
