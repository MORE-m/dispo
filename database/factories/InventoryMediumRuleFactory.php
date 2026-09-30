<?php

namespace Database\Factories;

use App\Models\AdvertisingMedium;
use App\Models\Inventory;
use App\Models\InventoryMediumRule;
use App\Support\InventoryMediumRule\InventoryMediumRuleOperativeContract;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<InventoryMediumRule>
 *
 * TEST-FIXTURE: booking/planning Defaults sind keine Produktivmatrix.
 */
class InventoryMediumRuleFactory extends Factory
{
    public function definition(): array
    {
        $fixture = InventoryMediumRuleOperativeContract::testFixtureDefaults();

        return [
            'inventory_id' => Inventory::factory(),
            'advertising_medium_id' => AdvertisingMedium::factory(),
            'is_active' => true,
            'booking_code' => $fixture['booking_code'],
            'planning_responsibility_key' => $fixture['planning_responsibility_key'],
            'hint_text' => $fixture['hint_text'],
            'sort' => 0,
            'default_length_seconds' => 30,
            'surcharge_percent' => 0,
            'is_discountable' => true,
            'is_ae_eligible' => true,
            'component_calculation_strategy' => 'shared_total_length',
            'lock_version' => 1,
        ];
    }

    /**
     * Unvollständige Regel (nur für Negativtests / Legacy-Sim).
     */
    public function incomplete(): static
    {
        return $this->state(fn (): array => [
            'booking_code' => null,
            'planning_responsibility_key' => null,
            'hint_text' => null,
        ]);
    }

    public function mustNotPlan(): static
    {
        return $this->state(fn (): array => [
            'planning_responsibility_key' => InventoryMediumRuleOperativeContract::PLANNING_MUST_NOT_PLAN,
        ]);
    }
}
