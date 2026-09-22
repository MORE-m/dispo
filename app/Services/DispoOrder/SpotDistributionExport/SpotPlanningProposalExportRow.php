<?php

namespace App\Services\DispoOrder\SpotDistributionExport;

/**
 * Eine Average-Exportzeile = ein gespeicherter Zeitraum aus time_ranges_snapshot.
 */
final readonly class SpotPlanningProposalExportRow
{
    public const string PLANNING_STATUS = 'Vorschlag';

    public function __construct(
        public string $dispoOrderNumber,
        public string $customerName,
        public string $positionLabel,
        public int $positionSort,
        public int $positionId,
        public string $inventoryName,
        public string $advertisingMediumName,
        public string $periodFromDisplay,
        public string $periodToDisplay,
        public string $dayGroupLabel,
        public string $hourConstraintLabel,
        public int $quantity,
        public string $quantityUnit,
        public int $totalLengthSeconds,
        public string $componentsLabel,
        public int $componentAirings,
        public string $planningStatus = self::PLANNING_STATUS,
    ) {}

    /**
     * @return list<string|int>
     */
    public function values(): array
    {
        return [
            $this->dispoOrderNumber,
            $this->customerName,
            $this->positionLabel,
            $this->inventoryName,
            $this->advertisingMediumName,
            $this->periodFromDisplay,
            $this->periodToDisplay,
            $this->dayGroupLabel,
            $this->hourConstraintLabel,
            $this->quantity,
            $this->quantityUnit,
            $this->totalLengthSeconds,
            $this->componentsLabel,
            $this->componentAirings,
            $this->planningStatus,
        ];
    }
}
