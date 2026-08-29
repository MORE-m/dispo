<?php

namespace Database\Factories;

use App\Enums\InventoryType;
use App\Models\Inventory;
use App\Models\Organization;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Inventory>
 */
class InventoryFactory extends Factory
{
    public function definition(): array
    {
        return [
            'organization_id' => Organization::factory(),
            'name' => fake()->unique()->company(),
            'code' => strtoupper(fake()->unique()->lexify('???')),
            'type' => InventoryType::Sender,
            'is_active' => true,
            'sort' => 0,
            'logo_path' => null,
        ];
    }
}
