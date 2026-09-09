<?php

namespace App\Enums;

enum ConfigurationSnapshotSource: string
{
    case SeedActive = 'seed_active';
    case LegacyBackfill = 'legacy_backfill';
    case DispoOrderCreate = 'dispo_order_create';
    case DispoOrderLegacyBackfill = 'dispo_order_legacy_backfill';
    /** DF-3.3a2β / VER-003: positionsbezogener Effektiv-Snapshot (Calc). */
    case CalculationPositionEffective = 'calculation_position_effective';
    /** DF-3.3a2β / VER-003: positionsbezogener Effektiv-Snapshot (Dispo). */
    case DispoOrderPositionEffective = 'dispo_order_position_effective';
}
