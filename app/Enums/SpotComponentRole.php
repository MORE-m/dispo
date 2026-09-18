<?php

namespace App\Enums;

/**
 * BL-P4-02c / AT-04 / BL-P4-02e / SPT-012: Rollen von Spot-Komponenten.
 * Abbinder bleibt für spätere Teilblöcke vorgesehen.
 */
enum SpotComponentRole: string
{
    case MainSpot = 'main_spot';
    case Allonge = 'allonge';
    case Reminder = 'reminder';

    public function label(): string
    {
        return match ($this) {
            self::MainSpot => 'Hauptspot',
            self::Allonge => 'Allonge',
            self::Reminder => 'Reminder',
        };
    }

    /**
     * Freigegebene Rollen ohne erzwungenes Profil (optional Hauptspot/Allonge).
     *
     * @return list<self>
     */
    public static function allowedForOptionalAllonge(): array
    {
        return [self::MainSpot, self::Allonge];
    }

    /**
     * @deprecated Use allowedForOptionalAllonge() or profile contract.
     *
     * @return list<self>
     */
    public static function allowedInBlP402c(): array
    {
        return self::allowedForOptionalAllonge();
    }
}
