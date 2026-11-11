<?php

namespace App\Services\Calculation\OaTkp;

/**
 * PO-CALC-TKP-OA003-1: Ergebnis des isolierten OA-Festpreis-Settlement-Kerns.
 *
 * Noch nicht produktiv in Writer/Preview verdrahtet.
 *
 * @param  list<OaTkpTargetingSelectionInput>  $targetings
 */
final readonly class OaTkpFixedPriceSettlementResult
{
    /**
     * @param  list<OaTkpTargetingSelectionInput>  $targetings
     */
    public function __construct(
        public int $impressions,
        public string $fixedPriceNn,
        public string $effectiveTkp,
        public string $basisTkp,
        public string $finalTkp,
        public string $listTkp,
        public string $priceListMinimumTkp,
        public string $targetingMinimumSurchargeTotal,
        public string $effectiveMinimumTkp,
        public string $targetingSurchargeTotal,
        public string $referenceTkp,
        public string $mediaGross,
        public string $positionDiscountAmount,
        public string $afterPositionDiscount,
        public string $orderDiscountAmount,
        public string $afterOrderDiscount,
        public string $aeAmount,
        public string $nnInvest,
        public string $effectivePayFactorPercent,
        public string $effectiveTotalDiscountPercent,
        public string $effectiveDiscountBeforeAePercent,
        public bool $belowMinimumTkp,
        public string $aePercent,
        public bool $isAeEligible,
        public array $targetings = [],
    ) {}
}
