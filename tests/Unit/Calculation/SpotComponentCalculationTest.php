<?php

namespace Tests\Unit\Calculation;

use App\Enums\ComponentCalculationStrategy;
use App\Enums\DayGroup;
use App\Enums\SpotCalculationMethod;
use App\Enums\SpotComponentRole;
use App\Services\Calculation\CalculationEngine;
use App\Services\Calculation\ComponentInput;
use App\Services\Calculation\PlannerEntryInput;
use App\Services\Calculation\PlanRowInput;
use App\Services\Calculation\PositionInput;
use Tests\TestCase;

/**
 * BL-P4-02c / AT-04: Spot-Komponenten Rechenvertrag.
 */
class SpotComponentCalculationTest extends TestCase
{
    public function test_shared_total_length_example_without_surcharge(): void
    {
        $result = (new CalculationEngine)->calculatePosition($this->averageComponents(
            ComponentCalculationStrategy::SharedTotalLength,
        ), '0');

        $this->assertSame('600.00', $result->mediaGross);
        $this->assertSame(100, $result->lengthIndex);
        $this->assertSame(10, $result->spotCount);
        $this->assertCount(2, $result->components);
    }

    public function test_individual_example_without_surcharge(): void
    {
        $result = (new CalculationEngine)->calculatePosition($this->averageComponents(
            ComponentCalculationStrategy::Individual,
        ), '0');

        $this->assertSame('640.00', $result->mediaGross);
        $this->assertSame(10, $result->spotCount);
        $this->assertSame('420.00', $result->components[0]['media_gross']);
        $this->assertSame('220.00', $result->components[1]['media_gross']);
        $this->assertSame(105, $result->components[0]['length_index']);
        $this->assertSame(110, $result->components[1]['length_index']);
    }

    public function test_shared_and_individual_with_thirty_percent_surcharge(): void
    {
        $shared = (new CalculationEngine)->calculatePosition($this->averageComponents(
            ComponentCalculationStrategy::SharedTotalLength,
            surcharge: '30',
        ), '0');
        $individual = (new CalculationEngine)->calculatePosition($this->averageComponents(
            ComponentCalculationStrategy::Individual,
            surcharge: '30',
        ), '0');

        $this->assertSame('780.00', $shared->mediaGross);
        $this->assertSame('832.00', $individual->mediaGross);
    }

    public function test_components_do_not_multiply_spot_count(): void
    {
        $result = (new CalculationEngine)->calculatePosition($this->averageComponents(
            ComponentCalculationStrategy::Individual,
        ), '0');

        $this->assertSame(10, $result->spotCount);
    }

    public function test_total_length_is_sum_of_components(): void
    {
        $position = $this->averageComponents(ComponentCalculationStrategy::SharedTotalLength);
        $this->assertSame(30, $position->lengthSeconds);
    }

    public function test_stable_component_sort_order(): void
    {
        $result = (new CalculationEngine)->calculatePosition($this->averageComponents(
            ComponentCalculationStrategy::Individual,
        ), '0');

        $this->assertSame('main_spot', $result->components[0]['role']);
        $this->assertSame('allonge', $result->components[1]['role']);
        $this->assertSame(0, $result->components[0]['sort']);
        $this->assertSame(1, $result->components[1]['sort']);
    }

    public function test_calendar_individual_sums_cells_without_spot_multiplication(): void
    {
        $position = new PositionInput(
            inventoryId: 1,
            inventoryName: 'Test',
            positionKey: 'k',
            lengthSeconds: 30,
            surchargePercent: '0',
            positionDiscountPercent: '0',
            aePercent: '0',
            isDiscountable: true,
            isAeEligible: false,
            totalSpotCount: 15,
            spotMethod: SpotCalculationMethod::Calendar,
            rows: [],
            plannerEntries: [
                new PlannerEntryInput('2026-09-14', 8, 10, DayGroup::MoFr, '2.0000'),
                new PlannerEntryInput('2026-09-14', 14, 5, DayGroup::MoFr, '3.0000'),
            ],
            components: [
                new ComponentInput(SpotComponentRole::MainSpot, 'Hauptspot', 20, 0),
                new ComponentInput(SpotComponentRole::Allonge, 'Allonge', 10, 1),
            ],
            componentCalculationStrategy: ComponentCalculationStrategy::Individual,
        );

        $result = (new CalculationEngine)->calculatePosition($position, '0');

        // Cell1: 10×2×20×1.05 + 10×2×10×1.10 = 420 + 220 = 640
        // Cell2: 5×3×20×1.05 + 5×3×10×1.10 = 315 + 165 = 480
        // Total = 1120
        $this->assertSame('1120.00', $result->mediaGross);
        $this->assertSame(15, $result->spotCount);
        $this->assertSame('640.00', $result->plannerEntries[0]['line_gross']);
        $this->assertSame('480.00', $result->plannerEntries[1]['line_gross']);
    }

    public function test_legacy_without_components_unchanged(): void
    {
        $position = new PositionInput(
            inventoryId: 1,
            inventoryName: 'Test',
            positionKey: 'k',
            lengthSeconds: 30,
            surchargePercent: '0',
            positionDiscountPercent: '0',
            aePercent: '0',
            isDiscountable: true,
            isAeEligible: false,
            totalSpotCount: 10,
            spotMethod: SpotCalculationMethod::Average,
            rows: [new PlanRowInput(8, DayGroup::MoFr, 0, '2.0000')],
        );

        $result = (new CalculationEngine)->calculatePosition($position, '0');

        $this->assertSame('600.00', $result->mediaGross);
        $this->assertSame([], $result->components);
    }

    private function averageComponents(
        ComponentCalculationStrategy $strategy,
        string $surcharge = '0',
    ): PositionInput {
        return new PositionInput(
            inventoryId: 1,
            inventoryName: 'Radio Hamburg',
            positionKey: 'k',
            lengthSeconds: 30,
            surchargePercent: $surcharge,
            positionDiscountPercent: '0',
            aePercent: '0',
            isDiscountable: true,
            isAeEligible: false,
            totalSpotCount: 10,
            spotMethod: SpotCalculationMethod::Average,
            rows: [new PlanRowInput(8, DayGroup::MoFr, 0, '2.0000')],
            components: [
                new ComponentInput(SpotComponentRole::MainSpot, 'Hauptspot', 20, 0),
                new ComponentInput(SpotComponentRole::Allonge, 'Allonge', 10, 1),
            ],
            componentCalculationStrategy: $strategy,
        );
    }
}
