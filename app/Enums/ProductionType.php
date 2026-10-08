<?php

namespace App\Enums;

/**
 * BL-P5-02a: Produktionsarten. Dieser Slice kennt nur Spotproduktion.
 */
enum ProductionType: string
{
    case SpotProduction = 'spot_production';

    public function label(): string
    {
        return match ($this) {
            self::SpotProduction => 'Spotproduktion',
        };
    }

    public function isSupportedInSlice(): bool
    {
        return match ($this) {
            self::SpotProduction => true,
        };
    }
}
