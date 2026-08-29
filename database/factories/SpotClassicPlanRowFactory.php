<?php

namespace Database\Factories;

use App\Enums\DayGroup;
use App\Models\CalculationPosition;
use App\Models\SpotClassicPlanRow;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SpotClassicPlanRow>
 */
class SpotClassicPlanRowFactory extends Factory
{
    public function definition(): array
    {
        return [
            'calculation_position_id' => CalculationPosition::factory(),
            'hour' => 8,
            'day_group' => DayGroup::MoFr,
            'spot_count' => 1,
            'second_price' => '1.0000',
            'line_gross' => '0.00',
        ];
    }
}
