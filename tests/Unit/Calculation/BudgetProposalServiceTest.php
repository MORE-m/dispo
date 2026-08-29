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
    public function test_bud_005_equal_budget_does_not_use_existing_ratio(): void
    {
        $service = new BudgetProposalService(new CalculationEngine);
        $hamburg = $this->position('id:1', 1, 'Radio Hamburg', '1.0000');
        $rock = $this->position('id:2', 2, 'ROCK ANTENNE Hamburg', '1.0000');

        $proposal = $service->propose(
            [$hamburg, $rock],
            '170.00',
            '0',
            BudgetStrategy::EqualBudget,
        );

        $counts = [];
        foreach ($proposal['positions'] as $position) {
            $counts[$position['inventory_id']] = array_sum(array_column($position['rows'], 'spot_count'));
        }

        $this->assertSame($counts[1] ?? 0, $counts[2] ?? 0);
        $this->assertGreaterThan(0, $counts[1] ?? 0);
        $this->assertTrue((float) $proposal['remainder'] >= 0);
        $this->assertStringContainsString('BLK-007', $proposal['explanation']);
    }

    public function test_bud_006_maximize_spots_fills_cheapest_hours(): void
    {
        $service = new BudgetProposalService(new CalculationEngine);
        $expensive = $this->position('id:1', 1, 'Radio Hamburg', '2.0000');
        $cheap = $this->position('id:2', 2, 'ROCK ANTENNE Hamburg', '1.0000');

        $proposal = $service->propose(
            [$expensive, $cheap],
            '85.00',
            '0',
            BudgetStrategy::MaximizeSpots,
        );

        $byInventory = [];
        foreach ($proposal['positions'] as $position) {
            $byInventory[$position['inventory_id']] = array_sum(array_column($position['rows'], 'spot_count'));
        }

        $this->assertSame(0, $byInventory[1] ?? 0);
        $this->assertGreaterThan(0, $byInventory[2] ?? 0);
        $this->assertTrue(
            (float) $proposal['remainder'] >= 0,
            'BUD-007 Rest muss ausgewiesen werden',
        );
    }

    private function position(
        string $positionKey,
        int $inventoryId,
        string $name,
        string $price,
    ): PositionInput {
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
            rows: [new PlanRowInput(8, DayGroup::MoFr, 0, $price)],
        );
    }
}
