<?php

namespace Tests\Concerns;

use App\Enums\CalculationKind;
use App\Enums\CalculationMethodMode;
use App\Enums\DayGroup;
use App\Enums\PriceListStatus;
use App\Enums\Role;
use App\Models\AdvertisingMedium;
use App\Models\AdvertisingMediumCalculationMethod;
use App\Models\CalculationMethod;
use App\Models\Inventory;
use App\Models\InventoryMediumRule;
use App\Models\OaPlatform;
use App\Models\OaTkpPriceList;
use App\Models\OaTkpPriceListItem;
use App\Models\PriceList;
use App\Models\PriceListItem;
use App\Models\User;
use App\Support\Calculation\EngineProfileRegistry;
use App\Support\PriceList\PriceListCalendar;
use Database\Seeders\InitialCatalogMatCoreSeeder;

/**
 * NATIVE-2: Fixtures für Free-Position-Tests.
 *
 * `native_ad` wird im Test-DB explizit für den free_position-Pfad konfiguriert
 * (kind=free_position + Medium-Override-Zuordnung free_position × free_position).
 * Produktiv bleibt das Pair planned; Tests simulieren Release über
 * {@see EngineProfileRegistry::fakeFreePositionPairReleasedForTests()}.
 */
trait CreatesFreePositionCatalog
{
    /**
     * @return array{user: User, medium: AdvertisingMedium, inventory: Inventory, rule: InventoryMediumRule}
     */
    protected function seedFreeContext(?string $discountLimit = null): array
    {
        $this->seed(InitialCatalogMatCoreSeeder::class);

        $user = User::factory()->role(Role::Sales)->create(['discount_limit_percent' => $discountLimit]);
        $medium = $this->configureNativeAdForFreePosition();
        $inventory = Inventory::factory()->create(['name' => 'Free Sender', 'code' => 'FREESND']);
        $rule = InventoryMediumRule::factory()->create([
            'inventory_id' => $inventory->id,
            'advertising_medium_id' => $medium->id,
        ]);

        return compact('user', 'medium', 'inventory', 'rule');
    }

    protected function configureNativeAdForFreePosition(): AdvertisingMedium
    {
        $method = CalculationMethod::query()->where('key', 'free_position')->firstOrFail();
        $medium = AdvertisingMedium::query()->where('code', 'native_ad')->firstOrFail();

        $medium->kind = CalculationKind::FreePosition;
        $medium->calculation_method_mode = CalculationMethodMode::Override;
        $medium->default_calculation_method_id = (int) $method->id;
        $medium->is_discountable = true;
        $medium->is_ae_eligible = true;
        $medium->save();

        AdvertisingMediumCalculationMethod::query()
            ->where('advertising_medium_id', $medium->id)
            ->delete();

        $assignment = new AdvertisingMediumCalculationMethod;
        $assignment->advertising_medium_id = $medium->id;
        $assignment->calculation_method_id = (int) $method->id;
        $assignment->engine_profile_key = EngineProfileRegistry::PROFILE_FREE_POSITION;
        $assignment->is_active = true;
        $assignment->sort = 10;
        $assignment->lock_version = 1;
        $assignment->save();

        return $medium->fresh();
    }

    /**
     * @param  array<string, mixed>  $ctx
     * @param  array<string, mixed>  $override
     * @return array<string, mixed>
     */
    protected function freePosition(array $ctx, array $override = []): array
    {
        return array_merge([
            'client_key' => (string) str()->uuid(),
            'inventory_id' => (int) $ctx['inventory']->id,
            'advertising_medium_id' => (int) $ctx['medium']->id,
            'calculation_method_key' => 'free_position',
            'free_price' => '5000.00',
            'position_discount_percent' => '0',
            'ae_percent' => '15',
        ], $override);
    }

    /**
     * Spot-Fixture auf dem Seed-Medium `spot_classic` mit eigenem Inventar und Preisliste.
     *
     * @return array{medium: AdvertisingMedium, inventory: Inventory}
     */
    protected function createFreeSpotFixture(): array
    {
        $medium = AdvertisingMedium::query()->where('code', 'spot_classic')->firstOrFail();
        $inventory = Inventory::factory()->create(['name' => 'Free Spot Sender', 'code' => 'FRSPOT']);
        InventoryMediumRule::factory()->create([
            'inventory_id' => $inventory->id,
            'advertising_medium_id' => $medium->id,
        ]);
        $list = PriceList::factory()->create([
            'inventory_id' => $inventory->id,
            'status' => PriceListStatus::Active,
            'year' => PriceListCalendar::currentYear(),
            'version' => 'FREE-SPOT',
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

        return compact('medium', 'inventory');
    }

    /**
     * @param  array{medium: AdvertisingMedium, inventory: Inventory}  $spot
     * @return array<string, mixed>
     */
    protected function freeSpotPosition(array $spot): array
    {
        return [
            'client_key' => (string) str()->uuid(),
            'inventory_id' => $spot['inventory']->id,
            'advertising_medium_id' => $spot['medium']->id,
            'spot_method' => 'average',
            'length_seconds' => 30,
            'total_spot_count' => 10,
            'position_discount_percent' => '0',
            'ae_percent' => '15',
            'plan_rows' => [['hour' => 8, 'day_group' => 'mo_fr']],
        ];
    }

    /**
     * OA-TKP-Kontext (pre_stream × Online-Audio-Kombi, Liste 12/8, 100.000 Impressions → 1.200,00).
     *
     * @return array{medium: AdvertisingMedium, inventory: Inventory, platform: OaPlatform, list: OaTkpPriceList}
     */
    protected function seedFreeOaContext(): array
    {
        $medium = AdvertisingMedium::query()->where('code', 'pre_stream')->firstOrFail();
        $inventory = Inventory::query()->where('code', 'inv_more_kombi_online_audio')->firstOrFail();
        $list = OaTkpPriceList::query()->create([
            'year' => PriceListCalendar::currentYear(),
            'name' => 'Fixture Free '.PriceListCalendar::currentYear(),
            'version' => PriceListCalendar::currentYear().'.1',
            'revision_number' => 1,
            'lock_version' => 1,
            'status' => PriceListStatus::Active,
            'published_at' => now(),
        ]);
        OaTkpPriceListItem::query()->create([
            'oa_tkp_price_list_id' => $list->id,
            'advertising_medium_id' => $medium->id,
            'inventory_id' => $inventory->id,
            'list_tkp' => '12.0000',
            'minimum_tkp' => '8.0000',
        ]);
        $platform = OaPlatform::query()->create([
            'code' => 'fixture_plat_free',
            'name' => 'Fixture Platform Free',
            'is_active' => true,
            'sort' => 1,
            'is_adserver_relevant' => false,
        ]);

        return compact('medium', 'inventory', 'platform', 'list');
    }

    /**
     * @param  array<string, mixed>  $oa
     * @param  array<string, mixed>  $override
     * @return array<string, mixed>
     */
    protected function freeOaPosition(array $oa, array $override = []): array
    {
        return array_merge([
            'client_key' => (string) str()->uuid(),
            'inventory_id' => (int) $oa['inventory']->id,
            'advertising_medium_id' => (int) $oa['medium']->id,
            'calculation_method_key' => 'tkp',
            'impressions' => 100_000,
            'basis_tkp' => '10.0000',
            'oa_platform_allocations' => [
                ['oa_platform_id' => (int) $oa['platform']->id, 'impressions' => 100_000],
            ],
            'oa_targeting_selections' => [],
            'position_discount_percent' => '0',
            'ae_percent' => '15',
        ], $override);
    }
}
