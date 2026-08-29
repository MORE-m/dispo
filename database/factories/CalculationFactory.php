<?php

namespace Database\Factories;

use App\Enums\CalculationStatus;
use App\Enums\PlanningMode;
use App\Models\Calculation;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Calculation>
 */
class CalculationFactory extends Factory
{
    public function definition(): array
    {
        $year = 2026;

        return [
            'number' => 'K-'.$year.'-'.str_pad((string) fake()->unique()->numberBetween(1, 99999), 5, '0', STR_PAD_LEFT),
            'number_year' => $year,
            'number_seq' => fake()->unique()->numberBetween(1, 99999),
            'status' => CalculationStatus::Draft,
            'planning_mode' => PlanningMode::Manual,
            'advisor_id' => User::factory(),
            'order_discount_percent' => 0,
        ];
    }
}
