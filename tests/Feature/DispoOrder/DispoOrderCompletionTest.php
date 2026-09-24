<?php

namespace Tests\Feature\DispoOrder;

use App\Enums\DispoOrderCommentType;
use App\Enums\DispoOrderStatus;
use App\Enums\Role;
use App\Models\AuditEvent;
use App\Models\DispoOrder;
use App\Models\DispoOrderComment;
use App\Models\DispoOrderStatusEvent;
use App\Models\User;
use App\Services\Calculation\CalculationWriter;
use App\Services\DispoOrder\DispoOrderApprovalService;
use App\Services\DispoOrder\DispoOrderInvoiceEndService;
use App\Services\DispoOrder\DispoOrderOperationalStatusService;
use App\Services\DispoOrder\DispoOrderSalesInquiryService;
use App\Services\DynamicField\ConfigurationSnapshotFreezeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\CreatesSpotClassicCatalog;
use Tests\Concerns\EnsuresCustomerConfirmationException;
use Tests\TestCase;

class DispoOrderCompletionTest extends TestCase
{
    use CreatesSpotClassicCatalog;
    use EnsuresCustomerConfirmationException;
    use RefreshDatabase;

    /**
     * @param  array{period_open?: bool, flight_start?: string, flight_end?: string}  $period
     * @param  array{organization: mixed, medium: mixed, hamburg: mixed, rock: mixed}|null  $catalog
     * @return array{order: DispoOrder, disposition: User, creator: User, admin: User, management: User, catalog: array}
     */
    private function disposedOrder(array $period = [], ?array $catalog = null): array
    {
        $periodOpen = (bool) ($period['period_open'] ?? false);
        $flightStart = $period['flight_start'] ?? '2026-03-01';
        $flightEnd = $period['flight_end'] ?? '2026-03-31';

        $catalog ??= $this->createSpotClassicCatalog();
        $creator = User::factory()->role(Role::Sales)->create();
        $approver = User::factory()->role(Role::Sales)->create();
        $disposition = User::factory()->role(Role::Disposition)->create();
        $admin = User::factory()->role(Role::Admin)->create();
        $management = User::factory()->role(Role::Management)->create();

        $fpHeader = (string) app(ConfigurationSnapshotFreezeService::class)
            ->resolveLiveSchemaForCalculationV3()['schema_fingerprint'];
        $fpPos = (string) app(ConfigurationSnapshotFreezeService::class)
            ->resolveLivePositionSchema((int) $catalog['medium']->id)['schema_fingerprint'];

        $calculation = app(CalculationWriter::class)->create([
            'planning_mode' => 'manual',
            'customer_name' => 'Completion GmbH',
            'order_discount_percent' => '0',
            'schema_fingerprint' => $fpHeader,
            'dynamic_field_values' => ['campaign_period' => null],
            'positions' => [[
                'inventory_id' => $catalog['hamburg']->id,
                'advertising_medium_id' => $catalog['medium']->id,
                'schema_fingerprint' => $fpPos,
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
        ], $creator);

        $this->actingAs($creator)->post(route('dispo-orders.store', $calculation), [
            'position_ids' => [$calculation->positions()->first()->id],
        ])->assertRedirect();

        $order = DispoOrder::query()->latest('id')->firstOrFail();
        $order = $this->seedCustomerConfirmationException($order, $creator);
        $approvals = app(DispoOrderApprovalService::class);
        $submitted = $approvals->submit($order, $creator, $order->lock_version);
        $order = $approvals->approve($submitted, $approver, $submitted->lock_version, null, true);

        $ops = app(DispoOrderOperationalStatusService::class);
        $order = $ops->transition($order, $disposition, $order->lock_version, DispoOrderStatus::InProgress);
        $order = $ops->transition($order, $disposition, $order->lock_version, DispoOrderStatus::Disposed);

        return compact('order', 'disposition', 'creator', 'admin', 'management', 'catalog');
    }

    private function fillInvoiceMonths(DispoOrder $order, User $actor, array $months = [3]): DispoOrder
    {
        // disposed is not editable – reopen first
        $ops = app(DispoOrderOperationalStatusService::class);
        $order = $ops->transition(
            $order,
            $actor,
            $order->lock_version,
            DispoOrderStatus::InProgress,
            'Rechnung per Ende nachpflegen',
        );
        $position = $order->positions()->firstOrFail();
        $order = app(DispoOrderInvoiceEndService::class)->update(
            $order,
            $position,
            $actor,
            $order->lock_version,
            $months,
        );
        $order = $ops->transition($order, $actor, $order->lock_version, DispoOrderStatus::Disposed);

        return $order->fresh(['positions', 'approvalRequests', 'statusEvents']);
    }

    public function test_happy_path_disposition_completes_when_ready(): void
    {
        ['order' => $order, 'disposition' => $disposition] = $this->disposedOrder();
        $order = $this->fillInvoiceMonths($order, $disposition, [1, 3]);
        $before = $order->lock_version;

        $this->actingAs($disposition)->postJson(route('dispo-orders.complete', $order), [
            'lock_version' => $order->lock_version,
        ])->assertOk();

        $order->refresh();
        $this->assertSame(DispoOrderStatus::Completed, $order->status);
        $this->assertSame($before + 1, $order->lock_version);
        $this->assertDatabaseHas('dispo_order_status_events', [
            'dispo_order_id' => $order->id,
            'from_status' => DispoOrderStatus::Disposed->value,
            'to_status' => DispoOrderStatus::Completed->value,
            'is_completion_override' => 0,
            'changed_by_id' => $disposition->id,
        ]);
        $audit = AuditEvent::query()
            ->where('action', 'dispo_order.completed')
            ->where('auditable_id', $order->id)
            ->latest('id')
            ->first();
        $this->assertNotNull($audit);
        $this->assertFalse((bool) ($audit->new_values['override'] ?? true));
    }

    public function test_roles_admin_management_ok_sales_pm_forbidden(): void
    {
        $catalog = $this->createSpotClassicCatalog();
        ['order' => $order, 'disposition' => $disposition, 'admin' => $admin] = $this->disposedOrder([], $catalog);
        $order = $this->fillInvoiceMonths($order, $disposition);

        $this->actingAs($admin)->postJson(route('dispo-orders.complete', $order), [
            'lock_version' => $order->lock_version,
        ])->assertOk();

        ['order' => $order2, 'management' => $management2] = $this->disposedOrder(['period_open' => true], $catalog);
        $this->actingAs($management2)->postJson(route('dispo-orders.complete', $order2), [
            'lock_version' => $order2->lock_version,
        ])->assertOk();

        ['order' => $order3] = $this->disposedOrder(['period_open' => true], $catalog);
        $sales = User::factory()->role(Role::Sales)->create();
        $this->actingAs($sales)->postJson(route('dispo-orders.complete', $order3), [
            'lock_version' => $order3->lock_version,
        ])->assertForbidden();
        $pm = User::factory()->role(Role::ProductManagement)->create();
        $this->actingAs($pm)->postJson(route('dispo-orders.complete', $order3), [
            'lock_version' => $order3->lock_version,
        ])->assertForbidden();
    }

    public function test_invoice_end_missing_blocks_at18(): void
    {
        ['order' => $order, 'disposition' => $disposition] = $this->disposedOrder(['period_open' => false]);

        $response = $this->actingAs($disposition)->postJson(route('dispo-orders.complete', $order), [
            'lock_version' => $order->lock_version,
        ]);
        $response->assertUnprocessable();
        $this->assertStringContainsString(
            'Rechnung per Ende fehlt',
            json_encode($response->json('errors'), JSON_UNESCAPED_UNICODE) ?: '',
        );
        $this->assertSame(DispoOrderStatus::Disposed, $order->fresh()->status);
        $this->assertSame(0, AuditEvent::query()->where('action', 'dispo_order.completed')->count());
    }

    public function test_open_period_allows_empty_invoice_months(): void
    {
        ['order' => $order, 'disposition' => $disposition] = $this->disposedOrder(['period_open' => true]);

        $this->actingAs($disposition)->postJson(route('dispo-orders.complete', $order), [
            'lock_version' => $order->lock_version,
        ])->assertOk();
        $this->assertSame(DispoOrderStatus::Completed, $order->fresh()->status);
    }

    public function test_open_sales_inquiry_blocks(): void
    {
        // Build to in_progress, ask inquiry, cannot complete from sales_inquiry;
        // answer back then dispose without answering? Need open inquiry while disposed –
        // but disposed can't have open inquiry in normal flow. Spec: check remains.
        // Simulate: force status disposed while leaving open inquiry comment? Or
        // from material_received ask inquiry... inquiry leaves status sales_inquiry.
        // For readiness while disposed with open inquiry: plant comment without status.
        ['order' => $order, 'disposition' => $disposition] = $this->disposedOrder();
        $order = $this->fillInvoiceMonths($order, $disposition);

        $ops = app(DispoOrderOperationalStatusService::class);
        $order = $ops->transition(
            $order,
            $disposition,
            $order->lock_version,
            DispoOrderStatus::InProgress,
            'Inquiry test',
        );
        $order = app(DispoOrderSalesInquiryService::class)->ask(
            $order,
            $disposition,
            $order->lock_version,
            'Bitte Budget klären',
        );
        // Answer to leave inquiry open? findOpenSalesInquiry looks for unanswered.
        // Stay in sales_inquiry – complete endpoint requires disposed.
        $this->actingAs($disposition)->postJson(route('dispo-orders.complete', $order), [
            'lock_version' => $order->lock_version,
        ])->assertUnprocessable();

        // Answer, dispose, but leave a second open inquiry artificially is hard.
        // Instead: answer, go disposed, then insert open inquiry comment via service ask is blocked from disposed.
        // Mark readiness check by creating unanswered sales inquiry comment directly after disposed.
        $sales = User::factory()->role(Role::Sales)->create();
        $order = app(DispoOrderSalesInquiryService::class)->answer(
            $order,
            $order->comments()->latest('id')->firstOrFail(),
            $sales,
            $order->lock_version,
            'Budget ok',
        );
        $order = $ops->transition($order, $disposition, $order->lock_version, DispoOrderStatus::InProgress);
        $order = $ops->transition($order, $disposition, $order->lock_version, DispoOrderStatus::Disposed);

        // Plant open inquiry comment without status change (readiness still checks)
        DispoOrderComment::query()->create([
            'dispo_order_id' => $order->id,
            'type' => DispoOrderCommentType::SalesInquiry,
            'body' => 'Offene Restfrage',
            'created_by_id' => $disposition->id,
            'created_by_name' => $disposition->name,
            'parent_id' => null,
        ]);

        $this->actingAs($disposition)->postJson(route('dispo-orders.complete', $order->fresh()), [
            'lock_version' => $order->fresh()->lock_version,
        ])->assertUnprocessable();
    }

    public function test_admin_override_requires_reason_and_audits(): void
    {
        ['order' => $order, 'disposition' => $disposition, 'admin' => $admin, 'management' => $management] = $this->disposedOrder();
        // missing invoice months → blocked

        $this->actingAs($disposition)->postJson(route('dispo-orders.complete', $order), [
            'lock_version' => $order->lock_version,
            'override_reason' => 'Sollte nicht greifen',
        ])->assertUnprocessable();

        $this->actingAs($management)->postJson(route('dispo-orders.complete', $order), [
            'lock_version' => $order->lock_version,
            'override_reason' => 'GF darf nicht overriden',
        ])->assertUnprocessable();

        $this->actingAs($admin)->postJson(route('dispo-orders.complete', $order), [
            'lock_version' => $order->lock_version,
        ])->assertUnprocessable();

        $this->actingAs($admin)->postJson(route('dispo-orders.complete', $order), [
            'lock_version' => $order->lock_version,
            'override_reason' => '   ',
        ])->assertUnprocessable();

        $this->actingAs($admin)->postJson(route('dispo-orders.complete', $order), [
            'lock_version' => $order->lock_version,
            'override_reason' => str_repeat('x', 2001),
        ])->assertUnprocessable();

        $this->assertSame(0, AuditEvent::query()->where('action', 'dispo_order.completed')->count());

        $reason = 'Rechnungsmonat wird nachträglich außerhalb des Systems dokumentiert.';
        $this->actingAs($admin)->postJson(route('dispo-orders.complete', $order), [
            'lock_version' => $order->lock_version,
            'override_reason' => $reason,
        ])->assertOk();

        $order->refresh();
        $this->assertSame(DispoOrderStatus::Completed, $order->status);
        $event = DispoOrderStatusEvent::query()
            ->where('dispo_order_id', $order->id)
            ->where('to_status', DispoOrderStatus::Completed->value)
            ->first();
        $this->assertTrue((bool) $event?->is_completion_override);
        $this->assertSame($reason, $event?->reason);
        $this->assertNotEmpty($event?->completion_override_violations);

        $audit = AuditEvent::query()
            ->where('action', 'dispo_order.completed')
            ->where('auditable_id', $order->id)
            ->latest('id')
            ->firstOrFail();
        $this->assertTrue((bool) $audit->new_values['override']);
        $this->assertSame($reason, $audit->new_values['override_reason']);
        $this->assertContains('invoice_end_months', $audit->new_values['violated_checks'] ?? []);
        $this->assertSame($admin->id, $audit->new_values['changed_by_id'] ?? null);
    }

    public function test_wrong_status_and_stale_lock(): void
    {
        ['order' => $order, 'disposition' => $disposition] = $this->disposedOrder();
        $order = $this->fillInvoiceMonths($order, $disposition);
        $ops = app(DispoOrderOperationalStatusService::class);
        $order = $ops->transition(
            $order,
            $disposition,
            $order->lock_version,
            DispoOrderStatus::InProgress,
            'noch nicht abschließen',
        );

        $this->actingAs($disposition)->postJson(route('dispo-orders.complete', $order), [
            'lock_version' => $order->lock_version,
        ])->assertUnprocessable();

        $order = $ops->transition($order, $disposition, $order->lock_version, DispoOrderStatus::Disposed);
        $this->actingAs($disposition)->postJson(route('dispo-orders.complete', $order), [
            'lock_version' => $order->lock_version + 3,
        ])->assertStatus(409);
    }

    public function test_confirmation_without_approval_ack_fails(): void
    {
        ['order' => $order, 'disposition' => $disposition] = $this->disposedOrder();
        $order = $this->fillInvoiceMonths($order, $disposition);

        // Clear acknowledgement on approved request → fail closed
        $approved = $order->approvalRequests()->where('status', 'approved')->firstOrFail();
        // ApprovalRequest blocks updates after decision – use DB
        DB::table('dispo_order_approval_requests')
            ->where('id', $approved->id)
            ->update([
                'customer_confirmation_exception_acknowledged' => 0,
                'customer_confirmation_exception_acknowledged_by_id' => null,
                'customer_confirmation_exception_acknowledged_by_name' => null,
                'customer_confirmation_exception_acknowledged_at' => null,
            ]);

        $this->actingAs($disposition)->postJson(route('dispo-orders.complete', $order->fresh()), [
            'lock_version' => $order->fresh()->lock_version,
        ])->assertUnprocessable();
    }

    public function test_normal_complete_when_ready_does_not_require_override(): void
    {
        ['order' => $order, 'admin' => $admin, 'disposition' => $disposition] = $this->disposedOrder();
        $order = $this->fillInvoiceMonths($order, $disposition);

        $this->actingAs($admin)->postJson(route('dispo-orders.complete', $order), [
            'lock_version' => $order->lock_version,
            'override_reason' => 'unnötig',
        ])->assertOk();

        $event = DispoOrderStatusEvent::query()
            ->where('dispo_order_id', $order->id)
            ->where('to_status', DispoOrderStatus::Completed->value)
            ->first();
        $this->assertFalse((bool) $event?->is_completion_override);
    }
}
