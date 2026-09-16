<?php

namespace Database\Factories;

use App\Enums\DayGroup;
use App\Models\CalculationPosition;
use App\Models\CalculationPositionPlannerEntry;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CalculationPositionPlannerEntry>
 */
class CalculationPositionPlannerEntryFactory extends Factory
{
    public function definition(): array
    {
        return [
            'calculation_position_id' => CalculationPosition::factory(),
            'date' => now()->toDateString(),
            'hour' => 8,
            'spot_count' => 5,
            'day_group' => DayGroup::MoFr,
            'second_price' => '1.0000',
            'line_gross' => '150.00',
        ];
    }
}
