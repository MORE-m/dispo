<?php

namespace App\Support\Advertising;

use App\Models\AdvertisingMediumCalculationMethod;
use App\Models\CalculationMethod;
use Illuminate\Support\Collection;

/**
 * ADV-001c3c: unveränderlicher Evaluationskontext für Override-Buchbarkeit.
 *
 * Ermöglicht Simulation ohne DB-Mutation und ohne setRelation-Tricks.
 * Inherit-Medien nutzen diesen Snapshot nicht.
 */
final readonly class MediumMethodCatalogSnapshot
{
    /**
     * @param  Collection<int, AdvertisingMediumCalculationMethod>  $assignments
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
