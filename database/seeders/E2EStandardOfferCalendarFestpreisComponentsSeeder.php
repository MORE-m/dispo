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
 * E2E-Katalog für BL-P4-03j Calendar × Festpreis × Hauptspot+Allonge (isolierter Port).
 *
 * Zwei Inventare mit gleichen Sekundenpreisen, aber unterschiedlichen Strategien
 * (shared_total_length / individual). Preisjahr 2026 fest; Stunde 8 = 2,00 €/s.
 *
 * Fail-closed: nur APP_ENV=testing + E2E_SERVER=1 + nicht Dev-DB „dispo“.
 */
class E2EStandardOfferCalendarFestpreisComponentsSeeder extends Seeder
{
    public function run(): void
    {
        E2EIsolatedEnvironmentGuard::assertSafeForE2ESeeding();

        foreach ([
            ['email' => 'pm@example.com', 'name' => 'E2E PM', 'role' => Role::ProductManagement],
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

        $organization = Organization::factory()->create([
            'name' => 'E2E Calendar Festpreis Komponenten',
        ]);
        $spotsCategoryId = (int) AdvertisingCategory::query()
            ->where('key', CanonicalAdvertisingCategories::SPOTS)
            ->value('id');

        $medium = AdvertisingMedium::factory()->create([
            'code' => 'spot_classic',
            'category_id' => $spotsCategoryId,
            'kind' => CalculationKind::SpotClassic,
        ]);

        $inventories = [
            [
                'name' => 'Radio Hamburg Shared',
                'code' => 'RHS',
                'sort' => 1,
                'strategy' => ComponentCalculationStrategy::SharedTotalLength,
                'versionPrefix' => 'e2e-cal-fp-comp-shared',
            ],
            [
                'name' => 'Radio Hamburg Individual',
                'code' => 'RHI',
                'sort' => 2,
                'strategy' => ComponentCalculationStrategy::Individual,
                'versionPrefix' => 'e2e-cal-fp-comp-individual',
            ],
        ];

        $years = array_values(array_unique([
            2026,
            (int) now('Europe/Berlin')->year,
        ]));

        foreach ($inventories as $row) {
            $inventory = Inventory::factory()->create([
                'organization_id' => $organization->id,
                'name' => $row['name'],
                'code' => $row['code'],
                'sort' => $row['sort'],
                'logo_path' => null,
            ]);

            InventoryMediumRule::factory()->create([
                'inventory_id' => $inventory->id,
                'advertising_medium_id' => $medium->id,
                'component_calculation_strategy' => $row['strategy'],
            ]);

            foreach ($years as $year) {
                $list = PriceList::factory()->create([
                    'inventory_id' => $inventory->id,
                    'status' => PriceListStatus::Active,
                    'year' => $year,
                    'version' => $row['versionPrefix'].'-'.$year,
                    'valid_from' => sprintf('%d-01-01', $year),
                ]);

                foreach (range(0, 23) as $hour) {
                    foreach ([DayGroup::MoFr, DayGroup::Sa, DayGroup::So] as $group) {
                        PriceListItem::factory()->create([
                            'price_list_id' => $list->id,
                            'hour' => $hour,
                            'day_group' => $group,
                            'second_price' => $hour === 8 ? '2.0000' : '1.0000',
                        ]);
                    }
                }
            }
        }
    }
}
