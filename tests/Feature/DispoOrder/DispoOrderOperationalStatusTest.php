<?php

namespace Tests\Feature\DispoOrder;

use App\Enums\DispoOrderStatus;
use App\Enums\Role;
use App\Models\AuditEvent;
use App\Models\DispoOrder;
use App\Models\DispoOrderStatusEvent;
use App\Models\User;
use App\Services\DispoOrder\DispoOrderApprovalService;
use App\Services\DispoOrder\DispoOrderOperationalStatusService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\CreatesSavedCalculation;
use Tests\Concerns\CreatesSpotClassicCatalog;
use Tests\Concerns\EnsuresCustomerConfirmationException;
use Tests\TestCase;

class DispoOrderOperationalStatusTest extends TestCase
{
    use CreatesSavedCalculation;
    use CreatesSpotClassicCatalog;
    use EnsuresCustomerConfirmationException;
    use RefreshDatabase;

    /**
     * @return array{order: DispoOrder, creator: User, disposition: User}
     */
    private function orderAtDisposition(): array
    {
        $catalog = $this->createSpotClassicCatalog();
        $creator = User::factory()->role(Role::Sales)->create();
        $approver = User::factory()->role(Role::Sales)->create();
        $disposition = User::factory()->role(Role::Disposition)->create();

        $calculation = $this->createSavedCalculation($catalog, [
            ['inventory_id' => $catalog['hamburg']->id],
        ], $creator);

        $this->actingAs($creator)->post(route('dispo-orders.store', $calculation), [
            'position_ids' => [$calculation->positions()->first()->id],
        ])->assertRedirect();

        $order = DispoOrder::query()->latest('id')->firstOrFail();
        $service = app(DispoOrderApprovalService::class);
        $order = $this->seedCustomerConfirmationException($order, $creator);
        $submitted = $service->submit($order, $creator, $order->lock_version);
        $approved = $service->approve($submitted, $approver, $submitted->lock_version, null, true);

        $this->assertSame(DispoOrderStatus::AtDisposition, $approved->status);

        return [
            'order' => $approved,
            'creator' => $creator,
            'disposition' => $disposition,
        ];
    }

    public function test_disposition_can_start_progress(): void
    {
        ['order' => $order, 'disposition' => $disposition] = $this->orderAtDisposition();
        $beforeLock = $order->lock_version;
        $nn = (string) $order->nn_invest;

        $this->actingAs($disposition)->postJson(route('dispo-orders.transition-status', $order), [
            'lock_version' => $order->lock_version,
            'target_status' => DispoOrderStatus::InProgress->value,
        ])->assertOk();

        $order->refresh();
        $this->assertSame(DispoOrderStatus::InProgress, $order->status);
        $this->assertSame($beforeLock + 1, $order->lock_version);
        $this->assertSame($nn, (string) $order->nn_invest);
        $this->assertDatabaseHas('dispo_order_status_events', [
            'dispo_order_id' => $order->id,
            'from_status' => DispoOrderStatus::AtDisposition->value,
            'to_status' => DispoOrderStatus::InProgress->value,
            'changed_by_id' => $disposition->id,
            'is_reopen' => 0,
            'lock_version_after' => $order->lock_version,
        ]);
        $this->assertDatabaseHas('audit_events', [
            'action' => 'dispo_order.status_changed',
            'auditable_type' => DispoOrder::class,
            'auditable_id' => $order->id,
        ]);
    }

    public function test_admin_and_management_allowed_sales_and_pm_forbidden(): void
    {
        ['order' => $order] = $this->orderAtDisposition();

        $admin = User::factory()->role(Role::Admin)->create();
        $this->actingAs($admin)->postJson(route('dispo-orders.transition-status', $order), [
            'lock_version' => $order->lock_version,
            'target_status' => DispoOrderStatus::InProgress->value,
        ])->assertOk();

        $order->refresh();
        $management = User::factory()->role(Role::Management)->create();
        $this->actingAs($management)->postJson(route('dispo-orders.transition-status', $order), [
            'lock_version' => $order->lock_version,
            'target_status' => DispoOrderStatus::MaterialMissing->value,
        ])->assertOk();

        $order->refresh();
        $this->actingAs(User::factory()->role(Role::Sales)->create())
            ->postJson(route('dispo-orders.transition-status', $order), [
                'lock_version' => $order->lock_version,
                'target_status' => DispoOrderStatus::InProgress->value,
            ])->assertForbidden();

        $this->actingAs(User::factory()->role(Role::ProductManagement)->create())
            ->postJson(route('dispo-orders.transition-status', $order), [
                'lock_version' => $order->lock_version,
                'target_status' => DispoOrderStatus::InProgress->value,
            ])->assertForbidden();
    }

    public function test_stale_lock_version_returns_conflict(): void
    {
        ['order' => $order, 'disposition' => $disposition] = $this->orderAtDisposition();

        $this->actingAs($disposition)->postJson(route('dispo-orders.transition-status', $order), [
            'lock_version' => $order->lock_version - 1,
            'target_status' => DispoOrderStatus::InProgress->value,
        ])->assertStatus(409);

        $this->assertSame(0, DispoOrderStatusEvent::query()->where('dispo_order_id', $order->id)->count());
        $this->assertSame(0, AuditEvent::query()->where('action', 'dispo_order.status_changed')->count());
    }

    public function test_invalid_target_status_returns_validation_error(): void
    {
        ['order' => $order, 'disposition' => $disposition] = $this->orderAtDisposition();

        $this->actingAs($disposition)->postJson(route('dispo-orders.transition-status', $order), [
            'lock_version' => $order->lock_version,
            'target_status' => DispoOrderStatus::Disposed->value,
        ])->assertStatus(422)->assertJsonValidationErrors(['target_status']);
    }

    public function test_forbidden_edge_from_in_progress_to_completed(): void
    {
        ['order' => $order, 'disposition' => $disposition] = $this->orderAtDisposition();
        app(DispoOrderOperationalStatusService::class)->transition(
            $order,
            $disposition,
            $order->lock_version,
            DispoOrderStatus::InProgress,
        );
        $order->refresh();

        $this->actingAs($disposition)->postJson(route('dispo-orders.transition-status', $order), [
            'lock_version' => $order->lock_version,
            'target_status' => DispoOrderStatus::Completed->value,
        ])->assertStatus(422);
    }

    public function test_material_flow_and_dispose(): void
    {
        ['order' => $order, 'disposition' => $disposition] = $this->orderAtDisposition();
        $service = app(DispoOrderOperationalStatusService::class);

        $order = $service->transition($order, $disposition, $order->lock_version, DispoOrderStatus::InProgress);
        $order = $service->transition($order, $disposition, $order->lock_version, DispoOrderStatus::MaterialMissing);
        $order = $service->transition($order, $disposition, $order->lock_version, DispoOrderStatus::MaterialReceived);
        $order = $service->transition($order, $disposition, $order->lock_version, DispoOrderStatus::Disposed);

        $this->assertSame(DispoOrderStatus::Disposed, $order->status);
        $this->assertSame(4, DispoOrderStatusEvent::query()->where('dispo_order_id', $order->id)->count());
    }

    public function test_reopen_requires_non_empty_reason(): void
    {
        ['order' => $order, 'disposition' => $disposition] = $this->orderAtDisposition();
        $service = app(DispoOrderOperationalStatusService::class);
        $order = $service->transition($order, $disposition, $order->lock_version, DispoOrderStatus::InProgress);
        $order = $service->transition($order, $disposition, $order->lock_version, DispoOrderStatus::Disposed);

        $this->actingAs($disposition)->postJson(route('dispo-orders.transition-status', $order), [
            'lock_version' => $order->lock_version,
            'target_status' => DispoOrderStatus::InProgress->value,
        ])->assertStatus(422)->assertJsonValidationErrors(['reason']);

        $this->actingAs($disposition)->postJson(route('dispo-orders.transition-status', $order), [
            'lock_version' => $order->lock_version,
            'target_status' => DispoOrderStatus::InProgress->value,
            'reason' => '   ',
        ])->assertStatus(422)->assertJsonValidationErrors(['reason']);

        $this->assertSame(0, DispoOrderStatusEvent::query()
            ->where('dispo_order_id', $order->id)
            ->where('is_reopen', true)
            ->count());
    }

    public function test_reopen_with_reason_succeeds_and_records_history(): void
    {
        ['order' => $order, 'disposition' => $disposition] = $this->orderAtDisposition();
        $service = app(DispoOrderOperationalStatusService::class);
        $order = $service->transition($order, $disposition, $order->lock_version, DispoOrderStatus::InProgress);
        $order = $service->transition($order, $disposition, $order->lock_version, DispoOrderStatus::Disposed);
        $before = $order->lock_version;

        $this->actingAs($disposition)->postJson(route('dispo-orders.transition-status', $order), [
            'lock_version' => $order->lock_version,
            'target_status' => DispoOrderStatus::InProgress->value,
            'reason' => 'Kunde ändert Spotzeiten.',
        ])->assertOk();

        $order->refresh();
        $this->assertSame(DispoOrderStatus::InProgress, $order->status);
        $this->assertSame($before + 1, $order->lock_version);
        $this->assertDatabaseHas('dispo_order_status_events', [
            'dispo_order_id' => $order->id,
            'from_status' => DispoOrderStatus::Disposed->value,
            'to_status' => DispoOrderStatus::InProgress->value,
            'reason' => 'Kunde ändert Spotzeiten.',
            'is_reopen' => 1,
        ]);
        $this->assertDatabaseHas('audit_events', [
            'action' => 'dispo_order.status_reopened',
            'auditable_id' => $order->id,
        ]);
    }

    public function test_show_exposes_targets_only_for_authorized_roles(): void
    {
        ['order' => $order, 'disposition' => $disposition] = $this->orderAtDisposition();

        $this->actingAs($disposition)
            ->get(route('dispo-orders.show', $order))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('canTransitionOperationalStatus', true)
                ->has('operationalStatusTargets', 1)
                ->where('operationalStatusTargets.0.value', DispoOrderStatus::InProgress->value)
                ->where('order.status_history', [])
            );

        $this->actingAs(User::factory()->role(Role::Sales)->create())
            ->get(route('dispo-orders.show', $order))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('canTransitionOperationalStatus', false)
                ->where('operationalStatusTargets', [])
            );
    }

    public function test_approval_history_unchanged_by_operational_transition(): void
    {
        ['order' => $order, 'disposition' => $disposition] = $this->orderAtDisposition();
        $approvalCount = $order->approvalRequests()->count();

        app(DispoOrderOperationalStatusService::class)->transition(
            $order,
            $disposition,
            $order->lock_version,
            DispoOrderStatus::InProgress,
        );

        $order->refresh();
        $this->assertSame($approvalCount, $order->approvalRequests()->count());
    }

    public function test_status_events_are_immutable(): void
    {
        ['order' => $order, 'disposition' => $disposition] = $this->orderAtDisposition();
        app(DispoOrderOperationalStatusService::class)->transition(
            $order,
            $disposition,
            $order->lock_version,
            DispoOrderStatus::InProgress,
        );

        $event = DispoOrderStatusEvent::query()->firstOrFail();
        $this->expectException(\LogicException::class);
        $event->reason = 'mutiert';
        $event->save();
    }
}
