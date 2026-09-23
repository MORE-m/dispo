<?php

namespace Tests\Feature\DispoOrder;

use App\Enums\DerivedCampaignPeriodStatus;
use App\Enums\DispoOrderStatus;
use App\Enums\Role;
use App\Models\DispoOrder;
use App\Models\User;
use App\Services\Calculation\CalculationWriter;
use App\Services\DispoOrder\DispoOrderWriter;
use App\Services\DynamicField\ConfigurationSnapshotFreezeService;
use App\Support\DispoOrder\DerivedCampaignPeriodContract;
use App\Support\PriceList\PriceListCalendar;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\CreatesSpotClassicCatalog;
use Tests\Concerns\EnsuresCustomerConfirmationException;
use Tests\TestCase;

/**
 * DSP-DCP-001: Feature-Ableitung über Writer, Show-Props, Draft-Reject, Legacy.
 */
class DerivedCampaignPeriodFeatureTest extends TestCase
{
    use CreatesSpotClassicCatalog;
    use EnsuresCustomerConfirmationException;
    use RefreshDatabase;

    public function test_create_calendar_persists_derived_columns(): void
    {
        $year = PriceListCalendar::currentYear();
        $start = sprintf('%04d-09-14', $year);
        $end = sprintf('%04d-09-20', $year);

        $order = $this->createOrderFromPayload($this->calendarPayload([
            ['date' => $end, 'hour' => 14, 'spot_count' => 2],
            ['date' => $start, 'hour' => 8, 'spot_count' => 1],
            ['date' => sprintf('%04d-09-16', $year), 'hour' => 9, 'spot_count' => 0],
        ]));

        $order->refresh();
        $this->assertSame($start, $order->derived_campaign_period_start?->toDateString());
        $this->assertSame($end, $order->derived_campaign_period_end?->toDateString());
        $this->assertSame(DerivedCampaignPeriodStatus::Complete, $order->derived_campaign_period_status);
        $this->assertNotNull($order->derived_campaign_period_at);
        $this->assertSame(
            DerivedCampaignPeriodContract::CONTRACT_VERSION,
            $order->derived_campaign_period_snapshot['contract_version'] ?? null,
        );
        $this->assertSame(1, $order->derived_campaign_period_snapshot['contributing_count'] ?? null);
    }

    public function test_create_average_with_closed_flight_persists_derived_columns(): void
    {
        $order = $this->createOrderFromPayload($this->averagePayload(
            flightStart: '2026-03-01',
            flightEnd: '2026-03-31',
            periodOpen: false,
        ));

        $order->refresh();
        $this->assertSame('2026-03-01', $order->derived_campaign_period_start?->toDateString());
        $this->assertSame('2026-03-31', $order->derived_campaign_period_end?->toDateString());
        $this->assertSame(DerivedCampaignPeriodStatus::Complete, $order->derived_campaign_period_status);
    }

    public function test_partial_position_selection_derives_only_selected(): void
    {
        $year = PriceListCalendar::currentYear();
        $catalog = $this->createSpotClassicCatalog();
        $user = User::factory()->role(Role::Sales)->create();
        $fp = $this->fingerprints($catalog);

        $calculation = app(CalculationWriter::class)->create([
            'planning_mode' => 'manual',
            'customer_name' => 'Teilauswahl GmbH',
            'order_discount_percent' => '0',
            'schema_fingerprint' => $fp['header'],
            'dynamic_field_values' => ['campaign_period' => null],
            'positions' => [
                [
                    'inventory_id' => $catalog['hamburg']->id,
                    'advertising_medium_id' => $catalog['medium']->id,
                    'schema_fingerprint' => $fp['position'],
                    'spot_method' => 'calendar',
                    'calculation_method_key' => 'calendar',
                    'length_seconds' => 30,
                    'position_discount_percent' => '0',
                    'ae_percent' => '0',
                    'planner_entries' => [
                        ['date' => sprintf('%04d-02-01', $year), 'hour' => 8, 'spot_count' => 1],
                        ['date' => sprintf('%04d-02-10', $year), 'hour' => 8, 'spot_count' => 1],
                    ],
                ],
                [
                    'inventory_id' => $catalog['rock']->id,
                    'advertising_medium_id' => $catalog['medium']->id,
                    'schema_fingerprint' => $fp['position'],
                    'spot_method' => 'calendar',
                    'calculation_method_key' => 'calendar',
                    'length_seconds' => 30,
                    'position_discount_percent' => '0',
                    'ae_percent' => '0',
                    'planner_entries' => [
                        ['date' => sprintf('%04d-08-01', $year), 'hour' => 8, 'spot_count' => 1],
                        ['date' => sprintf('%04d-08-20', $year), 'hour' => 8, 'spot_count' => 1],
                    ],
                ],
            ],
        ], $user);

        $firstId = $calculation->positions()->orderBy('sort')->firstOrFail()->id;
        $order = app(DispoOrderWriter::class)
            ->createFromCalculation($calculation, [$firstId], $user)
            ->order
            ->fresh();

        $this->assertSame(1, $order->positions()->count());
        $this->assertSame(sprintf('%04d-02-01', $year), $order->derived_campaign_period_start?->toDateString());
        $this->assertSame(sprintf('%04d-02-10', $year), $order->derived_campaign_period_end?->toDateString());
        $this->assertSame(DerivedCampaignPeriodStatus::Complete, $order->derived_campaign_period_status);
    }

    public function test_two_orders_same_calc_can_have_different_periods(): void
    {
        $year = PriceListCalendar::currentYear();
        $catalog = $this->createSpotClassicCatalog();
        $user = User::factory()->role(Role::Sales)->create();
        $fp = $this->fingerprints($catalog);

        $calculation = app(CalculationWriter::class)->create([
            'planning_mode' => 'manual',
            'customer_name' => 'Zwei Auftraege GmbH',
            'order_discount_percent' => '0',
            'schema_fingerprint' => $fp['header'],
            'dynamic_field_values' => ['campaign_period' => null],
            'positions' => [
                [
                    'inventory_id' => $catalog['hamburg']->id,
                    'advertising_medium_id' => $catalog['medium']->id,
                    'schema_fingerprint' => $fp['position'],
                    'spot_method' => 'calendar',
                    'calculation_method_key' => 'calendar',
                    'length_seconds' => 30,
                    'position_discount_percent' => '0',
                    'ae_percent' => '0',
                    'planner_entries' => [
                        ['date' => sprintf('%04d-01-05', $year), 'hour' => 8, 'spot_count' => 1],
                    ],
                ],
                [
                    'inventory_id' => $catalog['rock']->id,
                    'advertising_medium_id' => $catalog['medium']->id,
                    'schema_fingerprint' => $fp['position'],
                    'spot_method' => 'calendar',
                    'calculation_method_key' => 'calendar',
                    'length_seconds' => 30,
                    'position_discount_percent' => '0',
                    'ae_percent' => '0',
                    'planner_entries' => [
                        ['date' => sprintf('%04d-11-05', $year), 'hour' => 8, 'spot_count' => 1],
                    ],
                ],
            ],
        ], $user);

        $ids = $calculation->positions()->orderBy('sort')->pluck('id')->all();
        $orderA = app(DispoOrderWriter::class)
            ->createFromCalculation($calculation, [$ids[0]], $user)
            ->order
            ->fresh();
        $orderB = app(DispoOrderWriter::class)
            ->createFromCalculation($calculation, [$ids[1]], $user)
            ->order
            ->fresh();

        $this->assertSame(sprintf('%04d-01-05', $year), $orderA->derived_campaign_period_start?->toDateString());
        $this->assertSame(sprintf('%04d-11-05', $year), $orderB->derived_campaign_period_start?->toDateString());
        $this->assertNotSame(
            $orderA->derived_campaign_period_start?->toDateString(),
            $orderB->derived_campaign_period_start?->toDateString(),
        );
    }

    public function test_campaign_period_calc_origin_remains_independent(): void
    {
        $year = PriceListCalendar::currentYear();
        $plannerStart = sprintf('%04d-09-14', $year);
        $plannerEnd = sprintf('%04d-09-18', $year);

        $order = $this->createOrderFromPayload($this->calendarPayload(
            [
                ['date' => $plannerStart, 'hour' => 8, 'spot_count' => 1],
                ['date' => $plannerEnd, 'hour' => 10, 'spot_count' => 2],
            ],
            campaignPeriod: ['start' => '2026-01-01', 'end' => '2026-01-31'],
        ));

        $header = $order->fieldValues()->with('snapshotFieldDefinition')->get()
            ->keyBy(fn ($row) => $row->snapshotFieldDefinition->key);
        $this->assertArrayHasKey('campaign_period', $header->all());
        $this->assertSame('2026-01-01', $header['campaign_period']->value_period_start?->toDateString());
        $this->assertSame('2026-01-31', $header['campaign_period']->value_period_end?->toDateString());

        $this->assertSame($plannerStart, $order->derived_campaign_period_start?->toDateString());
        $this->assertSame($plannerEnd, $order->derived_campaign_period_end?->toDateString());
    }

    public function test_divergent_campaign_period_does_not_block_create_but_flags_conflict(): void
    {
        $year = PriceListCalendar::currentYear();
        $plannerStart = sprintf('%04d-09-14', $year);
        $plannerEnd = sprintf('%04d-09-18', $year);
        $user = User::factory()->role(Role::Sales)->create();

        $order = $this->createOrderFromPayload($this->calendarPayload(
            [
                ['date' => $plannerStart, 'hour' => 8, 'spot_count' => 1],
                ['date' => $plannerEnd, 'hour' => 10, 'spot_count' => 2],
            ],
            campaignPeriod: ['start' => '2026-01-01', 'end' => '2026-01-31'],
            customerName: 'Konflikt GmbH',
        ), $user);

        $this->assertSame(DerivedCampaignPeriodStatus::Complete, $order->fresh()->derived_campaign_period_status);

        $this->actingAs($user)
            ->get(route('dispo-orders.show', $order))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('dispo-orders/show')
                ->where('order.derived_campaign_period.start', $plannerStart)
                ->where('order.derived_campaign_period.end', $plannerEnd)
                ->where('order.derived_campaign_period.status', 'complete')
                ->where('order.derived_campaign_period.conflict_with_calculation', true)
                ->where('order.derived_campaign_period.calculation_period_complete', true)
                ->where('order.derived_campaign_period.derived_period_complete', true)
                ->where('order.dynamic_field_values.campaign_period.start', '2026-01-01')
                ->where('order.dynamic_field_values.campaign_period.end', '2026-01-31'));
    }

    public function test_identical_periods_have_no_conflict(): void
    {
        $year = PriceListCalendar::currentYear();
        $start = sprintf('%04d-09-14', $year);
        $end = sprintf('%04d-09-18', $year);
        $user = User::factory()->role(Role::Sales)->create();

        $order = $this->createOrderFromPayload($this->calendarPayload(
            [
                ['date' => $start, 'hour' => 8, 'spot_count' => 1],
                ['date' => $end, 'hour' => 10, 'spot_count' => 2],
            ],
            campaignPeriod: ['start' => $start, 'end' => $end],
        ), $user);

        $this->actingAs($user)
            ->get(route('dispo-orders.show', $order))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('order.derived_campaign_period.conflict_with_calculation', false)
                ->where('order.derived_campaign_period.start', $start)
                ->where('order.derived_campaign_period.end', $end));
    }

    public function test_calc_change_after_create_does_not_change_derived(): void
    {
        $year = PriceListCalendar::currentYear();
        $original = sprintf('%04d-09-14', $year);
        $user = User::factory()->role(Role::Sales)->create();

        $calculation = app(CalculationWriter::class)->create(
            $this->calendarPayload([['date' => $original, 'hour' => 8, 'spot_count' => 2]]),
            $user,
        );
        $position = $calculation->positions()->firstOrFail();
        $order = app(DispoOrderWriter::class)
            ->createFromCalculation($calculation, [$position->id], $user)
            ->order
            ->fresh();

        $this->assertSame($original, $order->derived_campaign_period_start?->toDateString());
        $derivedAt = $order->derived_campaign_period_at?->toIso8601String();
        $snapshot = $order->derived_campaign_period_snapshot;

        $payload = app(CalculationWriter::class)->payloadFromCalculation($calculation->fresh());
        $payload['lock_version'] = $calculation->fresh()->lock_version;
        $payload['positions'][0]['planner_entries'] = [
            ['date' => sprintf('%04d-12-01', $year), 'hour' => 8, 'spot_count' => 9],
        ];
        app(CalculationWriter::class)->update($calculation->fresh(), $payload, $user);

        $order->refresh();
        $this->assertSame($original, $order->derived_campaign_period_start?->toDateString());
        $this->assertSame($original, $order->derived_campaign_period_end?->toDateString());
        $this->assertSame($derivedAt, $order->derived_campaign_period_at?->toIso8601String());
        $this->assertSame($snapshot, $order->derived_campaign_period_snapshot);
    }

    public function test_revision_gets_new_derivation(): void
    {
        $year = PriceListCalendar::currentYear();
        $firstDate = sprintf('%04d-09-14', $year);
        $secondDate = sprintf('%04d-10-01', $year);
        $creator = User::factory()->role(Role::Sales)->create();

        $calculation = app(CalculationWriter::class)->create(
            $this->calendarPayload([['date' => $firstDate, 'hour' => 8, 'spot_count' => 2]], customerName: 'Revision GmbH'),
            $creator,
        );
        $positionIds = $calculation->positions()->pluck('id')->all();

        $this->actingAs($creator)->post(route('dispo-orders.store', $calculation), [
            'position_ids' => $positionIds,
        ])->assertRedirect();

        $order = DispoOrder::query()->latest('id')->firstOrFail();
        $this->assertSame($firstDate, $order->derived_campaign_period_start?->toDateString());

        $order = $this->seedCustomerConfirmationException($order, $creator);
        $this->actingAs($creator)->postJson(route('dispo-orders.submit', $order), [
            'lock_version' => $order->lock_version,
        ])->assertOk();
        $order->refresh();

        $decider = User::factory()->role(Role::Sales)->create();
        $this->actingAs($decider)->postJson(route('dispo-orders.reject', $order), [
            'lock_version' => $order->lock_version,
            'reason' => 'Zeitraum korrigieren',
        ])->assertOk();
        $order->refresh();

        $payload = app(CalculationWriter::class)->payloadFromCalculation($calculation->fresh());
        $payload['lock_version'] = $calculation->fresh()->lock_version;
        $payload['positions'][0]['planner_entries'] = [
            ['date' => $secondDate, 'hour' => 8, 'spot_count' => 4],
        ];
        app(CalculationWriter::class)->update($calculation->fresh(), $payload, $creator);
        $calculation->refresh()->load('positions');

        $this->actingAs($creator)->post(route('dispo-orders.store', $calculation), [
            'position_ids' => $calculation->positions()->pluck('id')->all(),
            'revises_dispo_order_id' => $order->id,
        ])->assertRedirect();

        $revision = DispoOrder::query()->where('revises_dispo_order_id', $order->id)->firstOrFail();
        $this->assertSame($firstDate, $order->fresh()->derived_campaign_period_start?->toDateString());
        $this->assertSame($secondDate, $revision->derived_campaign_period_start?->toDateString());
        $this->assertSame($secondDate, $revision->derived_campaign_period_end?->toDateString());
        $this->assertSame(DerivedCampaignPeriodStatus::Complete, $revision->derived_campaign_period_status);
    }

    public function test_legacy_simulated_order_presents_legacy_status(): void
    {
        $user = User::factory()->role(Role::Sales)->create();
        $order = $this->createOrderFromPayload($this->calendarPayload([
            ['date' => sprintf('%04d-09-14', PriceListCalendar::currentYear()), 'hour' => 8, 'spot_count' => 1],
        ]), $user);

        DB::table('dispo_orders')->where('id', $order->id)->update([
            'derived_campaign_period_start' => null,
            'derived_campaign_period_end' => null,
            'derived_campaign_period_status' => DerivedCampaignPeriodStatus::Legacy->value,
            'derived_campaign_period_at' => null,
            'derived_campaign_period_snapshot' => null,
        ]);

        $order->refresh();
        $this->assertSame(DerivedCampaignPeriodStatus::Legacy, $order->derived_campaign_period_status);

        $this->actingAs($user)
            ->get(route('dispo-orders.show', $order))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('order.derived_campaign_period.status', 'legacy')
                ->where('order.derived_campaign_period.start', null)
                ->where('order.derived_campaign_period.end', null)
                ->where('order.derived_campaign_period.conflict_with_calculation', false)
                ->where('order.derived_campaign_period.derived_period_complete', false));
    }

    public function test_draft_update_rejects_derived_keys_and_campaign_period(): void
    {
        $user = User::factory()->role(Role::Sales)->create();
        $order = $this->createOrderFromPayload($this->calendarPayload([
            ['date' => sprintf('%04d-09-14', PriceListCalendar::currentYear()), 'hour' => 8, 'spot_count' => 1],
        ]), $user);

        $this->actingAs($user)->patch(route('dispo-orders.update', $order), [
            'lock_version' => $order->lock_version,
            'dynamic_field_values' => [
                'billing_special_features' => null,
                'disposition_notes' => null,
            ],
            'derived_campaign_period_start' => '2026-01-01',
        ])->assertSessionHasErrors('derived_campaign_period_start');

        $this->actingAs($user)->patch(route('dispo-orders.update', $order), [
            'lock_version' => $order->lock_version,
            'dynamic_field_values' => [
                'billing_special_features' => null,
                'disposition_notes' => null,
            ],
            'derived_campaign_period' => ['start' => '2026-01-01', 'end' => '2026-01-31'],
        ])->assertSessionHasErrors('derived_campaign_period');

        $this->actingAs($user)->patch(route('dispo-orders.update', $order), [
            'lock_version' => $order->lock_version,
            'dynamic_field_values' => [
                'billing_special_features' => null,
                'disposition_notes' => null,
                'campaign_period' => ['start' => '2026-01-01', 'end' => '2026-01-31'],
            ],
        ])->assertSessionHasErrors('dynamic_field_values.campaign_period');

        $order->refresh();
        $this->assertSame(DispoOrderStatus::Draft, $order->status);
        $this->assertSame(DerivedCampaignPeriodStatus::Complete, $order->derived_campaign_period_status);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function createOrderFromPayload(array $payload, ?User $user = null): DispoOrder
    {
        $user ??= User::factory()->role(Role::Sales)->create();
        $calculation = app(CalculationWriter::class)->create($payload, $user);
        $ids = $calculation->positions()->pluck('id')->all();

        return app(DispoOrderWriter::class)
            ->createFromCalculation($calculation, $ids, $user)
            ->order;
    }

    /**
     * @param  list<array{date: string, hour: int, spot_count: int}>  $entries
     * @param  array{start: string, end: string}|null  $campaignPeriod
     * @return array<string, mixed>
     */
    private function calendarPayload(
        array $entries,
        ?array $campaignPeriod = null,
        string $customerName = 'Derived Calendar GmbH',
    ): array {
        $catalog = $this->createSpotClassicCatalog();
        $fp = $this->fingerprints($catalog);

        return [
            'planning_mode' => 'manual',
            'customer_name' => $customerName,
            'order_discount_percent' => '0',
            'schema_fingerprint' => $fp['header'],
            'dynamic_field_values' => [
                'campaign_period' => $campaignPeriod,
            ],
            'positions' => [[
                'inventory_id' => $catalog['hamburg']->id,
                'advertising_medium_id' => $catalog['medium']->id,
                'schema_fingerprint' => $fp['position'],
                'spot_method' => 'calendar',
                'calculation_method_key' => 'calendar',
                'length_seconds' => 30,
                'position_discount_percent' => '0',
                'ae_percent' => '0',
                'planner_entries' => $entries,
            ]],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function averagePayload(
        string $flightStart,
        string $flightEnd,
        bool $periodOpen = false,
        string $customerName = 'Derived Average GmbH',
    ): array {
        $catalog = $this->createSpotClassicCatalog();
        $fp = $this->fingerprints($catalog);

        return [
            'planning_mode' => 'manual',
            'customer_name' => $customerName,
            'order_discount_percent' => '0',
            'schema_fingerprint' => $fp['header'],
            'dynamic_field_values' => [
                'campaign_period' => null,
            ],
            'positions' => [[
                'inventory_id' => $catalog['hamburg']->id,
                'advertising_medium_id' => $catalog['medium']->id,
                'schema_fingerprint' => $fp['position'],
                'spot_method' => 'average',
                'length_seconds' => 30,
                'total_spot_count' => 10,
                'position_discount_percent' => '0',
                'ae_percent' => '0',
                'plan_rows' => [['hour' => 8, 'day_group' => 'mo_fr']],
                'time_ranges' => [[
                    'start_hour' => 8,
                    'end_hour_exclusive' => 9,
                    'day_group' => 'mo_fr',
                    'spot_count' => 10,
                ]],
                'dynamic_field_values' => [
                    'period_open' => $periodOpen,
                    'position_flight_period' => $periodOpen ? null : [
                        'start' => $flightStart,
                        'end' => $flightEnd,
                    ],
                ],
            ]],
        ];
    }

    /**
     * @param  array{hamburg: mixed, rock: mixed, medium: mixed}  $catalog
     * @return array{header: string, position: string}
     */
    private function fingerprints(array $catalog): array
    {
        return [
            'header' => (string) app(ConfigurationSnapshotFreezeService::class)
                ->resolveLiveSchemaForCalculationV3()['schema_fingerprint'],
            'position' => (string) app(ConfigurationSnapshotFreezeService::class)
                ->resolveLivePositionSchema((int) $catalog['medium']->id)['schema_fingerprint'],
        ];
    }
}
