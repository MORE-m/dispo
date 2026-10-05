<?php

namespace Database\Seeders;

use App\Enums\DispoOrderCommentType;
use App\Enums\NotificationOutboxChannel;
use App\Enums\NotificationOutboxStatus;
use App\Models\AuditEvent;
use App\Models\DispoOrder;
use App\Models\DispoOrderComment;
use App\Models\NotificationOutbox;
use App\Models\User;
use App\Services\DispoOrder\DispoOrderApprovalNotificationPublisher;
use App\Services\DispoOrder\DispoOrderSalesInquiryService;
use App\Services\Notification\NotificationOutboxIntent;
use App\Services\Notification\NotificationOutboxWriter;
use App\Support\E2E\E2EIsolatedEnvironmentGuard;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Isolierter Seed für PO-NOT002-ADMIN-1 Browser-Smoke.
 * Kein SMTP, kein Dispatch, nur lesbare Outbox-/Suppress-Testdaten.
 */
class E2ENotificationOutboxAdminSeeder extends Seeder
{
    public function run(): void
    {
        E2EIsolatedEnvironmentGuard::assertSafeForE2ESeeding();

        $this->call(E2ECalculationSeeder::class);

        $admin = User::query()->where('email', 'admin@example.com')->firstOrFail();
        $sales = User::query()->where('email', 'sales@example.com')->firstOrFail();
        $order = DispoOrder::query()->latest('id')->firstOrFail();
        $order->forceFill(['campaign' => 'Outbox-Admin-Smoke'])->save();

        $comment = DispoOrderComment::query()->create([
            'dispo_order_id' => $order->id,
            'type' => DispoOrderCommentType::SalesInquiry,
            'body' => 'E2E Smoke Rückfrage',
            'created_by_id' => $admin->id,
            'created_by_name' => $admin->name,
            'parent_id' => null,
        ]);

        $writer = app(NotificationOutboxWriter::class);
        $occurredAt = CarbonImmutable::now()->toIso8601String();

        $failed = $writer->enqueue(new NotificationOutboxIntent(
            eventType: DispoOrderSalesInquiryService::EVENT_ASKED,
            sourceType: DispoOrderSalesInquiryService::SOURCE_TYPE_COMMENT,
            sourceId: $comment->id,
            channel: NotificationOutboxChannel::Email,
            recipientUserId: $sales->id,
            recipientEmail: $sales->email,
            recipientName: $sales->name,
            payload: [
                'order_number' => $order->number,
                'customer_name' => $order->customer_name,
                'campaign' => $order->campaign,
                'event_label' => 'Rückfrage Vertrieb',
                'actor_id' => $admin->id,
                'actor_name' => $admin->name,
                'internal_url' => 'https://should-not-be-linked.example.test/x',
                'occurred_at' => $occurredAt,
            ],
            idempotencyKey: 'e2e-outbox-admin-failed-ask',
        ));

        DB::table('notification_outbox')->where('id', $failed->id)->update([
            'status' => NotificationOutboxStatus::Failed->value,
            'last_error' => 'SMTP failed password=e2e-secret-should-mask Bearer e2e.token.value',
            'attempt_count' => 3,
            'last_attempt_at' => now(),
        ]);

        $sent = $writer->enqueue(new NotificationOutboxIntent(
            eventType: DispoOrderApprovalNotificationPublisher::EVENT_APPROVED,
            sourceType: DispoOrderApprovalNotificationPublisher::SOURCE_TYPE,
            sourceId: 4242,
            channel: NotificationOutboxChannel::Email,
            recipientUserId: $sales->id,
            recipientEmail: $sales->email,
            recipientName: $sales->name,
            payload: [
                'order_number' => $order->number,
                'customer_name' => $order->customer_name,
                'campaign' => $order->campaign,
                'event_label' => 'Freigabe erteilt',
                'actor_id' => $admin->id,
                'actor_name' => $admin->name,
                'internal_url' => 'https://should-not-be-linked.example.test/y',
                'occurred_at' => $occurredAt,
            ],
            idempotencyKey: 'e2e-outbox-admin-sent-approved',
        ));

        DB::table('notification_outbox')->where('id', $sent->id)->update([
            'status' => NotificationOutboxStatus::Sent->value,
            'sent_at' => now(),
            'attempt_count' => 1,
            'last_attempt_at' => now(),
        ]);

        AuditEvent::query()->create([
            'auditable_type' => DispoOrder::class,
            'auditable_id' => $order->id,
            'action' => DispoOrderSalesInquiryService::AUDIT_NOTIFICATION_SUPPRESSED,
            'old_values' => null,
            'new_values' => [
                'event_type' => DispoOrderSalesInquiryService::EVENT_ASKED,
                'reason' => 'missing_advisor',
                'intended_recipient_user_id' => $sales->id,
            ],
            'user_id' => $admin->id,
            'correlation_id' => null,
            'created_at' => now(),
        ]);

        AuditEvent::query()->create([
            'auditable_type' => DispoOrder::class,
            'auditable_id' => $order->id,
            'action' => DispoOrderApprovalNotificationPublisher::AUDIT_NOTIFICATION_SUPPRESSED,
            'old_values' => null,
            'new_values' => [
                'event_type' => DispoOrderApprovalNotificationPublisher::EVENT_REJECTED,
                'reason' => 'invalid_email',
                'intended_recipient_user_id' => $sales->id,
            ],
            'user_id' => $admin->id,
            'correlation_id' => null,
            'created_at' => now()->subSecond(),
        ]);

        if (NotificationOutbox::query()->count() < 2) {
            throw new RuntimeException('E2E Outbox-Admin-Seed: zu wenig Outbox-Zeilen.');
        }
        if (! NotificationOutbox::query()->whereKey($failed->id)->where('status', 'failed')->exists()) {
            throw new RuntimeException('E2E Outbox-Admin-Seed: Failed-Zeile fehlt.');
        }
        if (AuditEvent::query()->whereIn('action', [
            DispoOrderSalesInquiryService::AUDIT_NOTIFICATION_SUPPRESSED,
            DispoOrderApprovalNotificationPublisher::AUDIT_NOTIFICATION_SUPPRESSED,
        ])->count() < 2) {
            throw new RuntimeException('E2E Outbox-Admin-Seed: Suppress-Audits fehlen.');
        }
    }
}
