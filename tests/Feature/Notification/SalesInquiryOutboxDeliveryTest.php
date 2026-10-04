<?php

namespace Tests\Feature\Notification;

use App\Enums\NotificationOutboxStatus;
use App\Jobs\DeliverSalesInquiryOutboxJob;
use App\Mail\SalesInquiryOutboxMail;
use App\Models\NotificationOutbox;
use App\Services\DispoOrder\DispoOrderApprovalNotificationPublisher;
use App\Services\DispoOrder\DispoOrderSalesInquiryService;
use App\Services\Notification\NotificationOutboxDeliveryService;
use App\Services\Notification\NotificationOutboxStateMachine;
use App\Services\Notification\NotificationOutboxWriter;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use RuntimeException;
use Tests\Support\NotificationOutboxTestFactory;
use Tests\TestCase;

/**
 * BL-P9-02c / PO-BLP902C-1 – Ask/Answer SMTP-Delivery.
 */
class SalesInquiryOutboxDeliveryTest extends TestCase
{
    use RefreshDatabase;

    private function enqueueAsk(array $overrides = []): NotificationOutbox
    {
        return app(NotificationOutboxWriter::class)->enqueue(
            NotificationOutboxTestFactory::intent(array_merge([
                'eventType' => DispoOrderSalesInquiryService::EVENT_ASKED,
                'sourceType' => DispoOrderSalesInquiryService::SOURCE_TYPE_COMMENT,
                'sourceId' => 501,
                'recipientEmail' => 'advisor@example.test',
                'recipientName' => 'Advisor',
                'payload' => [
                    'order_number' => 'DA-2026-1-1',
                    'customer_name' => 'Kunde GmbH',
                    'campaign' => 'Kampagne A',
                    'event_label' => 'Rückfrage Vertrieb',
                    'actor_id' => 3,
                    'actor_name' => 'Dispo',
                    'internal_url' => 'https://dispo.example.test/dispo-orders/9',
                    'occurred_at' => '2026-09-29T12:00:00+02:00',
                ],
            ], $overrides)),
        );
    }

    public function test_dispatch_claims_ask_and_queues_job(): void
    {
        Queue::fake();
        $row = $this->enqueueAsk();

        $dispatched = app(NotificationOutboxDeliveryService::class)->dispatchDue();

        $this->assertSame(1, $dispatched);
        $row->refresh();
        $this->assertSame(NotificationOutboxStatus::Queued, $row->status);
        Queue::assertPushed(DeliverSalesInquiryOutboxJob::class, function (DeliverSalesInquiryOutboxJob $job) use ($row): bool {
            return $job->outboxId === $row->id;
        });
    }

    public function test_dispatch_delivers_approval_events_and_still_ignores_other_events(): void
    {
        Queue::fake();
        $approved = $this->enqueueAsk([
            'eventType' => DispoOrderApprovalNotificationPublisher::EVENT_APPROVED,
            'sourceType' => DispoOrderApprovalNotificationPublisher::SOURCE_TYPE,
            'sourceId' => 801,
        ]);
        $rejected = $this->enqueueAsk([
            'eventType' => DispoOrderApprovalNotificationPublisher::EVENT_REJECTED,
            'sourceType' => DispoOrderApprovalNotificationPublisher::SOURCE_TYPE,
            'sourceId' => 802,
        ]);
        $submitted = app(NotificationOutboxWriter::class)->enqueue(
            NotificationOutboxTestFactory::intent([
                'eventType' => 'dispo_order.approval.submitted',
                'sourceId' => 803,
            ]),
        );
        $other = app(NotificationOutboxWriter::class)->enqueue(
            NotificationOutboxTestFactory::intent([
                'eventType' => 'other.event',
                'sourceId' => 99,
            ]),
        );

        $dispatched = app(NotificationOutboxDeliveryService::class)->dispatchDue();

        $this->assertSame(2, $dispatched);
        $this->assertSame(NotificationOutboxStatus::Queued, $approved->fresh()->status);
        $this->assertSame(NotificationOutboxStatus::Queued, $rejected->fresh()->status);
        $this->assertSame(NotificationOutboxStatus::Pending, $submitted->fresh()->status);
        $this->assertSame(NotificationOutboxStatus::Pending, $other->fresh()->status);
        Queue::assertPushed(DeliverSalesInquiryOutboxJob::class, 2);
    }

    public function test_deliver_sends_mail_with_not001_payload_only_and_marks_sent(): void
    {
        Mail::fake();
        $row = $this->enqueueAsk();
        app(NotificationOutboxStateMachine::class)->markQueued($row);

        app(NotificationOutboxDeliveryService::class)->deliver($row->id);

        Mail::assertSent(SalesInquiryOutboxMail::class, function (SalesInquiryOutboxMail $mail) use ($row): bool {
            $payload = $mail->outbox->payload_json;
            $encoded = json_encode($payload);
            $this->assertIsString($encoded);
            $this->assertStringNotContainsString('Frage', $encoded);
            $this->assertStringNotContainsString('Antwort', $encoded);
            $this->assertSame('Rückfrage Vertrieb', $payload['event_label']);
            $this->assertSame($row->id, $mail->outbox->id);

            return true;
        });

        $row->refresh();
        $this->assertSame(NotificationOutboxStatus::Sent, $row->status);
        $this->assertSame(1, $row->attempt_count);
        $this->assertNotNull($row->sent_at);
        $this->assertNull($row->last_error);
    }

    public function test_deliver_failure_returns_to_pending_until_max_attempts_then_failed(): void
    {
        Mail::shouldReceive('to')->andReturnSelf();
        Mail::shouldReceive('send')->andThrow(new RuntimeException('SMTP down'));

        $row = $this->enqueueAsk(['sourceId' => 777]);
        $delivery = app(NotificationOutboxDeliveryService::class);
        $states = app(NotificationOutboxStateMachine::class);

        for ($i = 1; $i <= NotificationOutboxDeliveryService::MAX_ATTEMPTS - 1; $i++) {
            $states->tryClaimPending($row->fresh());
            try {
                $delivery->deliver($row->id);
                $this->fail('Erwartete SMTP-Exception.');
            } catch (RuntimeException $exception) {
                $this->assertSame('SMTP down', $exception->getMessage());
            }
            $row->refresh();
            $this->assertSame(NotificationOutboxStatus::Pending, $row->status);
            $this->assertSame($i, $row->attempt_count);
            $this->assertStringContainsString('SMTP down', (string) $row->last_error);
        }

        $states->tryClaimPending($row->fresh());
        $delivery->deliver($row->id);

        $row->refresh();
        $this->assertSame(NotificationOutboxStatus::Failed, $row->status);
        $this->assertSame(NotificationOutboxDeliveryService::MAX_ATTEMPTS, $row->attempt_count);
        $this->assertStringContainsString('SMTP down', (string) $row->last_error);
        $this->assertNull($row->sent_at);
    }

    public function test_recover_stuck_queued_and_sending_returns_to_pending(): void
    {
        $delivery = app(NotificationOutboxDeliveryService::class);
        $states = app(NotificationOutboxStateMachine::class);

        $queued = $this->enqueueAsk(['sourceId' => 10]);
        $states->markQueued($queued);
        NotificationOutbox::query()->whereKey($queued->id)->update([
            'updated_at' => CarbonImmutable::now()->subSeconds(
                NotificationOutboxDeliveryService::STUCK_AFTER_SECONDS + 5,
            ),
        ]);

        $sending = $this->enqueueAsk(['sourceId' => 11]);
        $states->markQueued($sending);
        $sending = $states->markSending($sending);
        NotificationOutbox::query()->whereKey($sending->id)->update([
            'last_attempt_at' => CarbonImmutable::now()->subSeconds(
                NotificationOutboxDeliveryService::STUCK_AFTER_SECONDS + 5,
            ),
            'updated_at' => CarbonImmutable::now()->subSeconds(
                NotificationOutboxDeliveryService::STUCK_AFTER_SECONDS + 5,
            ),
        ]);

        $fresh = $this->enqueueAsk(['sourceId' => 12]);
        $states->markQueued($fresh);

        $recovered = $delivery->recoverStuck();

        $this->assertSame(2, $recovered);
        $this->assertSame(NotificationOutboxStatus::Pending, $queued->fresh()->status);
        $this->assertSame(NotificationOutboxStatus::Pending, $sending->fresh()->status);
        $this->assertSame(NotificationOutboxStatus::Queued, $fresh->fresh()->status);
    }

    public function test_artisan_dispatch_command_recovers_and_dispatches(): void
    {
        Queue::fake();
        $row = $this->enqueueAsk(['sourceId' => 20]);

        $exit = Artisan::call('notification-outbox:dispatch-sales-inquiry');

        $this->assertSame(0, $exit);
        $this->assertSame(NotificationOutboxStatus::Queued, $row->fresh()->status);
        Queue::assertPushed(DeliverSalesInquiryOutboxJob::class);
    }

    public function test_job_deliver_happy_path(): void
    {
        Mail::fake();
        $row = $this->enqueueAsk(['sourceId' => 30]);
        app(NotificationOutboxStateMachine::class)->markQueued($row);

        (new DeliverSalesInquiryOutboxJob($row->id))
            ->handle(app(NotificationOutboxDeliveryService::class));

        Mail::assertSent(SalesInquiryOutboxMail::class);
        $this->assertSame(NotificationOutboxStatus::Sent, $row->fresh()->status);
    }
}
