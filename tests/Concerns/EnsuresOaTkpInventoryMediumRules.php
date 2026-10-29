<?php

namespace Tests\Concerns;

use App\Models\AdvertisingMedium;
use App\Models\Inventory;
use App\Models\InventoryMediumRule;
use App\Support\OnlineAudio\OaTkpCoreMedia;

/**
 * PO-CALC-TKP-1D4c-4c: Test-Hilfen für operative OA-Medium×Inventar-Zuordnungen.
 */
trait EnsuresOaTkpInventoryMediumRules
{
    /**
     * @param  list<array{0: string, 1: string}>  $pairs  medium_code, inventory_code
     */
    protected function ensureOaInventoryMediumRules(array $pairs): void
    {
        foreach ($pairs as [$mediumCode, $inventoryCode]) {
            $this->assertTrue(OaTkpCoreMedia::contains($mediumCode), $mediumCode);
            $medium = AdvertisingMedium::query()->where('code', $mediumCode)->firstOrFail();
            $inventory = Inventory::query()->where('code', $inventoryCode)->firstOrFail();
            InventoryMediumRule::query()->firstOrCreate(
                [
                    'inventory_id' => (int) $inventory->id,
                    'advertising_medium_id' => (int) $medium->id,
                ],
                ['is_active' => true],
            );
        }
    }

    protected function ensureDefaultOaPriceListPairRule(): void
    {
        $this->ensureOaInventoryMediumRules([
            ['pre_stream', 'inv_more_kombi_online_audio'],
        ]);
    }
}
