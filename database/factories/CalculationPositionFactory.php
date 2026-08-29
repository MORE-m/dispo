<?php

namespace Database\Factories;

use App\Enums\CalculationKind;
use App\Enums\SpotCalculationMethod;
use App\Models\AdvertisingMedium;
use App\Models\Calculation;
use App\Models\CalculationPosition;
use App\Models\Inventory;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CalculationPosition>
 */
class CalculationPositionFactory extends Factory
{
    public function definition(): array
    {
        return [
            'calculation_id' => Calculation::factory(),
            'inventory_id' => Inventory::factory(),
            'advertising_medium_id' => AdvertisingMedium::factory(),
            'kind' => CalculationKind::SpotClassic,
            'spot_method' => SpotCalculationMethod::Average,
            'length_seconds' => 30,
            'total_spot_count' => 0,
            'surcharge_percent' => 0,
            'position_discount_percent' => 0,
            'ae_percent' => 15,
            'is_discountable' => true,
            'is_ae_eligible' => true,
        ];
    }
}
