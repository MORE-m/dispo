<?php

namespace Database\Seeders;

use App\Enums\CalculationKind;
use App\Enums\DayGroup;
use App\Enums\PriceListStatus;
use App\Enums\Role;
use App\Models\AdvertisingCategory;
use App\Models\AdvertisingMedium;
use App\Models\Inventory;
use App\Models\InventoryMediumRule;
use App\Models\Organization;
use App\Models\PriceList;
use App\Models\PriceListItem;
use App\Models\User;
use App\Support\Advertising\CanonicalAdvertisingCategories;
use App\Support\E2E\E2EIsolatedEnvironmentGuard;
use App\Support\PriceList\PriceListCalendar;
use Illuminate\Database\Seeder;

/**
 * E2E-Katalog für PO-SPOT-SINGLE-1 Single × Average (isolierter Port 8069).
 *
 * - Radio Hamburg (RH): Ø 1,00 €/s · Single +50 % → 450,00 bei 10×30s
 * - MORE Hamburg-Kombi+ (MHKP): Classic ok, keine Single-Regel (MAT-003)
 * - Spot Classic: +0 % (Smoke-Regression)
 *
 * Fail-closed: nur APP_ENV=testing + E2E_SERVER=1 + nicht Dev-DB „dispo“.
 */
class E2ESpotSingleSeeder extends Seeder
{
    public function run(): void
    {
        E2EIsolatedEnvironmentGuard::assertSafeForE2ESeeding();

        foreach ([
            ['email' => 'sales@example.com', 'name' => 'E2E Vertrieb', 'role' => Role::Sales],
            ['email' => 'admin@example.com', 'name' => 'E2E Admin', 'role' => Role::Admin],
        ] as $attrs) {
            User::query()->updateOrCreate(
                ['email' => $attrs['email']],
                [
                    'name' => $attrs['name'],
                    'password' => 'password',
                    'role' => $attrs['role'],
                ],
            );
        }

        if (Organization::query()->exists()) {
            return;
        }

        $organization = Organization::factory()->create(['name' => 'E2E Spot Single']);
        $spotsCategoryId = (int) AdvertisingCategory::query()
            ->where('key', CanonicalAdvertisingCategories::SPOTS)
            ->value('id');

        $classic = AdvertisingMedium::factory()->create([
            'code' => 'spot_classic',
            'name' => 'Werbespot',
            'category_id' => $spotsCategoryId,
            'kind' => CalculationKind::SpotClassic,
            'sort' => 10,
        ]);
        $single = AdvertisingMedium::factory()->spotSingle()->create([
            'category_id' => $spotsCategoryId,
        ]);

        $hamburg = Inventory::factory()->create([
            'organization_id' => $organization->id,
            'name' => 'Radio Hamburg',
            'code' => 'RH',
            'sort' => 1,
            'logo_path' => null,
        ]);
        $kombiPlus = Inventory::factory()->create([
            'organization_id' => $organization->id,
            'name' => 'MORE Hamburg-Kombi+',
            'code' => 'MHKP',
            'sort' => 2,
            'logo_path' => null,
        ]);

        InventoryMediumRule::factory()->create([
            'inventory_id' => $hamburg->id,
            'advertising_medium_id' => $classic->id,
            'default_length_seconds' => 30,
            'surcharge_percent' => '0',
        ]);
        InventoryMediumRule::factory()->create([
            'inventory_id' => $hamburg->id,
            'advertising_medium_id' => $single->id,
            'default_length_seconds' => 30,
            'surcharge_percent' => '50',
        ]);
        InventoryMediumRule::factory()->create([
            'inventory_id' => $kombiPlus->id,
            'advertising_medium_id' => $classic->id,
            'default_length_seconds' => 30,
            'surcharge_percent' => '0',
        ]);
        // MAT-003: keine Single-Regel auf Kombi+

        foreach ([$hamburg, $kombiPlus] as $inventory) {
            $list = PriceList::factory()->create([
                'inventory_id' => $inventory->id,
                'status' => PriceListStatus::Active,
                'year' => PriceListCalendar::currentYear(),
                'version' => 'e2e-single-'.$inventory->code,
                'valid_from' => now()->toDateString(),
            ]);

            foreach (range(0, 23) as $hour) {
                foreach ([DayGroup::MoFr, DayGroup::Sa, DayGroup::So] as $group) {
                    PriceListItem::factory()->create([
                        'price_list_id' => $list->id,
                        'hour' => $hour,
                        'day_group' => $group,
                        'second_price' => '1.0000',
                    ]);
                }
            }
        }
    }
}
