<?php

namespace App\Support\Advertising;

/**
 * ADV-001c3a: Ergebnis der Medium-Live-Buchbarkeit für neue Kalkulationspositionen.
 *
 * Inventar-/Preislisten-Kombinationen sind bewusst nicht Teil dieses Vertrags.
 */
final readonly class AdvertisingMediumLiveBookabilityResult
{
    public function __construct(
        public bool $isBookableForNewPositions,
        public ?string $unbookableReason,
        public ?string $engineProfileKey = null,
        public ?string $calculationMethodKey = null,
        public ?string $calculationMethodName = null,
        public ?string $algorithmVersion = null,
    ) {
        if ($this->isBookableForNewPositions && $this->unbookableReason !== null) {
            throw new \InvalidArgumentException(
                'Buchbares Medium darf keine unbookable_reason tragen.',
            );
        }

        if (! $this->isBookableForNewPositions && ($this->unbookableReason === null || trim($this->unbookableReason) === '')) {
            throw new \InvalidArgumentException(
                'Nicht buchbares Medium benötigt eine deutsche unbookable_reason.',
            );
        }
    }

    /**
     * @return array{is_bookable_for_new_positions: bool, unbookable_reason: string|null}
     */
    public function toPayload(): array
    {
        return [
            'is_bookable_for_new_positions' => $this->isBookableForNewPositions,
            'unbookable_reason' => $this->unbookableReason,
        ];
    }
}
