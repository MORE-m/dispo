<?php

namespace App\Enums;

/**
 * Eingefrorener Ableitungsstatus des Dispo-Kampagnenzeitraums (DSP-DCP-001).
 */
enum DerivedCampaignPeriodStatus: string
{
    case Complete = 'complete';
    case Partial = 'partial';
    case Open = 'open';
    case Legacy = 'legacy';

    public function label(): string
    {
        return match ($this) {
            self::Complete => 'Vollständig',
            self::Partial => 'Teilweise',
            self::Open => 'Offen',
            self::Legacy => 'Historischer Auftrag – nicht abgeleitet',
        };
    }
}
