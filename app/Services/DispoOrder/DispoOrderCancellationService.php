<?php

namespace App\Services\DispoOrder;

use App\Enums\DispoOrderStatus;
use App\Exceptions\DispoOrderConflictException;
use App\Models\DispoOrder;
use App\Models\DispoOrderStatusEvent;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Fachlicher Storno → cancelled (BL-P8-02e / PO-BLP802E-1 / STA-005 / AT-19).
 */
final class DispoOrderCancellationService
{
    public const int REASON_MAX = 2000;

    public function __construct(
        private readonly AuditLogger $audit,
    ) {}

    public function cancel(
        DispoOrder $order,
        User $user,
        int $expectedLockVersion,
        string $reason,
    ): DispoOrder {
        if (! Gate::forUser($user)->allows('cancel', $order)) {
            abort(403);
        }

        $trimmedReason = trim($reason);
        if ($trimmedReason === '' || preg_match('/^\s+$/u', $reason) === 1) {
            throw ValidationException::withMessages([
                'reason' => 'Eine Begründung ist für die Stornierung erforderlich.',
            ]);
        }

        if (mb_strlen($trimmedReason) > self::REASON_MAX) {
            throw ValidationException::withMessages([
                'reason' => 'Die Begründung darf maximal 2000 Zeichen haben.',
            ]);
        }

        return DB::transaction(function () use ($order, $user, $expectedLockVersion, $trimmedReason): DispoOrder {
            $locked = $this->lockOrder($order);
            $this->assertLockVersion($locked, $expectedLockVersion);

            if (! Gate::forUser($user)->allows('cancel', $locked)) {
                abort(403);
            }

            $from = $locked->status;
            $to = DispoOrderStatus::Cancelled;

            if ($from === $to) {
                throw new DispoOrderConflictException(
                    sprintf('Der Status „%s“ ist bereits gesetzt.', $from->label()),
                );
            }

            if (! DispoOrderStatusTransition::isCancellationSource($from)) {
                throw ValidationException::withMessages([
                    'order' => sprintf(
                        'Stornierung ist aus dem Status „%s“ nicht möglich.',
                        $from->label(),
                    ),
                ]);
            }

            DispoOrderStatusTransition::assertCanTransition($from, $to);

            $locked->status = $to;
            $locked->lock_version = $locked->lock_version + 1;
            $locked->save();

            $event = new DispoOrderStatusEvent;
            $event->dispo_order_id = $locked->id;
            $event->from_status = $from;
            $event->to_status = $to;
            $event->changed_by_id = $user->id;
            $event->changed_by_name = $user->name;
            $event->changed_at = now();
            $event->reason = $trimmedReason;
            $event->is_reopen = false;
            $event->lock_version_after = $locked->lock_version;
            $event->save();

            $fresh = $this->reload($locked);

            $this->audit->record(
                $fresh,
                'dispo_order.cancelled',
                $user,
                [
                    'status' => $from->value,
                    'lock_version' => $expectedLockVersion,
                ],
                [
                    'status' => $fresh->status->value,
                    'lock_version' => $fresh->lock_version,
                    'from_status' => $from->value,
                    'to_status' => $to->value,
                    'reason' => $trimmedReason,
                    'status_event_id' => $event->id,
                    'changed_at' => $event->changed_at->toIso8601String(),
                    'changed_by_id' => $user->id,
                    'changed_by_name' => $user->name,
                ],
            );

            return $fresh;
        });
    }

    /**
     * @return array{
     *     cancelled_by_name: string,
     *     cancelled_at: string,
     *     reason: string|null,
     *     from_status: string,
     *     from_status_label: string,
     *     available: bool,
     *     unavailable_message: string|null
     * }|null
     */
    public function cancellationSummaryProp(DispoOrder $order): ?array
    {
        if ($order->status !== DispoOrderStatus::Cancelled) {
            return null;
        }

        $event = ($order->relationLoaded('statusEvents')
            ? $order->statusEvents
            : $order->statusEvents()->get()
        )->sortByDesc('id')
            ->first(
                fn (DispoOrderStatusEvent $item): bool => $item->to_status === DispoOrderStatus::Cancelled,
            );

        if ($event === null) {
            $event = DispoOrderStatusEvent::query()
                ->where('dispo_order_id', $order->id)
                ->where('to_status', DispoOrderStatus::Cancelled->value)
                ->orderByDesc('id')
                ->first();
        }

        if ($event === null) {
            return [
                'cancelled_by_name' => '',
                'cancelled_at' => '',
                'reason' => null,
                'from_status' => '',
                'from_status_label' => '',
                'available' => false,
                'unavailable_message' => 'Historische Stornodaten nicht verfügbar.',
            ];
        }

        return [
            'cancelled_by_name' => $event->changed_by_name,
            'cancelled_at' => $event->changed_at->toIso8601String(),
            'reason' => $event->reason,
            'from_status' => $event->from_status->value,
            'from_status_label' => $event->from_status->label(),
            'available' => true,
            'unavailable_message' => null,
        ];
    }

    private function lockOrder(DispoOrder $order): DispoOrder
    {
        return DispoOrder::query()
            ->whereKey($order->id)
            ->lockForUpdate()
            ->firstOrFail();
    }

    private function assertLockVersion(DispoOrder $order, int $expectedLockVersion): void
    {
        if ($order->lock_version !== $expectedLockVersion) {
            throw new DispoOrderConflictException(
                'Der Dispoauftrag wurde parallel geändert. Bitte die Seite neu laden.',
            );
        }
    }

    private function reload(DispoOrder $order): DispoOrder
    {
        $order->refresh();
        $order->load([
            'positions',
            'creator',
            'approvalRequests',
            'pendingApprovalRequest',
            'latestApprovalRequest',
            'statusEvents',
        ]);

        return $order;
    }
}
