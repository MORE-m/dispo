<?php

namespace Database\Seeders;

use App\Enums\CalculationKind;
use App\Enums\DayGroup;
use App\Enums\PriceListStatus;
use App\Enums\Role;
use App\Models\AdvertisingCategory;
use App\Models\AdvertisingCategoryCalculationMethod;
use App\Models\AdvertisingMedium;
use App\Models\CalculationMethod;
use App\Models\Inventory;
use App\Models\InventoryMediumRule;
use App\Models\Organization;
use App\Models\PriceList;
use App\Models\PriceListItem;
use App\Models\User;
use App\Support\Advertising\CanonicalAdvertisingCategories;
use App\Support\Calculation\EngineProfileRegistry;
use App\Support\E2E\E2EIsolatedEnvironmentGuard;
use App\Support\PriceList\PriceListCalendar;
use Illuminate\Database\Seeder;

/**
 * E2E-Katalog für BL-P5-01a SWF Trailer × Durchschnitt (isolierter Port 8055).
 *
 * Synthetische Testwerte (keine operativen Daten, kein Seed für RH/ROCK/OLDIE/CARAVAN):
 * - „Trailer Testsender A“: Ø-Sekundenpreis 2,00 · 20 s · +30 %
 * - „Trailer Testsender B“: Ø-Sekundenpreis 1,50 · 15 s · +50 %
 * - „Trailer Testsender C“: Trailer-Regel ohne Länge/Aufschlag (fail-closed)
 *
 * Fail-closed: nur APP_ENV=testing + E2E_SERVER=1 + nicht Dev-DB „dispo“.
 */
class E2ESwfTrailerAverageSeeder extends Seeder
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

        $this->assignSwfCategoryAverageToTrailerProfile();

        $organization = Organization::factory()->create(['name' => 'E2E Trailer']);
        $spotsCategoryId = (int) AdvertisingCategory::query()
            ->where('key', CanonicalAdvertisingCategories::SPOTS)
            ->value('id');
        $swfCategoryId = (int) AdvertisingCategory::query()
            ->where('key', CanonicalAdvertisingCategories::SPECIAL_ADVERTISING_FORMATS)
            ->value('id');

        $spot = AdvertisingMedium::factory()->create([
            'code' => 'spot_classic',
            'name' => 'Spot Classic',
            'category_id' => $spotsCategoryId,
            'kind' => CalculationKind::SpotClassic,
        ]);
        $trailer = AdvertisingMedium::factory()->create([
            'code' => 'trailer_station_voice',
            'name' => 'Trailer/Vorpr. Element Station Voice',
            'category_id' => $swfCategoryId,
            'kind' => CalculationKind::SwfTrailer,
        ]);

        $inventories = [
            ['name' => 'Trailer Testsender A', 'code' => 'TTA', 'sort' => 1, 'price' => '2.0000', 'length' => 20, 'surcharge' => '30'],
            ['name' => 'Trailer Testsender B', 'code' => 'TTB', 'sort' => 2, 'price' => '1.5000', 'length' => 15, 'surcharge' => '50'],
            ['name' => 'Trailer Testsender C', 'code' => 'TTC', 'sort' => 3, 'price' => '1.0000', 'length' => null, 'surcharge' => null],
        ];

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
                'advertising_medium_id' => $spot->id,
                'default_length_seconds' => 30,
                'surcharge_percent' => '0',
            ]);
            InventoryMediumRule::factory()->create([
                'inventory_id' => $inventory->id,
                'advertising_medium_id' => $trailer->id,
                'default_length_seconds' => $row['length'],
                'surcharge_percent' => $row['surcharge'],
            ]);

            $list = PriceList::factory()->create([
                'inventory_id' => $inventory->id,
                'status' => PriceListStatus::Active,
                'year' => PriceListCalendar::currentYear(),
                'version' => 'e2e-trailer-'.$row['code'],
                'valid_from' => now()->toDateString(),
            ]);

            foreach (range(0, 23) as $hour) {
                foreach ([DayGroup::MoFr, DayGroup::Sa, DayGroup::So] as $group) {
                    PriceListItem::factory()->create([
                        'price_list_id' => $list->id,
                        'hour' => $hour,
                        'day_group' => $group,
                        'second_price' => $row['price'],
                    ]);
                }
            }
        }
    }

    private function assignSwfCategoryAverageToTrailerProfile(): void
    {
        $category = AdvertisingCategory::query()
            ->where('key', CanonicalAdvertisingCategories::SPECIAL_ADVERTISING_FORMATS)
            ->firstOrFail();
        $average = CalculationMethod::query()->where('key', 'average')->firstOrFail();

        $assignment = AdvertisingCategoryCalculationMethod::query()
            ->where('advertising_category_id', $category->id)
            ->where('calculation_method_id', $average->id)
            ->first() ?? new AdvertisingCategoryCalculationMethod;
        $assignment->advertising_category_id = $category->id;
        $assignment->calculation_method_id = $average->id;
        $assignment->engine_profile_key = EngineProfileRegistry::PROFILE_SWF_TRAILER;
        $assignment->is_active = true;
        $assignment->sort = 10;
        $assignment->lock_version = 1;
        $assignment->save();

        $category->default_calculation_method_id = $average->id;
        $category->save();
    }
}
