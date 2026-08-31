<?php

namespace App\Enums;

enum BudgetProposalStatus: string
{
    case Current = 'current';
    case Stale = 'stale';
    case Manual = 'manual';

    public function label(): string
    {
        return match ($this) {
            self::Current => 'Aktuell',
            self::Stale => 'Neuoptimierung erforderlich',
            self::Manual => 'Manuell angepasst',
        };
    }
}
