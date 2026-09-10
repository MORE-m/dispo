<?php

namespace App\Support\Advertising;

use App\Models\AdvertisingCategoryCalculationMethod;
use App\Models\CalculationMethod;
use Illuminate\Support\Collection;

/**
 * ADV-001c3b2: unveränderlicher Evaluationskontext für Inherit-Buchbarkeit.
 *
 * Ermöglicht Simulation ohne DB-Mutation und ohne setRelation-Tricks.
 * Override-Medien nutzen diesen Snapshot nicht.
 */
final readonly class CategoryMethodCatalogSnapshot
{
    /**
     * @param  Collection<int, AdvertisingCategoryCalculationMethod>  $assignments
     */
    public function __construct(
        public ?CalculationMethod $defaultCalculationMethod,
        public Collection $assignments,
    ) {}

    public function defaultCalculationMethodId(): ?int
    {
        $id = $this->defaultCalculationMethod?->id;

        return $id !== null ? (int) $id : null;
    }
}
