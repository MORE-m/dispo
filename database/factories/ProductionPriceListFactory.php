<?php

namespace Database\Factories;

use App\Enums\PriceListStatus;
use App\Enums\ProductionType;
use App\Models\Inventory;
use App\Models\ProductionPriceList;
use App\Support\PriceList\PriceListCalendar;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ProductionPriceList>
 */
class ProductionPriceListFactory extends Factory
{
    private static int $revisionSequence = 0;

    public function definition(): array
    {
        self::$revisionSequence++;

        return [
            'inventory_id' => Inventory::factory(),
            'production_type' => ProductionType::SpotProduction,
            'year' => PriceListCalendar::currentYear(),
            'name' => 'Spotproduktion',
            'version' => (string) self::$revisionSequence,
            'revision_number' => self::$revisionSequence,
            'lock_version' => 1,
            'status' => PriceListStatus::Active,
            'unit_price' => '500.00',
            'is_discountable' => false,
            'is_ae_eligible' => false,
            'published_at' => now(),
        ];
    }

    public function draft(): static
    {
        return $this->state(fn (): array => [
            'status' => PriceListStatus::Draft,
            'published_at' => null,
        ]);
    }

    public function archived(): static
    {
        return $this->state(fn (): array => [
            'status' => PriceListStatus::Archived,
            'archived_at' => now(),
        ]);
    }
}
