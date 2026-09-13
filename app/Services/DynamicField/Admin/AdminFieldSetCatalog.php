<?php

namespace App\Services\DynamicField\Admin;

use App\Models\FieldSet;
use App\Services\DynamicField\DispoConfigurationSnapshotComposer;

/**
 * DF-3.1 / DF-3.3-fs: Core-Keys und Administrierbarkeit von Feldsets.
 *
 * Core-Keys sind historische Literale (gleich Migrations-Backfill). Freie
 * Feldsets (is_system=false) sind administrierbar. Unbekannte is_system=true
 * Datensätze sind kein weiteres Core-Feldset.
 */
final class AdminFieldSetCatalog
{
    public const CALCULATION_CORE = 'system_calculation_core';

    public const DISPO_ORDER_CORE = 'system_dispo_order_core';

    /**
     * @return list<string>
     */
    public static function coreKeys(): array
    {
        return [
            self::CALCULATION_CORE,
            self::DISPO_ORDER_CORE,
        ];
    }

    /**
     * @return list<string>
     */
    public static function allowedKeys(): array
    {
        return self::coreKeys();
    }

    public static function isCoreKey(string $key): bool
    {
        return in_array($key, self::coreKeys(), true);
    }

    public static function isAllowed(string $key): bool
    {
        return self::isCoreKey($key);
    }

    public static function isAdministrable(FieldSet $fieldSet): bool
    {
        if (self::isCoreKey($fieldSet->key)) {
            return true;
        }

        if ($fieldSet->is_system) {
            return false;
        }

        return true;
    }

    /**
     * Admin-Regelkontext: nur im Kalkulations-Kern sind die später als
     * Calc-Origin eingefrorenen Keys Action-Ziele (Conditions bleiben lesbar).
     * Freie Feldsets werden nicht über denselben Feldnamen als Systemkontext behandelt.
     *
     * Quelle der Keys: DispoConfigurationSnapshotComposer::CALC_ORIGIN_KEYS.
     */
    public static function isCalcOriginActionTarget(string $fieldSetKey, string $fieldKey): bool
    {
        if ($fieldSetKey !== self::CALCULATION_CORE) {
            return false;
        }

        return in_array($fieldKey, DispoConfigurationSnapshotComposer::CALC_ORIGIN_KEYS, true);
    }
}
