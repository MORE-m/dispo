<?php

namespace Database\Seeders;

use App\Enums\DayGroup;
use App\Enums\PriceListStatus;
use App\Enums\Role;
use App\Models\AdvertisingMedium;
use App\Models\Inventory;
use App\Models\InventoryMediumRule;
use App\Models\Organization;
use App\Models\PriceList;
use App\Models\PriceListItem;
use App\Models\User;
use Illuminate\Database\Seeder;

/**
 * Minimaler Katalog und Testbenutzer für Playwright-E2E (nur lokal/CI).
 */
class E2ECalculationSeeder extends Seeder
{
    public function run(): void
    {
        User::query()->updateOrCreate(
            ['email' => 'sales@example.com'],
            [
                'name' => 'E2E Vertrieb',
                'password' => 'password',
                'role' => Role::Sales,
            ],
        );

        if (Organization::query()->exists()) {
            return;
        }

        $organization = Organization::factory()->create(['name' => 'E2E Sendergruppe']);
        $medium = AdvertisingMedium::factory()->create(['code' => 'spot_classic']);

        foreach ([['RH', 'Radio Hamburg', 1], ['RAH', 'ROCK ANTENNE Hamburg', 2]] as [$code, $name, $sort]) {
            $inventory = Inventory::factory()->create([
                'organization_id' => $organization->id,
                'name' => $name,
                'code' => $code,
                'sort' => $sort,
            ]);

            InventoryMediumRule::factory()->create([
                'inventory_id' => $inventory->id,
                'advertising_medium_id' => $medium->id,
            ]);

            $list = PriceList::factory()->create([
                'inventory_id' => $inventory->id,
                'status' => PriceListStatus::Active,
                'version' => 'e2e-'.$code,
                'valid_from' => now()->toDateString(),
            ]);

            foreach ([8, 10] as $hour) {
                foreach ([DayGroup::MoFr, DayGroup::Sa, DayGroup::So] as $group) {
                    PriceListItem::factory()->create([
                        'price_list_id' => $list->id,
                        'hour' => $hour,
                        'day_group' => $group,
                        'second_price' => $code === 'RH' ? '1.0000' : '0.8000',
                    ]);
                }
            }
        }
    }
}
