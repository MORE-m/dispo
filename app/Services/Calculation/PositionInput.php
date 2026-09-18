<?php

namespace App\Services\Calculation;

use App\Enums\ComponentCalculationStrategy;
use App\Enums\PricingSettlementMode;
use App\Enums\SpotCalculationMethod;

final readonly class PositionInput
{
    /**
     * @param  list<PlanRowInput>  $rows
     * @param  list<TimeRangeInput>  $timeRanges
     * @param  list<PlannerEntryInput>  $plannerEntries
     * @param  list<DiscountInput>  $positionDiscounts
     * @param  list<ComponentInput>  $components
     */
    public function __construct(
        public int $inventoryId,
        public string $inventoryName,
        public ?string $positionKey,
        public int $lengthSeconds,
        public string $surchargePercent,
        public string $positionDiscountPercent,
        public string $aePercent,
        public bool $isDiscountable,
        public bool $isAeEligible,
        public int $totalSpotCount,
        public SpotCalculationMethod $spotMethod,
        public array $rows,
        public ?int $lengthIndex = null,
        public array $timeRanges = [],
        public array $plannerEntries = [],
        public array $positionDiscounts = [],
        public bool $needsSpotRedistribution = false,
        public array $components = [],
        public ?ComponentCalculationStrategy $componentCalculationStrategy = null,
        public PricingSettlementMode $pricingSettlementMode = PricingSettlementMode::Normal,
        public ?string $fixedPriceNn = null,
    ) {}

    public function hasComponents(): bool
    {
        return $this->components !== [];
    }
}
