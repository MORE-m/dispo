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

trait CreatesSpotClassicCatalog
{
    /**
     * @return array{organization: Organization, medium: AdvertisingMedium, hamburg: Inventory, rock: Inventory}
     */
    protected function createSpotClassicCatalog(): array
    {
        $organization = Organization::factory()->create();
        $medium = AdvertisingMedium::factory()->create();

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

        foreach ([$hamburg, $rock] as $inventory) {
            InventoryMediumRule::factory()->create([
                'inventory_id' => $inventory->id,
                'advertising_medium_id' => $medium->id,
            ]);

            $list = PriceList::factory()->create([
                'inventory_id' => $inventory->id,
                'status' => PriceListStatus::Active,
                'version' => '2026-'.$inventory->code,
                'valid_from' => now()->toDateString(),
            ]);

            foreach (range(0, 23) as $hour) {
                foreach ([DayGroup::MoFr, DayGroup::Sa, DayGroup::So] as $group) {
                    $price = $inventory->code === 'RH' ? '1.0000' : '0.8000';
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
            'medium' => $medium,
            'hamburg' => $hamburg,
            'rock' => $rock,
        ];
    }
}
