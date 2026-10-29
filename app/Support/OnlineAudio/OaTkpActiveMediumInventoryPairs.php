<?php

namespace App\Support\OnlineAudio;

use App\Models\AdvertisingMedium;
use App\Models\Inventory;
use App\Models\InventoryMediumRule;
use Illuminate\Support\Collection;

/**
 * PO-CALC-TKP-1D4c-4c: aktive Medium×Inventar-Paare für OA-TKP-Kernmedien.
 *
 * Primär: operative InventoryMediumRules (typisch 41).
 * Fallback: kanonische Paare aus dem D4c-1 Import-Template (ohne Preise),
 * falls lokal noch keine Regeln geseedet sind (Tests / leere Umgebung).
 */
final class OaTkpActiveMediumInventoryPairs
{
    /**
     * @return list<array{
     *     medium_code: string,
     *     inventory_code: string,
     *     advertising_medium_id: int|null,
     *     inventory_id: int|null
     * }>
     */
    public static function all(): array
    {
        $fromRules = self::fromActiveRules();
        if ($fromRules !== []) {
            return $fromRules;
        }

        return self::fromImportTemplate();
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
     * @return list<array{
     *     medium_code: string,
     *     inventory_code: string,
     *     advertising_medium_id: int|null,
     *     inventory_id: int|null
     * }>
     */
    private static function fromActiveRules(): array
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
     * @return list<array{
     *     medium_code: string,
     *     inventory_code: string,
     *     advertising_medium_id: int|null,
     *     inventory_id: int|null
     * }>
     */
    private static function fromImportTemplate(): array
    {
        $path = base_path('docs/readiness/import-templates/oa-tkp-price-matrix-2026-2027.csv');
        if (! is_readable($path)) {
            return [];
        }

        $handle = fopen($path, 'rb');
        if ($handle === false) {
            return [];
        }

        $header = fgetcsv($handle, 0, ';');
        if (! is_array($header)) {
            fclose($handle);

            return [];
        }

        $seen = [];
        $pairs = [];
        while (($row = fgetcsv($handle, 0, ';')) !== false) {
            if (count($row) < 5) {
                continue;
            }
            $year = (int) ($row[0] ?? 0);
            // Template enthält 2026 und 2027; Paare sind jahr-unabhängig – einmalig nehmen.
            if ($year !== 2027) {
                continue;
            }
            $mediumCode = trim((string) ($row[1] ?? ''));
            $inventoryCode = trim((string) ($row[3] ?? ''));
            if ($mediumCode === '' || $inventoryCode === '') {
                continue;
            }
            if ($mediumCode === 'native_ad' || ! OaTkpCoreMedia::contains($mediumCode)) {
                continue;
            }
            $key = $mediumCode.'|'.$inventoryCode;
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;

            $mediumId = AdvertisingMedium::query()->where('code', $mediumCode)->value('id');
            $inventoryId = Inventory::query()->where('code', $inventoryCode)->value('id');

            $pairs[] = [
                'medium_code' => $mediumCode,
                'inventory_code' => $inventoryCode,
                'advertising_medium_id' => $mediumId !== null ? (int) $mediumId : null,
                'inventory_id' => $inventoryId !== null ? (int) $inventoryId : null,
            ];
        }
        fclose($handle);

        usort(
            $pairs,
            static fn (array $a, array $b): int => [$a['medium_code'], $a['inventory_code']]
                <=> [$b['medium_code'], $b['inventory_code']],
        );

        return $pairs;
    }
}
