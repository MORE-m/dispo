<?php

namespace App\Services\Calculation;

use App\Enums\ComponentCalculationStrategy;
use App\Enums\PricingSettlementMode;
use App\Enums\SpotCalculationMethod;
use App\Support\Calculation\EngineProfileRegistry;

final readonly class PositionInput
{
    /**
     * @param  list<PlanRowInput>  $rows
     * @param  list<TimeRangeInput>  $timeRanges
     * @param  list<PlannerEntryInput>  $plannerEntries
     * @param  list<DiscountInput>  $positionDiscounts
     * @param  list<ComponentInput>  $components
     * @param  list<ProductionLineInput>  $productionLines
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
        /** BL-P5-01a: swf_trailer rechnet ohne Spotlängenindex (Index fest 100). */
        public ?string $engineProfileKey = null,
        /** BL-P5-02a: gepinnte Produktionszeilen (nur Spot Classic × Durchschnitt). */
        public array $productionLines = [],
        /** BL-P5-02a: AE-Satz für AE-fähige Produktionszeilen (0 ohne Auftrags-AE). */
        public string $productionAePercent = '0',
    ) {}

    public function hasProductionLines(): bool
    {
        return $this->productionLines !== [];
    }

    public function isSwfTrailer(): bool
    {
        return $this->engineProfileKey === EngineProfileRegistry::PROFILE_SWF_TRAILER;
    }

    public function hasComponents(): bool
    {
        return $this->components !== [];
    }
}
