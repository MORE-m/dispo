<?php

namespace App\Enums;

enum DispoOrderApprovalKind: string
{
    case Regular = 'regular';
    case Special = 'special';

    public function label(): string
    {
        return match ($this) {
            self::Regular => 'Reguläre Freigabe',
            self::Special => 'Sonderfreigabe',
        };
    }
}
