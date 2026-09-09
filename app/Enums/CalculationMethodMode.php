<?php

namespace App\Enums;

/**
 * ADV-001c1: Vererbungsmodus für Berechnungsmethoden am Werbemittel.
 * inherit = ausschließlich Kategorie-Zuordnungen; override = ausschließlich Medium-Zuordnungen.
 */
enum CalculationMethodMode: string
{
    case Inherit = 'inherit';
    case Override = 'override';
}
