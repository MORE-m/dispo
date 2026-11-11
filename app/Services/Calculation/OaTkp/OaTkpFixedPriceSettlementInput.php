<?php

namespace App\Services\Calculation\OaTkp;

/**
 * PO-CALC-TKP-OA003-1: Input für isolierten OA-Festpreis-Settlement-Kern.
 *
 * Kein frei gewählter basis_tkp – der effektive TKP wird aus Festpreis und
 * Impressions abgeleitet. Forward-Rabatte sind vertraglich ausgeschlossen und
 * werden vom Settlement-Service nicht akzeptiert.
 *
 * @param  list<OaTkpTargetingSelectionInput>  $targetings
 */
final readonly class OaTkpFixedPriceSettlementInput
{
    /**
     * @param  list<OaTkpTargetingSelectionInput>  $targetings
     */
    public function __construct(
        public int $impressions,
        public string $fixedPriceNn,
        public string $listTkp,
        public string $priceListMinimumTkp,
        public array $targetings = [],
        public string $aePercent = '0',
        public bool $aeEnabled = false,
        public bool $isAeEligible = false,
    ) {}
}
