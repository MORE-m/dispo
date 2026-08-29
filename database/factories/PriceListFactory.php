<?php

namespace Database\Factories;

use App\Enums\PriceListStatus;
use App\Models\Inventory;
use App\Models\PriceList;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PriceList>
 */
class PriceListFactory extends Factory
{
    public function definition(): array
    {
        return [
            'inventory_id' => Inventory::factory(),
            'name' => 'Preisliste',
            'version' => '2026-1',
            'status' => PriceListStatus::Active,
            'valid_from' => '2026-01-01',
        ];
    }
}
