<?php

namespace App\Services\DispoOrder;

use App\Enums\DispoOrderApprovalKind;
use App\Enums\NotificationOutboxChannel;
use App\Models\DispoOrder;
use App\Models\DispoOrderApprovalRequest;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Services\Notification\NotificationOutboxIntent;
use App\Services\Notification\NotificationOutboxWriter;

/**
 * Outbox für Freigabe erteilt/abgelehnt (PO-APPROVAL-NOTIFY-1 / BL-P9-02d).
 * Empfänger ausschließlich submitted_by_id; keine Submit-/Invalidierungsmails.
 */
final class DispoOrderApprovalNotificationPublisher
{
    public const string EVENT_APPROVED = 'dispo_order.approval.approved';

    public const string EVENT_REJECTED = 'dispo_order.approval.rejected';

    public const string SOURCE_TYPE = 'dispo_order_approval_request';

    public const string AUDIT_NOTIFICATION_SUPPRESSED = 'dispo_order.approval.notification_suppressed';

    public function __construct(
        private readonly AuditLogger $audit,
        private readonly NotificationOutboxWriter $outbox,
    ) {}

    public function publishDecision(
        DispoOrder $order,
        DispoOrderApprovalRequest $request,
        User $actor,
        string $eventType,
    ): void {
        $recipientUserId = (int) $request->submitted_by_id;
        if ($recipientUserId <= 0) {
            $this->recordSuppressed($order, $actor, $eventType, $request, 'missing_submitter', null);

            return;
        }

        if ($recipientUserId === (int) $actor->id) {
            $this->recordSuppressed($order, $actor, $eventType, $request, 'self_notification', $recipientUserId);

            return;
        }

        $recipient = User::query()->find($recipientUserId);
        if ($recipient === null) {
            $this->recordSuppressed($order, $actor, $eventType, $request, 'recipient_not_loadable', $recipientUserId);

            return;
        }

        $email = trim((string) $recipient->email);
        if (! $this->isValidEmail($email)) {
            $this->recordSuppressed($order, $actor, $eventType, $request, 'invalid_email', $recipientUserId);

            return;
        }

        $occurredAt = $request->decided_at !== null
            ? $request->decided_at->toIso8601String()
            : now()->toIso8601String();

        $intent = new NotificationOutboxIntent(
            eventType: $eventType,
            sourceType: self::SOURCE_TYPE,
            sourceId: (int) $request->id,
            channel: NotificationOutboxChannel::Email,
            recipientUserId: (int) $recipient->id,
            recipientEmail: $email,
            recipientName: trim((string) $recipient->name) !== ''
                ? trim((string) $recipient->name)
                : $email,
            payload: [
                'order_number' => (string) $order->number,
                'customer_name' => $order->customer_name,
                'campaign' => $order->campaign,
                'event_label' => $this->eventLabel($eventType, $request->kind),
                'actor_id' => (int) $actor->id,
                'actor_name' => (string) $actor->name,
                'internal_url' => route('dispo-orders.show', $order, absolute: true),
                'occurred_at' => $occurredAt,
            ],
        );

        $this->outbox->enqueue($intent);
    }

    public function eventLabel(string $eventType, DispoOrderApprovalKind $kind): string
    {
        $kindPhrase = $kind === DispoOrderApprovalKind::Special
            ? 'Sonderfreigabe'
            : 'reguläre Freigabe';

        $eventPhrase = $eventType === self::EVENT_REJECTED
            ? 'Freigabe abgelehnt'
            : 'Freigabe erteilt';

        return $eventPhrase.' ('.$kindPhrase.')';
    }

    private function recordSuppressed(
        DispoOrder $order,
        User $actor,
        string $eventType,
        DispoOrderApprovalRequest $request,
        string $reason,
        ?int $intendedRecipientUserId,
    ): void {
        $this->audit->record(
            $order,
            self::AUDIT_NOTIFICATION_SUPPRESSED,
            $actor,
            null,
            [
                'event_type' => $eventType,
                'reason' => $reason,
                'approval_request_id' => $request->id,
                'intended_recipient_user_id' => $intendedRecipientUserId,
            ],
        );
    }

    private function isValidEmail(string $email): bool
    {
        if ($email === '') {
            return false;
        }

        return filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
    }
}
