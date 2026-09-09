<?php

namespace Database\Factories;

use App\Models\AdvertisingCategory;
use App\Models\AdvertisingCategoryCalculationMethod;
use App\Models\CalculationMethod;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AdvertisingCategoryCalculationMethod>
 */
class AdvertisingCategoryCalculationMethodFactory extends Factory
{
    protected $model = AdvertisingCategoryCalculationMethod::class;

    public function definition(): array
    {
        return [
            'advertising_category_id' => AdvertisingCategory::factory(),
            'calculation_method_id' => CalculationMethod::factory(),
            'engine_profile_key' => null,
            'is_active' => true,
            'sort' => 0,
            'lock_version' => 1,
        ];
    }
}
