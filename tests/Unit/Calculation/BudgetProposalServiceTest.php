<?php

namespace Tests\Unit\Calculation;

use App\Enums\BudgetStrategy;
use App\Enums\DayGroup;
use App\Enums\SpotCalculationMethod;
use App\Services\Calculation\BudgetProposalService;
use App\Services\Calculation\CalculationEngine;
use App\Services\Calculation\PlanRowInput;
use App\Services\Calculation\PositionInput;
use Tests\TestCase;

class BudgetProposalServiceTest extends TestCase
{
    public function test_bud_005_equal_budget_splits_per_position_not_per_hour(): void
    {
        $service = new BudgetProposalService(new CalculationEngine);
        $hamburg = $this->position('id:1', 1, 'Radio Hamburg', ['1.0000', '3.0000']);
        $rock = $this->position('id:2', 2, 'ROCK ANTENNE Hamburg', ['0.8000']);

        $proposal = $service->propose(
            [$hamburg, $rock],
            '200.00',
            '0',
            BudgetStrategy::EqualBudget,
        );

        $this->assertCount(2, $proposal['positions']);
        $this->assertArrayHasKey('total_spot_count', $proposal['positions'][0]);
        $this->assertArrayNotHasKey('rows', $proposal['positions'][0]);
        $this->assertStringNotContainsString('BLK-007', $proposal['explanation']);
        $this->assertTrue((float) $proposal['remainder'] >= 0);
    }

    public function test_bud_006_maximize_spots_prefers_cheapest_position(): void
    {
        $service = new BudgetProposalService(new CalculationEngine);
        $expensive = $this->position('id:1', 1, 'Radio Hamburg', ['2.0000']);
        $cheap = $this->position('id:2', 2, 'ROCK ANTENNE Hamburg', ['1.0000']);

        $proposal = $service->propose(
            [$expensive, $cheap],
            '85.00',
            '0',
            BudgetStrategy::MaximizeSpots,
        );

        $byKey = collect($proposal['positions'])->keyBy('position_key');
        $this->assertSame(0, $byKey->get('id:1')['total_spot_count'] ?? 0);
        $this->assertGreaterThan(0, $byKey->get('id:2')['total_spot_count'] ?? 0);
    }

    /**
     * @param  list<string>  $prices
     */
    private function position(string $positionKey, int $inventoryId, string $name, array $prices): PositionInput
    {
        $rows = [];
        foreach ($prices as $index => $price) {
            $rows[] = new PlanRowInput(8 + $index, DayGroup::MoFr, 0, $price);
        }

        return new PositionInput(
            inventoryId: $inventoryId,
            inventoryName: $name,
            positionKey: $positionKey,
            lengthSeconds: 30,
            surchargePercent: '0',
            positionDiscountPercent: '0',
            aePercent: '15',
            isDiscountable: true,
            isAeEligible: true,
            totalSpotCount: 0,
            spotMethod: SpotCalculationMethod::Average,
            rows: $rows,
        );
    }
}
