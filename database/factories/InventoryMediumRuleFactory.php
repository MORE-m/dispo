<?php

namespace Database\Factories;

use App\Models\AdvertisingMedium;
use App\Models\Inventory;
use App\Models\InventoryMediumRule;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<InventoryMediumRule>
 */
class InventoryMediumRuleFactory extends Factory
{
    public function definition(): array
    {
        return [
            'inventory_id' => Inventory::factory(),
            'advertising_medium_id' => AdvertisingMedium::factory(),
            'is_active' => true,
            'default_length_seconds' => 30,
            'surcharge_percent' => 0,
            'is_discountable' => true,
            'is_ae_eligible' => true,
        ];
    }
}
