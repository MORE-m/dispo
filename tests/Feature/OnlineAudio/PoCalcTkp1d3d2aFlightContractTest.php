<?php

namespace Tests\Feature\OnlineAudio;

use App\Enums\DayGroup;
use App\Enums\DerivedCampaignPeriodStatus;
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
use App\Support\DispoOrder\DerivedCampaignPeriodContract;
use App\Support\PriceList\PriceListCalendar;
use Database\Seeders\InitialCatalogMatCoreSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * PO-CALC-TKP-1D3d-2a: OA-TKP Flight-Vertrag (bestehend) über Handoff/Show absichern.
 * Keine neuen Pflichtfelder, keine Status-/Completion-Gates.
 */
class PoCalcTkp1d3d2aFlightContractTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function oa_closed_flight_persists_derived_complete_from_flight_source(): void
    {
        $ctx = $this->seedContext();
        $calc = app(CalculationWriter::class)->create($this->payload([
            $this->oaPosition($ctx, [
                'dynamic_field_values' => [
                    'period_open' => false,
                    'position_flight_period' => [
                        'start' => '2026-04-01',
                        'end' => '2026-04-30',
                    ],
                ],
            ]),
        ]), $ctx['user']);

        $oaId = (int) $calc->positions->firstOrFail()->id;
        $order = app(DispoOrderWriter::class)
            ->createFromCalculation($calc, [$oaId], $ctx['user'])
            ->order
            ->fresh();

        $this->assertSame('2026-04-01', $order->derived_campaign_period_start?->toDateString());
        $this->assertSame('2026-04-30', $order->derived_campaign_period_end?->toDateString());
        $this->assertSame(DerivedCampaignPeriodStatus::Complete, $order->derived_campaign_period_status);
        $this->assertSame(
            DerivedCampaignPeriodContract::SOURCE_FLIGHT,
            $order->derived_campaign_period_snapshot['positions'][0]['source'] ?? null,
        );
        $this->assertSame('tkp', $order->derived_campaign_period_snapshot['positions'][0]['spot_method'] ?? null);

        $this->actingAs($ctx['user'])
            ->get(route('dispo-orders.show', $order))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('order.derived_campaign_period.status', 'complete')
                ->where('order.derived_campaign_period.start', '2026-04-01')
                ->where('order.derived_campaign_period.end', '2026-04-30')
                ->where('order.derived_campaign_period.derived_period_complete', true)
                ->where('order.positions.0.dynamic_field_values.period_open', false)
                ->where('order.positions.0.dynamic_field_values.position_flight_period.start', '2026-04-01')
                ->where('order.positions.0.dynamic_field_values.position_flight_period.end', '2026-04-30'));
    }

    #[Test]
    public function oa_open_flight_derives_open_and_show_has_no_concrete_derived_range(): void
    {
        $ctx = $this->seedContext();
        $calc = app(CalculationWriter::class)->create($this->payload([
            $this->oaPosition($ctx, [
                'dynamic_field_values' => [
                    'period_open' => true,
                    'position_flight_period' => null,
                ],
            ]),
        ]), $ctx['user']);

        $oaId = (int) $calc->positions->firstOrFail()->id;
        $order = app(DispoOrderWriter::class)
            ->createFromCalculation($calc, [$oaId], $ctx['user'])
            ->order
            ->fresh();

        $this->assertSame(DerivedCampaignPeriodStatus::Open, $order->derived_campaign_period_status);
        $this->assertNull($order->derived_campaign_period_start);
        $this->assertNull($order->derived_campaign_period_end);
        $this->assertSame(
            DerivedCampaignPeriodContract::REASON_PERIOD_OPEN,
            $order->derived_campaign_period_snapshot['positions'][0]['unresolved_reason'] ?? null,
        );

        $this->actingAs($ctx['user'])
            ->get(route('dispo-orders.show', $order))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('order.derived_campaign_period.status', 'open')
                ->where('order.derived_campaign_period.start', null)
                ->where('order.derived_campaign_period.end', null)
                ->where('order.derived_campaign_period.derived_period_complete', false)
                ->where('order.positions.0.dynamic_field_values.period_open', true));
    }

    #[Test]
    public function header_campaign_period_does_not_override_oa_derived_flight(): void
    {
        $ctx = $this->seedContext();
        $calc = app(CalculationWriter::class)->create($this->payload([
            $this->oaPosition($ctx, [
                'dynamic_field_values' => [
                    'period_open' => false,
                    'position_flight_period' => [
                        'start' => '2026-04-01',
                        'end' => '2026-04-30',
                    ],
                ],
            ]),
        ], [
            'dynamic_field_values' => [
                'campaign_period' => ['start' => '2026-01-01', 'end' => '2026-01-31'],
            ],
        ]), $ctx['user']);

        $oaId = (int) $calc->positions->firstOrFail()->id;
        $order = app(DispoOrderWriter::class)
            ->createFromCalculation($calc, [$oaId], $ctx['user'])
            ->order
            ->fresh();

        $this->assertSame('2026-04-01', $order->derived_campaign_period_start?->toDateString());
        $this->assertSame('2026-04-30', $order->derived_campaign_period_end?->toDateString());

        $header = $order->fieldValues()
            ->with('snapshotFieldDefinition')
            ->get()
            ->keyBy(fn ($v) => $v->snapshotFieldDefinition?->key);
        $this->assertSame('2026-01-01', $header['campaign_period']->value_period_start?->toDateString());
        $this->assertSame('2026-01-31', $header['campaign_period']->value_period_end?->toDateString());

        $this->actingAs($ctx['user'])
            ->get(route('dispo-orders.show', $order))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('order.derived_campaign_period.start', '2026-04-01')
                ->where('order.derived_campaign_period.end', '2026-04-30')
                ->where('order.derived_campaign_period.conflict_with_calculation', true));
    }

    #[Test]
    public function two_oa_positions_with_different_flights_form_envelope(): void
    {
        $ctx = $this->seedContext();
        $calc = app(CalculationWriter::class)->create($this->payload([
            $this->oaPosition($ctx, [
                'impressions' => 50_000,
                'oa_platform_allocations' => [
                    ['oa_platform_id' => (int) $ctx['platform']->id, 'impressions' => 50_000],
                ],
                'dynamic_field_values' => [
                    'period_open' => false,
                    'position_flight_period' => [
                        'start' => '2026-02-01',
                        'end' => '2026-02-15',
                    ],
                ],
            ]),
            $this->oaPosition($ctx, [
                'impressions' => 50_000,
                'oa_platform_allocations' => [
                    ['oa_platform_id' => (int) $ctx['platform']->id, 'impressions' => 50_000],
                ],
                'dynamic_field_values' => [
                    'period_open' => false,
                    'position_flight_period' => [
                        'start' => '2026-05-10',
                        'end' => '2026-05-20',
                    ],
                ],
            ]),
        ]), $ctx['user']);

        $ids = $calc->positions()->orderBy('sort')->pluck('id')->map(fn ($id) => (int) $id)->all();
        $order = app(DispoOrderWriter::class)
            ->createFromCalculation($calc, $ids, $ctx['user'])
            ->order
            ->fresh();

        $this->assertSame(DerivedCampaignPeriodStatus::Complete, $order->derived_campaign_period_status);
        $this->assertSame('2026-02-01', $order->derived_campaign_period_start?->toDateString());
        $this->assertSame('2026-05-20', $order->derived_campaign_period_end?->toDateString());
        $this->assertSame(2, $order->derived_campaign_period_snapshot['contributing_count'] ?? null);
    }

    #[Test]
    public function mixed_spot_calendar_and_oa_flight_derives_envelope(): void
    {
        $ctx = $this->seedContext();
        $spot = $this->createSpotFixture();
        $year = PriceListCalendar::currentYear();
        $calc = app(CalculationWriter::class)->create($this->payload([
            $this->spotCalendarPosition($spot, [
                ['date' => sprintf('%04d-03-01', $year), 'hour' => 8, 'spot_count' => 1],
                ['date' => sprintf('%04d-03-10', $year), 'hour' => 9, 'spot_count' => 1],
            ]),
            $this->oaPosition($ctx, [
                'dynamic_field_values' => [
                    'period_open' => false,
                    'position_flight_period' => [
                        'start' => '2026-01-15',
                        'end' => '2026-01-31',
                    ],
                ],
            ]),
        ]), $ctx['user']);

        $spotId = (int) $calc->positions->first(fn ($p): bool => $p->engine_profile_key === 'spot_classic')->id;
        $oaId = (int) $calc->positions->first(fn ($p): bool => $p->engine_profile_key === 'oa_tkp')->id;

        $order = app(DispoOrderWriter::class)
            ->createFromCalculation($calc, [$spotId, $oaId], $ctx['user'])
            ->order
            ->fresh();

        $this->assertSame(DerivedCampaignPeriodStatus::Complete, $order->derived_campaign_period_status);
        $this->assertSame('2026-01-15', $order->derived_campaign_period_start?->toDateString());
        $this->assertSame(sprintf('%04d-03-10', $year), $order->derived_campaign_period_end?->toDateString());

        $sources = array_column($order->derived_campaign_period_snapshot['positions'] ?? [], 'source');
        $this->assertContains(DerivedCampaignPeriodContract::SOURCE_PLANNER, $sources);
        $this->assertContains(DerivedCampaignPeriodContract::SOURCE_FLIGHT, $sources);
    }

    #[Test]
    public function calc_change_after_handoff_does_not_change_frozen_derived(): void
    {
        $ctx = $this->seedContext();
        $calc = app(CalculationWriter::class)->create($this->payload([
            $this->oaPosition($ctx, [
                'dynamic_field_values' => [
                    'period_open' => false,
                    'position_flight_period' => [
                        'start' => '2026-04-01',
                        'end' => '2026-04-30',
                    ],
                ],
            ]),
        ]), $ctx['user']);

        $oaId = (int) $calc->positions->firstOrFail()->id;
        $order = app(DispoOrderWriter::class)
            ->createFromCalculation($calc, [$oaId], $ctx['user'])
            ->order
            ->fresh();

        $derivedAt = $order->derived_campaign_period_at?->toIso8601String();
        $snapshot = $order->derived_campaign_period_snapshot;

        $freshCalc = $calc->fresh();
        $updatePayload = app(CalculationWriter::class)->payloadFromCalculation($freshCalc);
        $updatePayload['lock_version'] = $freshCalc->lock_version;
        $updatePayload['positions'][0]['dynamic_field_values']['period_open'] = false;
        $updatePayload['positions'][0]['dynamic_field_values']['position_flight_period'] = [
            'start' => '2026-12-01',
            'end' => '2026-12-31',
        ];
        $updatePayload['positions'][0]['expected_oa_tkp_price_list_id'] = (int) $order->positions()
            ->where('line_role', DispoOrderPosition::LINE_ROLE_MEDIA)
            ->value('oa_tkp_price_list_id');
        unset(
            $updatePayload['positions'][0]['engine_profile_key'],
            $updatePayload['positions'][0]['list_tkp_snapshot'],
            $updatePayload['positions'][0]['minimum_tkp_snapshot'],
            $updatePayload['positions'][0]['final_tkp'],
            $updatePayload['positions'][0]['targeting_surcharge_total'],
            $updatePayload['positions'][0]['below_minimum_tkp'],
            $updatePayload['positions'][0]['length_seconds'],
            $updatePayload['positions'][0]['total_spot_count'],
            $updatePayload['positions'][0]['plan_rows'],
            $updatePayload['positions'][0]['time_ranges'],
            $updatePayload['positions'][0]['planner_entries'],
        );
        app(CalculationWriter::class)->update($freshCalc, $updatePayload, $ctx['user']);

        $order->refresh();
        $this->assertSame('2026-04-01', $order->derived_campaign_period_start?->toDateString());
        $this->assertSame('2026-04-30', $order->derived_campaign_period_end?->toDateString());
        $this->assertSame($derivedAt, $order->derived_campaign_period_at?->toIso8601String());
        $this->assertSame($snapshot, $order->derived_campaign_period_snapshot);
    }

    #[Test]
    public function legacy_simulated_oa_order_presents_legacy_status(): void
    {
        $ctx = $this->seedContext();
        $calc = app(CalculationWriter::class)->create($this->payload([
            $this->oaPosition($ctx, [
                'dynamic_field_values' => [
                    'period_open' => false,
                    'position_flight_period' => [
                        'start' => '2026-04-01',
                        'end' => '2026-04-30',
                    ],
                ],
            ]),
        ]), $ctx['user']);

        $oaId = (int) $calc->positions->firstOrFail()->id;
        $order = app(DispoOrderWriter::class)
            ->createFromCalculation($calc, [$oaId], $ctx['user'])
            ->order
            ->fresh();

        DB::table('dispo_orders')->where('id', $order->id)->update([
            'derived_campaign_period_start' => null,
            'derived_campaign_period_end' => null,
            'derived_campaign_period_status' => DerivedCampaignPeriodStatus::Legacy->value,
            'derived_campaign_period_at' => null,
            'derived_campaign_period_snapshot' => null,
        ]);

        $order->refresh();
        $this->assertSame(DerivedCampaignPeriodStatus::Legacy, $order->derived_campaign_period_status);

        $this->actingAs($ctx['user'])
            ->get(route('dispo-orders.show', $order))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('order.derived_campaign_period.status', 'legacy')
                ->where('order.derived_campaign_period.start', null)
                ->where('order.derived_campaign_period.end', null)
                ->where('order.derived_campaign_period.derived_period_complete', false));
    }

    #[Test]
    public function release_protection_remains_planned_closed(): void
    {
        $this->assertSame(
            EngineCapabilityStatus::Planned,
            EngineProfileRegistry::pairStatus('oa_tkp', 'tkp'),
        );
        $this->assertNull(EngineProfileRegistry::currentReleasedVersion('oa_tkp', 'tkp'));
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
            'dynamic_field_values' => ['campaign_period' => null],
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
        $inventory = Inventory::factory()->create(['name' => 'D3d2a Dispo Spot', 'code' => 'D3D2ADISPOSPOT']);
        InventoryMediumRule::factory()->create([
            'inventory_id' => $inventory->id,
            'advertising_medium_id' => $medium->id,
        ]);
        $list = PriceList::factory()->create([
            'inventory_id' => $inventory->id,
            'status' => PriceListStatus::Active,
            'year' => PriceListCalendar::currentYear(),
            'version' => 'D3D2A-DISPO-SPOT',
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
     * @param  list<array{date: string, hour: int, spot_count: int}>  $entries
     * @return array<string, mixed>
     */
    private function spotCalendarPosition(array $spot, array $entries): array
    {
        return [
            'inventory_id' => $spot['inventory']->id,
            'advertising_medium_id' => $spot['medium']->id,
            'spot_method' => 'calendar',
            'calculation_method_key' => 'calendar',
            'length_seconds' => 30,
            'position_discount_percent' => '0',
            'ae_percent' => '15',
            'planner_entries' => $entries,
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
            'name' => 'Fixture D3d2a Flight',
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
            'code' => 'fixture_d3d2a_plat',
            'name' => 'Fixture D3d2a Platform',
            'is_active' => true,
            'sort' => 1,
            'is_adserver_relevant' => false,
        ]);
        $targeting = OaTargetingDefinition::query()->create([
            'code' => 'geo_d3d2a',
            'name' => 'Geo D3d2a',
            'category' => OaTargetingCategory::Technisch,
            'tkp_surcharge' => '2.0000',
            'revision_number' => 1,
            'is_active' => true,
        ]);

        return compact('user', 'medium', 'inventory', 'platform', 'targeting', 'list');
    }
}
