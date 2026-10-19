<?php

namespace Database\Seeders;

use App\Enums\CalculationKind;
use App\Enums\CalculationMethodMode;
use App\Enums\DayGroup;
use App\Enums\PriceListStatus;
use App\Enums\Role;
use App\Models\AdvertisingCategory;
use App\Models\AdvertisingCategoryCalculationMethod;
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
 * E2E-Katalog für PO-SWF-CALENDAR-1 Familien-E2E (isolierter Port 8072).
 *
 * Promo × Calendar: RH 1,00 €/s · 20 s · +50 % · 10 Slots → 300,00 (ohne Spotlängenindex).
 */
class E2ESwfCalendarSeeder extends Seeder
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

        $organization = Organization::factory()->create(['name' => 'E2E SWF Calendar']);
        $spotsCategoryId = (int) AdvertisingCategory::query()
            ->where('key', CanonicalAdvertisingCategories::SPOTS)
            ->value('id');
        $swfCategoryId = (int) AdvertisingCategory::query()
            ->where('key', CanonicalAdvertisingCategories::SPECIAL_ADVERTISING_FORMATS)
            ->value('id');
        $average = CalculationMethod::query()->where('key', 'average')->firstOrFail();

        $classic = AdvertisingMedium::factory()->create([
            'code' => 'spot_classic',
            'name' => 'Werbespot',
            'category_id' => $spotsCategoryId,
            'kind' => CalculationKind::SpotClassic,
            'sort' => 10,
        ]);
        $promo = AdvertisingMedium::factory()->swfPromo()->create([
            'category_id' => $swfCategoryId,
            'calculation_method_mode' => CalculationMethodMode::Override,
            'default_calculation_method_id' => $average->id,
        ]);
        $promoAssignment = new AdvertisingMediumCalculationMethod;
        $promoAssignment->advertising_medium_id = $promo->id;
        $promoAssignment->calculation_method_id = $average->id;
        $promoAssignment->engine_profile_key = EngineProfileRegistry::PROFILE_SWF_PROMO;
        $promoAssignment->is_active = true;
        $promoAssignment->sort = 10;
        $promoAssignment->lock_version = 1;
        $promoAssignment->save();

        $calendar = CalculationMethod::query()->where('key', 'calendar')->firstOrFail();
        $promoCalendar = new AdvertisingMediumCalculationMethod;
        $promoCalendar->advertising_medium_id = $promo->id;
        $promoCalendar->calculation_method_id = $calendar->id;
        $promoCalendar->engine_profile_key = EngineProfileRegistry::PROFILE_SWF_PROMO;
        $promoCalendar->is_active = true;
        $promoCalendar->sort = 20;
        $promoCalendar->lock_version = 1;
        $promoCalendar->save();

        $trailer = AdvertisingMedium::factory()->create([
            'code' => 'trailer_station_voice',
            'name' => 'Trailer/Vorpr. Element Station Voice',
            'category_id' => $swfCategoryId,
            'kind' => CalculationKind::SwfTrailer,
            'sort' => 140,
        ]);
        $swfCategory = AdvertisingCategory::query()->findOrFail($swfCategoryId);
        if ($swfCategory->default_calculation_method_id === null) {
            $swfCategory->default_calculation_method_id = $average->id;
            $swfCategory->save();
        }
        if (! $swfCategory->calculationMethodAssignments()->where('calculation_method_id', $average->id)->exists()) {
            $catAssignment = new AdvertisingCategoryCalculationMethod;
            $catAssignment->advertising_category_id = $swfCategoryId;
            $catAssignment->calculation_method_id = $average->id;
            $catAssignment->engine_profile_key = EngineProfileRegistry::PROFILE_SWF_TRAILER;
            $catAssignment->is_active = true;
            $catAssignment->sort = 10;
            $catAssignment->lock_version = 1;
            $catAssignment->save();
        }
        if (! $swfCategory->calculationMethodAssignments()->where('calculation_method_id', $calendar->id)->exists()) {
            $catCalendar = new AdvertisingCategoryCalculationMethod;
            $catCalendar->advertising_category_id = $swfCategoryId;
            $catCalendar->calculation_method_id = $calendar->id;
            $catCalendar->engine_profile_key = EngineProfileRegistry::PROFILE_SWF_TRAILER;
            $catCalendar->is_active = true;
            $catCalendar->sort = 20;
            $catCalendar->lock_version = 1;
            $catCalendar->save();
        }

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
            'advertising_medium_id' => $promo->id,
            'default_length_seconds' => 20,
            'surcharge_percent' => '50',
        ]);
        InventoryMediumRule::factory()->create([
            'inventory_id' => $hamburg->id,
            'advertising_medium_id' => $trailer->id,
            'default_length_seconds' => 20,
            'surcharge_percent' => '30',
        ]);
        InventoryMediumRule::factory()->create([
            'inventory_id' => $kombiPlus->id,
            'advertising_medium_id' => $classic->id,
            'default_length_seconds' => 30,
            'surcharge_percent' => '0',
        ]);

        foreach ([$hamburg, $kombiPlus] as $inventory) {
            $list = PriceList::factory()->create([
                'inventory_id' => $inventory->id,
                'status' => PriceListStatus::Active,
                'year' => PriceListCalendar::currentYear(),
                'version' => 'e2e-promo-'.$inventory->code,
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
