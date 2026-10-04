<?php

namespace Tests\Feature\DispoOrder;

use App\Enums\DispoOrderCommentType;
use App\Enums\DispoOrderStatus;
use App\Enums\NotificationOutboxStatus;
use App\Enums\Role;
use App\Models\AuditEvent;
use App\Models\DispoOrder;
use App\Models\DispoOrderComment;
use App\Models\NotificationOutbox;
use App\Models\User;
use App\Services\DispoOrder\DispoOrderApprovalService;
use App\Services\DispoOrder\DispoOrderOperationalStatusService;
use App\Services\DispoOrder\DispoOrderSalesInquiryService;
use App\Services\Notification\NotificationOutboxIntent;
use App\Services\Notification\NotificationOutboxWriter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\Concerns\CreatesSavedCalculation;
use Tests\Concerns\CreatesSpotClassicCatalog;
use Tests\Concerns\EnsuresCustomerConfirmationException;
use Tests\Support\NotificationOutboxTestFactory;
use Tests\TestCase;

/**
 * BL-P9-02b / PO-BLP902B-1 – Ask/Answer → Outbox (NOT-001 Payload, Suppress-Audit).
 */
class DispoOrderSalesInquiryOutboxTest extends TestCase
{
    use CreatesSavedCalculation;
    use CreatesSpotClassicCatalog;
    use EnsuresCustomerConfirmationException;
    use RefreshDatabase;

    /**
     * @return array{order: DispoOrder, creator: User, disposition: User, sales: User}
     */
    private function orderInProgress(): array
    {
        $catalog = $this->createSpotClassicCatalog();
        $creator = User::factory()->role(Role::Sales)->create([
            'email' => 'advisor-ok@example.test',
            'name' => 'Advisor Sales',
        ]);
        $approver = User::factory()->role(Role::Sales)->create();
        $disposition = User::factory()->role(Role::Disposition)->create([
            'email' => 'disposition@example.test',
            'name' => 'Dispo User',
        ]);

        $calculation = $this->createSavedCalculation($catalog, [
            ['inventory_id' => $catalog['hamburg']->id],
        ], $creator);

        $this->actingAs($creator)->post(route('dispo-orders.store', $calculation), [
            'position_ids' => [$calculation->positions()->first()->id],
        ])->assertRedirect();

        $order = DispoOrder::query()->latest('id')->firstOrFail();
        $this->assertSame($creator->id, (int) $order->advisor_id);

        $approvals = app(DispoOrderApprovalService::class);
        $order = $this->seedCustomerConfirmationException($order, $creator);
        $submitted = $approvals->submit($order, $creator, $order->lock_version);
        $approved = $approvals->approve($submitted, $approver, $submitted->lock_version, null, true);
        $inProgress = app(DispoOrderOperationalStatusService::class)->transition(
            $approved,
            $disposition,
            $approved->lock_version,
            DispoOrderStatus::InProgress,
        );

        return [
            'order' => $inProgress,
            'creator' => $creator,
            'disposition' => $disposition,
            'sales' => $creator,
        ];
    }

    public function test_ask_enqueues_outbox_for_advisor_with_not001_payload_without_question_body(): void
    {
        ['order' => $order, 'creator' => $advisor, 'disposition' => $disposition] = $this->orderInProgress();

        $asked = app(DispoOrderSalesInquiryService::class)->ask(
            $order,
            $disposition,
            $order->lock_version,
            'Geheimer Fragetext bitte nicht in die Mail.',
        );

        $comment = DispoOrderComment::query()
            ->where('dispo_order_id', $asked->id)
            ->where('type', DispoOrderCommentType::SalesInquiry)
            ->firstOrFail();

        $row = NotificationOutbox::query()
            ->where('event_type', DispoOrderSalesInquiryService::EVENT_ASKED)
            ->sole();
        $this->assertSame(DispoOrderSalesInquiryService::EVENT_ASKED, $row->event_type);
        $this->assertSame(DispoOrderSalesInquiryService::SOURCE_TYPE_COMMENT, $row->source_type);
        $this->assertSame($comment->id, (int) $row->source_id);
        $this->assertSame($advisor->id, (int) $row->recipient_user_id);
        $this->assertSame('advisor-ok@example.test', $row->recipient_email);
        $this->assertSame(NotificationOutboxStatus::Pending, $row->status);

        $payload = $row->payload_json;
        $this->assertEqualsCanonicalizing(
            NotificationOutboxIntent::PAYLOAD_KEYS,
            array_keys($payload),
        );
        $this->assertCount(count(NotificationOutboxIntent::PAYLOAD_KEYS), $payload);
        $this->assertSame($asked->number, $payload['order_number']);
        $this->assertSame('Rückfrage Vertrieb', $payload['event_label']);
        $this->assertSame($disposition->id, $payload['actor_id']);
        $this->assertStringContainsString((string) $asked->id, $payload['internal_url']);
        $payloadJson = json_encode($payload);
        $this->assertIsString($payloadJson);
        $this->assertStringNotContainsString('Geheimer Fragetext', $payloadJson);

        $this->assertSame(0, AuditEvent::query()
            ->where('action', DispoOrderSalesInquiryService::AUDIT_NOTIFICATION_SUPPRESSED)
            ->count());
    }

    public function test_answer_enqueues_for_asker_and_payload_excludes_answer_text(): void
    {
        ['order' => $order, 'disposition' => $disposition] = $this->orderInProgress();
        $service = app(DispoOrderSalesInquiryService::class);

        $asked = $service->ask($order, $disposition, $order->lock_version, 'Frage');
        $inquiry = DispoOrderComment::query()
            ->where('dispo_order_id', $asked->id)
            ->where('type', DispoOrderCommentType::SalesInquiry)
            ->firstOrFail();

        $askOutboxId = NotificationOutbox::query()
            ->where('event_type', DispoOrderSalesInquiryService::EVENT_ASKED)
            ->value('id');
        $this->assertNotNull($askOutboxId);

        $sales = User::factory()->role(Role::Sales)->create([
            'email' => 'answerer@example.test',
        ]);
        $answered = $service->answer(
            $asked,
            $inquiry,
            $sales,
            $asked->lock_version,
            'Geheimer Antworttext nicht in Payload.',
        );

        $response = DispoOrderComment::query()
            ->where('dispo_order_id', $answered->id)
            ->where('type', DispoOrderCommentType::SalesInquiryResponse)
            ->firstOrFail();

        $answerRow = NotificationOutbox::query()
            ->where('event_type', DispoOrderSalesInquiryService::EVENT_ANSWERED)
            ->sole();

        $this->assertSame($response->id, (int) $answerRow->source_id);
        $this->assertSame($disposition->id, (int) $answerRow->recipient_user_id);
        $this->assertSame('Antwort auf Rückfrage', $answerRow->payload_json['event_label']);
        $this->assertSame($sales->id, $answerRow->payload_json['actor_id']);

        $payloadJson = json_encode($answerRow->payload_json);
        $this->assertIsString($payloadJson);
        $this->assertStringNotContainsString('Geheimer Antworttext', $payloadJson);
        $this->assertEqualsCanonicalizing(
            NotificationOutboxIntent::PAYLOAD_KEYS,
            array_keys($answerRow->payload_json),
        );
        $this->assertCount(count(NotificationOutboxIntent::PAYLOAD_KEYS), $answerRow->payload_json);
    }

    public function test_ask_with_missing_advisor_suppresses_outbox_and_audits_reason(): void
    {
        ['order' => $order, 'disposition' => $disposition] = $this->orderInProgress();
        $order->advisor_id = null;
        $order->advisor_name = null;
        $order->save();

        $asked = app(DispoOrderSalesInquiryService::class)->ask(
            $order,
            $disposition,
            $order->lock_version,
            'Ohne Berater.',
        );

        $this->assertSame(DispoOrderStatus::SalesInquiry, $asked->status);
        $this->assertSame(0, NotificationOutbox::query()
            ->where('event_type', DispoOrderSalesInquiryService::EVENT_ASKED)
            ->count());

        $audit = AuditEvent::query()
            ->where('action', DispoOrderSalesInquiryService::AUDIT_NOTIFICATION_SUPPRESSED)
            ->sole();
        $this->assertSame('missing_advisor', $audit->new_values['reason'] ?? null);
        $this->assertSame(DispoOrderSalesInquiryService::EVENT_ASKED, $audit->new_values['event_type'] ?? null);
    }

    public function test_ask_with_invalid_email_suppresses_and_keeps_fachvorgang(): void
    {
        ['order' => $order, 'creator' => $advisor, 'disposition' => $disposition] = $this->orderInProgress();
        $advisor->email = 'nicht-gueltig';
        $advisor->save();

        $asked = app(DispoOrderSalesInquiryService::class)->ask(
            $order,
            $disposition,
            $order->lock_version,
            'Ungültige Mail.',
        );

        $this->assertSame(DispoOrderStatus::SalesInquiry, $asked->status);
        $this->assertSame(1, DispoOrderComment::query()->where('dispo_order_id', $asked->id)->count());
        $this->assertSame(0, NotificationOutbox::query()
            ->where('event_type', DispoOrderSalesInquiryService::EVENT_ASKED)
            ->count());
        $audit = AuditEvent::query()
            ->where('action', DispoOrderSalesInquiryService::AUDIT_NOTIFICATION_SUPPRESSED)
            ->sole();
        $this->assertSame('invalid_email', $audit->new_values['reason'] ?? null);
    }

    public function test_ask_self_notification_is_suppressed(): void
    {
        ['order' => $order, 'disposition' => $disposition] = $this->orderInProgress();
        $order->advisor_id = $disposition->id;
        $order->advisor_name = $disposition->name;
        $order->save();

        $asked = app(DispoOrderSalesInquiryService::class)->ask(
            $order,
            $disposition,
            $order->lock_version,
            'Ich frage mich selbst.',
        );

        $this->assertSame(DispoOrderStatus::SalesInquiry, $asked->status);
        $this->assertSame(0, NotificationOutbox::query()
            ->where('event_type', DispoOrderSalesInquiryService::EVENT_ASKED)
            ->count());
        $audit = AuditEvent::query()
            ->where('action', DispoOrderSalesInquiryService::AUDIT_NOTIFICATION_SUPPRESSED)
            ->sole();
        $this->assertSame('self_notification', $audit->new_values['reason'] ?? null);
    }

    public function test_required_outbox_write_failure_rolls_back_ask_fachvorgang(): void
    {
        ['order' => $order, 'disposition' => $disposition] = $this->orderInProgress();
        $beforeStatus = $order->status;
        $beforeLock = $order->lock_version;
        $beforeComments = DispoOrderComment::query()->count();
        $beforeAudits = AuditEvent::query()->count();
        $beforeOutbox = NotificationOutbox::query()->count();

        $failOnce = true;
        NotificationOutbox::saving(function () use (&$failOnce): void {
            if ($failOnce) {
                $failOnce = false;
                throw new RuntimeException('outbox write failed');
            }
        });

        try {
            app(DispoOrderSalesInquiryService::class)->ask(
                $order,
                $disposition,
                $order->lock_version,
                'Darf nicht persistieren.',
            );
            $this->fail('Expected outbox write failure.');
        } catch (RuntimeException $exception) {
            $this->assertSame('outbox write failed', $exception->getMessage());
        }

        $order->refresh();
        $this->assertSame($beforeStatus, $order->status);
        $this->assertSame($beforeLock, $order->lock_version);
        $this->assertSame($beforeComments, DispoOrderComment::query()->count());
        $this->assertSame($beforeOutbox, NotificationOutbox::query()->count());
        $this->assertSame($beforeAudits, AuditEvent::query()->count());
    }

    public function test_repeated_enqueue_same_comment_identity_is_idempotent(): void
    {
        ['order' => $order, 'creator' => $advisor, 'disposition' => $disposition] = $this->orderInProgress();

        $asked = app(DispoOrderSalesInquiryService::class)->ask(
            $order,
            $disposition,
            $order->lock_version,
            'Einmal fragen.',
        );
        $comment = DispoOrderComment::query()
            ->where('dispo_order_id', $asked->id)
            ->where('type', DispoOrderCommentType::SalesInquiry)
            ->firstOrFail();

        $first = NotificationOutbox::query()
            ->where('event_type', DispoOrderSalesInquiryService::EVENT_ASKED)
            ->sole();

        $intent = NotificationOutboxTestFactory::intent([
            'eventType' => DispoOrderSalesInquiryService::EVENT_ASKED,
            'sourceType' => DispoOrderSalesInquiryService::SOURCE_TYPE_COMMENT,
            'sourceId' => (int) $comment->id,
            'recipientUserId' => (int) $advisor->id,
            'recipientEmail' => $advisor->email,
            'recipientName' => $advisor->name,
            'payload' => [
                'order_number' => $asked->number,
                'customer_name' => $asked->customer_name,
                'campaign' => $asked->campaign,
                'event_label' => 'Rückfrage Vertrieb',
                'actor_id' => $disposition->id,
                'actor_name' => $disposition->name,
                'internal_url' => route('dispo-orders.show', $asked, absolute: true),
                'occurred_at' => '2026-09-29T10:00:00+02:00',
            ],
        ]);

        $second = app(NotificationOutboxWriter::class)->enqueue($intent);
        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, NotificationOutbox::query()
            ->where('event_type', DispoOrderSalesInquiryService::EVENT_ASKED)
            ->count());
        $this->assertSame($first->payload_json, $second->payload_json);
    }
}
