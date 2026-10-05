<?php

namespace Tests\Feature\Administration;

use App\Enums\DispoOrderApprovalKind;
use App\Enums\DispoOrderApprovalStatus;
use App\Enums\DispoOrderCommentType;
use App\Enums\DispoOrderStatus;
use App\Enums\NotificationOutboxStatus;
use App\Enums\Role;
use App\Models\AuditEvent;
use App\Models\DispoOrder;
use App\Models\DispoOrderApprovalRequest;
use App\Models\DispoOrderComment;
use App\Models\NotificationOutbox;
use App\Models\User;
use App\Services\DispoOrder\DispoOrderApprovalNotificationPublisher;
use App\Services\DispoOrder\DispoOrderSalesInquiryService;
use App\Services\DispoOrder\DispoOrderWriter;
use App\Services\Notification\NotificationOutboxAdminQuery;
use App\Services\Notification\NotificationOutboxWriter;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\CreatesSavedCalculation;
use Tests\Concerns\CreatesSpotClassicCatalog;
use Tests\Support\NotificationOutboxTestFactory;
use Tests\TestCase;

/**
 * PO-NOT002-ADMIN-1 / BL-P9-02e – lesende Admin-Outbox-/Suppress-Sicht.
 */
class NotificationOutboxAdminTest extends TestCase
{
    use CreatesSavedCalculation;
    use CreatesSpotClassicCatalog;
    use RefreshDatabase;

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function enqueueVisible(array $overrides = []): NotificationOutbox
    {
        $defaults = [
            'eventType' => DispoOrderSalesInquiryService::EVENT_ASKED,
            'sourceType' => DispoOrderSalesInquiryService::SOURCE_TYPE_COMMENT,
            'sourceId' => 9001,
            'recipientUserId' => 11,
            'recipientEmail' => 'empfaenger@example.test',
            'recipientName' => 'Emil Empfänger',
            'payload' => [
                'order_number' => 'DA-FALLBACK-1',
                'customer_name' => 'Kunde GmbH',
                'campaign' => 'Kampagne',
                'event_label' => 'Rückfrage Vertrieb',
                'actor_id' => 7,
                'actor_name' => 'Ada Admin',
                'internal_url' => 'https://evil.example.test/hijack',
                'occurred_at' => '2026-10-05T10:00:00+02:00',
            ],
        ];

        return app(NotificationOutboxWriter::class)->enqueue(
            NotificationOutboxTestFactory::intent(array_replace_recursive($defaults, $overrides)),
        );
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function setOutboxState(NotificationOutbox $row, array $attributes): void
    {
        DB::table('notification_outbox')->where('id', $row->id)->update($attributes);
        $row->refresh();
    }

    public function test_guests_are_redirected_to_login_on_all_routes(): void
    {
        $row = $this->enqueueVisible();
        $this->setOutboxState($row, ['status' => NotificationOutboxStatus::Failed->value]);

        $this->get(route('administration.notification-outbox.index'))
            ->assertRedirect(route('login'));
        $this->get(route('administration.notification-outbox.suppressed'))
            ->assertRedirect(route('login'));
        $this->get(route('administration.notification-outbox.show', $row))
            ->assertRedirect(route('login'));
    }

    public function test_management_sales_disposition_and_pm_are_forbidden_on_all_routes(): void
    {
        $row = $this->enqueueVisible();
        $this->setOutboxState($row, ['status' => NotificationOutboxStatus::Failed->value]);

        $actors = [
            User::factory()->role(Role::Management)->create(),
            User::factory()->role(Role::Sales)->create(),
            User::factory()->role(Role::Disposition)->create(),
            User::factory()->role(Role::ProductManagement)->create([
                'can_view_dispo_orders' => true,
            ]),
        ];

        foreach ($actors as $actor) {
            $this->actingAs($actor)
                ->get(route('administration.notification-outbox.index'))
                ->assertForbidden();
            $this->actingAs($actor)
                ->get(route('administration.notification-outbox.suppressed'))
                ->assertForbidden();
            $this->actingAs($actor)
                ->get(route('administration.notification-outbox.show', $row))
                ->assertForbidden();
        }
    }

    public function test_hub_module_only_available_for_admin(): void
    {
        $admin = User::factory()->role(Role::Admin)->create();
        $management = User::factory()->role(Role::Management)->create();

        $this->actingAs($admin)
            ->get(route('administration.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('administration/index')
                ->where('modules', fn ($modules): bool => collect($modules)->contains(
                    fn (array $m): bool => $m['key'] === 'notification-outbox'
                        && $m['available'] === true
                        && $m['href'] === '/administration/benachrichtigungen',
                )));

        $this->actingAs($management)
            ->get(route('administration.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('administration/index')
                ->where('modules', fn ($modules): bool => collect($modules)->contains(
                    fn (array $m): bool => $m['key'] === 'notification-outbox'
                        && $m['available'] === false
                        && $m['href'] === null,
                )));
    }

    public function test_default_filter_is_failed_and_hidden_events_are_excluded(): void
    {
        $admin = User::factory()->role(Role::Admin)->create();

        $failedAsk = $this->enqueueVisible([
            'sourceId' => 1,
            'idempotencyKey' => 'admin-visible-failed-ask',
        ]);
        $this->setOutboxState($failedAsk, [
            'status' => NotificationOutboxStatus::Failed->value,
            'last_error' => 'SMTP timeout',
            'attempt_count' => 2,
        ]);

        $pendingAsk = $this->enqueueVisible([
            'sourceId' => 2,
            'idempotencyKey' => 'admin-visible-pending-ask',
        ]);
        $this->assertSame(NotificationOutboxStatus::Pending, $pendingAsk->status);

        $hidden = $this->enqueueVisible([
            'eventType' => 'test.event',
            'sourceType' => 'test_source',
            'sourceId' => 3,
            'idempotencyKey' => 'admin-hidden-event',
        ]);
        $this->setOutboxState($hidden, [
            'status' => NotificationOutboxStatus::Failed->value,
        ]);

        $this->actingAs($admin)
            ->get(route('administration.notification-outbox.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('administration/notification-outbox/index')
                ->where('filters.status', 'failed')
                ->where('filters.event_type', 'all')
                ->where('pagination.per_page', NotificationOutboxAdminQuery::PER_PAGE)
                ->has('rows', 1)
                ->where('rows.0.id', $failedAsk->id)
                ->where('rows.0.status', 'failed')
                ->where('rows.0.status_label', 'Fehlgeschlagen')
                ->where('rows.0.event_label', 'Rückfrage Vertrieb')
                ->where('rows.0.order_number', 'DA-FALLBACK-1')
                ->where('rows.0.order_url', null)
                ->where('rows.0.error_text', 'SMTP timeout'));

        $this->actingAs($admin)
            ->get(route('administration.notification-outbox.show', $hidden))
            ->assertNotFound();
    }

    public function test_status_and_event_filters_and_invalid_values(): void
    {
        $admin = User::factory()->role(Role::Admin)->create();

        $sentAnswer = $this->enqueueVisible([
            'eventType' => DispoOrderSalesInquiryService::EVENT_ANSWERED,
            'sourceId' => 10,
            'idempotencyKey' => 'filter-sent-answer',
            'payload' => ['event_label' => 'Antwort auf Rückfrage'],
        ]);
        $this->setOutboxState($sentAnswer, [
            'status' => NotificationOutboxStatus::Sent->value,
            'sent_at' => now(),
        ]);

        $failedApproved = $this->enqueueVisible([
            'eventType' => DispoOrderApprovalNotificationPublisher::EVENT_APPROVED,
            'sourceType' => DispoOrderApprovalNotificationPublisher::SOURCE_TYPE,
            'sourceId' => 11,
            'idempotencyKey' => 'filter-failed-approved',
            'payload' => ['event_label' => 'Freigabe erteilt'],
        ]);
        $this->setOutboxState($failedApproved, [
            'status' => NotificationOutboxStatus::Failed->value,
        ]);

        $this->actingAs($admin)
            ->get(route('administration.notification-outbox.index', [
                'status' => 'sent',
                'event_type' => DispoOrderSalesInquiryService::EVENT_ANSWERED,
            ]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('filters.status', 'sent')
                ->where('filters.event_type', DispoOrderSalesInquiryService::EVENT_ANSWERED)
                ->has('rows', 1)
                ->where('rows.0.id', $sentAnswer->id)
                ->where('rows.0.status_label', 'Gesendet'));

        $this->actingAs($admin)
            ->get(route('administration.notification-outbox.index', [
                'status' => 'all',
                'event_type' => DispoOrderApprovalNotificationPublisher::EVENT_APPROVED,
            ]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('rows', 1)
                ->where('rows.0.id', $failedApproved->id));

        $this->actingAs($admin)
            ->get(route('administration.notification-outbox.index', [
                'status' => 'bogus',
            ]))
            ->assertSessionHasErrors('status');

        $this->actingAs($admin)
            ->get(route('administration.notification-outbox.index', [
                'event_type' => 'not.allowed',
            ]))
            ->assertSessionHasErrors('event_type');
    }

    public function test_all_five_outbox_statuses_appear_under_all_filter(): void
    {
        $admin = User::factory()->role(Role::Admin)->create();
        $ids = [];

        foreach (NotificationOutboxStatus::cases() as $index => $status) {
            $row = $this->enqueueVisible([
                'sourceId' => 100 + $index,
                'idempotencyKey' => 'status-'.$status->value,
            ]);
            $this->setOutboxState($row, [
                'status' => $status->value,
                'sent_at' => $status === NotificationOutboxStatus::Sent ? now() : null,
            ]);
            $ids[] = $row->id;
        }

        $this->actingAs($admin)
            ->get(route('administration.notification-outbox.index', ['status' => 'all']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('rows', 5)
                ->where('rows.0.id', max($ids))
                ->where('rows.4.id', min($ids))
                ->where('rows', function ($rows) use ($ids): bool {
                    $seen = collect($rows)->pluck('status')->sort()->values()->all();
                    $expected = collect(NotificationOutboxStatus::cases())
                        ->map(fn ($s) => $s->value)
                        ->sort()
                        ->values()
                        ->all();

                    return $seen === $expected
                        && collect($rows)->pluck('id')->all() === collect($ids)->sortDesc()->values()->all();
                }));
    }

    public function test_pagination_preserves_filters_and_orders_by_id_desc(): void
    {
        $admin = User::factory()->role(Role::Admin)->create();
        $ids = [];
        for ($i = 1; $i <= 26; $i++) {
            $row = $this->enqueueVisible([
                'sourceId' => 2000 + $i,
                'idempotencyKey' => 'page-failed-'.$i,
            ]);
            $this->setOutboxState($row, ['status' => NotificationOutboxStatus::Failed->value]);
            $ids[] = $row->id;
        }

        rsort($ids);

        $this->actingAs($admin)
            ->get(route('administration.notification-outbox.index', [
                'status' => 'failed',
                'page' => 1,
            ]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('pagination.current_page', 1)
                ->where('pagination.last_page', 2)
                ->where('pagination.total', 26)
                ->where('pagination.per_page', 25)
                ->has('rows', 25)
                ->where('rows.0.id', $ids[0])
                ->where('rows.24.id', $ids[24]));

        $this->actingAs($admin)
            ->get(route('administration.notification-outbox.index', [
                'status' => 'failed',
                'page' => 2,
            ]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('pagination.current_page', 2)
                ->has('rows', 1)
                ->where('rows.0.id', $ids[25])
                ->where('filters.status', 'failed'));
    }

    public function test_detail_masks_secrets_and_does_not_expose_payload_or_internal_url(): void
    {
        $admin = User::factory()->role(Role::Admin)->create();
        $secret = 'supersecret-token-xyz';
        $row = $this->enqueueVisible([
            'sourceId' => 55,
            'idempotencyKey' => 'detail-secret',
        ]);
        $this->setOutboxState($row, [
            'status' => NotificationOutboxStatus::Failed->value,
            'last_error' => 'SMTP failed password='.$secret.' Bearer abc.def.ghi '
                .'https://mailuser:mailpass@smtp.example.test/send',
            'attempt_count' => 3,
        ]);
        $storedError = (string) DB::table('notification_outbox')->where('id', $row->id)->value('last_error');
        $this->assertStringContainsString($secret, $storedError);

        $response = $this->actingAs($admin)
            ->get(route('administration.notification-outbox.show', $row))
            ->assertOk();

        $response->assertInertia(fn (Assert $page) => $page
            ->component('administration/notification-outbox/show')
            ->where('row.id', $row->id)
            ->where('row.actor_name', 'Ada Admin')
            ->where('row.customer_name', 'Kunde GmbH')
            ->where('row.order_number', 'DA-FALLBACK-1')
            ->where('row.order_url', null)
            ->missing('row.payload_json')
            ->missing('row.internal_url')
            ->missing('row.last_error')
            ->where('row.error_text', function (?string $text) use ($secret): bool {
                return is_string($text)
                    && ! str_contains($text, $secret)
                    && ! str_contains($text, 'mailpass')
                    && ! str_contains($text, 'abc.def.ghi')
                    && str_contains($text, '[redacted]');
            }));

        $page = $response->viewData('page');
        $propsJson = json_encode($page['props'] ?? []);
        $this->assertIsString($propsJson);
        $this->assertStringNotContainsString($secret, $propsJson);
        $this->assertStringNotContainsString('mailpass', $propsJson);
        $this->assertStringNotContainsString('https://evil.example.test/hijack', $propsJson);
        $this->assertStringContainsString($secret, (string) DB::table('notification_outbox')->where('id', $row->id)->value('last_error'));
    }

    public function test_source_resolution_for_comment_and_approval_request_and_fallbacks(): void
    {
        $admin = User::factory()->role(Role::Admin)->create();
        $sales = User::factory()->role(Role::Sales)->create();
        $catalog = $this->createSpotClassicCatalog();
        $calculation = $this->createSavedCalculation($catalog, [
            ['inventory_id' => $catalog['hamburg']->id],
        ], $sales);

        $order = app(DispoOrderWriter::class)->createFromCalculation(
            $calculation,
            array_values($calculation->positions()->pluck('id')->all()),
            $sales,
        )->order;

        $comment = DispoOrderComment::query()->create([
            'dispo_order_id' => $order->id,
            'type' => DispoOrderCommentType::SalesInquiry,
            'body' => 'Frage',
            'created_by_id' => $sales->id,
            'created_by_name' => $sales->name,
            'parent_id' => null,
        ]);

        $request = DispoOrderApprovalRequest::query()->create([
            'dispo_order_id' => $order->id,
            'cycle_number' => 1,
            'status' => DispoOrderApprovalStatus::Approved,
            'kind' => DispoOrderApprovalKind::Regular,
            'submitted_by_id' => $sales->id,
            'submitted_by_name' => $sales->name,
            'submitted_at' => CarbonImmutable::now(),
            'submitted_lock_version' => $order->lock_version,
            'open_guard' => null,
        ]);

        $commentRow = $this->enqueueVisible([
            'eventType' => DispoOrderSalesInquiryService::EVENT_ASKED,
            'sourceType' => DispoOrderSalesInquiryService::SOURCE_TYPE_COMMENT,
            'sourceId' => $comment->id,
            'idempotencyKey' => 'resolve-comment',
            'payload' => ['order_number' => 'SHOULD-NOT-WIN'],
        ]);
        $this->setOutboxState($commentRow, ['status' => NotificationOutboxStatus::Failed->value]);

        $requestRow = $this->enqueueVisible([
            'eventType' => DispoOrderApprovalNotificationPublisher::EVENT_REJECTED,
            'sourceType' => DispoOrderApprovalNotificationPublisher::SOURCE_TYPE,
            'sourceId' => $request->id,
            'idempotencyKey' => 'resolve-request',
            'payload' => [
                'order_number' => 'SHOULD-NOT-WIN-2',
                'event_label' => 'Freigabe abgelehnt',
            ],
        ]);
        $this->setOutboxState($requestRow, ['status' => NotificationOutboxStatus::Failed->value]);

        $orphan = $this->enqueueVisible([
            'sourceId' => 999_999,
            'idempotencyKey' => 'resolve-orphan',
            'recipientUserId' => 888_888,
            'payload' => ['order_number' => 'DA-ORPHAN'],
        ]);
        $this->setOutboxState($orphan, ['status' => NotificationOutboxStatus::Failed->value]);

        $expectedUrl = route('dispo-orders.show', $order, absolute: false);

        $this->actingAs($admin)
            ->get(route('administration.notification-outbox.index', ['status' => 'failed']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('rows', function ($rows) use ($commentRow, $requestRow, $orphan, $order, $expectedUrl): bool {
                    $byId = collect($rows)->keyBy('id');

                    return ($byId[$commentRow->id]['order_id'] ?? null) === $order->id
                        && ($byId[$commentRow->id]['order_number'] ?? null) === $order->number
                        && ($byId[$commentRow->id]['order_url'] ?? null) === $expectedUrl
                        && ($byId[$requestRow->id]['order_id'] ?? null) === $order->id
                        && ($byId[$requestRow->id]['order_url'] ?? null) === $expectedUrl
                        && ($byId[$orphan->id]['order_id'] ?? null) === null
                        && ($byId[$orphan->id]['order_number'] ?? null) === 'DA-ORPHAN'
                        && ($byId[$orphan->id]['order_url'] ?? null) === null
                        && ($byId[$orphan->id]['recipient_email'] ?? null) === 'empfaenger@example.test';
                }));

        $this->assertSame(DispoOrderStatus::Draft, $order->fresh()->status);
    }

    public function test_suppressed_tab_lists_both_actions_and_reasons_separate_from_failed(): void
    {
        $admin = User::factory()->role(Role::Admin)->create();
        $recipient = User::factory()->role(Role::Sales)->create([
            'email' => 'intended@example.test',
            'name' => 'Intended Recipient',
        ]);
        $sales = User::factory()->role(Role::Sales)->create();
        $catalog = $this->createSpotClassicCatalog();
        $calculation = $this->createSavedCalculation($catalog, [
            ['inventory_id' => $catalog['hamburg']->id],
        ], $sales);
        $order = app(DispoOrderWriter::class)->createFromCalculation(
            $calculation,
            array_values($calculation->positions()->pluck('id')->all()),
            $sales,
        )->order;

        $failed = $this->enqueueVisible([
            'sourceId' => 70,
            'idempotencyKey' => 'suppress-vs-failed',
        ]);
        $this->setOutboxState($failed, [
            'status' => NotificationOutboxStatus::Failed->value,
            'last_error' => 'SMTP down',
        ]);

        $reasons = [
            'missing_advisor',
            'missing_ask_author',
            'missing_submitter',
            'self_notification',
            'recipient_not_loadable',
            'invalid_email',
        ];

        foreach ($reasons as $i => $reason) {
            $action = $i % 2 === 0
                ? DispoOrderSalesInquiryService::AUDIT_NOTIFICATION_SUPPRESSED
                : DispoOrderApprovalNotificationPublisher::AUDIT_NOTIFICATION_SUPPRESSED;

            AuditEvent::query()->create([
                'auditable_type' => DispoOrder::class,
                'auditable_id' => $order->id,
                'action' => $action,
                'old_values' => null,
                'new_values' => [
                    'event_type' => $i % 2 === 0
                        ? DispoOrderSalesInquiryService::EVENT_ASKED
                        : DispoOrderApprovalNotificationPublisher::EVENT_APPROVED,
                    'reason' => $reason,
                    'intended_recipient_user_id' => $recipient->id,
                ],
                'user_id' => $admin->id,
                'correlation_id' => null,
                'created_at' => now()->subSeconds(10 - $i),
            ]);
        }

        AuditEvent::query()->create([
            'auditable_type' => DispoOrder::class,
            'auditable_id' => $order->id,
            'action' => 'dispo_order.sales_inquiry.asked',
            'old_values' => null,
            'new_values' => ['noise' => true],
            'user_id' => $admin->id,
            'correlation_id' => null,
            'created_at' => now(),
        ]);

        $this->actingAs($admin)
            ->get(route('administration.notification-outbox.suppressed'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('administration/notification-outbox/suppressed')
                ->has('rows', 6)
                ->where('pagination.total', 6)
                ->where('rows.0.intended_recipient_email', 'intended@example.test')
                ->where('rows.0.order_number', $order->number)
                ->where('rows.0.notice', function (string $notice): bool {
                    return str_contains($notice, 'keine E-Mail versendet')
                        && str_contains($notice, 'kein SMTP-Fehler');
                })
                ->where('rows', function ($rows): bool {
                    $labels = collect($rows)->pluck('reason_label')->all();

                    return in_array('Mediaberater fehlt', $labels, true)
                        && in_array('Autor der Rückfrage fehlt', $labels, true)
                        && in_array('Einreicher fehlt', $labels, true)
                        && in_array('Selbstbenachrichtigung unterdrückt', $labels, true)
                        && in_array('Empfänger nicht ladbar', $labels, true)
                        && in_array('Ungültige E-Mail-Adresse', $labels, true);
                }));

        $this->actingAs($admin)
            ->get(route('administration.notification-outbox.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('rows', 1)
                ->where('rows.0.id', $failed->id)
                ->where('rows.0.status', 'failed'));
    }

    public function test_get_requests_do_not_mutate_outbox_or_audit(): void
    {
        $admin = User::factory()->role(Role::Admin)->create();
        $row = $this->enqueueVisible(['idempotencyKey' => 'immutable-get']);
        $this->setOutboxState($row, [
            'status' => NotificationOutboxStatus::Failed->value,
            'last_error' => 'SMTP down',
            'attempt_count' => 2,
        ]);

        AuditEvent::query()->create([
            'auditable_type' => DispoOrder::class,
            'auditable_id' => 1,
            'action' => DispoOrderSalesInquiryService::AUDIT_NOTIFICATION_SUPPRESSED,
            'old_values' => null,
            'new_values' => [
                'event_type' => DispoOrderSalesInquiryService::EVENT_ASKED,
                'reason' => 'missing_advisor',
                'intended_recipient_user_id' => null,
            ],
            'user_id' => $admin->id,
            'correlation_id' => null,
            'created_at' => now(),
        ]);

        $outboxBefore = DB::table('notification_outbox')->orderBy('id')->get()->map(fn ($r) => (array) $r)->all();
        $auditBefore = DB::table('audit_events')->orderBy('id')->get()->map(fn ($r) => (array) $r)->all();

        $this->actingAs($admin)->get(route('administration.notification-outbox.index'))->assertOk();
        $this->actingAs($admin)->get(route('administration.notification-outbox.show', $row))->assertOk();
        $this->actingAs($admin)->get(route('administration.notification-outbox.suppressed'))->assertOk();

        $this->assertSame(
            $outboxBefore,
            DB::table('notification_outbox')->orderBy('id')->get()->map(fn ($r) => (array) $r)->all(),
        );
        $this->assertSame(
            $auditBefore,
            DB::table('audit_events')->orderBy('id')->get()->map(fn ($r) => (array) $r)->all(),
        );
    }
}
