<?php

namespace App\Services\Calculation;

use App\Enums\SpotCalculationMethod;

final readonly class PositionInput
{
    /**
     * @param  list<PlanRowInput>  $rows
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
    ) {}
}
