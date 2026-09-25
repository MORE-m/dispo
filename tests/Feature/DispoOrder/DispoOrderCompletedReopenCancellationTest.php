<?php

namespace Tests\Feature\DispoOrder;

use App\Enums\DispoOrderStatus;
use App\Enums\Role;
use App\Models\AuditEvent;
use App\Models\DispoOrder;
use App\Models\DispoOrderStatusEvent;
use App\Models\User;
use App\Services\Calculation\CalculationWriter;
use App\Services\DispoOrder\DispoOrderApprovalService;
use App\Services\DispoOrder\DispoOrderInvoiceEndService;
use App\Services\DispoOrder\DispoOrderOperationalStatusService;
use App\Services\DispoOrder\DispoOrderSalesInquiryService;
use App\Services\DynamicField\ConfigurationSnapshotFreezeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\CreatesSpotClassicCatalog;
use Tests\Concerns\EnsuresCustomerConfirmationException;
use Tests\TestCase;

class DispoOrderCompletedReopenCancellationTest extends TestCase
{
    use CreatesSpotClassicCatalog;
    use EnsuresCustomerConfirmationException;
    use RefreshDatabase;

    /**
     * @return array{order: DispoOrder, disposition: User, creator: User, admin: User, management: User, sales: User, pm: User}
     */
    private function completedOrder(): array
    {
        $ctx = $this->disposedReadyOrder();
        $order = $ctx['order'];
        $disposition = $ctx['disposition'];

        $this->actingAs($disposition)->postJson(route('dispo-orders.complete', $order), [
            'lock_version' => $order->lock_version,
        ])->assertOk();

        $order->refresh();
        $this->assertSame(DispoOrderStatus::Completed, $order->status);

        return [
            'order' => $order->fresh(['positions', 'statusEvents', 'approvalRequests']),
            'disposition' => $disposition,
            'creator' => $ctx['creator'],
            'admin' => $ctx['admin'],
            'management' => $ctx['management'],
            'sales' => $ctx['sales'],
            'pm' => $ctx['pm'],
        ];
    }

    /**
     * @param  array{period_open?: bool}|array{}  $period
     * @return array{order: DispoOrder, disposition: User, creator: User, admin: User, management: User, sales: User, pm: User, catalog: array}
     */
    private function disposedReadyOrder(array $period = []): array
    {
        $periodOpen = (bool) ($period['period_open'] ?? false);
        $catalog = $this->createSpotClassicCatalog();
        $creator = User::factory()->role(Role::Sales)->create();
        $approver = User::factory()->role(Role::Sales)->create();
        $disposition = User::factory()->role(Role::Disposition)->create();
        $admin = User::factory()->role(Role::Admin)->create();
        $management = User::factory()->role(Role::Management)->create();
        $sales = User::factory()->role(Role::Sales)->create();
        $pm = User::factory()->role(Role::ProductManagement)->create();

        $fpHeader = (string) app(ConfigurationSnapshotFreezeService::class)
            ->resolveLiveSchemaForCalculationV3()['schema_fingerprint'];
        $fpPos = (string) app(ConfigurationSnapshotFreezeService::class)
            ->resolveLivePositionSchema((int) $catalog['medium']->id)['schema_fingerprint'];

        $calculation = app(CalculationWriter::class)->create([
            'planning_mode' => 'manual',
            'customer_name' => 'Reopen Cancel GmbH',
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
        $order = $approvals->approve($submitted, $approver, $submitted->lock_version, null, true);

        $ops = app(DispoOrderOperationalStatusService::class);
        $order = $ops->transition($order, $disposition, $order->lock_version, DispoOrderStatus::InProgress);

        $position = $order->positions()->firstOrFail();
        $order = app(DispoOrderInvoiceEndService::class)->update(
            $order,
            $position,
            $disposition,
            $order->lock_version,
            [3],
        );
        $order = $ops->transition($order, $disposition, $order->lock_version, DispoOrderStatus::Disposed);

        return compact('order', 'disposition', 'creator', 'admin', 'management', 'sales', 'pm', 'catalog');
    }

    public function test_admin_can_reopen_completed_with_reason(): void
    {
        ['order' => $order, 'admin' => $admin] = $this->completedOrder();
        $before = $order->lock_version;
        $approvalCount = $order->approvalRequests()->count();
        $completionEvents = DispoOrderStatusEvent::query()
            ->where('dispo_order_id', $order->id)
            ->where('to_status', DispoOrderStatus::Completed->value)
            ->count();

        $this->actingAs($admin)->postJson(route('dispo-orders.reopen-completed', $order), [
            'lock_version' => $order->lock_version,
            'reason' => 'Auftrag muss operativ nachbearbeitet werden.',
        ])->assertOk();

        $order->refresh();
        $this->assertSame(DispoOrderStatus::InProgress, $order->status);
        $this->assertSame($before + 1, $order->lock_version);

        $event = DispoOrderStatusEvent::query()
            ->where('dispo_order_id', $order->id)
            ->where('to_status', DispoOrderStatus::InProgress->value)
            ->where('is_reopen', true)
            ->latest('id')
            ->firstOrFail();
        $this->assertSame(DispoOrderStatus::Completed, $event->from_status);
        $this->assertSame('Auftrag muss operativ nachbearbeitet werden.', $event->reason);
        $this->assertSame($admin->id, $event->changed_by_id);

        $this->assertSame(1, AuditEvent::query()
            ->where('action', 'dispo_order.status_reopened')
            ->where('auditable_id', $order->id)
            ->count());

        $this->assertSame($approvalCount, $order->approvalRequests()->count());
        $this->assertSame($completionEvents, DispoOrderStatusEvent::query()
            ->where('dispo_order_id', $order->id)
            ->where('to_status', DispoOrderStatus::Completed->value)
            ->count());
    }

    public function test_management_can_reopen_completed(): void
    {
        ['order' => $order, 'management' => $management] = $this->completedOrder();

        $this->actingAs($management)->postJson(route('dispo-orders.reopen-completed', $order), [
            'lock_version' => $order->lock_version,
            'reason' => 'Nachbearbeitung durch Management.',
        ])->assertOk();

        $this->assertSame(DispoOrderStatus::InProgress, $order->fresh()->status);
    }

    /**
     * @return list<array{0: string}>
     */
    public static function reopenForbiddenRoleProvider(): array
    {
        return [
            ['disposition'],
            ['sales'],
            ['pm'],
        ];
    }

    #[DataProvider('reopenForbiddenRoleProvider')]
    public function test_reopen_completed_forbidden_roles(string $roleKey): void
    {
        $ctx = $this->completedOrder();
        $actor = $ctx[$roleKey];

        $this->actingAs($actor)->postJson(route('dispo-orders.reopen-completed', $ctx['order']), [
            'lock_version' => $ctx['order']->lock_version,
            'reason' => 'Sollte nicht erlaubt sein.',
        ])->assertForbidden();

        $this->assertSame(DispoOrderStatus::Completed, $ctx['order']->fresh()->status);
        $this->assertSame(0, AuditEvent::query()
            ->where('action', 'dispo_order.status_reopened')
            ->where('auditable_id', $ctx['order']->id)
            ->count());
    }

    public function test_reopen_reason_validation(): void
    {
        ['order' => $order, 'admin' => $admin] = $this->completedOrder();

        $this->actingAs($admin)->postJson(route('dispo-orders.reopen-completed', $order), [
            'lock_version' => $order->lock_version,
            'reason' => '',
        ])->assertStatus(422);

        $this->actingAs($admin)->postJson(route('dispo-orders.reopen-completed', $order), [
            'lock_version' => $order->lock_version,
            'reason' => '   ',
        ])->assertStatus(422);

        $this->actingAs($admin)->postJson(route('dispo-orders.reopen-completed', $order), [
            'lock_version' => $order->lock_version,
            'reason' => str_repeat('x', 2001),
        ])->assertStatus(422);

        $this->assertSame(DispoOrderStatus::Completed, $order->fresh()->status);
    }

    public function test_reopen_stale_lock_returns_409(): void
    {
        ['order' => $order, 'admin' => $admin] = $this->completedOrder();

        $this->actingAs($admin)->postJson(route('dispo-orders.reopen-completed', $order), [
            'lock_version' => $order->lock_version - 1,
            'reason' => 'Stale Versuch.',
        ])->assertStatus(409);
    }

    public function test_operational_endpoint_rejects_completed_reopen(): void
    {
        ['order' => $order, 'disposition' => $disposition, 'admin' => $admin] = $this->completedOrder();

        $this->actingAs($disposition)->postJson(route('dispo-orders.transition-status', $order), [
            'lock_version' => $order->lock_version,
            'target_status' => DispoOrderStatus::InProgress->value,
            'reason' => 'Bypass Versuch.',
        ])->assertStatus(422);

        $this->actingAs($admin)->postJson(route('dispo-orders.transition-status', $order), [
            'lock_version' => $order->lock_version,
            'target_status' => DispoOrderStatus::InProgress->value,
            'reason' => 'Bypass Versuch Admin.',
        ])->assertStatus(422);

        $this->assertSame(DispoOrderStatus::Completed, $order->fresh()->status);
    }

    /**
     * @return list<array{0: DispoOrderStatus}>
     */
    public static function cancelSourceProvider(): array
    {
        return [
            [DispoOrderStatus::AtDisposition],
            [DispoOrderStatus::InProgress],
            [DispoOrderStatus::SalesInquiry],
            [DispoOrderStatus::MaterialMissing],
            [DispoOrderStatus::MaterialReceived],
            [DispoOrderStatus::Disposed],
            [DispoOrderStatus::Completed],
        ];
    }

    #[DataProvider('cancelSourceProvider')]
    public function test_disposition_can_cancel_from_allowed_sources(DispoOrderStatus $source): void
    {
        $ctx = $this->orderInStatus($source);
        $order = $ctx['order'];
        $disposition = $ctx['disposition'];
        $before = $order->lock_version;

        $this->actingAs($disposition)->postJson(route('dispo-orders.cancel', $order), [
            'lock_version' => $order->lock_version,
            'reason' => 'Storno aus '.$source->value,
        ])->assertOk();

        $order->refresh();
        $this->assertSame(DispoOrderStatus::Cancelled, $order->status);
        $this->assertSame($before + 1, $order->lock_version);

        $event = DispoOrderStatusEvent::query()
            ->where('dispo_order_id', $order->id)
            ->where('to_status', DispoOrderStatus::Cancelled->value)
            ->latest('id')
            ->firstOrFail();
        $this->assertSame($source, $event->from_status);
        $this->assertSame('Storno aus '.$source->value, $event->reason);
    }

    /**
     * AT-19: Storno nach Abgeschlossen mit Pflichtgrund und vollständiger Historie.
     */
    public function test_at19_cancel_after_completed(): void
    {
        ['order' => $order, 'disposition' => $disposition] = $this->completedOrder();

        $this->actingAs($disposition)->postJson(route('dispo-orders.cancel', $order), [
            'lock_version' => $order->lock_version,
            'reason' => '',
        ])->assertStatus(422);

        $this->assertSame(0, AuditEvent::query()
            ->where('action', 'dispo_order.cancelled')
            ->where('auditable_id', $order->id)
            ->count());

        $reason = 'Kampagne wurde nachträglich vom Kunden storniert.';
        $this->actingAs($disposition)->postJson(route('dispo-orders.cancel', $order), [
            'lock_version' => $order->lock_version,
            'reason' => $reason,
        ])->assertOk();

        $order->refresh();
        $this->assertSame(DispoOrderStatus::Cancelled, $order->status);

        $events = DispoOrderStatusEvent::query()
            ->where('dispo_order_id', $order->id)
            ->where('to_status', DispoOrderStatus::Cancelled->value)
            ->get();
        $this->assertCount(1, $events);
        $event = $events->first();
        $this->assertSame(DispoOrderStatus::Completed, $event->from_status);
        $this->assertSame($reason, $event->reason);
        $this->assertSame($disposition->id, $event->changed_by_id);
        $this->assertNotNull($event->changed_at);

        $this->assertSame(1, AuditEvent::query()
            ->where('action', 'dispo_order.cancelled')
            ->where('auditable_id', $order->id)
            ->count());
    }

    public function test_cancel_roles_and_forbidden_sources(): void
    {
        ['order' => $order, 'admin' => $admin, 'management' => $management, 'sales' => $sales, 'pm' => $pm] = $this->completedOrder();

        $this->actingAs($sales)->postJson(route('dispo-orders.cancel', $order), [
            'lock_version' => $order->lock_version,
            'reason' => 'Sales darf nicht.',
        ])->assertForbidden();

        $this->actingAs($pm)->postJson(route('dispo-orders.cancel', $order), [
            'lock_version' => $order->lock_version,
            'reason' => 'PM darf nicht.',
        ])->assertForbidden();

        $this->actingAs($admin)->postJson(route('dispo-orders.cancel', $order), [
            'lock_version' => $order->lock_version,
            'reason' => 'Admin storniert.',
        ])->assertOk();

        $order->refresh();
        $this->assertSame(DispoOrderStatus::Cancelled, $order->status);

        // cancelled ist terminal
        $this->actingAs($management)->postJson(route('dispo-orders.cancel', $order), [
            'lock_version' => $order->lock_version,
            'reason' => 'Erneutes Storno.',
        ])->assertStatus(409);

        $this->actingAs($admin)->postJson(route('dispo-orders.reopen-completed', $order), [
            'lock_version' => $order->lock_version,
            'reason' => 'Reopen aus cancelled.',
        ])->assertStatus(422);
    }

    public function test_cancel_forbidden_from_draft_and_approval_statuses(): void
    {
        $catalog = $this->createSpotClassicCatalog();
        $creator = User::factory()->role(Role::Sales)->create();
        $disposition = User::factory()->role(Role::Disposition)->create();

        $fpHeader = (string) app(ConfigurationSnapshotFreezeService::class)
            ->resolveLiveSchemaForCalculationV3()['schema_fingerprint'];
        $fpPos = (string) app(ConfigurationSnapshotFreezeService::class)
            ->resolveLivePositionSchema((int) $catalog['medium']->id)['schema_fingerprint'];

        $calculation = app(CalculationWriter::class)->create([
            'planning_mode' => 'manual',
            'customer_name' => 'Draft Cancel Block',
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
                    'period_open' => true,
                    'position_flight_period' => null,
                ],
            ]],
        ], $creator);

        $this->actingAs($creator)->post(route('dispo-orders.store', $calculation), [
            'position_ids' => [$calculation->positions()->first()->id],
        ])->assertRedirect();

        $order = DispoOrder::query()->latest('id')->firstOrFail();
        $this->assertSame(DispoOrderStatus::Draft, $order->status);

        $this->actingAs($disposition)->postJson(route('dispo-orders.cancel', $order), [
            'lock_version' => $order->lock_version,
            'reason' => 'Draft storno.',
        ])->assertStatus(422);

        $order = $this->seedCustomerConfirmationException($order, $creator);
        $approvals = app(DispoOrderApprovalService::class);
        $order = $approvals->submit($order, $creator, $order->lock_version);
        $this->assertSame(DispoOrderStatus::AwaitingSalesApproval, $order->status);

        $this->actingAs($disposition)->postJson(route('dispo-orders.cancel', $order), [
            'lock_version' => $order->lock_version,
            'reason' => 'Awaiting storno.',
        ])->assertStatus(422);
    }

    public function test_operational_endpoint_rejects_cancel_edges(): void
    {
        ['order' => $order, 'disposition' => $disposition] = $this->completedOrder();

        $this->actingAs($disposition)->postJson(route('dispo-orders.transition-status', $order), [
            'lock_version' => $order->lock_version,
            'target_status' => DispoOrderStatus::Cancelled->value,
            'reason' => 'Bypass Cancel.',
        ])->assertStatus(422);

        $this->assertSame(DispoOrderStatus::Completed, $order->fresh()->status);
    }

    public function test_sales_inquiry_history_preserved_after_cancel(): void
    {
        $ctx = $this->disposedReadyOrder();
        $order = $ctx['order'];
        $disposition = $ctx['disposition'];
        $sales = $ctx['sales'];

        // zurück in in_progress für Rückfrage
        $ops = app(DispoOrderOperationalStatusService::class);
        $order = $ops->transition(
            $order,
            $disposition,
            $order->lock_version,
            DispoOrderStatus::InProgress,
            'Für Rückfrage',
        );
        $order = app(DispoOrderSalesInquiryService::class)->ask(
            $order,
            $disposition,
            $order->lock_version,
            'Frage vor Storno?',
        );
        $this->assertSame(DispoOrderStatus::SalesInquiry, $order->status);
        $commentCount = $order->comments()->count();

        $this->actingAs($disposition)->postJson(route('dispo-orders.cancel', $order), [
            'lock_version' => $order->lock_version,
            'reason' => 'Storno trotz offener Rückfrage.',
        ])->assertOk();

        $order->refresh();
        $this->assertSame(DispoOrderStatus::Cancelled, $order->status);
        $this->assertSame($commentCount, $order->comments()->count());

        $open = app(DispoOrderSalesInquiryService::class)->findOpenSalesInquiry($order);
        if ($open !== null) {
            $this->actingAs($sales)->postJson(
                route('dispo-orders.sales-inquiry.answer', [$order, $open]),
                [
                    'lock_version' => $order->lock_version,
                    'answer' => 'Antwort nach Storno.',
                ],
            )->assertStatus(409);
        }
    }

    public function test_show_props_for_completed_and_cancelled(): void
    {
        ['order' => $order, 'admin' => $admin, 'disposition' => $disposition, 'sales' => $sales] = $this->completedOrder();

        $this->actingAs($admin)->get(route('dispo-orders.show', $order))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('canReopenCompleted', true)
                ->where('canCancel', true)
                ->where('completionSummary.completed_by_name', fn ($v) => is_string($v) && $v !== '')
            );

        $this->actingAs($disposition)->get(route('dispo-orders.show', $order))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('canReopenCompleted', false)
                ->where('canCancel', true)
            );

        $this->actingAs($sales)->get(route('dispo-orders.show', $order))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('canReopenCompleted', false)
                ->where('canCancel', false)
            );

        $this->actingAs($disposition)->postJson(route('dispo-orders.cancel', $order), [
            'lock_version' => $order->lock_version,
            'reason' => 'Für Summary.',
        ])->assertOk();

        $this->actingAs($admin)->get(route('dispo-orders.show', $order->fresh()))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('canReopenCompleted', false)
                ->where('canCancel', false)
                ->where('cancellationSummary.available', true)
                ->where('cancellationSummary.reason', 'Für Summary.')
                ->where('cancellationSummary.from_status_label', 'Abgeschlossen')
            );
    }

    /**
     * @return array{order: DispoOrder, disposition: User, admin: User, management: User, sales: User, pm: User}
     */
    private function orderInStatus(DispoOrderStatus $target): array
    {
        if ($target === DispoOrderStatus::Completed) {
            return $this->completedOrder();
        }

        if ($target === DispoOrderStatus::Disposed) {
            $ctx = $this->disposedReadyOrder();

            return [
                'order' => $ctx['order'],
                'disposition' => $ctx['disposition'],
                'admin' => $ctx['admin'],
                'management' => $ctx['management'],
                'sales' => $ctx['sales'],
                'pm' => $ctx['pm'],
            ];
        }

        $ctx = $this->disposedReadyOrder();
        $order = $ctx['order'];
        $disposition = $ctx['disposition'];
        $ops = app(DispoOrderOperationalStatusService::class);

        $order = $ops->transition(
            $order,
            $disposition,
            $order->lock_version,
            DispoOrderStatus::InProgress,
            'Zurück für Quellstatus',
        );

        if ($target === DispoOrderStatus::InProgress) {
            return [
                'order' => $order,
                'disposition' => $disposition,
                'admin' => $ctx['admin'],
                'management' => $ctx['management'],
                'sales' => $ctx['sales'],
                'pm' => $ctx['pm'],
            ];
        }

        if ($target === DispoOrderStatus::AtDisposition) {
            // over sales inquiry answer path
            $order = app(DispoOrderSalesInquiryService::class)->ask(
                $order,
                $disposition,
                $order->lock_version,
                'Zwischenfrage',
            );
            $order = app(DispoOrderSalesInquiryService::class)->answer(
                $order,
                app(DispoOrderSalesInquiryService::class)->findOpenSalesInquiry($order),
                $ctx['sales'],
                $order->lock_version,
                'Antwort',
            );
            $this->assertSame(DispoOrderStatus::AtDisposition, $order->status);

            return [
                'order' => $order,
                'disposition' => $disposition,
                'admin' => $ctx['admin'],
                'management' => $ctx['management'],
                'sales' => $ctx['sales'],
                'pm' => $ctx['pm'],
            ];
        }

        if ($target === DispoOrderStatus::SalesInquiry) {
            $order = app(DispoOrderSalesInquiryService::class)->ask(
                $order,
                $disposition,
                $order->lock_version,
                'Rückfrage für Storno',
            );

            return [
                'order' => $order,
                'disposition' => $disposition,
                'admin' => $ctx['admin'],
                'management' => $ctx['management'],
                'sales' => $ctx['sales'],
                'pm' => $ctx['pm'],
            ];
        }

        if ($target === DispoOrderStatus::MaterialMissing) {
            $order = $ops->transition($order, $disposition, $order->lock_version, DispoOrderStatus::MaterialMissing);
        } elseif ($target === DispoOrderStatus::MaterialReceived) {
            $order = $ops->transition($order, $disposition, $order->lock_version, DispoOrderStatus::MaterialReceived);
        }

        $this->assertSame($target, $order->status);

        return [
            'order' => $order,
            'disposition' => $disposition,
            'admin' => $ctx['admin'],
            'management' => $ctx['management'],
            'sales' => $ctx['sales'],
            'pm' => $ctx['pm'],
        ];
    }
}
