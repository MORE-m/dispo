<?php

namespace App\Enums;

enum BudgetStrategy: string
{
    case EqualBudget = 'equal_budget';
    case MaximizeSpots = 'maximize_spots';
    case EqualSpotCount = 'equal_spot_count';

    public function label(): string
    {
        return match ($this) {
            self::EqualBudget => 'Budget je Sender gleich verteilen',
            self::MaximizeSpots => 'Spotanzahl maximieren',
            self::EqualSpotCount => 'Gleiche Spotanzahl je Wunschsender',
        };
    }

    public function isLegacy(): bool
    {
        return $this === self::EqualBudget || $this === self::MaximizeSpots;
    }
}
