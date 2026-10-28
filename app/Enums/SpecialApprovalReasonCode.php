<?php

namespace App\Enums;

enum SpecialApprovalReasonCode: string
{
    case PositionDiscountExceedsLimit = 'position_discount_exceeds_personal_limit';
    case OrderDiscountExceedsLimit = 'order_discount_exceeds_personal_limit';
    case EffectiveDiscountExceedsLimit = 'effective_discount_exceeds_personal_limit';
    /**
     * OA-TKP Under-Min (gleicher Reason-Code).
     * Ab D4c-4b: finaler TKP unter effektiver Mindestschwelle (FD-D4c-4b).
     * Legacy-Snapshots ohne price_list_minimum_tkp_snapshot: Basis vs. Mindest-Snapshot.
     */
    case OaTkpBasisBelowMinimum = 'oa_tkp_basis_below_minimum';
    case Unattributable = 'unattributable';

    public function label(): string
    {
        return match ($this) {
            self::PositionDiscountExceedsLimit => 'Positionsrabatt überschreitet persönliche Grenze',
            self::OrderDiscountExceedsLimit => 'Auftragsrabatt überschreitet persönliche Grenze',
            self::EffectiveDiscountExceedsLimit => 'Effektiver Rabatt überschreitet persönliche Grenze',
            self::OaTkpBasisBelowMinimum => 'Finaler TKP unterschreitet Mindest-TKP',
            self::Unattributable => 'Sonderfreigabegrund nicht eindeutig den ausgewählten Positionen zuordenbar',
        };
    }
}
