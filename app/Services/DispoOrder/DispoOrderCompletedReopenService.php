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
 * Completed-Reopen: completed → in_progress (BL-P8-02e / PO-BLP802E-1 / STA-004).
 * Nur Admin/Management; keine Freigabeinvalidierung.
 */
final class DispoOrderCompletedReopenService
{
    public const int REASON_MAX = 2000;

    public function __construct(
        private readonly AuditLogger $audit,
    ) {}

    public function reopen(
        DispoOrder $order,
        User $user,
        int $expectedLockVersion,
        string $reason,
    ): DispoOrder {
        if (! Gate::forUser($user)->allows('reopenCompleted', $order)) {
            abort(403);
        }

        $trimmedReason = trim($reason);
        if ($trimmedReason === '' || preg_match('/^\s+$/u', $reason) === 1) {
            throw ValidationException::withMessages([
                'reason' => 'Eine Begründung ist für die Wiederöffnung erforderlich.',
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

            if (! Gate::forUser($user)->allows('reopenCompleted', $locked)) {
                abort(403);
            }

            $from = $locked->status;
            $to = DispoOrderStatus::InProgress;

            if ($from !== DispoOrderStatus::Completed) {
                throw ValidationException::withMessages([
                    'order' => sprintf(
                        'Wiederöffnung nach Abschluss ist nur aus dem Status „%s“ möglich (aktuell: „%s“).',
                        DispoOrderStatus::Completed->label(),
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
            $event->is_reopen = true;
            $event->lock_version_after = $locked->lock_version;
            $event->save();

            $fresh = $this->reload($locked);

            $this->audit->record(
                $fresh,
                'dispo_order.status_reopened',
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
                    'is_reopen' => true,
                    'status_event_id' => $event->id,
                    'changed_at' => $event->changed_at->toIso8601String(),
                    'changed_by_id' => $user->id,
                    'changed_by_name' => $user->name,
                ],
            );

            return $fresh;
        });
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
