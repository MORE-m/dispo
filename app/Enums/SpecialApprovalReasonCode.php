<?php

namespace App\Enums;

enum SpecialApprovalReasonCode: string
{
    case PositionDiscountExceedsLimit = 'position_discount_exceeds_personal_limit';
    case OrderDiscountExceedsLimit = 'order_discount_exceeds_personal_limit';
    case EffectiveDiscountExceedsLimit = 'effective_discount_exceeds_personal_limit';
    case Unattributable = 'unattributable';

    public function label(): string
    {
        return match ($this) {
            self::PositionDiscountExceedsLimit => 'Positionsrabatt überschreitet persönliche Grenze',
            self::OrderDiscountExceedsLimit => 'Auftragsrabatt überschreitet persönliche Grenze',
            self::EffectiveDiscountExceedsLimit => 'Effektiver Rabatt überschreitet persönliche Grenze',
            self::Unattributable => 'Sonderfreigabegrund nicht eindeutig den ausgewählten Positionen zuordenbar',
        };
    }
}
