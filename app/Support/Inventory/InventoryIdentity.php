<?php

namespace App\Support\Inventory;

use App\Enums\InventoryType;
use App\Models\CalculationPosition;

/**
 * Anzeige eingefrorener Inventaridentität an Kalkulationspositionen.
 * Live-Namen aus `inventories` nur als Fallback, falls der Freeze fehlt.
 */
final class InventoryIdentity
{
    public static function displayName(CalculationPosition $position): string
    {
        $frozen = trim((string) ($position->inventory_name ?? ''));
        if ($frozen !== '') {
            return $frozen;
        }

        $position->loadMissing('inventory');
        $live = trim((string) $position->inventory->name);

        return $live !== '' ? $live : 'Unbekannt';
    }

    public static function displayCode(CalculationPosition $position): ?string
    {
        $frozen = trim((string) ($position->inventory_code ?? ''));
        if ($frozen !== '') {
            return $frozen;
        }

        $position->loadMissing('inventory');
        $live = trim((string) $position->inventory->code);

        return $live !== '' ? $live : null;
    }

    public static function typeLabel(InventoryType $type): string
    {
        return match ($type) {
            InventoryType::Sender => 'Sender',
            InventoryType::Kombi => 'Kombi',
        };
    }
}
