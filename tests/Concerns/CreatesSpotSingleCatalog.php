<?php

namespace Tests\Concerns;

use App\Enums\DayGroup;
use App\Enums\PriceListStatus;
use App\Models\AdvertisingMedium;
use App\Models\Inventory;
use App\Models\InventoryMediumRule;
use App\Models\Organization;
use App\Models\PriceList;
use App\Models\PriceListItem;
use App\Support\PriceList\PriceListCalendar;

/**
 * PO-SPOT-SINGLE-1: Single + Classic auf dem Spot-Classic-Pfad.
 *
 * Erlaubt (MAT-003): RH, ROCK, OLDIE, CARAVAN, MORE Hamburg-Kombi – Single +50 %.
 * Ausgeschlossen: MORE Hamburg-Kombi+, Bollerwagen, ffn – ohne Single-Regel.
 *
 * Default RH: Ø 1,00 · 30 s · 10 Spots × 1,50 = 450,00.
 * Classic-Regel bleibt surcharge 0 (Regression).
 */
trait CreatesSpotSingleCatalog
{
    /**
     * @return array{
     *     organization: Organization,
     *     single: AdvertisingMedium,
     *     classic: AdvertisingMedium,
     *     first: AdvertisingMedium,
     *     last: AdvertisingMedium,
     *     hamburg: Inventory,
     *     rock: Inventory,
     *     oldie: Inventory,
     *     caravan: Inventory,
     *     moreKombi: Inventory,
     *     moreKombiPlus: Inventory,
     *     bollerwagen: Inventory,
     *     ffn: Inventory,
     *     ruleSingleHamburg: InventoryMediumRule,
     *     ruleClassicHamburg: InventoryMediumRule,
     *     allowedInventories: list<Inventory>
     * }
     */
    protected function createSpotSingleCatalog(): array
    {
        $organization = Organization::factory()->create();
        $single = AdvertisingMedium::factory()->spotSingle()->create();
        $classic = AdvertisingMedium::factory()->create();
        $first = AdvertisingMedium::factory()->spotFirst()->create();
        $last = AdvertisingMedium::factory()->spotLast()->create();

        $allowed = [
            ['name' => 'Radio Hamburg', 'code' => 'RH', 'price' => '1.0000', 'sort' => 1],
            ['name' => 'ROCK ANTENNE Hamburg', 'code' => 'RAH', 'price' => '0.8000', 'sort' => 2],
            ['name' => 'OLDIE ANTENNE Hamburg', 'code' => 'OAH', 'price' => '0.7000', 'sort' => 3],
            ['name' => 'CARAVAN.fm', 'code' => 'CAR', 'price' => '0.6000', 'sort' => 4],
            ['name' => 'MORE Hamburg-Kombi', 'code' => 'MHK', 'price' => '1.2000', 'sort' => 5],
        ];
        $blocked = [
            ['name' => 'MORE Hamburg-Kombi+', 'code' => 'MHKP', 'price' => '1.2000', 'sort' => 6],
            ['name' => 'RADIO BOLLERWAGEN DAB+ Hamburg', 'code' => 'RBW', 'price' => '0.5000', 'sort' => 7],
            ['name' => 'ffn Hamburg Plus', 'code' => 'FFN', 'price' => '0.5000', 'sort' => 8],
        ];

        $inventories = [];
        $ruleSingleHamburg = null;
        $ruleClassicHamburg = null;
        $allowedInventories = [];

        foreach (array_merge($allowed, $blocked) as $definition) {
            $inventory = Inventory::factory()->create([
                'organization_id' => $organization->id,
                'name' => $definition['name'],
                'code' => $definition['code'],
                'sort' => $definition['sort'],
            ]);
            $inventories[$definition['code']] = $inventory;

            $isAllowed = collect($allowed)->contains(fn (array $row): bool => $row['code'] === $definition['code']);
            if ($isAllowed) {
                $allowedInventories[] = $inventory;
                $ruleSingle = InventoryMediumRule::factory()->create([
                    'inventory_id' => $inventory->id,
                    'advertising_medium_id' => $single->id,
                    'default_length_seconds' => 30,
                    'surcharge_percent' => '50',
                ]);
                if ($definition['code'] === 'RH') {
                    $ruleSingleHamburg = $ruleSingle;
                }
            }

            $ruleClassic = InventoryMediumRule::factory()->create([
                'inventory_id' => $inventory->id,
                'advertising_medium_id' => $classic->id,
                'default_length_seconds' => 30,
                'surcharge_percent' => '0',
            ]);
            if ($definition['code'] === 'RH') {
                $ruleClassicHamburg = $ruleClassic;
            }

            InventoryMediumRule::factory()->create([
                'inventory_id' => $inventory->id,
                'advertising_medium_id' => $first->id,
                'default_length_seconds' => 30,
                'surcharge_percent' => '30',
            ]);
            InventoryMediumRule::factory()->create([
                'inventory_id' => $inventory->id,
                'advertising_medium_id' => $last->id,
                'default_length_seconds' => 30,
                'surcharge_percent' => '30',
            ]);

            $year = PriceListCalendar::currentYear();
            $list = PriceList::factory()->create([
                'inventory_id' => $inventory->id,
                'status' => PriceListStatus::Active,
                'year' => $year,
                'version' => '2026-'.$definition['code'],
                'valid_from' => now()->toDateString(),
            ]);

            foreach (range(0, 23) as $hour) {
                foreach ([DayGroup::MoFr, DayGroup::Sa, DayGroup::So] as $group) {
                    PriceListItem::factory()->create([
                        'price_list_id' => $list->id,
                        'hour' => $hour,
                        'day_group' => $group,
                        'second_price' => $definition['price'],
                    ]);
                }
            }
        }

        return [
            'organization' => $organization,
            'single' => $single,
            'classic' => $classic,
            'first' => $first,
            'last' => $last,
            'hamburg' => $inventories['RH'],
            'rock' => $inventories['RAH'],
            'oldie' => $inventories['OAH'],
            'caravan' => $inventories['CAR'],
            'moreKombi' => $inventories['MHK'],
            'moreKombiPlus' => $inventories['MHKP'],
            'bollerwagen' => $inventories['RBW'],
            'ffn' => $inventories['FFN'],
            'ruleSingleHamburg' => $ruleSingleHamburg,
            'ruleClassicHamburg' => $ruleClassicHamburg,
            'allowedInventories' => $allowedInventories,
        ];
    }
}
