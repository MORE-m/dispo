<?php

namespace App\Enums;

enum ConfigurationSnapshotSource: string
{
    case SeedActive = 'seed_active';
    case LegacyBackfill = 'legacy_backfill';
}
