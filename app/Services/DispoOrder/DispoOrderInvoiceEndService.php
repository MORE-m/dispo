<?php

namespace App\Services\DispoOrder;

use App\Exceptions\DispoOrderConflictException;
use App\Models\DispoOrder;
use App\Models\DispoOrderPosition;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Support\DispoOrder\InvoiceEndMonthsContract;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Pflege „Rechnung per Ende“ je Dispo-Werbemittelposition (BL-P8-02d / PO-BLP802D-1).
 */
final class DispoOrderInvoiceEndService
{
    public function __construct(
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @param  list<mixed>  $months
     */
    public function update(
        DispoOrder $order,
        DispoOrderPosition $position,
        User $user,
        int $expectedLockVersion,
        array $months,
    ): DispoOrder {
        if (! Gate::forUser($user)->allows('updateInvoiceEndMonths', $order)) {
            abort(403);
        }

        return DB::transaction(function () use ($order, $position, $user, $expectedLockVersion, $months): DispoOrder {
            $locked = $this->lockOrder($order);
            $this->assertLockVersion($locked, $expectedLockVersion);

            if (! Gate::forUser($user)->allows('updateInvoiceEndMonths', $locked)) {
                abort(403);
            }

            if (! DispoOrderStatusTransition::isInvoiceEndEditable($locked->status)) {
                throw ValidationException::withMessages([
                    'order' => sprintf(
                        'Rechnung per Ende kann im Status „%s“ nicht bearbeitet werden.',
                        $locked->status->label(),
                    ),
                ]);
            }

            $lockedPosition = DispoOrderPosition::query()
                ->whereKey($position->id)
                ->where('dispo_order_id', $locked->id)
                ->lockForUpdate()
                ->first();

            if ($lockedPosition === null) {
                throw ValidationException::withMessages([
                    'position' => 'Die Position gehört nicht zu diesem Dispoauftrag.',
                ]);
            }

            $canonical = InvoiceEndMonthsContract::canonicalize($months);
            $before = InvoiceEndMonthsContract::canonicalize(
                is_array($lockedPosition->invoice_end_months) ? $lockedPosition->invoice_end_months : [],
            );

            $lockedPosition->invoice_end_months = $canonical;
            $lockedPosition->save();

            $locked->lock_version = $locked->lock_version + 1;
            $locked->save();

            $fresh = $this->reload($locked);

            $this->audit->record(
                $fresh,
                'dispo_order.invoice_end_months_updated',
                $user,
                [
                    'position_id' => $lockedPosition->id,
                    'invoice_end_months' => $before,
                    'lock_version' => $expectedLockVersion,
                ],
                [
                    'position_id' => $lockedPosition->id,
                    'invoice_end_months' => $canonical,
                    'lock_version' => $fresh->lock_version,
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
