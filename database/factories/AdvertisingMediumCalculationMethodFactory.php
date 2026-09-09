<?php

namespace Database\Factories;

use App\Models\AdvertisingMedium;
use App\Models\AdvertisingMediumCalculationMethod;
use App\Models\CalculationMethod;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AdvertisingMediumCalculationMethod>
 */
class AdvertisingMediumCalculationMethodFactory extends Factory
{
    protected $model = AdvertisingMediumCalculationMethod::class;

    public function definition(): array
    {
        return [
            'advertising_medium_id' => AdvertisingMedium::factory(),
            'calculation_method_id' => CalculationMethod::factory(),
            'engine_profile_key' => null,
            'is_active' => true,
            'sort' => 0,
            'lock_version' => 1,
        ];
    }
}
