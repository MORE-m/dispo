<?php

namespace Database\Factories;

use App\Enums\CalculationKind;
use App\Models\AdvertisingMedium;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AdvertisingMedium>
 */
class AdvertisingMediumFactory extends Factory
{
    public function definition(): array
    {
        return [
            'name' => 'Spot Classic',
            'code' => 'spot_classic',
            'kind' => CalculationKind::SpotClassic,
            'default_length_seconds' => 30,
            'is_discountable' => true,
            'is_ae_eligible' => true,
            'is_active' => true,
        ];
    }
}
