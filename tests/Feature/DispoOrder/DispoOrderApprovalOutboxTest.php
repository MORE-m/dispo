<?php

namespace Tests\Feature\DispoOrder;

use App\Enums\DispoOrderApprovalKind;
use App\Enums\DispoOrderApprovalStatus;
use App\Enums\DispoOrderStatus;
use App\Enums\DispoOrderUploadCategory;
use App\Enums\NotificationOutboxStatus;
use App\Enums\Role;
use App\Exceptions\DispoOrderConflictException;
use App\Mail\SalesInquiryOutboxMail;
use App\Models\AuditEvent;
use App\Models\DispoOrder;
use App\Models\DispoOrderApprovalRequest;
use App\Models\DispoOrderUpload;
use App\Models\NotificationOutbox;
use App\Models\User;
use App\Services\DispoOrder\DispoOrderApprovalNotificationPublisher;
use App\Services\DispoOrder\DispoOrderApprovalService;
use App\Services\Notification\NotificationOutboxDeliveryService;
use App\Services\Notification\NotificationOutboxIntent;
use App\Services\Notification\NotificationOutboxStateMachine;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Tests\Concerns\CreatesSavedCalculation;
use Tests\Concerns\CreatesSpotClassicCatalog;
use Tests\Concerns\EnsuresCustomerConfirmationException;
use Tests\TestCase;

/**
 * BL-P9-02d / PO-APPROVAL-NOTIFY-1 – Freigabe erteilt/abgelehnt → Outbox + SMTP.
 *
 * @phpstan-type DraftContext array{order: DispoOrder, creator: User, catalog: array}
 */
class DispoOrderApprovalOutboxTest extends TestCase
{
    use CreatesSavedCalculation;
    use CreatesSpotClassicCatalog;
    use EnsuresCustomerConfirmationException;
    use RefreshDatabase;

    /**
     * @param  array<string, mixed>  $options
     * @return DraftContext
     */
    private function draftOrder(?User $creator = null, array $options = [], ?array $catalog = null): array
    {
        $catalog ??= $this->createSpotClassicCatalog();
        $creator ??= User::factory()->role(Role::Sales)->create($options['creator'] ?? []);
        $calculation = $this->createSavedCalculation(
            $catalog,
            [
                [
                    'inventory_id' => $catalog['hamburg']->id,
                    'position_discount_percent' => $options['position_discount'] ?? '0',
                ],
            ],
            $creator,
            [
                'order_discount_percent' => $options['order_discount'] ?? '0',
                'first_position_discount_percent' => $options['position_discount'] ?? '0',
            ],
        );

        $this->actingAs($creator)->post(route('dispo-orders.store', $calculation), [
            'position_ids' => [$calculation->positions()->first()->id],
        ])->assertRedirect();

        $order = DispoOrder::query()->latest('id')->firstOrFail();
        if (! ($options['without_confirmation'] ?? false)) {
            $order = $this->seedCustomerConfirmationException($order, $creator);
        }

        return [
            'order' => $order,
            'creator' => $creator,
            'catalog' => $catalog,
        ];
    }

    public function test_submit_does_not_enqueue_approval_notification(): void
    {
        ['order' => $order, 'creator' => $creator] = $this->draftOrder();
        $submitted = app(DispoOrderApprovalService::class)->submit($order, $creator, $order->lock_version);

        $this->assertSame(DispoOrderStatus::AwaitingSalesApproval, $submitted->status);
        $this->assertSame(0, NotificationOutbox::query()->count());
    }

    public function test_approve_enqueues_outbox_for_submitter_with_not001_payload(): void
    {
        ['order' => $order, 'creator' => $creator] = $this->draftOrder(
            User::factory()->role(Role::Sales)->create([
                'email' => 'einreicher@example.test',
                'name' => 'Einreicher',
            ]),
        );
        $approver = User::factory()->role(Role::Sales)->create(['name' => 'Entscheider']);
        $service = app(DispoOrderApprovalService::class);
        $submitted = $service->submit($order, $creator, $order->lock_version);
        $request = $submitted->pendingApprovalRequest;
        $this->assertNotNull($request);

        $approved = $service->approve($submitted, $approver, $submitted->lock_version, null, true);

        $row = NotificationOutbox::query()->sole();
        $this->assertSame(DispoOrderApprovalNotificationPublisher::EVENT_APPROVED, $row->event_type);
        $this->assertSame(DispoOrderApprovalNotificationPublisher::SOURCE_TYPE, $row->source_type);
        $this->assertSame($request->id, (int) $row->source_id);
        $this->assertSame($creator->id, (int) $row->recipient_user_id);
        $this->assertSame('einreicher@example.test', $row->recipient_email);
        $this->assertSame(NotificationOutboxStatus::Pending, $row->status);
        $this->assertSame('Freigabe erteilt (reguläre Freigabe)', $row->payload_json['event_label']);
        $this->assertSame($approved->number, $row->payload_json['order_number']);
        $this->assertSame($approved->customer_name, $row->payload_json['customer_name']);
        $this->assertSame($approved->campaign, $row->payload_json['campaign']);
        $this->assertSame($approver->id, $row->payload_json['actor_id']);
        $this->assertSame('Entscheider', $row->payload_json['actor_name']);
        $this->assertStringContainsString((string) $approved->id, $row->payload_json['internal_url']);
        $this->assertEqualsCanonicalizing(
            NotificationOutboxIntent::PAYLOAD_KEYS,
            array_keys($row->payload_json),
        );
        $this->assertArrayNotHasKey('rejection_reason', $row->payload_json);
        $this->assertSame(0, AuditEvent::query()
            ->where('action', DispoOrderApprovalNotificationPublisher::AUDIT_NOTIFICATION_SUPPRESSED)
            ->count());
    }

    public function test_reject_enqueues_without_rejection_reason_and_special_is_labeled(): void
    {
        $catalog = $this->createSpotClassicCatalog();
        $creator = User::factory()->role(Role::Sales)->create([
            'discount_limit_percent' => '10',
            'email' => 'special-submitter@example.test',
        ]);
        ['order' => $order] = $this->draftOrder($creator, ['position_discount' => '20'], $catalog);
        $this->assertTrue($order->requiresSpecialApproval());

        $approver = User::factory()->role(Role::Sales)->create(['can_special_approve' => true]);
        $service = app(DispoOrderApprovalService::class);
        $submitted = $service->submit($order, $creator, $order->lock_version);
        $reason = 'Geheime Ablehnungsbegründung nicht in die Mail.';
        $rejected = $service->reject($submitted, $approver, $submitted->lock_version, $reason);

        $row = NotificationOutbox::query()->sole();
        $this->assertSame(DispoOrderApprovalNotificationPublisher::EVENT_REJECTED, $row->event_type);
        $this->assertSame(DispoOrderApprovalKind::Special, $rejected->latestApprovalRequest?->kind);
        $this->assertSame('Freigabe abgelehnt (Sonderfreigabe)', $row->payload_json['event_label']);
        $payloadJson = json_encode($row->payload_json);
        $this->assertIsString($payloadJson);
        $this->assertStringNotContainsString('Geheime Ablehnungsbegründung', $payloadJson);
        $this->assertArrayNotHasKey('rejection_reason', $row->payload_json);
        $this->assertArrayNotHasKey('special_approval_reasons', $row->payload_json);
    }

    public function test_recipient_is_submitter_even_when_different_from_creator_and_advisor(): void
    {
        $creator = User::factory()->role(Role::Sales)->create(['email' => 'ersteller@example.test']);
        $advisor = User::factory()->role(Role::Sales)->create(['email' => 'advisor@example.test']);
        $submitter = User::factory()->role(Role::Admin)->create(['email' => 'einreicher-admin@example.test']);
        $approver = User::factory()->role(Role::Sales)->create(['email' => 'entscheider@example.test']);

        ['order' => $order] = $this->draftOrder($creator);
        $order->advisor_id = $advisor->id;
        $order->advisor_name = $advisor->name;
        $order->save();

        $service = app(DispoOrderApprovalService::class);
        $submitted = $service->submit($order, $submitter, $order->lock_version);
        $this->assertSame($submitter->id, (int) $submitted->pendingApprovalRequest?->submitted_by_id);

        $service->approve($submitted, $approver, $submitted->lock_version, null, true);

        $row = NotificationOutbox::query()->sole();
        $this->assertSame($submitter->id, (int) $row->recipient_user_id);
        $this->assertSame('einreicher-admin@example.test', $row->recipient_email);
        $this->assertNotSame($creator->id, (int) $row->recipient_user_id);
        $this->assertNotSame($advisor->id, (int) $row->recipient_user_id);
    }

    public function test_self_notification_is_suppressed_and_decision_succeeds(): void
    {
        $service = app(DispoOrderApprovalService::class);
        $admin = User::factory()->role(Role::Admin)->create(['email' => 'self-admin@example.test']);
        ['order' => $order] = $this->draftOrder();
        $submitted = $service->submit($order, $admin, $order->lock_version);
        $approved = $service->approve($submitted, $admin, $submitted->lock_version, null, true);

        $this->assertSame(DispoOrderStatus::AtDisposition, $approved->status);
        $this->assertSame(0, NotificationOutbox::query()->count());
        $audit = AuditEvent::query()
            ->where('action', DispoOrderApprovalNotificationPublisher::AUDIT_NOTIFICATION_SUPPRESSED)
            ->sole();
        $this->assertSame('self_notification', $audit->new_values['reason'] ?? null);
        $this->assertSame(
            DispoOrderApprovalNotificationPublisher::EVENT_APPROVED,
            $audit->new_values['event_type'] ?? null,
        );
    }

    public function test_invalid_submitter_email_is_suppressed_and_reject_succeeds(): void
    {
        $service = app(DispoOrderApprovalService::class);
        $invalid = User::factory()->role(Role::Sales)->create(['email' => 'nicht-gueltig']);
        $approver = User::factory()->role(Role::Sales)->create();
        ['order' => $order] = $this->draftOrder($invalid);
        $submitted = $service->submit($order, $invalid, $order->lock_version);
        $rejected = $service->reject($submitted, $approver, $submitted->lock_version, 'Ablehnung');

        $this->assertSame(DispoOrderStatus::ApprovalRejected, $rejected->status);
        $this->assertSame(0, NotificationOutbox::query()->count());
        $audit = AuditEvent::query()
            ->where('action', DispoOrderApprovalNotificationPublisher::AUDIT_NOTIFICATION_SUPPRESSED)
            ->sole();
        $this->assertSame('invalid_email', $audit->new_values['reason'] ?? null);
    }

    public function test_unloadable_submitter_is_suppressed_without_outbox_write(): void
    {
        $service = app(DispoOrderApprovalService::class);
        ['order' => $order, 'creator' => $creator] = $this->draftOrder();
        $approver = User::factory()->role(Role::Sales)->create();
        $submitted = $service->submit($order, $creator, $order->lock_version);
        $request = $submitted->pendingApprovalRequest;
        $this->assertNotNull($request);
        $request->submitted_by_id = 9_999_999;

        app(DispoOrderApprovalNotificationPublisher::class)->publishDecision(
            $submitted,
            $request,
            $approver,
            DispoOrderApprovalNotificationPublisher::EVENT_APPROVED,
        );

        $this->assertSame(DispoOrderStatus::AwaitingSalesApproval, $submitted->fresh()->status);
        $this->assertSame(0, NotificationOutbox::query()->count());
        $audit = AuditEvent::query()
            ->where('action', DispoOrderApprovalNotificationPublisher::AUDIT_NOTIFICATION_SUPPRESSED)
            ->sole();
        $this->assertSame('recipient_not_loadable', $audit->new_values['reason'] ?? null);
        $this->assertSame(9_999_999, $audit->new_values['intended_recipient_user_id'] ?? null);
    }

    public function test_outbox_write_failure_rolls_back_decision_status_request_and_audit(): void
    {
        ['order' => $order, 'creator' => $creator] = $this->draftOrder();
        $approver = User::factory()->role(Role::Sales)->create();
        $service = app(DispoOrderApprovalService::class);
        $submitted = $service->submit($order, $creator, $order->lock_version);
        $beforeStatus = $submitted->status;
        $beforeLock = $submitted->lock_version;
        $requestId = $submitted->pendingApprovalRequest?->id;
        $this->assertNotNull($requestId);
        $beforeAudits = AuditEvent::query()->count();

        $failOnce = true;
        NotificationOutbox::saving(function () use (&$failOnce): void {
            if ($failOnce) {
                $failOnce = false;
                throw new RuntimeException('outbox write failed');
            }
        });

        try {
            $service->approve($submitted, $approver, $submitted->lock_version, null, true);
            $this->fail('Expected outbox write failure.');
        } catch (RuntimeException $exception) {
            $this->assertSame('outbox write failed', $exception->getMessage());
        }

        $order->refresh();
        $this->assertSame($beforeStatus, $order->status);
        $this->assertSame($beforeLock, $order->lock_version);
        $this->assertSame(DispoOrderApprovalStatus::Pending, DispoOrderApprovalRequest::query()->findOrFail($requestId)->status);
        $this->assertSame(0, NotificationOutbox::query()->count());
        $this->assertSame($beforeAudits, AuditEvent::query()->count());
        $this->assertSame(0, AuditEvent::query()->where('action', 'dispo_order.approved')->count());
    }

    public function test_stale_and_repeated_decision_do_not_enqueue_again(): void
    {
        ['order' => $order, 'creator' => $creator] = $this->draftOrder();
        $approver = User::factory()->role(Role::Sales)->create();
        $service = app(DispoOrderApprovalService::class);
        $submitted = $service->submit($order, $creator, $order->lock_version);
        $staleLock = $submitted->lock_version;
        $approved = $service->approve($submitted, $approver, $staleLock, null, true);
        $this->assertSame(1, NotificationOutbox::query()->count());

        try {
            $service->approve($approved, $approver, $staleLock, null, true);
            $this->fail('Expected stale conflict.');
        } catch (DispoOrderConflictException) {
        }

        $this->assertSame(1, NotificationOutbox::query()->count());
        $this->assertSame(DispoOrderStatus::AtDisposition, $approved->fresh()->status);
    }

    public function test_new_approval_cycle_enqueues_independent_notification(): void
    {
        Storage::fake((string) config('dispo.files_disk'));
        $catalog = $this->createSpotClassicCatalog();
        $creator = User::factory()->role(Role::Sales)->create(['email' => 'zyklus@example.test']);
        $approver = User::factory()->role(Role::Sales)->create();
        $admin = User::factory()->role(Role::Admin)->create();
        $calculation = $this->createSavedCalculation($catalog, [
            ['inventory_id' => $catalog['hamburg']->id],
        ], $creator);
        $this->actingAs($creator)->post(route('dispo-orders.store', $calculation), [
            'position_ids' => [$calculation->positions()->first()->id],
        ])->assertRedirect();
        $order = DispoOrder::query()->latest('id')->firstOrFail();
        $path = base_path('tests/fixtures/customer-confirmation-sample.pdf');
        $this->actingAs($creator)->withHeader('Accept', 'application/json')->post(
            route('dispo-orders.uploads.customer-confirmation', $order),
            [
                'lock_version' => $order->lock_version,
                'file' => new UploadedFile($path, 'bestaetigung.pdf', 'application/pdf', null, true),
            ],
        )->assertOk();
        $order->refresh();
        $upload = DispoOrderUpload::query()
            ->where('dispo_order_id', $order->id)
            ->where('category', DispoOrderUploadCategory::CustomerConfirmation->value)
            ->whereNull('archived_at')
            ->firstOrFail();

        $service = app(DispoOrderApprovalService::class);
        $firstSubmitted = $service->submit($order, $creator, $order->lock_version);
        $firstApproved = $service->approve($firstSubmitted, $approver, $firstSubmitted->lock_version, null, true);
        $firstRequestId = $firstApproved->latestApprovalRequest?->id;
        $this->assertNotNull($firstRequestId);

        $this->actingAs($admin)->withHeader('Accept', 'application/json')->postJson(
            route('dispo-orders.uploads.archive', [$firstApproved, $upload]),
            ['lock_version' => $firstApproved->lock_version],
        )->assertOk();
        $order->refresh();

        $this->actingAs($creator)->withHeader('Accept', 'application/json')->post(
            route('dispo-orders.uploads.customer-confirmation', $order),
            [
                'lock_version' => $order->lock_version,
                'file' => new UploadedFile($path, 'erneut.pdf', 'application/pdf', null, true),
            ],
        )->assertOk();
        $order->refresh();
        $secondSubmitted = $service->submit($order, $creator, $order->lock_version);
        $secondApproved = $service->approve($secondSubmitted, $approver, $secondSubmitted->lock_version, null, true);
        $secondRequestId = $secondApproved->latestApprovalRequest?->id;
        $this->assertNotNull($secondRequestId);
        $this->assertNotSame($firstRequestId, $secondRequestId);

        $rows = NotificationOutbox::query()
            ->where('event_type', DispoOrderApprovalNotificationPublisher::EVENT_APPROVED)
            ->orderBy('id')
            ->get();
        $this->assertCount(2, $rows);
        $this->assertSame($firstRequestId, (int) $rows[0]->source_id);
        $this->assertSame($secondRequestId, (int) $rows[1]->source_id);
        $this->assertNotSame($rows[0]->idempotency_key, $rows[1]->idempotency_key);
    }

    public function test_payload_stays_frozen_when_dispo_order_fields_change_later(): void
    {
        ['order' => $order, 'creator' => $creator] = $this->draftOrder();
        $originalCustomer = $order->customer_name;
        $approver = User::factory()->role(Role::Sales)->create();
        $service = app(DispoOrderApprovalService::class);
        $submitted = $service->submit($order, $creator, $order->lock_version);
        $service->approve($submitted, $approver, $submitted->lock_version, null, true);

        $order->refresh();
        $order->customer_name = 'Später geänderter Kunde';
        $order->campaign = 'Spätere Kampagne';
        $order->save();

        $row = NotificationOutbox::query()->sole();
        $this->assertSame($originalCustomer, $row->payload_json['customer_name']);
        $this->assertNotSame('Später geänderter Kunde', $row->payload_json['customer_name']);
        $this->assertNotSame('Spätere Kampagne', $row->payload_json['campaign']);
    }

    public function test_smtp_failure_keeps_decision_and_uses_retry(): void
    {
        Mail::shouldReceive('to')->andReturnSelf();
        Mail::shouldReceive('send')->andThrow(new RuntimeException('SMTP down'));

        ['order' => $order, 'creator' => $creator] = $this->draftOrder();
        $approver = User::factory()->role(Role::Sales)->create();
        $service = app(DispoOrderApprovalService::class);
        $submitted = $service->submit($order, $creator, $order->lock_version);
        $approved = $service->approve($submitted, $approver, $submitted->lock_version, null, true);
        $row = NotificationOutbox::query()->sole();
        app(NotificationOutboxStateMachine::class)->tryClaimPending($row);

        try {
            app(NotificationOutboxDeliveryService::class)->deliver($row->id);
            $this->fail('Expected SMTP exception.');
        } catch (RuntimeException $exception) {
            $this->assertSame('SMTP down', $exception->getMessage());
        }

        $this->assertSame(DispoOrderStatus::AtDisposition, $approved->fresh()->status);
        $this->assertSame(DispoOrderApprovalStatus::Approved, $approved->fresh()->latestApprovalRequest?->status);
        $row->refresh();
        $this->assertSame(NotificationOutboxStatus::Pending, $row->status);
        $this->assertSame(1, $row->attempt_count);
        $this->assertStringContainsString('SMTP down', (string) $row->last_error);
    }

    public function test_isolated_smoke_decision_to_rendered_mail_without_rejection_text(): void
    {
        Mail::fake();
        ['order' => $order, 'creator' => $creator] = $this->draftOrder(
            User::factory()->role(Role::Sales)->create(['email' => 'smoke@example.test']),
        );
        $approver = User::factory()->role(Role::Sales)->create(['name' => 'Smoke Entscheider']);
        $service = app(DispoOrderApprovalService::class);
        $submitted = $service->submit($order, $creator, $order->lock_version);
        $rejected = $service->reject(
            $submitted,
            $approver,
            $submitted->lock_version,
            'Darf nicht in der Mail stehen.',
        );

        $row = NotificationOutbox::query()->sole();
        app(NotificationOutboxDeliveryService::class)->dispatchDue();
        app(NotificationOutboxDeliveryService::class)->deliver($row->id);

        Mail::assertSent(SalesInquiryOutboxMail::class, function (SalesInquiryOutboxMail $mail) use ($row, $rejected): bool {
            $body = $mail->render();
            $this->assertStringContainsString((string) $rejected->number, $body);
            $this->assertStringContainsString('Freigabe abgelehnt (reguläre Freigabe)', $body);
            $this->assertStringContainsString('Smoke Entscheider', $body);
            $this->assertStringContainsString('Ablehnungsbegründung ist in der Anwendung einsehbar', $body);
            $this->assertStringNotContainsString('Darf nicht in der Mail stehen.', $body);
            $this->assertSame($row->id, $mail->outbox->id);

            return true;
        });

        $this->assertSame(NotificationOutboxStatus::Sent, $row->fresh()->status);
        $this->assertSame(DispoOrderStatus::ApprovalRejected, $rejected->fresh()->status);
    }
}
