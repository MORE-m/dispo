<?php

namespace Database\Factories;

use App\Enums\DiscountType;
use App\Models\CalculationPosition;
use App\Models\CalculationPositionDiscount;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CalculationPositionDiscount>
 */
class CalculationPositionDiscountFactory extends Factory
{
    public function definition(): array
    {
        return [
            'calculation_position_id' => CalculationPosition::factory(),
            'type' => DiscountType::Quantity,
            'custom_label' => null,
            'percent' => '10.0000',
            'sort' => 0,
        ];
    }
}
