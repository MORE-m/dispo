<?php

namespace App\Enums;

/**
 * BL-P4-02c / AT-04 / SPT-014: Berechnungsstrategie für Spot-Komponenten.
 */
enum ComponentCalculationStrategy: string
{
    case SharedTotalLength = 'shared_total_length';
    case Individual = 'individual';

    public function label(): string
    {
        return match ($this) {
            self::SharedTotalLength => 'Gemeinsame Gesamtlänge',
            self::Individual => 'Komponenten einzeln berechnen',
        };
    }
}
