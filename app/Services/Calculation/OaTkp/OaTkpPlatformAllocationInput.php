<?php

namespace App\Services\Calculation\OaTkp;

/**
 * PO-CALC-TKP-1B: Plattformanteil (nur Mengen; kein Preis, FD-OA-PLAT offen).
 */
final readonly class OaTkpPlatformAllocationInput
{
    public function __construct(
        public string $platformCode,
        public string $platformName,
        public int $impressions,
        public ?int $oaPlatformId = null,
        public int $sort = 0,
    ) {}
}
