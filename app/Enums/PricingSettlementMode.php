<?php

namespace App\Enums;

/**
 * Preisabschluss je Spot-Position (BL-P4-02d).
 * Unabhängig von spot_method / calculation_method_key (average|calendar).
 */
enum PricingSettlementMode: string
{
    case Normal = 'normal';
    case FixedPrice = 'fixed_price';

    public function label(): string
    {
        return match ($this) {
            self::Normal => 'Normal',
            self::FixedPrice => 'Festpreis',
        };
    }
}
