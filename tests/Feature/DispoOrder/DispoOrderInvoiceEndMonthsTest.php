<?php

namespace Tests\Feature\DispoOrder;

use App\Enums\DispoOrderStatus;
use App\Enums\Role;
use App\Models\AuditEvent;
use App\Models\DispoOrder;
use App\Models\DispoOrderPosition;
use App\Models\User;
use App\Services\Calculation\CalculationWriter;
use App\Services\DispoOrder\DispoOrderApprovalService;
use App\Services\DispoOrder\DispoOrderOperationalStatusService;
use App\Services\DynamicField\ConfigurationSnapshotFreezeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\CreatesSpotClassicCatalog;
use Tests\Concerns\EnsuresCustomerConfirmationException;
use Tests\TestCase;

class DispoOrderInvoiceEndMonthsTest extends TestCase
{
    use CreatesSpotClassicCatalog;
    use EnsuresCustomerConfirmationException;
    use RefreshDatabase;

    /**
     * @return array{order: DispoOrder, position: DispoOrderPosition, disposition: User, creator: User}
     */
    private function orderInProgressWithConcretePeriod(): array
    {
        $catalog = $this->createSpotClassicCatalog();
        $creator = User::factory()->role(Role::Sales)->create();
        $approver = User::factory()->role(Role::Sales)->create();
        $disposition = User::factory()->role(Role::Disposition)->create();
        $fp = [
            'header' => (string) app(ConfigurationSnapshotFreezeService::class)
                ->resolveLiveSchemaForCalculationV3()['schema_fingerprint'],
            'position' => (string) app(ConfigurationSnapshotFreezeService::class)
                ->resolveLivePositionSchema((int) $catalog['medium']->id)['schema_fingerprint'],
        ];

        $calculation = app(CalculationWriter::class)->create([
            'planning_mode' => 'manual',
            'customer_name' => 'Invoice End GmbH',
            'order_discount_percent' => '0',
            'schema_fingerprint' => $fp['header'],
            'dynamic_field_values' => ['campaign_period' => null],
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
                    'period_open' => false,
                    'position_flight_period' => [
                        'start' => '2026-03-01',
                        'end' => '2026-03-31',
                    ],
                ],
            ]],
        ], $creator);

        $this->actingAs($creator)->post(route('dispo-orders.store', $calculation), [
            'position_ids' => [$calculation->positions()->first()->id],
        ])->assertRedirect();

        $order = DispoOrder::query()->latest('id')->firstOrFail();
        $order = $this->seedCustomerConfirmationException($order, $creator);
        $approvals = app(DispoOrderApprovalService::class);
        $submitted = $approvals->submit($order, $creator, $order->lock_version);
        $approved = $approvals->approve($submitted, $approver, $submitted->lock_version, null, true);

        $ops = app(DispoOrderOperationalStatusService::class);
        $inProgress = $ops->transition(
            $approved,
            $disposition,
            $approved->lock_version,
            DispoOrderStatus::InProgress,
        );

        return [
            'order' => $inProgress->fresh(['positions']),
            'position' => $inProgress->positions()->orderBy('id')->firstOrFail(),
            'disposition' => $disposition,
            'creator' => $creator,
        ];
    }

    public function test_disposition_admin_management_can_update_sales_pm_forbidden(): void
    {
        ['order' => $order, 'position' => $position, 'disposition' => $disposition] = $this->orderInProgressWithConcretePeriod();

        $this->actingAs($disposition)->putJson(
            route('dispo-orders.positions.invoice-end', [$order, $position]),
            ['lock_version' => $order->lock_version, 'months' => [3, 1]],
        )->assertOk();

        $order->refresh();
        $position->refresh();
        $this->assertSame([1, 3], $position->invoice_end_months);
        $this->assertSame($order->lock_version, $order->getOriginal('lock_version') ?: $order->lock_version);

        $admin = User::factory()->role(Role::Admin)->create();
        $this->actingAs($admin)->putJson(
            route('dispo-orders.positions.invoice-end', [$order, $position]),
            ['lock_version' => $order->lock_version, 'months' => [12]],
        )->assertOk();

        $order->refresh();
        $management = User::factory()->role(Role::Management)->create();
        $this->actingAs($management)->putJson(
            route('dispo-orders.positions.invoice-end', [$order, $position]),
            ['lock_version' => $order->lock_version, 'months' => [2, 4]],
        )->assertOk();

        $sales = User::factory()->role(Role::Sales)->create();
        $this->actingAs($sales)->putJson(
            route('dispo-orders.positions.invoice-end', [$order->fresh(), $position]),
            ['lock_version' => $order->fresh()->lock_version, 'months' => [5]],
        )->assertForbidden();

        $pm = User::factory()->role(Role::ProductManagement)->create();
        $this->actingAs($pm)->putJson(
            route('dispo-orders.positions.invoice-end', [$order->fresh(), $position]),
            ['lock_version' => $order->fresh()->lock_version, 'months' => [5]],
        )->assertForbidden();
    }

    public function test_editable_and_locked_statuses(): void
    {
        ['order' => $order, 'position' => $position, 'disposition' => $disposition] = $this->orderInProgressWithConcretePeriod();
        $ops = app(DispoOrderOperationalStatusService::class);

        foreach ([
            DispoOrderStatus::MaterialMissing,
            DispoOrderStatus::MaterialReceived,
            DispoOrderStatus::InProgress,
        ] as $status) {
            if ($order->status !== $status) {
                $order = $ops->transition($order, $disposition, $order->lock_version, $status);
            }
            $this->actingAs($disposition)->putJson(
                route('dispo-orders.positions.invoice-end', [$order, $position]),
                ['lock_version' => $order->lock_version, 'months' => [1]],
            )->assertOk();
            $order->refresh();
        }

        $order = $ops->transition($order, $disposition, $order->lock_version, DispoOrderStatus::Disposed);
        $this->actingAs($disposition)->putJson(
            route('dispo-orders.positions.invoice-end', [$order, $position]),
            ['lock_version' => $order->lock_version, 'months' => [2]],
        )->assertUnprocessable();
    }

    public function test_validation_and_canonical_sort_and_empty(): void
    {
        ['order' => $order, 'position' => $position, 'disposition' => $disposition] = $this->orderInProgressWithConcretePeriod();

        $this->actingAs($disposition)->putJson(
            route('dispo-orders.positions.invoice-end', [$order, $position]),
            ['lock_version' => $order->lock_version, 'months' => [0]],
        )->assertUnprocessable();

        $this->actingAs($disposition)->putJson(
            route('dispo-orders.positions.invoice-end', [$order, $position]),
            ['lock_version' => $order->lock_version, 'months' => [13]],
        )->assertUnprocessable();

        $this->actingAs($disposition)->putJson(
            route('dispo-orders.positions.invoice-end', [$order, $position]),
            ['lock_version' => $order->lock_version, 'months' => ['März']],
        )->assertUnprocessable();

        $this->actingAs($disposition)->putJson(
            route('dispo-orders.positions.invoice-end', [$order, $position]),
            ['lock_version' => $order->lock_version, 'months' => [3, 3]],
        )->assertUnprocessable();

        $before = $order->lock_version;
        $this->actingAs($disposition)->putJson(
            route('dispo-orders.positions.invoice-end', [$order, $position]),
            ['lock_version' => $order->lock_version, 'months' => [12, 1, 6]],
        )->assertOk();

        $position->refresh();
        $order->refresh();
        $this->assertSame([1, 6, 12], $position->invoice_end_months);
        $this->assertSame($before + 1, $order->lock_version);
        $this->assertDatabaseHas('audit_events', [
            'action' => 'dispo_order.invoice_end_months_updated',
            'auditable_type' => DispoOrder::class,
            'auditable_id' => $order->id,
        ]);

        $this->actingAs($disposition)->putJson(
            route('dispo-orders.positions.invoice-end', [$order, $position]),
            ['lock_version' => $order->lock_version, 'months' => []],
        )->assertOk();
        $position->refresh();
        $this->assertNull($position->invoice_end_months);
    }

    public function test_stale_lock_returns_409_without_audit(): void
    {
        ['order' => $order, 'position' => $position, 'disposition' => $disposition] = $this->orderInProgressWithConcretePeriod();
        $beforeAudits = AuditEvent::query()
            ->where('action', 'dispo_order.invoice_end_months_updated')
            ->count();

        $this->actingAs($disposition)->putJson(
            route('dispo-orders.positions.invoice-end', [$order, $position]),
            ['lock_version' => $order->lock_version + 5, 'months' => [1]],
        )->assertStatus(409);

        $this->assertSame(
            $beforeAudits,
            AuditEvent::query()->where('action', 'dispo_order.invoice_end_months_updated')->count(),
        );
    }

    public function test_at_disposition_editable(): void
    {
        ['order' => $order, 'position' => $position, 'disposition' => $disposition] = $this->orderInProgressWithConcretePeriod();

        DB::table('dispo_orders')
            ->where('id', $order->id)
            ->update(['status' => DispoOrderStatus::AtDisposition->value]);
        $order->refresh();

        $this->actingAs($disposition)->putJson(
            route('dispo-orders.positions.invoice-end', [$order, $position]),
            ['lock_version' => $order->lock_version, 'months' => [5]],
        )->assertOk();
    }
}
