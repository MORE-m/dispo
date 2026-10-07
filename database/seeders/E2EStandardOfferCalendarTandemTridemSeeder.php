<?php

namespace Database\Seeders;

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
 * E2E-Katalog für BL-P4-03k Calendar × Tandem/Tridem (isolierter Port).
 *
 * Zwei getrennte Inventare, jeweils genau ein Forced-Profile-Medium:
 * - „Radio Hamburg Tandem“ → Medium tandem
 * - „Radio Hamburg Tridem“ → Medium tridem
 * UI-Inventarwechsel Tandem↔Tridem erzwingt damit Mediumwechsel und Slot-Rebuild
 * ohne manuelle Mediumwahl. Strategie shared_total_length; Preisjahr 2026;
 * Stunde 8 = 2,00 €/s.
 */
class E2EStandardOfferCalendarTandemTridemSeeder extends Seeder
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
            'name' => 'E2E Calendar Tandem Tridem',
        ]);
        $spotsCategoryId = (int) AdvertisingCategory::query()
            ->where('key', CanonicalAdvertisingCategories::SPOTS)
            ->value('id');

        $profiles = [
            [
                'inventory_name' => 'Radio Hamburg Tandem',
                'inventory_code' => 'RHT',
                'sort' => 1,
                'medium' => AdvertisingMedium::factory()->tandem()->create([
                    'category_id' => $spotsCategoryId,
                    'code' => 'e2e_spot_tandem',
                ]),
                'versionPrefix' => 'e2e-cal-tandem',
            ],
            [
                'inventory_name' => 'Radio Hamburg Tridem',
                'inventory_code' => 'RHD',
                'sort' => 2,
                'medium' => AdvertisingMedium::factory()->tridem()->create([
                    'category_id' => $spotsCategoryId,
                    'code' => 'e2e_spot_tridem',
                ]),
                'versionPrefix' => 'e2e-cal-tridem',
            ],
        ];

        $years = array_values(array_unique([
            2026,
            (int) now('Europe/Berlin')->year,
        ]));

        foreach ($profiles as $row) {
            $inventory = Inventory::factory()->create([
                'organization_id' => $organization->id,
                'name' => $row['inventory_name'],
                'code' => $row['inventory_code'],
                'sort' => $row['sort'],
                'logo_path' => null,
            ]);

            InventoryMediumRule::factory()->create([
                'inventory_id' => $inventory->id,
                'advertising_medium_id' => $row['medium']->id,
                'component_calculation_strategy' => ComponentCalculationStrategy::SharedTotalLength,
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
