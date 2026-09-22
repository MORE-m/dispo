<?php

namespace App\Services\DispoOrder\SpotDistributionExport;

/**
 * Eine Exportzeile = eine belegte Calendar-Zelle (Disposition, Datum, Stunde).
 */
final readonly class SpotDistributionExportRow
{
    public function __construct(
        public string $dispoOrderNumber,
        public string $customerName,
        public string $positionLabel,
        public int $positionSort,
        public int $positionId,
        public string $inventoryName,
        public string $advertisingMediumName,
        public string $dateIso,
        public string $weekdayLabel,
        public int $hour,
        public string $hourLabel,
        public string $dayGroupLabel,
        public int $quantity,
        public string $quantityUnit,
        public int $totalLengthSeconds,
        public string $componentsLabel,
        public int $componentAirings,
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
            $this->dateIso,
            $this->weekdayLabel,
            $this->hourLabel,
            $this->dayGroupLabel,
            $this->quantity,
            $this->quantityUnit,
            $this->totalLengthSeconds,
            $this->componentsLabel,
            $this->componentAirings,
        ];
    }
}
