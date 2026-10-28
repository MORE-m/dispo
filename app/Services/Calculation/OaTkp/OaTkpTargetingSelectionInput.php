<?php

namespace App\Services\Calculation\OaTkp;

/**
 * PO-CALC-TKP-1B / 1D4c-4b: Targeting-Auswahl.
 *
 * `tkpSurcharge` = Listen-Aufschlag (Final-TKP).
 * `minimumTkpSurcharge` = Mindest-Aufschlag (effektive Mindestschwelle).
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
        public string $minimumTkpSurcharge = '0.0000',
    ) {}
}
