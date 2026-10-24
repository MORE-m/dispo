<?php

namespace Tests\Feature\OnlineAudio;

use App\Enums\DayGroup;
use App\Enums\EngineCapabilityStatus;
use App\Enums\OaTargetingCategory;
use App\Enums\PriceListStatus;
use App\Enums\Role;
use App\Models\AdvertisingMedium;
use App\Models\DispoOrderPosition;
use App\Models\Inventory;
use App\Models\InventoryMediumRule;
use App\Models\OaPlatform;
use App\Models\OaTargetingDefinition;
use App\Models\OaTkpPriceList;
use App\Models\OaTkpPriceListItem;
use App\Models\PriceList;
use App\Models\PriceListItem;
use App\Models\User;
use App\Services\Calculation\CalculationWriter;
use App\Services\DispoOrder\DispoOrderWriter;
use App\Support\Calculation\EngineProfileRegistry;
use App\Support\PriceList\PriceListCalendar;
use Database\Seeders\InitialCatalogMatCoreSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * PO-CALC-TKP-1D3c: OA-Snapshot-Felder in Dispo-Show-Props.
 */
class PoCalcTkp1d3cDispoShowTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function oa_only_show_exposes_frozen_snapshot_fields(): void
    {
        $ctx = $this->seedContext();
        $calc = app(CalculationWriter::class)->create($this->payload([
            $this->oaPosition($ctx, [
                'oa_targeting_selections' => [
                    ['oa_targeting_definition_id' => (int) $ctx['targeting']->id],
                ],
            ]),
        ]), $ctx['user']);
        $oa = $calc->positions->firstOrFail();
        $order = app(DispoOrderWriter::class)
            ->createFromCalculation($calc, [(int) $oa->id], $ctx['user'])
            ->order;

        $frozenPlatformName = $oa->oaPlatformAllocations->firstOrFail()->platform_name;
        $frozenTargetingName = $oa->oaTargetingSelections->firstOrFail()->targeting_name;
        $frozenListId = (int) $oa->oa_tkp_price_list_id;
        $frozenVersion = $oa->oa_tkp_price_list_version;

        $ctx['platform']->forceFill(['name' => 'LIVE RENAMED PLATFORM'])->save();
        $ctx['targeting']->forceFill(['name' => 'LIVE RENAMED TARGETING'])->save();

        $this->actingAs($ctx['user'])
            ->get(route('dispo-orders.show', $order))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('dispo-orders/show')
                ->has('order.positions', 1)
                ->where('order.positions.0.calculation_method_key', 'tkp')
                ->where('order.positions.0.engine_profile_key', 'oa_tkp')
                ->where('order.positions.0.impressions', 100_000)
                ->where('order.positions.0.final_tkp', '12.0000')
                ->where('order.positions.0.basis_tkp', '10.0000')
                ->where('order.positions.0.list_tkp_snapshot', '12.0000')
                ->where('order.positions.0.minimum_tkp_snapshot', '8.0000')
                ->where('order.positions.0.targeting_surcharge_total', '2.0000')
                ->where('order.positions.0.oa_tkp_price_list_id', $frozenListId)
                ->where('order.positions.0.oa_tkp_price_list_version', $frozenVersion)
                ->where('order.positions.0.oa_platform_allocations_snapshot.0.platform_name', $frozenPlatformName)
                ->where('order.positions.0.oa_targeting_selections_snapshot.0.targeting_name', $frozenTargetingName)
                ->where('order.positions.0.oa_targeting_selections_snapshot.0.targeting_revision_number', 1)
                ->where('order.positions.0.total_spot_count', 0)
                ->where('order.positions.0.length_seconds', 0));

        $this->assertNotSame('LIVE RENAMED PLATFORM', $frozenPlatformName);
        $this->assertNotSame('LIVE RENAMED TARGETING', $frozenTargetingName);
    }

    #[Test]
    public function mixed_show_exposes_spot_and_oa_positions(): void
    {
        $ctx = $this->seedContext();
        $spot = $this->createSpotFixture();
        $calc = app(CalculationWriter::class)->create($this->payload([
            $this->spotPosition($spot),
            $this->oaPosition($ctx),
        ]), $ctx['user']);

        $spotId = (int) $calc->positions->first(fn ($p): bool => $p->engine_profile_key !== 'oa_tkp')->id;
        $oaId = (int) $calc->positions->first(fn ($p): bool => $p->engine_profile_key === 'oa_tkp')->id;

        $order = app(DispoOrderWriter::class)
            ->createFromCalculation($calc, [$spotId, $oaId], $ctx['user'])
            ->order;

        $this->actingAs($ctx['user'])
            ->get(route('dispo-orders.show', $order))
            ->assertOk()
            ->assertInertia(function (Assert $page): void {
                $page->component('dispo-orders/show')->has('order.positions', 2);
                $positions = collect($page->toArray()['props']['order']['positions'] ?? []);
                $spotRow = $positions->first(fn (array $row): bool => ($row['engine_profile_key'] ?? null) !== 'oa_tkp');
                $oaRow = $positions->first(fn (array $row): bool => ($row['engine_profile_key'] ?? null) === 'oa_tkp');
                $this->assertNotNull($spotRow);
                $this->assertNotNull($oaRow);
                $this->assertSame(10, (int) $spotRow['total_spot_count']);
                $this->assertSame(30, (int) $spotRow['length_seconds']);
                $this->assertNull($spotRow['impressions']);
                $this->assertSame(100_000, (int) $oaRow['impressions']);
                $this->assertSame('tkp', $oaRow['calculation_method_key']);
            });
    }

    #[Test]
    public function spot_only_show_remains_compatible(): void
    {
        $ctx = $this->seedContext();
        $spot = $this->createSpotFixture();
        $calc = app(CalculationWriter::class)->create($this->payload([
            $this->spotPosition($spot),
        ]), $ctx['user']);
        $spotId = (int) $calc->positions->firstOrFail()->id;
        $order = app(DispoOrderWriter::class)
            ->createFromCalculation($calc, [$spotId], $ctx['user'])
            ->order;

        $this->actingAs($ctx['user'])
            ->get(route('dispo-orders.show', $order))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('dispo-orders/show')
                ->has('order.positions', 1)
                ->where('order.positions.0.total_spot_count', 10)
                ->where('order.positions.0.length_seconds', 30)
                ->where('order.positions.0.impressions', null)
                ->where('order.positions.0.final_tkp', null)
                ->where('order.positions.0.oa_platform_allocations_snapshot', [])
                ->where('order.positions.0.oa_targeting_selections_snapshot', []));
    }

    #[Test]
    public function show_authorization_unchanged_for_oa_orders(): void
    {
        $ctx = $this->seedContext();
        $calc = app(CalculationWriter::class)->create($this->payload([
            $this->oaPosition($ctx),
        ]), $ctx['user']);
        $order = app(DispoOrderWriter::class)
            ->createFromCalculation($calc, [(int) $calc->positions->firstOrFail()->id], $ctx['user'])
            ->order;

        $this->actingAs($ctx['user'])
            ->get(route('dispo-orders.show', $order))
            ->assertOk();

        $this->actingAs(User::factory()->role(Role::ProductManagement)->create())
            ->get(route('dispo-orders.show', $order))
            ->assertForbidden();
    }

    #[Test]
    public function registry_remains_planned(): void
    {
        $this->assertSame(
            EngineCapabilityStatus::Planned,
            EngineProfileRegistry::pairStatus('oa_tkp', 'tkp'),
        );
        $this->assertNull(EngineProfileRegistry::currentReleasedVersion('oa_tkp', 'tkp'));
        $this->assertSame(0, DispoOrderPosition::query()->count());
    }

    /**
     * @param  list<array<string, mixed>>  $positions
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function payload(array $positions, array $overrides = []): array
    {
        return $this->withLiveSchemaFingerprint(array_merge([
            'planning_mode' => 'manual',
            'order_discount_percent' => '0',
            'ae_enabled' => false,
            'positions' => $positions,
        ], $overrides));
    }

    /**
     * @param  array<string, mixed>  $ctx
     * @param  array<string, mixed>  $override
     * @return array<string, mixed>
     */
    private function oaPosition(array $ctx, array $override = []): array
    {
        return array_merge([
            'inventory_id' => (int) $ctx['inventory']->id,
            'advertising_medium_id' => (int) $ctx['medium']->id,
            'calculation_method_key' => 'tkp',
            'impressions' => 100_000,
            'basis_tkp' => '10.0000',
            'oa_platform_allocations' => [
                ['oa_platform_id' => (int) $ctx['platform']->id, 'impressions' => 100_000],
            ],
            'oa_targeting_selections' => [],
            'position_discount_percent' => '0',
            'ae_percent' => '15',
        ], $override);
    }

    /**
     * @return array{medium: AdvertisingMedium, inventory: Inventory}
     */
    private function createSpotFixture(): array
    {
        $medium = AdvertisingMedium::query()->where('code', 'spot_classic')->firstOrFail();
        $inventory = Inventory::factory()->create(['name' => 'D3c Dispo Spot', 'code' => 'D3CDISPOSPOT']);
        InventoryMediumRule::factory()->create([
            'inventory_id' => $inventory->id,
            'advertising_medium_id' => $medium->id,
        ]);
        $list = PriceList::factory()->create([
            'inventory_id' => $inventory->id,
            'status' => PriceListStatus::Active,
            'year' => PriceListCalendar::currentYear(),
            'version' => 'D3C-DISPO-SPOT',
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
    private function spotPosition(array $spot): array
    {
        return [
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
     * @return array{
     *     user: User,
     *     medium: AdvertisingMedium,
     *     inventory: Inventory,
     *     platform: OaPlatform,
     *     targeting: OaTargetingDefinition,
     *     list: OaTkpPriceList
     * }
     */
    private function seedContext(): array
    {
        $this->seed(InitialCatalogMatCoreSeeder::class);
        $user = User::factory()->role(Role::Sales)->create();
        $medium = AdvertisingMedium::query()->where('code', 'pre_stream')->firstOrFail();
        $inventory = Inventory::query()->where('code', 'inv_more_kombi_online_audio')->firstOrFail();
        $list = OaTkpPriceList::query()->create([
            'year' => PriceListCalendar::currentYear(),
            'name' => 'Fixture D3c Show',
            'version' => PriceListCalendar::currentYear().'.1',
            'revision_number' => 1,
            'lock_version' => 1,
            'status' => PriceListStatus::Active,
            'published_at' => now(),
        ]);
        OaTkpPriceListItem::query()->create([
            'oa_tkp_price_list_id' => $list->id,
            'advertising_medium_id' => (int) $medium->id,
            'inventory_id' => (int) $inventory->id,
            'list_tkp' => '12.0000',
            'minimum_tkp' => '8.0000',
        ]);
        $platform = OaPlatform::query()->create([
            'code' => 'fixture_d3c_plat',
            'name' => 'Fixture D3c Platform',
            'is_active' => true,
            'sort' => 1,
            'is_adserver_relevant' => false,
        ]);
        $targeting = OaTargetingDefinition::query()->create([
            'code' => 'geo_d3c',
            'name' => 'Geo D3c',
            'category' => OaTargetingCategory::Technisch,
            'tkp_surcharge' => '2.0000',
            'revision_number' => 1,
            'is_active' => true,
        ]);

        return compact('user', 'medium', 'inventory', 'platform', 'targeting', 'list');
    }
}
