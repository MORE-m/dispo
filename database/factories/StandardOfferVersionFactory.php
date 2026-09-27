<?php

namespace Database\Factories;

use App\Enums\StandardOfferVersionStatus;
use App\Models\StandardOffer;
use App\Models\StandardOfferVersion;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<StandardOfferVersion>
 */
class StandardOfferVersionFactory extends Factory
{
    protected $model = StandardOfferVersion::class;

    public function definition(): array
    {
        return [
            'standard_offer_id' => StandardOffer::factory(),
            'version_number' => 1,
            'status' => StandardOfferVersionStatus::Draft,
            'title' => fake()->sentence(3),
            'author_id' => User::factory(),
            'draft_payload' => [
                'planning_mode' => 'manual',
                'order_discount_percent' => '0',
                'ae_enabled' => false,
                'positions' => [],
            ],
            'lock_version' => 1,
        ];
    }

    public function published(): static
    {
        return $this->state(fn (): array => [
            'status' => StandardOfferVersionStatus::Published,
            'published_at' => now(),
        ]);
    }

    public function archived(): static
    {
        return $this->state(fn (): array => [
            'status' => StandardOfferVersionStatus::Archived,
            'archived_at' => now(),
        ]);
    }
}
