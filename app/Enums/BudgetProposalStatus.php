<?php

namespace App\Enums;

enum BudgetProposalStatus: string
{
    case Draft = 'draft';
    case Current = 'current';
    case Stale = 'stale';
    case Applied = 'applied';
    case Manual = 'manual';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Entwurf',
            self::Current => 'Aktuell',
            self::Stale => 'Neuoptimierung erforderlich',
            self::Applied => 'Übernommen',
            self::Manual => 'Manuell angepasst',
        };
    }
}
