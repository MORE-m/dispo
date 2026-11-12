<?php

namespace App\Services\Calculation\FreePosition;

use App\Services\Calculation\DiscountInput;

/**
 * NATIVE-1: Eingabe für die generische Free-Position-Engine.
 *
 * `freePrice` ist der User-Ausgangspreis (nicht Client-`media_gross`).
 *
 * @param  list<DiscountInput>  $positionDiscounts
 */
final readonly class FreePositionInput
{
    /**
     * @param  list<DiscountInput>  $positionDiscounts
     */
    public function __construct(
        public string $freePrice,
        public array $positionDiscounts = [],
        public string $positionDiscountPercent = '0',
        public string $aePercent = '0',
        public bool $isDiscountable = true,
        public bool $isAeEligible = true,
        public ?string $positionKey = null,
        public ?string $inventoryName = null,
    ) {}
}
