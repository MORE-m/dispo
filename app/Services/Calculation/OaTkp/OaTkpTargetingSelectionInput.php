<?php

namespace App\Services\Calculation\OaTkp;

/**
 * PO-CALC-TKP-1B: Targeting-Auswahl (Aufschlag absolut TKP-EUR).
 */
final readonly class OaTkpTargetingSelectionInput
{
    public function __construct(
        public string $code,
        public string $name,
        public string $category,
        public string $tkpSurcharge,
        public int $revisionNumber = 1,
        public ?int $oaTargetingDefinitionId = null,
        public int $sort = 0,
    ) {}
}
