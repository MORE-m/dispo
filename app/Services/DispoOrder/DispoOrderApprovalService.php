<?php

namespace App\Services\DispoOrder;

use App\Enums\DispoOrderApprovalKind;
use App\Enums\DispoOrderApprovalStatus;
use App\Enums\DispoOrderStatus;
use App\Exceptions\DispoOrderConflictException;
use App\Models\DispoOrder;
use App\Models\DispoOrderApprovalRequest;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class DispoOrderApprovalService
{
    public function __construct(
        private readonly AuditLogger $audit,
    ) {}

    public function submit(DispoOrder $order, User $user, int $expectedLockVersion): DispoOrder
    {
        try {
            return DB::transaction(function () use ($order, $user, $expectedLockVersion): DispoOrder {
                $locked = $this->lockOrder($order);
                $this->assertLockVersion($locked, $expectedLockVersion);
                DispoOrderStatusTransition::assertCanTransition(
                    $locked->status,
                    DispoOrderStatus::AwaitingSalesApproval,
                );

                if ($locked->positions()->count() < 1) {
                    throw ValidationException::withMessages([
                        'order' => 'Der Dispoauftrag enthält keine Positionen und kann nicht eingereicht werden.',
                    ]);
                }

                if ($locked->pendingApprovalRequest()->exists()) {
                    throw new DispoOrderConflictException(
                        'Für diesen Dispoauftrag liegt bereits eine offene Freigabeanforderung vor.',
                    );
                }

                $kind = $locked->requiresSpecialApproval()
                    ? DispoOrderApprovalKind::Special
                    : DispoOrderApprovalKind::Regular;
                $reasons = $locked->special_approval_reasons ?? [];
                $previousStatus = $locked->status;
                $cycle = ((int) $locked->approvalRequests()->max('cycle_number')) + 1;

                $request = new DispoOrderApprovalRequest;
                $request->dispo_order_id = $locked->id;
                $request->cycle_number = max(1, $cycle);
                $request->status = DispoOrderApprovalStatus::Pending;
                $request->kind = $kind;
                $request->special_approval_reasons = $reasons;
                $request->submitted_by_id = $user->id;
                $request->submitted_by_name = $user->name;
                $request->submitted_at = now();
                $request->submitted_lock_version = $locked->lock_version;
                $request->open_guard = 1;
                $request->save();

                $locked->status = DispoOrderStatus::AwaitingSalesApproval;
                $locked->approval_kind = $kind;
                $locked->requires_special_approval = $kind === DispoOrderApprovalKind::Special;
                $locked->lock_version = $locked->lock_version + 1;
                $locked->save();

                $fresh = $this->reload($locked);

                $this->audit->record(
                    $fresh,
                    'dispo_order.submitted_for_approval',
                    $user,
                    [
                        'status' => $previousStatus->value,
                        'lock_version' => $expectedLockVersion,
                    ],
                    [
                        'status' => $fresh->status->value,
                        'lock_version' => $fresh->lock_version,
                        'approval_kind' => $kind->value,
                        'special_approval_reasons' => $reasons,
                        'approval_request_id' => $request->id,
                        'cycle_number' => $request->cycle_number,
                        'submitted_at' => $request->submitted_at->toIso8601String(),
                    ],
                );

                return $fresh;
            });
        } catch (UniqueConstraintViolationException) {
            throw new DispoOrderConflictException(
                'Für diesen Dispoauftrag liegt bereits eine offene Freigabeanforderung vor.',
            );
        }
    }

    public function approve(DispoOrder $order, User $user, int $expectedLockVersion, ?string $note = null): DispoOrder
    {
        return DB::transaction(function () use ($order, $user, $expectedLockVersion, $note): DispoOrder {
            $locked = $this->lockOrder($order);
            $this->assertLockVersion($locked, $expectedLockVersion);
            DispoOrderStatusTransition::assertCanTransition(
                $locked->status,
                DispoOrderStatus::AtDisposition,
            );

            $request = $this->pendingRequestOrFail($locked);
            $previousStatus = $locked->status;
            $trimmedNote = $note === null ? null : trim($note);
            if ($trimmedNote === '') {
                $trimmedNote = null;
            }

            $request->status = DispoOrderApprovalStatus::Approved;
            $request->decided_by_id = $user->id;
            $request->decided_by_name = $user->name;
            $request->decided_at = now();
            $request->decision_note = $trimmedNote;
            $request->open_guard = null;
            $request->save();

            $locked->status = DispoOrderStatus::AtDisposition;
            $locked->lock_version = $locked->lock_version + 1;
            $locked->save();

            $fresh = $this->reload($locked);

            $this->audit->record(
                $fresh,
                'dispo_order.approved',
                $user,
                [
                    'status' => $previousStatus->value,
                    'lock_version' => $expectedLockVersion,
                    'approval_request_id' => $request->id,
                ],
                [
                    'status' => $fresh->status->value,
                    'lock_version' => $fresh->lock_version,
                    'approval_kind' => $request->kind->value,
                    'approval_request_id' => $request->id,
                    'decision_note' => $trimmedNote,
                    'decided_at' => $request->decided_at->toIso8601String(),
                ],
            );

            return $fresh;
        });
    }

    public function reject(DispoOrder $order, User $user, int $expectedLockVersion, string $reason): DispoOrder
    {
        return DB::transaction(function () use ($order, $user, $expectedLockVersion, $reason): DispoOrder {
            $locked = $this->lockOrder($order);
            $this->assertLockVersion($locked, $expectedLockVersion);
            DispoOrderStatusTransition::assertCanTransition(
                $locked->status,
                DispoOrderStatus::ApprovalRejected,
            );

            $request = $this->pendingRequestOrFail($locked);
            $previousStatus = $locked->status;
            $trimmedReason = trim($reason);

            $request->status = DispoOrderApprovalStatus::Rejected;
            $request->decided_by_id = $user->id;
            $request->decided_by_name = $user->name;
            $request->decided_at = now();
            $request->rejection_reason = $trimmedReason;
            $request->open_guard = null;
            $request->save();

            $locked->status = DispoOrderStatus::ApprovalRejected;
            $locked->lock_version = $locked->lock_version + 1;
            $locked->save();

            $fresh = $this->reload($locked);

            $this->audit->record(
                $fresh,
                'dispo_order.rejected',
                $user,
                [
                    'status' => $previousStatus->value,
                    'lock_version' => $expectedLockVersion,
                    'approval_request_id' => $request->id,
                ],
                [
                    'status' => $fresh->status->value,
                    'lock_version' => $fresh->lock_version,
                    'approval_kind' => $request->kind->value,
                    'approval_request_id' => $request->id,
                    'rejection_reason' => $trimmedReason,
                    'decided_at' => $request->decided_at->toIso8601String(),
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

    private function pendingRequestOrFail(DispoOrder $order): DispoOrderApprovalRequest
    {
        $request = DispoOrderApprovalRequest::query()
            ->where('dispo_order_id', $order->id)
            ->where('status', DispoOrderApprovalStatus::Pending->value)
            ->where('open_guard', 1)
            ->lockForUpdate()
            ->first();

        if ($request === null) {
            throw new DispoOrderConflictException(
                'Es liegt keine offene Freigabeanforderung vor.',
            );
        }

        return $request;
    }

    private function reload(DispoOrder $order): DispoOrder
    {
        $order->refresh();
        $order->load(['positions', 'creator', 'approvalRequests', 'pendingApprovalRequest', 'latestApprovalRequest']);

        return $order;
    }
}
