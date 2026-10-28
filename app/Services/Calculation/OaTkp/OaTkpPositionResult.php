<?php

namespace App\Services\Calculation\OaTkp;

use App\Services\Calculation\DiscountInput;

/**
 * PO-CALC-TKP-1B / 1D4c-4b: Ergebnis einer Normal-TKP-Position inkl. Commercial.
 *
 * `minimumTkp` = effektive Mindestschwelle (Grundminimum + Σ Mindest-Aufschläge).
 * `priceListMinimumTkp` = Preislisten-Grundminimum.
 *
 * @param  list<OaTkpPlatformAllocationInput>  $platformAllocations
 * @param  list<OaTkpTargetingSelectionInput>  $targetings
 * @param  list<DiscountInput>  $appliedPositionDiscounts
 * @param  list<DiscountInput>  $appliedOrderDiscounts
 */
final readonly class OaTkpPositionResult
{
    /**
     * @param  list<OaTkpPlatformAllocationInput>  $platformAllocations
     * @param  list<OaTkpTargetingSelectionInput>  $targetings
     * @param  list<DiscountInput>  $appliedPositionDiscounts
     * @param  list<DiscountInput>  $appliedOrderDiscounts
     */
    public function __construct(
        public int $impressions,
        public string $basisTkp,
        public string $listTkp,
        public string $minimumTkp,
        public string $targetingSurchargeTotal,
        public string $finalTkp,
        public bool $belowMinimumTkp,
        public string $mediaGross,
        public string $positionDiscountAmount,
        public string $afterPositionDiscount,
        public string $orderDiscountAmount,
        public string $afterOrderDiscount,
        public string $aeAmount,
        public string $nnInvest,
        public string $effectiveDiscountPercent,
        public ?string $effectivePayFactorPercent,
        public int $oaTkpPriceListId,
        public string $oaTkpPriceListVersion,
        public int $oaTkpPriceListYear,
        public int $oaTkpPriceListRevision,
        public array $platformAllocations,
        public array $targetings,
        public int $inventoryId,
        public string $inventoryCode,
        public string $inventoryName,
        public int $advertisingMediumId,
        public string $advertisingMediumCode,
        public string $advertisingMediumName,
        public string $aePercent,
        public bool $isDiscountable,
        public bool $isAeEligible,
        public array $appliedPositionDiscounts = [],
        public array $appliedOrderDiscounts = [],
        public string $effectivePositionDiscountPercent = '0',
        public string $effectiveOrderDiscountPercent = '0',
        public ?int $advertisingCategoryId = null,
        public ?string $advertisingCategoryKey = null,
        public ?string $advertisingCategoryName = null,
        public ?string $positionKey = null,
        public string $priceListMinimumTkp = '0.0000',
        public string $targetingMinimumSurchargeTotal = '0.0000',
    ) {}
}
