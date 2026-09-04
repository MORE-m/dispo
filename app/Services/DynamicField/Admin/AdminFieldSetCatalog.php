<?php

namespace App\Services\DynamicField\Admin;

/**
 * DF-3.1: nur die beiden Kern-Feldsets sind administrierbar.
 */
final class AdminFieldSetCatalog
{
    public const CALCULATION_CORE = 'system_calculation_core';

    public const DISPO_ORDER_CORE = 'system_dispo_order_core';

    /**
     * @return list<string>
     */
    public static function allowedKeys(): array
    {
        return [
            self::CALCULATION_CORE,
            self::DISPO_ORDER_CORE,
        ];
    }

    public static function isAllowed(string $key): bool
    {
        return in_array($key, self::allowedKeys(), true);
    }
}
