<?php

namespace App\Services\DispoOrder;

use App\Enums\DispoOrderStatus;
use App\Exceptions\DispoOrderConflictException;
use App\Models\DispoOrder;
use App\Models\DispoOrderStatusEvent;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class DispoOrderOperationalStatusService
{
    public function __construct(
        private readonly AuditLogger $audit,
    ) {}

    public function transition(
        DispoOrder $order,
        User $user,
        int $expectedLockVersion,
        DispoOrderStatus $target,
        ?string $reason = null,
    ): DispoOrder {
        return DB::transaction(function () use ($order, $user, $expectedLockVersion, $target, $reason): DispoOrder {
            $locked = $this->lockOrder($order);
            $this->assertLockVersion($locked, $expectedLockVersion);

            $from = $locked->status;

            if ($from === $target) {
                throw new DispoOrderConflictException(
                    sprintf(
                        'Der Status „%s“ ist bereits gesetzt.',
                        $from->label(),
                    ),
                );
            }

            if (! DispoOrderStatusTransition::isOperationalTransition($from, $target)) {
                DispoOrderStatusTransition::assertCanTransition($from, $target);
                throw new DispoOrderConflictException(
                    sprintf(
                        'Der Statusübergang von „%s“ nach „%s“ ist kein operativer Übergang.',
                        $from->label(),
                        $target->label(),
                    ),
                );
            }

            DispoOrderStatusTransition::assertCanTransition($from, $target);

            $trimmedReason = $reason === null ? null : trim($reason);
            if ($trimmedReason === '') {
                $trimmedReason = null;
            }

            $isReopen = DispoOrderStatusTransition::isReopen($from, $target);
            if ($isReopen && $trimmedReason === null) {
                throw ValidationException::withMessages([
                    'reason' => 'Eine Begründung ist für die Wiederöffnung erforderlich.',
                ]);
            }

            $locked->status = $target;
            $locked->lock_version = $locked->lock_version + 1;
            $locked->save();

            $event = new DispoOrderStatusEvent;
            $event->dispo_order_id = $locked->id;
            $event->from_status = $from;
            $event->to_status = $target;
            $event->changed_by_id = $user->id;
            $event->changed_by_name = $user->name;
            $event->changed_at = now();
            $event->reason = $trimmedReason;
            $event->is_reopen = $isReopen;
            $event->lock_version_after = $locked->lock_version;
            $event->save();

            $fresh = $this->reload($locked);

            $this->audit->record(
                $fresh,
                $isReopen ? 'dispo_order.status_reopened' : 'dispo_order.status_changed',
                $user,
                [
                    'status' => $from->value,
                    'lock_version' => $expectedLockVersion,
                ],
                [
                    'status' => $fresh->status->value,
                    'lock_version' => $fresh->lock_version,
                    'from_status' => $from->value,
                    'to_status' => $target->value,
                    'reason' => $trimmedReason,
                    'is_reopen' => $isReopen,
                    'status_event_id' => $event->id,
                    'changed_at' => $event->changed_at->toIso8601String(),
                ],
            );

            return $fresh;
        });
    }

    /**
     * @return list<array{value: string, label: string, requires_reason: bool}>
     */
    public function allowedTargetsProp(DispoOrder $order): array
    {
        return array_map(
            fn (DispoOrderStatus $status): array => [
                'value' => $status->value,
                'label' => $status->label(),
                'requires_reason' => DispoOrderStatusTransition::requiresReason($order->status, $status),
            ],
            DispoOrderStatusTransition::allowedOperationalTargets($order->status),
        );
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
