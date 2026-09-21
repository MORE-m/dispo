<?php

namespace Database\Seeders;

use App\Enums\CalculationKind;
use App\Enums\ComponentCalculationStrategy;
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
use Illuminate\Database\Seeder;

/**
 * E2E-Katalog für BL-P4-02e Tandem/Tridem (isolierter Port 8028).
 *
 * Fail-closed: nur APP_ENV=testing + E2E_SERVER=1 + nicht Dev-DB „dispo“.
 */
class E2ETandemTridemSeeder extends Seeder
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

        $organization = Organization::factory()->create(['name' => 'E2E Tandem Tridem']);
        $spotsCategoryId = (int) AdvertisingCategory::query()
            ->where('key', CanonicalAdvertisingCategories::SPOTS)
            ->value('id');

        $classic = AdvertisingMedium::factory()->create([
            'code' => 'spot_classic',
            'name' => 'Spot Classic',
            'category_id' => $spotsCategoryId,
            'kind' => CalculationKind::SpotClassic,
        ]);

        $tandem = AdvertisingMedium::factory()->tandem()->create([
            'code' => 'spot_tandem',
            'category_id' => $spotsCategoryId,
        ]);

        $tridem = AdvertisingMedium::factory()->tridem()->create([
            'code' => 'spot_tridem',
            'category_id' => $spotsCategoryId,
        ]);

        $inventory = Inventory::factory()->create([
            'organization_id' => $organization->id,
            'name' => 'Radio Hamburg',
            'code' => 'RHTT',
            'sort' => 1,
            'logo_path' => null,
        ]);

        foreach ([$classic, $tandem, $tridem] as $medium) {
            InventoryMediumRule::factory()->create([
                'inventory_id' => $inventory->id,
                'advertising_medium_id' => $medium->id,
                'component_calculation_strategy' => ComponentCalculationStrategy::SharedTotalLength,
            ]);
        }

        $list = PriceList::factory()->create([
            'inventory_id' => $inventory->id,
            'status' => PriceListStatus::Active,
            'year' => (int) now('Europe/Berlin')->year,
            'version' => 'e2e-tandem-tridem',
            'valid_from' => now()->toDateString(),
        ]);

        foreach (range(0, 23) as $hour) {
            foreach ([DayGroup::MoFr, DayGroup::Sa, DayGroup::So] as $group) {
                $secondPrice = match ($hour) {
                    8 => '2.0000',
                    14 => '3.0000',
                    default => '1.0000',
                };

                PriceListItem::factory()->create([
                    'price_list_id' => $list->id,
                    'hour' => $hour,
                    'day_group' => $group,
                    'second_price' => $secondPrice,
                ]);
            }
        }
    }
}
