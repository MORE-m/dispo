<?php

namespace Tests\Feature\OnlineAudio;

use App\Enums\DayGroup;
use App\Enums\EngineCapabilityStatus;
use App\Enums\OaTargetingCategory;
use App\Enums\PriceListStatus;
use App\Enums\Role;
use App\Models\AdvertisingMedium;
use App\Models\DispoOrder;
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
use App\Services\Calculation\Decimal;
use App\Services\DispoOrder\DispoOrderWriter;
use App\Support\Calculation\EngineProfileRegistry;
use App\Support\Calculation\OaTkpWizardCapability;
use App\Support\PriceList\PriceListCalendar;
use Database\Seeders\InitialCatalogMatCoreSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * PO-CALC-TKP-1D3b: Adoption-API + Create-Dialog-Metadaten für OA-TKP.
 */
class PoCalcTkp1d3bDispoAdoptionTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function spot_only_positions_response_keeps_existing_fields(): void
    {
        $ctx = $this->seedContext();
        $spot = $this->createSpotFixture();
        $calc = app(CalculationWriter::class)->create($this->payload([
            $this->spotPosition($spot),
        ]), $ctx['user']);

        $response = $this->actingAs($ctx['user'])
            ->getJson(route('dispo-orders.positions', $calc))
            ->assertOk();

        $row = $response->json('positions.0');
        $this->assertSame((int) $calc->positions->firstOrFail()->id, (int) $row['id']);
        $this->assertArrayHasKey('length_seconds', $row);
        $this->assertArrayHasKey('total_spot_count', $row);
        $this->assertArrayHasKey('nn_invest', $row);
        $this->assertArrayHasKey('already_adopted', $row);
        $this->assertArrayHasKey('adoptions', $row);
        $this->assertArrayHasKey('calculation_method_key', $row);
        $this->assertArrayHasKey('engine_profile_key', $row);
        $this->assertArrayHasKey('impressions', $row);
        $this->assertArrayHasKey('final_tkp', $row);
        $this->assertNull($row['impressions']);
        $this->assertNull($row['final_tkp']);
        $this->assertSame(30, (int) $row['length_seconds']);
        $this->assertSame(10, (int) $row['total_spot_count']);
        $this->assertFalse($row['already_adopted']);
    }

    #[Test]
    public function oa_only_positions_response_exposes_stored_tkp_fields(): void
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

        $row = $this->actingAs($ctx['user'])
            ->getJson(route('dispo-orders.positions', $calc))
            ->assertOk()
            ->json('positions.0');

        $this->assertSame((int) $oa->id, (int) $row['id']);
        $this->assertSame('tkp', $row['calculation_method_key']);
        $this->assertSame('oa_tkp', $row['engine_profile_key']);
        $this->assertSame(100_000, (int) $row['impressions']);
        $this->assertSame(
            Decimal::roundPrice((string) $oa->final_tkp),
            $row['final_tkp'],
        );
        $this->assertSame('12.0000', $row['final_tkp']);
        $this->assertFalse($row['already_adopted']);
    }

    #[Test]
    public function mixed_positions_response_lists_spot_and_oa(): void
    {
        $ctx = $this->seedContext();
        $spot = $this->createSpotFixture();
        $calc = app(CalculationWriter::class)->create($this->payload([
            $this->spotPosition($spot),
            $this->oaPosition($ctx),
        ]), $ctx['user']);

        $rows = collect($this->actingAs($ctx['user'])
            ->getJson(route('dispo-orders.positions', $calc))
            ->assertOk()
            ->json('positions'));

        $this->assertCount(2, $rows);
        $spotRow = $rows->first(fn (array $row): bool => ($row['engine_profile_key'] ?? null) !== 'oa_tkp');
        $oaRow = $rows->first(fn (array $row): bool => ($row['engine_profile_key'] ?? null) === 'oa_tkp');
        $this->assertNotNull($spotRow);
        $this->assertNotNull($oaRow);
        $this->assertNull($spotRow['impressions']);
        $this->assertSame(100_000, (int) $oaRow['impressions']);
        $this->assertSame('tkp', $oaRow['calculation_method_key']);
    }

    #[Test]
    public function adoption_api_reflects_only_persisted_calculation_positions(): void
    {
        $ctx = $this->seedContext();
        $calc = app(CalculationWriter::class)->create($this->payload([
            $this->oaPosition($ctx),
        ]), $ctx['user']);
        $oa = $calc->positions->firstOrFail();

        $before = $this->actingAs($ctx['user'])
            ->getJson(route('dispo-orders.positions', $calc))
            ->assertOk()
            ->json('positions.0');

        $this->assertSame(100_000, (int) $before['impressions']);
        $this->assertSame(
            Decimal::roundPrice((string) $oa->final_tkp),
            $before['final_tkp'],
        );

        // Simuliert eine gespeicherte Calc-Änderung (nicht Wizard-Draft).
        $oa->forceFill([
            'impressions' => 250_000,
            'final_tkp' => '12.5000',
        ])->save();

        $after = $this->actingAs($ctx['user'])
            ->getJson(route('dispo-orders.positions', $calc->fresh()))
            ->assertOk()
            ->json('positions.0');

        $this->assertSame(250_000, (int) $after['impressions']);
        $this->assertSame('12.5000', $after['final_tkp']);
        $this->assertSame((int) $oa->id, (int) $after['id']);
        $this->assertCount(
            1,
            $this->actingAs($ctx['user'])
                ->getJson(route('dispo-orders.positions', $calc->fresh()))
                ->json('positions'),
        );
    }

    #[Test]
    public function already_adopted_oa_positions_remain_selectable_for_repeat_handoff(): void
    {
        $ctx = $this->seedContext();
        $calc = app(CalculationWriter::class)->create($this->payload([
            $this->oaPosition($ctx),
        ]), $ctx['user']);
        $oaId = (int) $calc->positions->firstOrFail()->id;

        $order = app(DispoOrderWriter::class)
            ->createFromCalculation($calc, [$oaId], $ctx['user'])
            ->order;

        $row = $this->actingAs($ctx['user'])
            ->getJson(route('dispo-orders.positions', $calc))
            ->assertOk()
            ->json('positions.0');

        $this->assertTrue($row['already_adopted']);
        $this->assertSame($order->number, $row['adoptions'][0]['dispo_order_number']);

        app(DispoOrderWriter::class)->createFromCalculation($calc->fresh(), [$oaId], $ctx['user']);
        $this->assertSame(2, DispoOrder::query()->count());
    }

    #[Test]
    public function foreign_position_ids_are_rejected_for_oa_calculations(): void
    {
        $ctx = $this->seedContext();
        $calc = app(CalculationWriter::class)->create($this->payload([
            $this->oaPosition($ctx),
        ]), $ctx['user']);
        $other = app(CalculationWriter::class)->create($this->payload([
            $this->oaPosition($ctx, [
                'impressions' => 50_000,
                'oa_platform_allocations' => [
                    ['oa_platform_id' => (int) $ctx['platform']->id, 'impressions' => 50_000],
                ],
            ]),
        ]), $ctx['user']);

        $this->actingAs($ctx['user'])
            ->post(route('dispo-orders.store', $calc), [
                'position_ids' => [(int) $other->positions->firstOrFail()->id],
            ])
            ->assertSessionHasErrors('position_ids');

        $this->assertSame(0, DispoOrder::query()->count());
    }

    #[Test]
    public function disposition_cannot_read_adoption_positions(): void
    {
        $ctx = $this->seedContext();
        $calc = app(CalculationWriter::class)->create($this->payload([
            $this->oaPosition($ctx),
        ]), $ctx['user']);
        $disposition = User::factory()->role(Role::Disposition)->create();

        $this->actingAs($disposition)
            ->getJson(route('dispo-orders.positions', $calc))
            ->assertForbidden();
    }

    #[Test]
    public function registry_and_http_store_update_remain_closed(): void
    {
        $this->assertSame(
            EngineCapabilityStatus::Planned,
            EngineProfileRegistry::pairStatus('oa_tkp', 'tkp'),
        );
        $this->assertNull(EngineProfileRegistry::currentReleasedVersion('oa_tkp', 'tkp'));

        config([OaTkpWizardCapability::CONFIG_KEY => true]);
        $ctx = $this->seedContext();
        $payload = $this->payload([$this->oaPosition($ctx)]);

        $this->actingAs($ctx['user'])
            ->postJson(route('calculations.store'), $payload)
            ->assertStatus(422);

        $calc = app(CalculationWriter::class)->create($payload, $ctx['user']);
        $update = app(CalculationWriter::class)->payloadFromCalculation($calc);
        $update['lock_version'] = $calc->lock_version;
        foreach ($update['positions'] as $index => $position) {
            unset(
                $update['positions'][$index]['engine_profile_key'],
                $update['positions'][$index]['list_tkp_snapshot'],
                $update['positions'][$index]['minimum_tkp_snapshot'],
                $update['positions'][$index]['final_tkp'],
                $update['positions'][$index]['targeting_surcharge_total'],
                $update['positions'][$index]['below_minimum_tkp'],
                $update['positions'][$index]['length_seconds'],
                $update['positions'][$index]['total_spot_count'],
                $update['positions'][$index]['plan_rows'],
                $update['positions'][$index]['time_ranges'],
                $update['positions'][$index]['planner_entries'],
            );
        }

        $this->actingAs($ctx['user'])
            ->putJson(route('calculations.update', $calc), $update)
            ->assertStatus(422);
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
        $inventory = Inventory::factory()->create(['name' => 'D3b Dispo Spot', 'code' => 'D3BDISPOSPOT']);
        InventoryMediumRule::factory()->create([
            'inventory_id' => $inventory->id,
            'advertising_medium_id' => $medium->id,
        ]);
        $list = PriceList::factory()->create([
            'inventory_id' => $inventory->id,
            'status' => PriceListStatus::Active,
            'year' => PriceListCalendar::currentYear(),
            'version' => 'D3B-DISPO-SPOT',
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
            'name' => 'Fixture D3b Adoption',
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
            'code' => 'fixture_d3b_plat',
            'name' => 'Fixture D3b Platform',
            'is_active' => true,
            'sort' => 1,
            'is_adserver_relevant' => false,
        ]);
        $targeting = OaTargetingDefinition::query()->create([
            'code' => 'geo_d3b',
            'name' => 'Geo D3b',
            'category' => OaTargetingCategory::Technisch,
            'tkp_surcharge' => '2.0000',
            'revision_number' => 1,
            'is_active' => true,
        ]);

        return compact('user', 'medium', 'inventory', 'platform', 'targeting', 'list');
    }
}
