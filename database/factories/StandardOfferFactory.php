<?php

namespace Database\Factories;

use App\Models\StandardOffer;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<StandardOffer>
 */
class StandardOfferFactory extends Factory
{
    protected $model = StandardOffer::class;

    public function definition(): array
    {
        $year = (int) now('Europe/Berlin')->format('Y');
        $seq = fake()->unique()->numberBetween(1, 99999);

        return [
            'number' => sprintf('SA-%d-%05d', $year, $seq),
            'number_year' => $year,
            'number_seq' => $seq,
            'title' => fake()->sentence(3),
            'lock_version' => 1,
            'created_by' => User::factory(),
        ];
    }
}
