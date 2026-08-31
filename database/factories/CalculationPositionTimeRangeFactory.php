<?php

namespace Database\Factories;

use App\Enums\DayGroup;
use App\Models\CalculationPosition;
use App\Models\CalculationPositionTimeRange;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CalculationPositionTimeRange>
 */
class CalculationPositionTimeRangeFactory extends Factory
{
    public function definition(): array
    {
        return [
            'calculation_position_id' => CalculationPosition::factory(),
            'start_hour' => 8,
            'end_hour_exclusive' => 9,
            'day_group' => DayGroup::MoFr,
            'spot_count' => 10,
            'sort' => 0,
            'average_second_price' => '1.0000',
            'range_gross' => '300.00',
        ];
    }
}
