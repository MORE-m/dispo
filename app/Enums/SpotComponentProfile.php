<?php

namespace App\Enums;

/**
 * BL-P4-02e / SPT-012: stabiles Komponentenprofil am Werbemittel (nicht namensbasiert).
 * null am Medium = optionales Hauptspot/Allonge-Verhalten (BL-P4-02c).
 */
enum SpotComponentProfile: string
{
    case Tandem = 'tandem';
    case Tridem = 'tridem';

    public function label(): string
    {
        return match ($this) {
            self::Tandem => 'Tandem / Reminder',
            self::Tridem => 'Tridem',
        };
    }

    public function unitLabel(): string
    {
        return match ($this) {
            self::Tandem => 'Tandem-Einheiten',
            self::Tridem => 'Tridem-Einheiten',
        };
    }

    public function unitCount(): int
    {
        return match ($this) {
            self::Tandem => 2,
            self::Tridem => 3,
        };
    }

    public function reminderCount(): int
    {
        return match ($this) {
            self::Tandem => 1,
            self::Tridem => 2,
        };
    }

    /**
     * Erzwungene Strategie (SPT-012): immer gemeinsame Gesamtlänge.
     */
    public function requiredStrategy(): ComponentCalculationStrategy
    {
        return ComponentCalculationStrategy::SharedTotalLength;
    }

    public function isForced(): bool
    {
        return true;
    }
}
