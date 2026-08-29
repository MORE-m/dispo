<?php

namespace App\Enums;

/**
 * Kalkulationsart je Spot-Position (CAL-002, SPT-001–SPT-004).
 * Unabhängig vom Planungsweg (PlanningMode: manual/budget).
 */
enum SpotCalculationMethod: string
{
    case Average = 'average';
    case Calendar = 'calendar';
    case FixedPrice = 'fixed_price';

    public function label(): string
    {
        return match ($this) {
            self::Average => 'Durchschnitt',
            self::Calendar => 'Kalenderplaner',
            self::FixedPrice => 'Festpreis',
        };
    }

    public function isImplementedInGateB(): bool
    {
        return $this === self::Average;
    }
}
