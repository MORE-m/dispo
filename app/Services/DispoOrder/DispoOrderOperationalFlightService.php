<?php

namespace App\Services\DispoOrder;

use App\Exceptions\DispoOrderConflictException;
use App\Models\DispoOrder;
use App\Models\DispoOrderPosition;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Support\DispoOrder\OperationalFlightContract;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Pflege „Verbindlicher Kampagnenzeitraum (operativ)“ je OA-TKP-Dispo-Position (PO-CALC-TKP-1D3d-2b-1).
 */
final class DispoOrderOperationalFlightService
{
    public function __construct(
        private readonly AuditLogger $audit,
    ) {}

    public function update(
        DispoOrder $order,
        DispoOrderPosition $position,
        User $user,
        int $expectedLockVersion,
        mixed $startRaw,
        mixed $endRaw,
    ): DispoOrder {
        if (! Gate::forUser($user)->allows('updateOperationalFlight', $order)) {
            abort(403);
        }

        return DB::transaction(function () use ($order, $position, $user, $expectedLockVersion, $startRaw, $endRaw): DispoOrder {
            $locked = $this->lockOrder($order);
            $this->assertLockVersion($locked, $expectedLockVersion);

            if (! Gate::forUser($user)->allows('updateOperationalFlight', $locked)) {
                abort(403);
            }

            if (! DispoOrderStatusTransition::isOperationalFlightEditable($locked->status)) {
                throw ValidationException::withMessages([
                    'order' => sprintf(
                        '%s kann im Status „%s“ nicht bearbeitet werden.',
                        OperationalFlightContract::LABEL,
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

            if ($lockedPosition->isProductionLine()) {
                throw ValidationException::withMessages([
                    'position' => 'Operativer Flight ist nur für Medienpositionen vorgesehen.',
                ]);
            }

            if (! OperationalFlightContract::isOaTkpMediaPosition($lockedPosition)) {
                throw ValidationException::withMessages([
                    'position' => OperationalFlightContract::LABEL.' ist nur für Online-Audio-TKP-Positionen zulässig.',
                ]);
            }

            $canonical = OperationalFlightContract::canonicalizeForPersist($startRaw, $endRaw);
            $beforeStart = OperationalFlightContract::dateToIso($lockedPosition->operational_flight_start);
            $beforeEnd = OperationalFlightContract::dateToIso($lockedPosition->operational_flight_end);

            $lockedPosition->operational_flight_start = $canonical['start'];
            $lockedPosition->operational_flight_end = $canonical['end'];
            $lockedPosition->save();

            $locked->lock_version = $locked->lock_version + 1;
            $locked->save();

            $fresh = $this->reload($locked);

            $this->audit->record(
                $fresh,
                'dispo_order.operational_flight_updated',
                $user,
                [
                    'position_id' => $lockedPosition->id,
                    'operational_flight_start' => $beforeStart,
                    'operational_flight_end' => $beforeEnd,
                    'lock_version' => $expectedLockVersion,
                ],
                [
                    'position_id' => $lockedPosition->id,
                    'operational_flight_start' => $canonical['start'],
                    'operational_flight_end' => $canonical['end'],
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
