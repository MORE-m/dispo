<?php

namespace App\Enums;

enum ConfigurationSnapshotSource: string
{
    case SeedActive = 'seed_active';
    case LegacyBackfill = 'legacy_backfill';
    case DispoOrderCreate = 'dispo_order_create';
    case DispoOrderLegacyBackfill = 'dispo_order_legacy_backfill';
}
