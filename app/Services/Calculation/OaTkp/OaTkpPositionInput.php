<?php

namespace App\Services\Calculation\OaTkp;

use App\Services\Calculation\DiscountInput;

/**
 * PO-CALC-TKP-1B: Eingabe einer Normal-TKP-Position.
 *
 * @param  list<OaTkpPlatformAllocationInput>  $platformAllocations
 * @param  list<OaTkpTargetingSelectionInput>  $targetings
 * @param  list<DiscountInput>  $positionDiscounts
 */
final readonly class OaTkpPositionInput
{
    /**
     * @param  list<OaTkpPlatformAllocationInput>  $platformAllocations
     * @param  list<OaTkpTargetingSelectionInput>  $targetings
     * @param  list<DiscountInput>  $positionDiscounts
     */
    public function __construct(
        public int $inventoryId,
        public string $inventoryCode,
        public string $inventoryName,
        public int $advertisingMediumId,
        public string $advertisingMediumCode,
        public string $advertisingMediumName,
        public int $impressions,
        public string $basisTkp,
        public string $listTkp,
        public string $minimumTkp,
        public int $oaTkpPriceListId,
        public string $oaTkpPriceListVersion,
        public int $oaTkpPriceListYear,
        public int $oaTkpPriceListRevision,
        public array $platformAllocations = [],
        public array $targetings = [],
        public array $positionDiscounts = [],
        public string $positionDiscountPercent = '0',
        public string $aePercent = '15',
        public bool $isDiscountable = true,
        public bool $isAeEligible = true,
        public ?string $positionKey = null,
        public ?int $advertisingCategoryId = null,
        public ?string $advertisingCategoryKey = null,
        public ?string $advertisingCategoryName = null,
    ) {}
}
