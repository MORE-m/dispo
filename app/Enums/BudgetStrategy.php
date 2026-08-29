<?php

namespace App\Enums;

enum BudgetStrategy: string
{
    case EqualBudget = 'equal_budget';
    case MaximizeSpots = 'maximize_spots';

    public function label(): string
    {
        return match ($this) {
            self::EqualBudget => 'Budget je Sender gleich verteilen',
            self::MaximizeSpots => 'Spotanzahl maximieren',
        };
    }
}
