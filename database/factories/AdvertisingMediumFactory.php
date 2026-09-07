<?php

namespace Database\Factories;

use App\Enums\CalculationKind;
use App\Models\AdvertisingCategory;
use App\Models\AdvertisingMedium;
use App\Support\Advertising\CanonicalAdvertisingCategories;
use Illuminate\Database\Eloquent\Factories\Factory;
use RuntimeException;

/**
 * @extends Factory<AdvertisingMedium>
 */
class AdvertisingMediumFactory extends Factory
{
    public function definition(): array
    {
        return [
            'category_id' => fn (): int => $this->spotsCategoryId(),
            'name' => 'Spot Classic',
            'code' => 'spot_classic',
            'kind' => CalculationKind::SpotClassic,
            'default_length_seconds' => 30,
            'is_discountable' => true,
            'is_ae_eligible' => true,
            'is_active' => true,
        ];
    }

    private function spotsCategoryId(): int
    {
        $id = AdvertisingCategory::query()
            ->where('key', CanonicalAdvertisingCategories::SPOTS)
            ->value('id');

        if ($id === null) {
            throw new RuntimeException(
                'AdvertisingMediumFactory: kanonische Kategorie „spots“ fehlt (ADV-001a Migration/Seed).',
            );
        }

        return (int) $id;
    }
}
