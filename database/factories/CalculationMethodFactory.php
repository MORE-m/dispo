<?php

namespace Database\Factories;

use App\Models\CalculationMethod;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CalculationMethod>
 *
 * Nur für Tests. Produktive Keys entstehen ausschließlich in der ADV-001c1-Migration.
 */
class CalculationMethodFactory extends Factory
{
    protected $model = CalculationMethod::class;

    public function definition(): array
    {
        return [
            'key' => 'test_'.fake()->unique()->lexify('????????'),
            'name' => fake()->unique()->words(2, true),
            'help_text' => null,
            'sort' => 100,
            'is_active' => true,
            'lock_version' => 1,
        ];
    }
}
