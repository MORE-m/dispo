<?php

namespace Tests\Feature\OnlineAudio;

use App\Enums\DayGroup;
use App\Enums\DispoOrderStatus;
use App\Enums\EngineCapabilityStatus;
use App\Enums\OaTargetingCategory;
use App\Enums\PriceListStatus;
use App\Enums\Role;
use App\Models\AdvertisingMedium;
use App\Models\AuditEvent;
use App\Models\DispoOrder;
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
use App\Services\DispoOrder\DispoOrderApprovalService;
use App\Services\DispoOrder\DispoOrderCompletionService;
use App\Services\DispoOrder\DispoOrderOperationalFlightService;
use App\Services\DispoOrder\DispoOrderOperationalStatusService;
use App\Services\DispoOrder\DispoOrderWriter;
use App\Support\Calculation\EngineProfileRegistry;
use App\Support\DispoOrder\OperationalFlightContract;
use App\Support\PriceList\PriceListCalendar;
use Database\Seeders\InitialCatalogMatCoreSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\EnsuresCustomerConfirmationException;
use Tests\TestCase;

/**
 * PO-CALC-TKP-1D3d-2b-1: operativer Flight + disposed/completed Gates.
 */
class PoCalcTkp1d3d2b1OperationalFlightTest extends TestCase
{
    use EnsuresCustomerConfirmationException;
    use RefreshDatabase;

    #[Test]
    public function handoff_leaves_operational_flight_null_even_with_closed_calc_flight(): void
    {
        $ctx = $this->seedContext();
        $order = $this->createOaOrder($ctx, [
            'dynamic_field_values' => [
                'period_open' => false,
                'position_flight_period' => [
                    'start' => '2026-04-01',
                    'end' => '2026-04-30',
                ],
            ],
        ]);

        $position = $order->positions()->firstOrFail();
        $this->assertNull($position->operational_flight_start);
        $this->assertNull($position->operational_flight_end);

        $this->actingAs($ctx['user'])
            ->get(route('dispo-orders.show', $order))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('canUpdateOperationalFlight', false)
                ->where('order.positions.0.operational_flight_start', null)
                ->where('order.positions.0.operational_flight_end', null)
                ->where('order.positions.0.operational_flight_valid', false)
                ->where('order.positions.0.dynamic_field_values.period_open', false)
                ->where('order.positions.0.dynamic_field_values.position_flight_period.start', '2026-04-01'));
    }

    #[Test]
    public function disposition_can_set_and_change_operational_flight_with_audit(): void
    {
        $ctx = $this->seedContext();
        ['order' => $order, 'disposition' => $disposition, 'position' => $position] = $this->orderAtDisposition($ctx);

        $this->actingAs($disposition)
            ->get(route('dispo-orders.show', $order))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('canUpdateOperationalFlight', true));

        $this->actingAs($disposition)->putJson(
            route('dispo-orders.positions.operational-flight', [$order, $position]),
            [
                'lock_version' => $order->lock_version,
                'operational_flight_start' => '2026-05-01',
                'operational_flight_end' => '2026-05-31',
            ],
        )->assertOk();

        $order->refresh();
        $position->refresh();
        $this->assertSame('2026-05-01', $position->operational_flight_start?->toDateString());
        $this->assertSame('2026-05-31', $position->operational_flight_end?->toDateString());

        $audit = AuditEvent::query()
            ->where('action', 'dispo_order.operational_flight_updated')
            ->where('auditable_id', $order->id)
            ->latest('id')
            ->firstOrFail();
        $this->assertArrayHasKey('operational_flight_start', $audit->old_values ?? []);
        $this->assertArrayHasKey('operational_flight_end', $audit->old_values ?? []);
        $this->assertNull($audit->old_values['operational_flight_start']);
        $this->assertNull($audit->old_values['operational_flight_end']);
        $this->assertSame('2026-05-01', $audit->new_values['operational_flight_start'] ?? null);
        $this->assertSame('2026-05-31', $audit->new_values['operational_flight_end'] ?? null);
        $this->assertSame((int) $position->id, (int) ($audit->new_values['position_id'] ?? 0));

        $this->actingAs($disposition)->putJson(
            route('dispo-orders.positions.operational-flight', [$order, $position]),
            [
                'lock_version' => $order->lock_version,
                'operational_flight_start' => '2026-06-01',
                'operational_flight_end' => '2026-06-15',
            ],
        )->assertOk();

        $position->refresh();
        $this->assertSame('2026-06-01', $position->operational_flight_start?->toDateString());
        $this->assertSame('2026-06-15', $position->operational_flight_end?->toDateString());
    }

    #[Test]
    public function invalid_flight_payloads_and_permissions_are_rejected(): void
    {
        $ctx = $this->seedContext();
        ['order' => $order, 'disposition' => $disposition, 'position' => $position] = $this->orderAtDisposition($ctx);
        $sales = User::factory()->role(Role::Sales)->create();

        $this->actingAs($sales)->putJson(
            route('dispo-orders.positions.operational-flight', [$order, $position]),
            [
                'lock_version' => $order->lock_version,
                'operational_flight_start' => '2026-05-01',
                'operational_flight_end' => '2026-05-31',
            ],
        )->assertForbidden();

        foreach ([
            ['2026-05-01', null],
            [null, '2026-05-31'],
            ['2026-05-31', '2026-05-01'],
        ] as [$start, $end]) {
            $this->actingAs($disposition)->putJson(
                route('dispo-orders.positions.operational-flight', [$order, $position]),
                [
                    'lock_version' => $order->fresh()->lock_version,
                    'operational_flight_start' => $start,
                    'operational_flight_end' => $end,
                ],
            )->assertUnprocessable();
        }

        $this->actingAs($disposition)->putJson(
            route('dispo-orders.positions.operational-flight', [$order, $position]),
            [
                'lock_version' => $order->fresh()->lock_version + 9,
                'operational_flight_start' => '2026-05-01',
                'operational_flight_end' => '2026-05-31',
            ],
        )->assertStatus(409);

        $other = $this->createOaOrder($ctx);
        $foreign = $other->positions()->firstOrFail();
        $this->actingAs($disposition)->putJson(
            route('dispo-orders.positions.operational-flight', [$order, $foreign]),
            [
                'lock_version' => $order->fresh()->lock_version,
                'operational_flight_start' => '2026-05-01',
                'operational_flight_end' => '2026-05-31',
            ],
        )->assertUnprocessable();
    }

    #[Test]
    public function spot_position_cannot_receive_operational_flight(): void
    {
        $ctx = $this->seedContext();
        $spot = $this->createSpotFixture();
        $calc = app(CalculationWriter::class)->create($this->payload([
            $this->spotAveragePosition($spot),
            $this->oaPosition($ctx),
        ]), $ctx['user']);
        $spotId = (int) $calc->positions->first(fn ($p): bool => $p->engine_profile_key === 'spot_classic')->id;
        $oaId = (int) $calc->positions->first(fn ($p): bool => $p->engine_profile_key === 'oa_tkp')->id;
        $order = app(DispoOrderWriter::class)
            ->createFromCalculation($calc, [$spotId, $oaId], $ctx['user'])
            ->order
            ->fresh();
        ['order' => $order, 'disposition' => $disposition] = $this->advanceToAtDisposition($order, $ctx['user']);
        $spotPos = $order->positions()->where('calculation_position_id', $spotId)->firstOrFail();

        $this->actingAs($disposition)->putJson(
            route('dispo-orders.positions.operational-flight', [$order, $spotPos]),
            [
                'lock_version' => $order->lock_version,
                'operational_flight_start' => '2026-05-01',
                'operational_flight_end' => '2026-05-31',
            ],
        )->assertUnprocessable();
    }

    #[Test]
    public function operational_flight_update_does_not_mutate_snapshots(): void
    {
        $ctx = $this->seedContext();
        ['order' => $order, 'disposition' => $disposition, 'position' => $position] = $this->orderAtDisposition($ctx, [
            'dynamic_field_values' => [
                'period_open' => false,
                'position_flight_period' => [
                    'start' => '2026-04-01',
                    'end' => '2026-04-30',
                ],
            ],
        ]);

        $before = [
            'derived_start' => $order->derived_campaign_period_start?->toDateString(),
            'derived_end' => $order->derived_campaign_period_end?->toDateString(),
            'derived_status' => $order->derived_campaign_period_status?->value,
            'derived_snapshot' => $order->derived_campaign_period_snapshot,
            'period_open' => $position->fieldValues()
                ->whereHas('snapshotFieldDefinition', fn ($q) => $q->where('key', 'period_open'))
                ->first()?->value_boolean,
            'flight_start' => $position->fieldValues()
                ->whereHas('snapshotFieldDefinition', fn ($q) => $q->where('key', 'position_flight_period'))
                ->first()?->value_period_start?->toDateString(),
            'list_id' => $position->oa_tkp_price_list_id,
            'final_tkp' => (string) $position->final_tkp,
            'platforms' => $position->oa_platform_allocations_snapshot,
        ];

        app(DispoOrderOperationalFlightService::class)->update(
            $order,
            $position,
            $disposition,
            $order->lock_version,
            '2026-07-01',
            '2026-07-31',
        );

        $order->refresh();
        $position->refresh();
        $this->assertSame($before['derived_start'], $order->derived_campaign_period_start?->toDateString());
        $this->assertSame($before['derived_end'], $order->derived_campaign_period_end?->toDateString());
        $this->assertSame($before['derived_status'], $order->derived_campaign_period_status?->value);
        $this->assertSame($before['derived_snapshot'], $order->derived_campaign_period_snapshot);
        $this->assertFalse((bool) $position->fieldValues()
            ->whereHas('snapshotFieldDefinition', fn ($q) => $q->where('key', 'period_open'))
            ->first()?->value_boolean);
        $this->assertSame('2026-04-01', $position->fieldValues()
            ->whereHas('snapshotFieldDefinition', fn ($q) => $q->where('key', 'position_flight_period'))
            ->first()?->value_period_start?->toDateString());
        $this->assertSame($before['list_id'], $position->oa_tkp_price_list_id);
        $this->assertSame($before['final_tkp'], (string) $position->final_tkp);
        $this->assertSame($before['platforms'], $position->oa_platform_allocations_snapshot);
        $this->assertSame('2026-07-01', $position->operational_flight_start?->toDateString());
    }

    #[Test]
    public function disposed_requires_operational_flight_for_oa_including_mixed(): void
    {
        $ctx = $this->seedContext();
        ['order' => $order, 'disposition' => $disposition] = $this->orderInProgress($ctx);

        try {
            app(DispoOrderOperationalStatusService::class)->transition(
                $order,
                $disposition,
                $order->lock_version,
                DispoOrderStatus::Disposed,
            );
            $this->fail('disposed ohne Ops-Flight muss fehlschlagen.');
        } catch (ValidationException $e) {
            $this->assertStringContainsString(
                OperationalFlightContract::LABEL,
                collect($e->errors())->flatten()->implode(' '),
            );
        }
        $this->assertSame(DispoOrderStatus::InProgress, $order->fresh()->status);

        $position = $order->positions()->firstOrFail();
        app(DispoOrderOperationalFlightService::class)->update(
            $order->fresh(),
            $position,
            $disposition,
            $order->fresh()->lock_version,
            '2026-05-01',
            '2026-05-31',
        );

        $order = app(DispoOrderOperationalStatusService::class)->transition(
            $order->fresh(),
            $disposition,
            $order->fresh()->lock_version,
            DispoOrderStatus::Disposed,
        );
        $this->assertSame(DispoOrderStatus::Disposed, $order->status);

        // Mixed: Spot ok, OA ohne Flight blockiert.
        $spot = $this->createSpotFixture();
        $calc = app(CalculationWriter::class)->create($this->payload([
            $this->spotAveragePosition($spot),
            $this->oaPosition($ctx),
        ]), $ctx['user']);
        $ids = $calc->positions()->pluck('id')->map(fn ($id) => (int) $id)->all();
        $mixed = app(DispoOrderWriter::class)->createFromCalculation($calc, $ids, $ctx['user'])->order->fresh();
        ['order' => $mixed, 'disposition' => $disp2] = $this->advanceToInProgress($mixed, $ctx['user']);

        try {
            app(DispoOrderOperationalStatusService::class)->transition(
                $mixed,
                $disp2,
                $mixed->lock_version,
                DispoOrderStatus::Disposed,
            );
            $this->fail('Mixed ohne OA-Ops-Flight muss blockieren.');
        } catch (ValidationException) {
            $this->assertSame(DispoOrderStatus::InProgress, $mixed->fresh()->status);
        }
    }

    #[Test]
    public function all_oa_positions_must_have_flight_before_disposed(): void
    {
        $ctx = $this->seedContext();
        $calc = app(CalculationWriter::class)->create($this->payload([
            $this->oaPosition($ctx, [
                'impressions' => 50_000,
                'oa_platform_allocations' => [
                    ['oa_platform_id' => (int) $ctx['platform']->id, 'impressions' => 50_000],
                ],
            ]),
            $this->oaPosition($ctx, [
                'impressions' => 50_000,
                'oa_platform_allocations' => [
                    ['oa_platform_id' => (int) $ctx['platform']->id, 'impressions' => 50_000],
                ],
            ]),
        ]), $ctx['user']);
        $ids = $calc->positions()->orderBy('sort')->pluck('id')->map(fn ($id) => (int) $id)->all();
        $order = app(DispoOrderWriter::class)->createFromCalculation($calc, $ids, $ctx['user'])->order->fresh();
        ['order' => $order, 'disposition' => $disposition] = $this->advanceToInProgress($order, $ctx['user']);

        $positions = $order->positions()->orderBy('sort')->get();
        app(DispoOrderOperationalFlightService::class)->update(
            $order,
            $positions[0],
            $disposition,
            $order->lock_version,
            '2026-01-01',
            '2026-01-31',
        );

        try {
            app(DispoOrderOperationalStatusService::class)->transition(
                $order->fresh(),
                $disposition,
                $order->fresh()->lock_version,
                DispoOrderStatus::Disposed,
            );
            $this->fail('Zweite OA-Position ohne Flight muss blockieren.');
        } catch (ValidationException) {
            $this->assertTrue(true);
        }

        app(DispoOrderOperationalFlightService::class)->update(
            $order->fresh(),
            $positions[1],
            $disposition,
            $order->fresh()->lock_version,
            '2026-02-01',
            '2026-02-28',
        );

        $order = app(DispoOrderOperationalStatusService::class)->transition(
            $order->fresh(),
            $disposition,
            $order->fresh()->lock_version,
            DispoOrderStatus::Disposed,
        );
        $this->assertSame(DispoOrderStatus::Disposed, $order->status);
    }

    #[Test]
    public function force_complete_cannot_bypass_missing_operational_flight(): void
    {
        $ctx = $this->seedContext();
        ['order' => $order, 'disposition' => $disposition, 'position' => $position] = $this->orderInProgress($ctx);
        app(DispoOrderOperationalFlightService::class)->update(
            $order,
            $position,
            $disposition,
            $order->lock_version,
            '2026-05-01',
            '2026-05-31',
        );
        $order = app(DispoOrderOperationalStatusService::class)->transition(
            $order->fresh(),
            $disposition,
            $order->fresh()->lock_version,
            DispoOrderStatus::Disposed,
        );

        // Historisches/Defense-Szenario: Ops-Flight nach disposed entzogen.
        DB::table('dispo_order_positions')->where('id', $position->id)->update([
            'operational_flight_start' => null,
            'operational_flight_end' => null,
        ]);

        $admin = User::factory()->role(Role::Admin)->create();
        $this->actingAs($admin)->postJson(route('dispo-orders.complete', $order->fresh()), [
            'lock_version' => $order->fresh()->lock_version,
            'override_reason' => 'Admin versucht Flight zu umgehen.',
        ])->assertUnprocessable();

        $this->assertSame(DispoOrderStatus::Disposed, $order->fresh()->status);
        $this->assertSame(0, AuditEvent::query()->where('action', 'dispo_order.completed')->count());

        try {
            app(DispoOrderCompletionService::class)->complete(
                $order->fresh(),
                $admin,
                $order->fresh()->lock_version,
                'direkter Service-Override',
            );
            $this->fail('forceComplete darf Ops-Flight nicht umgehen.');
        } catch (ValidationException $e) {
            $this->assertStringContainsString(
                OperationalFlightContract::LABEL,
                collect($e->errors())->flatten()->implode(' '),
            );
        }
    }

    #[Test]
    public function release_protection_remains_planned(): void
    {
        $this->assertSame(
            EngineCapabilityStatus::Planned,
            EngineProfileRegistry::pairStatus('oa_tkp', 'tkp'),
        );
        $this->assertNull(EngineProfileRegistry::currentReleasedVersion('oa_tkp', 'tkp'));
    }

    /**
     * @param  array<string, mixed>  $oaOverride
     */
    private function createOaOrder(array $ctx, array $oaOverride = []): DispoOrder
    {
        $calc = app(CalculationWriter::class)->create($this->payload([
            $this->oaPosition($ctx, $oaOverride),
        ]), $ctx['user']);
        $oaId = (int) $calc->positions->firstOrFail()->id;

        return app(DispoOrderWriter::class)
            ->createFromCalculation($calc, [$oaId], $ctx['user'])
            ->order
            ->fresh();
    }

    /**
     * @param  array<string, mixed>  $oaOverride
     * @return array{order: DispoOrder, disposition: User, position: DispoOrderPosition}
     */
    private function orderAtDisposition(array $ctx, array $oaOverride = []): array
    {
        $order = $this->createOaOrder($ctx, $oaOverride);

        return $this->advanceToAtDisposition($order, $ctx['user']);
    }

    /**
     * @param  array<string, mixed>  $oaOverride
     * @return array{order: DispoOrder, disposition: User, position: DispoOrderPosition}
     */
    private function orderInProgress(array $ctx, array $oaOverride = []): array
    {
        $order = $this->createOaOrder($ctx, $oaOverride);

        return $this->advanceToInProgress($order, $ctx['user']);
    }

    /**
     * @return array{order: DispoOrder, disposition: User, position: DispoOrderPosition}
     */
    private function advanceToAtDisposition(DispoOrder $order, User $creator): array
    {
        $approver = User::factory()->role(Role::Sales)->create();
        $disposition = User::factory()->role(Role::Disposition)->create();
        $order = $this->seedCustomerConfirmationException($order, $creator);
        $approvals = app(DispoOrderApprovalService::class);
        $submitted = $approvals->submit($order, $creator, $order->lock_version);
        $order = $approvals->approve($submitted, $approver, $submitted->lock_version, null, true);

        return [
            'order' => $order->fresh(),
            'disposition' => $disposition,
            'position' => $order->fresh()->positions()->firstOrFail(),
        ];
    }

    /**
     * @return array{order: DispoOrder, disposition: User, position: DispoOrderPosition}
     */
    private function advanceToInProgress(DispoOrder $order, User $creator): array
    {
        ['order' => $order, 'disposition' => $disposition, 'position' => $position] = $this->advanceToAtDisposition($order, $creator);
        $order = app(DispoOrderOperationalStatusService::class)->transition(
            $order,
            $disposition,
            $order->lock_version,
            DispoOrderStatus::InProgress,
        );

        return [
            'order' => $order,
            'disposition' => $disposition,
            'position' => $position->fresh(),
        ];
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
        $inventory = Inventory::factory()->create(['name' => 'D3d2b1 Spot', 'code' => 'D3D2B1SPOT']);
        InventoryMediumRule::factory()->create([
            'inventory_id' => $inventory->id,
            'advertising_medium_id' => $medium->id,
        ]);
        $list = PriceList::factory()->create([
            'inventory_id' => $inventory->id,
            'status' => PriceListStatus::Active,
            'year' => PriceListCalendar::currentYear(),
            'version' => 'D3D2B1-SPOT',
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
    private function spotAveragePosition(array $spot): array
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
            'dynamic_field_values' => ['period_open' => true],
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
            'name' => 'Fixture D3d2b1 Flight',
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
            'code' => 'fixture_d3d2b1_plat',
            'name' => 'Fixture D3d2b1 Platform',
            'is_active' => true,
            'sort' => 1,
            'is_adserver_relevant' => false,
        ]);
        $targeting = OaTargetingDefinition::query()->create([
            'code' => 'geo_d3d2b1',
            'name' => 'Geo D3d2b1',
            'category' => OaTargetingCategory::Technisch,
            'tkp_surcharge' => '2.0000',
            'revision_number' => 1,
            'is_active' => true,
        ]);

        return compact('user', 'medium', 'inventory', 'platform', 'targeting', 'list');
    }
}
