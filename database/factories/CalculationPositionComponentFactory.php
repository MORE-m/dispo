<?php

namespace Database\Factories;

use App\Enums\SpotComponentRole;
use App\Models\CalculationPosition;
use App\Models\CalculationPositionComponent;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CalculationPositionComponent>
 */
class CalculationPositionComponentFactory extends Factory
{
    protected $model = CalculationPositionComponent::class;

    public function definition(): array
    {
        return [
            'calculation_position_id' => CalculationPosition::factory(),
            'role' => SpotComponentRole::MainSpot,
            'label' => SpotComponentRole::MainSpot->label(),
            'length_seconds' => 30,
            'sort' => 0,
            'length_index' => null,
            'media_gross' => null,
        ];
    }
}
