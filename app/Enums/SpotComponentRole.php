<?php

namespace App\Enums;

/**
 * BL-P4-02c / AT-04: Rollen von Spot-Komponenten.
 * Weitere Rollen (Abbinder, Reminder, …) sind für spätere Teilblöcke vorgesehen.
 */
enum SpotComponentRole: string
{
    case MainSpot = 'main_spot';
    case Allonge = 'allonge';

    public function label(): string
    {
        return match ($this) {
            self::MainSpot => 'Hauptspot',
            self::Allonge => 'Allonge',
        };
    }

    /**
     * In diesem Teilblock freigegebene Rollen.
     *
     * @return list<self>
     */
    public static function allowedInBlP402c(): array
    {
        return [self::MainSpot, self::Allonge];
    }
}
