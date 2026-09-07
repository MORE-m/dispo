<?php

namespace Database\Factories;

use App\Models\AdvertisingCategory;
use App\Support\Advertising\CanonicalAdvertisingCategories;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AdvertisingCategory>
 */
class AdvertisingCategoryFactory extends Factory
{
    public function definition(): array
    {
        return [
            'key' => 'test_'.fake()->unique()->lexify('????????'),
            'name' => fake()->unique()->words(2, true),
            'is_active' => true,
            'sort' => 100,
        ];
    }

    public function spots(): static
    {
        return $this->state(fn (): array => [
            'key' => CanonicalAdvertisingCategories::SPOTS,
            'name' => 'Spots',
            'sort' => 10,
        ]);
    }
}
