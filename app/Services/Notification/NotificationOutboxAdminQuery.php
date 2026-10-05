<?php

namespace App\Services\Notification;

use App\Enums\NotificationOutboxChannel;
use App\Enums\NotificationOutboxStatus;
use App\Models\AuditEvent;
use App\Models\DispoOrder;
use App\Models\DispoOrderApprovalRequest;
use App\Models\DispoOrderComment;
use App\Models\NotificationOutbox;
use App\Models\User;
use App\Services\DispoOrder\DispoOrderApprovalNotificationPublisher;
use App\Services\DispoOrder\DispoOrderSalesInquiryService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * Lesende Admin-Abfragen für Outbox + Suppression (PO-NOT002-ADMIN-1 / BL-P9-02e).
 */
final class NotificationOutboxAdminQuery
{
    public const int PER_PAGE = 25;

    public const string DEFAULT_STATUS = 'failed';

    public const string EVENT_FILTER_ALL = 'all';

    public const string STATUS_FILTER_ALL = 'all';

    /** @var list<string> */
    public const array VISIBLE_EVENT_TYPES = [
        DispoOrderSalesInquiryService::EVENT_ASKED,
        DispoOrderSalesInquiryService::EVENT_ANSWERED,
        DispoOrderApprovalNotificationPublisher::EVENT_APPROVED,
        DispoOrderApprovalNotificationPublisher::EVENT_REJECTED,
    ];

    /** @var list<string> */
    public const array SUPPRESS_ACTIONS = [
        DispoOrderSalesInquiryService::AUDIT_NOTIFICATION_SUPPRESSED,
        DispoOrderApprovalNotificationPublisher::AUDIT_NOTIFICATION_SUPPRESSED,
    ];

    public function __construct(
        private readonly NotificationErrorDisplay $errors,
    ) {}

    /**
     * @return array{status: string, event_type: string}
     */
    public function normalizeOutboxFilters(?string $status, ?string $eventType): array
    {
        $status = $status === null || $status === '' ? self::DEFAULT_STATUS : trim($status);
        $eventType = $eventType === null || $eventType === '' ? self::EVENT_FILTER_ALL : trim($eventType);

        $allowedStatuses = array_merge(
            [self::STATUS_FILTER_ALL],
            array_map(fn (NotificationOutboxStatus $s): string => $s->value, NotificationOutboxStatus::cases()),
        );
        if (! in_array($status, $allowedStatuses, true)) {
            throw ValidationException::withMessages([
                'status' => 'Ungültiger Statusfilter.',
            ]);
        }

        $allowedEvents = array_merge([self::EVENT_FILTER_ALL], self::VISIBLE_EVENT_TYPES);
        if (! in_array($eventType, $allowedEvents, true)) {
            throw ValidationException::withMessages([
                'event_type' => 'Ungültiger Ereignisfilter.',
            ]);
        }

        return [
            'status' => $status,
            'event_type' => $eventType,
        ];
    }

    /**
     * @param  array{status: string, event_type: string}  $filters
     * @return LengthAwarePaginator<int, array<string, mixed>>
     */
    public function paginateOutbox(array $filters, int $page = 1): LengthAwarePaginator
    {
        $query = $this->visibleOutboxQuery();

        if ($filters['status'] !== self::STATUS_FILTER_ALL) {
            $query->where('status', $filters['status']);
        }
        if ($filters['event_type'] !== self::EVENT_FILTER_ALL) {
            $query->where('event_type', $filters['event_type']);
        }

        /** @var LengthAwarePaginator<int, NotificationOutbox> $paginator */
        $paginator = $query
            ->orderByDesc('id')
            ->paginate(self::PER_PAGE, ['*'], 'page', max(1, $page))
            ->withQueryString();

        $ordersBySource = $this->resolveOrdersForOutboxRows(collect($paginator->items()));

        return $paginator->through(
            fn (NotificationOutbox $row): array => $this->listRow($row, $ordersBySource),
        );
    }

    /**
     * @return array<string, mixed>|null
     */
    public function detail(int $outboxId): ?array
    {
        /** @var NotificationOutbox|null $row */
        $row = $this->visibleOutboxQuery()->whereKey($outboxId)->first();
        if ($row === null) {
            return null;
        }

        $ordersBySource = $this->resolveOrdersForOutboxRows(collect([$row]));

        return $this->detailRow($row, $ordersBySource);
    }

    /**
     * @return LengthAwarePaginator<int, array<string, mixed>>
     */
    public function paginateSuppressions(int $page = 1): LengthAwarePaginator
    {
        /** @var LengthAwarePaginator<int, AuditEvent> $paginator */
        $paginator = AuditEvent::query()
            ->whereIn('action', self::SUPPRESS_ACTIONS)
            ->orderByDesc('id')
            ->paginate(self::PER_PAGE, ['*'], 'page', max(1, $page))
            ->withQueryString();

        /** @var list<AuditEvent> $events */
        $events = $paginator->items();

        $orderIds = collect($events)
            ->filter(fn (AuditEvent $e): bool => $e->auditable_type === DispoOrder::class)
            ->pluck('auditable_id')
            ->map(fn ($id): int => (int) $id)
            ->unique()
            ->values()
            ->all();

        /** @var Collection<int, DispoOrder> $orders */
        $orders = DispoOrder::query()
            ->whereIn('id', $orderIds)
            ->get(['id', 'number'])
            ->keyBy('id');

        $userIds = collect($events)
            ->map(function (AuditEvent $e): ?int {
                $values = $e->new_values ?? [];
                $intended = $values['intended_recipient_user_id'] ?? null;

                return is_numeric($intended) ? (int) $intended : null;
            })
            ->filter(fn (?int $id): bool => $id !== null && $id > 0)
            ->map(fn (?int $id): int => (int) $id)
            ->unique()
            ->values()
            ->all();

        /** @var Collection<int, User> $users */
        $users = User::query()
            ->whereIn('id', $userIds)
            ->get(['id', 'name', 'email'])
            ->keyBy('id');

        return $paginator->through(
            fn (AuditEvent $event): array => $this->suppressRow($event, $orders, $users),
        );
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    public function statusFilterOptions(): array
    {
        $options = [
            ['value' => self::STATUS_FILTER_ALL, 'label' => 'Alle Status'],
        ];
        foreach (NotificationOutboxStatus::cases() as $status) {
            $options[] = [
                'value' => $status->value,
                'label' => $this->statusLabel($status),
            ];
        }

        return $options;
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    public function eventFilterOptions(): array
    {
        $options = [
            ['value' => self::EVENT_FILTER_ALL, 'label' => 'Alle Ereignisse'],
        ];
        foreach (self::VISIBLE_EVENT_TYPES as $eventType) {
            $options[] = [
                'value' => $eventType,
                'label' => $this->eventTypeLabel($eventType),
            ];
        }

        return $options;
    }

    /**
     * @return Builder<NotificationOutbox>
     */
    private function visibleOutboxQuery(): Builder
    {
        return NotificationOutbox::query()
            ->where('channel', NotificationOutboxChannel::Email->value)
            ->whereIn('event_type', self::VISIBLE_EVENT_TYPES);
    }

    /**
     * @param  Collection<int, NotificationOutbox>  $rows
     * @return array{comments: Collection<int, DispoOrder>, requests: Collection<int, DispoOrder>}
     */
    private function resolveOrdersForOutboxRows(Collection $rows): array
    {
        $commentIds = $rows
            ->where('source_type', DispoOrderSalesInquiryService::SOURCE_TYPE_COMMENT)
            ->pluck('source_id')
            ->map(fn ($id): int => (int) $id)
            ->unique()
            ->values()
            ->all();

        $requestIds = $rows
            ->where('source_type', DispoOrderApprovalNotificationPublisher::SOURCE_TYPE)
            ->pluck('source_id')
            ->map(fn ($id): int => (int) $id)
            ->unique()
            ->values()
            ->all();

        $commentOrderIds = DispoOrderComment::query()
            ->whereIn('id', $commentIds)
            ->pluck('dispo_order_id', 'id');

        $requestOrderIds = DispoOrderApprovalRequest::query()
            ->whereIn('id', $requestIds)
            ->pluck('dispo_order_id', 'id');

        $allOrderIds = $commentOrderIds->merge($requestOrderIds)
            ->map(fn ($id): int => (int) $id)
            ->unique()
            ->values()
            ->all();

        $orders = DispoOrder::query()
            ->whereIn('id', $allOrderIds)
            ->get(['id', 'number'])
            ->keyBy('id');

        /** @var Collection<int, DispoOrder> $comments */
        $comments = collect();
        foreach ($commentOrderIds as $commentId => $orderId) {
            $order = $orders->get((int) $orderId);
            if ($order !== null) {
                $comments->put((int) $commentId, $order);
            }
        }

        /** @var Collection<int, DispoOrder> $requests */
        $requests = collect();
        foreach ($requestOrderIds as $requestId => $orderId) {
            $order = $orders->get((int) $orderId);
            if ($order !== null) {
                $requests->put((int) $requestId, $order);
            }
        }

        return [
            'comments' => $comments,
            'requests' => $requests,
        ];
    }

    /**
     * @param  array{comments: Collection<int, DispoOrder>, requests: Collection<int, DispoOrder>}  $ordersBySource
     * @return array<string, mixed>
     */
    private function listRow(NotificationOutbox $row, array $ordersBySource): array
    {
        $order = $this->orderForRow($row, $ordersBySource);
        $payload = $row->payload_json;
        $error = $this->errors->forList($row->last_error);
        $status = $row->status;

        return [
            'id' => $row->id,
            'created_at' => $row->created_at?->toIso8601String(),
            'last_attempt_at' => $row->last_attempt_at?->toIso8601String(),
            'event_type' => $row->event_type,
            'event_label' => $this->eventLabelFromPayloadOrType($payload, $row->event_type),
            'status' => $status->value,
            'status_label' => $this->statusLabel($status),
            'attempt_count' => (int) $row->attempt_count,
            'recipient_name' => $row->recipient_name,
            'recipient_email' => $row->recipient_email,
            'order_number' => $order !== null
                ? $order->number
                : (is_string($payload['order_number'] ?? null) ? $payload['order_number'] : null),
            'order_id' => $order?->id,
            'order_url' => $order !== null
                ? route('dispo-orders.show', $order, absolute: false)
                : null,
            'error_text' => $error['text'],
            'error_truncated' => $error['truncated'],
        ];
    }

    /**
     * @param  array{comments: Collection<int, DispoOrder>, requests: Collection<int, DispoOrder>}  $ordersBySource
     * @return array<string, mixed>
     */
    private function detailRow(NotificationOutbox $row, array $ordersBySource): array
    {
        $list = $this->listRow($row, $ordersBySource);
        $payload = $row->payload_json;
        $error = $this->errors->forDetail($row->last_error);

        return array_merge($list, [
            'available_at' => $row->available_at->toIso8601String(),
            'sent_at' => $row->sent_at?->toIso8601String(),
            'updated_at' => $row->updated_at?->toIso8601String(),
            'recipient_user_id' => $row->recipient_user_id,
            'source_type' => $row->source_type,
            'source_id' => (int) $row->source_id,
            'channel' => NotificationOutboxChannel::Email->value,
            'actor_name' => is_string($payload['actor_name'] ?? null) ? $payload['actor_name'] : null,
            'actor_id' => is_int($payload['actor_id'] ?? null) ? $payload['actor_id'] : null,
            'customer_name' => is_string($payload['customer_name'] ?? null) ? $payload['customer_name'] : null,
            'campaign' => is_string($payload['campaign'] ?? null) ? $payload['campaign'] : null,
            'occurred_at' => is_string($payload['occurred_at'] ?? null) ? $payload['occurred_at'] : null,
            'error_text' => $error['text'],
            'error_truncated' => $error['truncated'],
        ]);
    }

    /**
     * @param  Collection<int, DispoOrder>  $orders
     * @param  Collection<int, User>  $users
     * @return array<string, mixed>
     */
    private function suppressRow(
        AuditEvent $event,
        Collection $orders,
        Collection $users,
    ): array {
        $values = $event->new_values ?? [];
        $eventType = is_string($values['event_type'] ?? null) ? $values['event_type'] : null;
        $reason = is_string($values['reason'] ?? null) ? $values['reason'] : null;
        $intendedRaw = $values['intended_recipient_user_id'] ?? null;
        $intendedId = is_numeric($intendedRaw) ? (int) $intendedRaw : null;

        $order = $event->auditable_type === DispoOrder::class
            ? $orders->get((int) $event->auditable_id)
            : null;

        $user = $intendedId !== null ? $users->get($intendedId) : null;

        return [
            'id' => $event->id,
            'created_at' => $event->created_at->toIso8601String(),
            'action' => $event->action,
            'event_type' => $eventType,
            'event_label' => $eventType !== null ? $this->eventTypeLabel($eventType) : 'Unbekanntes Ereignis',
            'reason' => $reason,
            'reason_label' => $this->suppressReasonLabel($reason),
            'order_id' => $order?->id,
            'order_number' => $order?->number,
            'order_url' => $order !== null
                ? route('dispo-orders.show', $order, absolute: false)
                : null,
            'intended_recipient_user_id' => $intendedId,
            'intended_recipient_name' => $user?->name,
            'intended_recipient_email' => $user?->email,
            'notice' => 'Es wurde keine E-Mail versendet. Dies ist kein SMTP-Fehler und wird nicht erneut versucht.',
        ];
    }

    /**
     * @param  array{comments: Collection<int, DispoOrder>, requests: Collection<int, DispoOrder>}  $ordersBySource
     */
    private function orderForRow(NotificationOutbox $row, array $ordersBySource): ?DispoOrder
    {
        if ($row->source_type === DispoOrderSalesInquiryService::SOURCE_TYPE_COMMENT) {
            return $ordersBySource['comments']->get((int) $row->source_id);
        }

        if ($row->source_type === DispoOrderApprovalNotificationPublisher::SOURCE_TYPE) {
            return $ordersBySource['requests']->get((int) $row->source_id);
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function eventLabelFromPayloadOrType(array $payload, string $eventType): string
    {
        if (is_string($payload['event_label'] ?? null) && trim($payload['event_label']) !== '') {
            return trim($payload['event_label']);
        }

        return $this->eventTypeLabel($eventType);
    }

    private function eventTypeLabel(string $eventType): string
    {
        return match ($eventType) {
            DispoOrderSalesInquiryService::EVENT_ASKED => 'Rückfrage Vertrieb',
            DispoOrderSalesInquiryService::EVENT_ANSWERED => 'Antwort auf Rückfrage',
            DispoOrderApprovalNotificationPublisher::EVENT_APPROVED => 'Freigabe erteilt',
            DispoOrderApprovalNotificationPublisher::EVENT_REJECTED => 'Freigabe abgelehnt',
            default => $eventType,
        };
    }

    private function statusLabel(NotificationOutboxStatus $status): string
    {
        return match ($status) {
            NotificationOutboxStatus::Pending => 'Ausstehend',
            NotificationOutboxStatus::Queued => 'In Warteschlange',
            NotificationOutboxStatus::Sending => 'Wird gesendet',
            NotificationOutboxStatus::Sent => 'Gesendet',
            NotificationOutboxStatus::Failed => 'Fehlgeschlagen',
        };
    }

    private function suppressReasonLabel(?string $reason): string
    {
        return match ($reason) {
            'missing_advisor' => 'Mediaberater fehlt',
            'missing_ask_author' => 'Autor der Rückfrage fehlt',
            'missing_submitter' => 'Einreicher fehlt',
            'self_notification' => 'Selbstbenachrichtigung unterdrückt',
            'recipient_not_loadable' => 'Empfänger nicht ladbar',
            'invalid_email' => 'Ungültige E-Mail-Adresse',
            null, '' => 'Unbekannter Grund',
            default => $reason,
        };
    }
}
