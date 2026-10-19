<?php

namespace Database\Seeders;

use App\Enums\CalculationKind;
use App\Enums\CalculationMethodMode;
use App\Enums\DayGroup;
use App\Enums\PriceListStatus;
use App\Enums\Role;
use App\Models\AdvertisingCategory;
use App\Models\AdvertisingMedium;
use App\Models\AdvertisingMediumCalculationMethod;
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
 * E2E-Katalog für PO-ALLONGE-1 (isolierter Port 8067).
 *
 * Synthetische Werte, keine operativen Startwerte:
 * - Allonge Testsender A: 2,00 €/s · Default 10 s · +30 % · bestätigt
 * - Allonge Testsender Unbestätigt: 2,00 €/s · 30 s / 0 % · nicht bestätigt
 * - Folgejahr beide Sender: 4,00 €/s, nur für den Preisjahr-Wechsel im Browser
 */
class E2ESwfAllongeAverageSeeder extends Seeder
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

        $organization = Organization::factory()->create(['name' => 'E2E Allonge']);
        $spotsCategoryId = (int) AdvertisingCategory::query()
            ->where('key', CanonicalAdvertisingCategories::SPOTS)
            ->value('id');
        $swfCategoryId = (int) AdvertisingCategory::query()
            ->where('key', CanonicalAdvertisingCategories::SPECIAL_ADVERTISING_FORMATS)
            ->value('id');
        $average = CalculationMethod::query()->where('key', 'average')->firstOrFail();

        $spot = AdvertisingMedium::factory()->create([
            'code' => 'spot_classic',
            'name' => 'Spot Classic',
            'category_id' => $spotsCategoryId,
            'kind' => CalculationKind::SpotClassic,
        ]);
        $allonge = AdvertisingMedium::factory()->create([
            'code' => 'allonge',
            'name' => 'Allonge',
            'category_id' => $swfCategoryId,
            'kind' => CalculationKind::SwfAllonge,
            'calculation_method_mode' => CalculationMethodMode::Override,
            'default_calculation_method_id' => $average->id,
        ]);
        $assignment = new AdvertisingMediumCalculationMethod;
        $assignment->advertising_medium_id = $allonge->id;
        $assignment->calculation_method_id = $average->id;
        $assignment->engine_profile_key = EngineProfileRegistry::PROFILE_SWF_ALLONGE;
        $assignment->is_active = true;
        $assignment->sort = 10;
        $assignment->lock_version = 1;
        $assignment->save();

        $calendar = CalculationMethod::query()->where('key', 'calendar')->firstOrFail();
        $calendarAssignment = new AdvertisingMediumCalculationMethod;
        $calendarAssignment->advertising_medium_id = $allonge->id;
        $calendarAssignment->calculation_method_id = $calendar->id;
        $calendarAssignment->engine_profile_key = EngineProfileRegistry::PROFILE_SWF_ALLONGE;
        $calendarAssignment->is_active = true;
        $calendarAssignment->sort = 20;
        $calendarAssignment->lock_version = 1;
        $calendarAssignment->save();

        $rows = [
            ['name' => 'Allonge Testsender A', 'code' => 'ATA', 'sort' => 1, 'length' => 10, 'surcharge' => '30', 'confirmed' => true],
            ['name' => 'Allonge Testsender Unbestätigt', 'code' => 'ATU', 'sort' => 2, 'length' => 30, 'surcharge' => '0', 'confirmed' => false],
        ];

        foreach ($rows as $row) {
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
                'advertising_medium_id' => $allonge->id,
                'default_length_seconds' => $row['length'],
                'surcharge_percent' => $row['surcharge'],
                'swf_allonge_confirmed_at' => $row['confirmed'] ? now() : null,
            ]);

            $list = PriceList::factory()->create([
                'inventory_id' => $inventory->id,
                'status' => PriceListStatus::Active,
                'year' => PriceListCalendar::currentYear(),
                'version' => 'e2e-allonge-'.$row['code'],
                'valid_from' => now()->toDateString(),
            ]);

            foreach (range(0, 23) as $hour) {
                foreach ([DayGroup::MoFr, DayGroup::Sa, DayGroup::So] as $group) {
                    PriceListItem::factory()->create([
                        'price_list_id' => $list->id,
                        'hour' => $hour,
                        'day_group' => $group,
                        'second_price' => '2.0000',
                    ]);
                }
            }

            $nextList = PriceList::factory()->create([
                'inventory_id' => $inventory->id,
                'status' => PriceListStatus::Active,
                'year' => PriceListCalendar::nextYear(),
                'version' => 'e2e-allonge-next-'.$row['code'],
                'valid_from' => now()->toDateString(),
            ]);

            foreach (range(0, 23) as $hour) {
                foreach ([DayGroup::MoFr, DayGroup::Sa, DayGroup::So] as $group) {
                    PriceListItem::factory()->create([
                        'price_list_id' => $nextList->id,
                        'hour' => $hour,
                        'day_group' => $group,
                        'second_price' => '4.0000',
                    ]);
                }
            }
        }
    }
}
