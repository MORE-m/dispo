<?php

namespace Database\Factories;

use App\Enums\DayGroup;
use App\Models\PriceList;
use App\Models\PriceListItem;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PriceListItem>
 */
class PriceListItemFactory extends Factory
{
    public function definition(): array
    {
        return [
            'price_list_id' => PriceList::factory(),
            'hour' => 8,
            'day_group' => DayGroup::MoFr,
            'second_price' => '1.0000',
        ];
    }
}
