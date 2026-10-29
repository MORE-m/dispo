<?php

namespace App\Support\OnlineAudio;

use App\Models\InventoryMediumRule;
use App\Services\OnlineAudio\Import\OaTkpStammdatenImportException;
use Illuminate\Support\Collection;

/**
 * PO-CALC-TKP-1D4c-4c: kanonische Medium×Inventar-Paare für OA-TKP-Aktivierung.
 *
 * SoT = aktive {@see InventoryMediumRule}-Zeilen der OA-Kernmedien.
 * Kein CSV-/Dateifallback: ohne operative Zuordnungen ist Aktivierung fail-closed.
 */
final class OaTkpActiveMediumInventoryPairs
{
    /**
     * @return list<array{
     *     medium_code: string,
     *     inventory_code: string,
     *     advertising_medium_id: int,
     *     inventory_id: int
     * }>
     */
    public static function all(): array
    {
        /** @var Collection<int, InventoryMediumRule> $rules */
        $rules = InventoryMediumRule::query()
            ->where('is_active', true)
            ->whereHas('advertisingMedium', function ($query): void {
                $query->whereIn('code', OaTkpCoreMedia::codes());
            })
            ->with(['advertisingMedium:id,code', 'inventory:id,code'])
            ->orderBy('advertising_medium_id')
            ->orderBy('inventory_id')
            ->get();

        $pairs = [];
        foreach ($rules as $rule) {
            $medium = $rule->advertisingMedium;
            $inventory = $rule->inventory;
            if ($medium === null || $inventory === null) {
                continue;
            }
            $mediumCode = (string) $medium->code;
            if ($mediumCode === 'native_ad' || ! OaTkpCoreMedia::contains($mediumCode)) {
                continue;
            }
            $pairs[] = [
                'medium_code' => $mediumCode,
                'inventory_code' => (string) $inventory->code,
                'advertising_medium_id' => (int) $medium->id,
                'inventory_id' => (int) $inventory->id,
            ];
        }

        return $pairs;
    }

    /**
     * @return array<string, true> Keys = "{medium_code}|{inventory_code}"
     */
    public static function pairKeySet(): array
    {
        $set = [];
        foreach (self::all() as $pair) {
            $set[$pair['medium_code'].'|'.$pair['inventory_code']] = true;
        }

        return $set;
    }

    /**
     * Für Aktivierung: leere operative Zuordnungen sind unzulässig (kein Datei-Fallback).
     *
     * @return array<string, true>
     */
    public static function requiredPairKeySetForActivation(): array
    {
        $set = self::pairKeySet();
        if ($set === []) {
            throw new OaTkpStammdatenImportException(
                'Aktivierung ungültig.',
                [
                    'Keine aktiven OA-Medium×Inventar-Zuordnungen vorhanden.'
                    .' Aktivierung ist ohne operative InventoryMediumRules nicht zulässig.',
                ],
            );
        }

        return $set;
    }
}
