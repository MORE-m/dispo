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
 * PO-SPOT-SURCHARGE-1: First/Last + Classic auf dem Spot-Classic-Pfad.
 *
 * Default: Hamburg Ø-Sekundenpreis 1,00 · Länge aus Regel 30 s · Erst/Letzt +30 %.
 * 10 Spots × 1,00 × 30 × 1,00 × 1,30 = 390,00 (Erst oder Letzt).
 * Classic-Regel bleibt surcharge 0 (Regression).
 */
trait CreatesSpotFirstLastCatalog
{
    /**
     * @return array{
     *     organization: Organization,
     *     first: AdvertisingMedium,
     *     last: AdvertisingMedium,
     *     classic: AdvertisingMedium,
     *     hamburg: Inventory,
     *     rock: Inventory,
     *     ruleFirstHamburg: InventoryMediumRule,
     *     ruleLastHamburg: InventoryMediumRule,
     *     ruleClassicHamburg: InventoryMediumRule
     * }
     */
    protected function createSpotFirstLastCatalog(): array
    {
        $organization = Organization::factory()->create();
        $first = AdvertisingMedium::factory()->spotFirst()->create();
        $last = AdvertisingMedium::factory()->spotLast()->create();
        $classic = AdvertisingMedium::factory()->create();

        $hamburg = Inventory::factory()->create([
            'organization_id' => $organization->id,
            'name' => 'Radio Hamburg',
            'code' => 'RH',
            'sort' => 1,
        ]);
        $rock = Inventory::factory()->create([
            'organization_id' => $organization->id,
            'name' => 'ROCK ANTENNE Hamburg',
            'code' => 'RAH',
            'sort' => 2,
        ]);

        $ruleFirstHamburg = null;
        $ruleLastHamburg = null;
        $ruleClassicHamburg = null;

        foreach ([$hamburg, $rock] as $inventory) {
            $price = $inventory->code === 'RH' ? '1.0000' : '0.8000';

            $ruleFirst = InventoryMediumRule::factory()->create([
                'inventory_id' => $inventory->id,
                'advertising_medium_id' => $first->id,
                'default_length_seconds' => 30,
                'surcharge_percent' => '30',
            ]);
            $ruleLast = InventoryMediumRule::factory()->create([
                'inventory_id' => $inventory->id,
                'advertising_medium_id' => $last->id,
                'default_length_seconds' => 30,
                'surcharge_percent' => '30',
            ]);
            $ruleClassic = InventoryMediumRule::factory()->create([
                'inventory_id' => $inventory->id,
                'advertising_medium_id' => $classic->id,
                'default_length_seconds' => 30,
                'surcharge_percent' => '0',
            ]);

            if ($inventory->id === $hamburg->id) {
                $ruleFirstHamburg = $ruleFirst;
                $ruleLastHamburg = $ruleLast;
                $ruleClassicHamburg = $ruleClassic;
            }

            $year = PriceListCalendar::currentYear();
            $list = PriceList::factory()->create([
                'inventory_id' => $inventory->id,
                'status' => PriceListStatus::Active,
                'year' => $year,
                'version' => '2026-'.$inventory->code,
                'valid_from' => now()->toDateString(),
            ]);

            foreach (range(0, 23) as $hour) {
                foreach ([DayGroup::MoFr, DayGroup::Sa, DayGroup::So] as $group) {
                    PriceListItem::factory()->create([
                        'price_list_id' => $list->id,
                        'hour' => $hour,
                        'day_group' => $group,
                        'second_price' => $price,
                    ]);
                }
            }
        }

        return [
            'organization' => $organization,
            'first' => $first,
            'last' => $last,
            'classic' => $classic,
            'hamburg' => $hamburg,
            'rock' => $rock,
            'ruleFirstHamburg' => $ruleFirstHamburg,
            'ruleLastHamburg' => $ruleLastHamburg,
            'ruleClassicHamburg' => $ruleClassicHamburg,
        ];
    }
}
