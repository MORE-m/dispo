<?php

namespace Database\Factories;

use App\Enums\DiscountType;
use App\Models\Calculation;
use App\Models\CalculationOrderDiscount;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CalculationOrderDiscount>
 */
class CalculationOrderDiscountFactory extends Factory
{
    public function definition(): array
    {
        return [
            'calculation_id' => Calculation::factory(),
            'type' => DiscountType::Quantity,
            'custom_label' => null,
            'percent' => '10.0000',
            'sort' => 0,
        ];
    }
}
