<?php

namespace Tests\Unit\Calculation;

use App\Enums\ComponentCalculationStrategy;
use App\Enums\DayGroup;
use App\Enums\SpotCalculationMethod;
use App\Enums\SpotComponentProfile;
use App\Enums\SpotComponentRole;
use App\Services\Calculation\CalculationEngine;
use App\Services\Calculation\ComponentInput;
use App\Services\Calculation\PlanRowInput;
use App\Services\Calculation\PositionInput;
use App\Support\Advertising\SpotComponentProfileContract;
use Tests\TestCase;

/**
 * BL-P4-02e: Tandem/Tridem Rechenvertrag (shared_total_length, keine ×2/×3).
 */
class TandemTridemCalculationTest extends TestCase
{
    public function test_tandem_shared_total_length_example_600(): void
    {
        $result = (new CalculationEngine)->calculatePosition($this->tandemAverage(), '0');

        $this->assertSame('600.00', $result->mediaGross);
        $this->assertSame(100, $result->lengthIndex);
        $this->assertSame(10, $result->spotCount);
        $this->assertCount(2, $result->components);
        $this->assertSame('main_spot', $result->components[0]['role']);
        $this->assertSame('reminder', $result->components[1]['role']);
    }

    public function test_tridem_shared_total_length_example_760(): void
    {
        $result = (new CalculationEngine)->calculatePosition($this->tridemAverage(), '0');

        $this->assertSame('760.00', $result->mediaGross);
        $this->assertSame(95, $result->lengthIndex);
        $this->assertSame(10, $result->spotCount);
        $this->assertCount(3, $result->components);
    }

    public function test_components_do_not_multiply_spot_count(): void
    {
        $tandem = (new CalculationEngine)->calculatePosition($this->tandemAverage(), '0');
        $tridem = (new CalculationEngine)->calculatePosition($this->tridemAverage(), '0');

        $this->assertSame(10, $tandem->spotCount);
        $this->assertSame(10, $tridem->spotCount);
        $this->assertSame('600.00', $tandem->mediaGross);
        $this->assertSame('760.00', $tridem->mediaGross);
    }

    public function test_derived_airings_helper(): void
    {
        $this->assertSame(20, SpotComponentProfileContract::derivedAirings(SpotComponentProfile::Tandem, 10));
        $this->assertSame(30, SpotComponentProfileContract::derivedAirings(SpotComponentProfile::Tridem, 10));
        $this->assertSame(0, SpotComponentProfileContract::derivedAirings(SpotComponentProfile::Tandem, 0));
    }

    public function test_tridem_exposes_canonical_sort_in_results(): void
    {
        $result = (new CalculationEngine)->calculatePosition($this->tridemAverage(), '0');

        $this->assertSame([1, 2, 3], array_column($result->components, 'sort'));
        $this->assertSame(['main_spot', 'reminder', 'reminder'], array_column($result->components, 'role'));
    }

    private function tandemAverage(): PositionInput
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
            totalSpotCount: 10,
            spotMethod: SpotCalculationMethod::Average,
            rows: [new PlanRowInput(8, DayGroup::MoFr, 0, '2.0000')],
            components: [
                new ComponentInput(SpotComponentRole::MainSpot, 'Hauptspot', 20, 1),
                new ComponentInput(SpotComponentRole::Reminder, 'Reminder', 10, 2),
            ],
            componentCalculationStrategy: ComponentCalculationStrategy::SharedTotalLength,
        );
    }

    private function tridemAverage(): PositionInput
    {
        return new PositionInput(
            inventoryId: 1,
            inventoryName: 'Radio Hamburg',
            positionKey: 'k',
            lengthSeconds: 40,
            surchargePercent: '0',
            positionDiscountPercent: '0',
            aePercent: '0',
            isDiscountable: true,
            isAeEligible: false,
            totalSpotCount: 10,
            spotMethod: SpotCalculationMethod::Average,
            rows: [new PlanRowInput(8, DayGroup::MoFr, 0, '2.0000')],
            components: [
                new ComponentInput(SpotComponentRole::MainSpot, 'Hauptspot', 20, 1),
                new ComponentInput(SpotComponentRole::Reminder, 'Reminder', 10, 2),
                new ComponentInput(SpotComponentRole::Reminder, 'Reminder', 10, 3),
            ],
            componentCalculationStrategy: ComponentCalculationStrategy::SharedTotalLength,
        );
    }
}
