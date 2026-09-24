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
use App\Services\DynamicField\DispoOrderDynamicFieldWriter;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class DispoOrderApprovalService
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly DispoOrderDynamicFieldWriter $dynamicFields,
        private readonly DispoOrderCustomerConfirmationService $customerConfirmation,
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

                $this->dynamicFields->assertReadyForSubmit($locked);
                $this->customerConfirmation->assertReadyForSubmit($locked);

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
                $request->customer_confirmation_without_upload = (bool) $locked->customer_confirmation_without_upload;
                $request->customer_confirmation_exception_reason = $locked->customer_confirmation_exception_reason;
                $request->customer_confirmation_exception_set_by_id = $locked->customer_confirmation_exception_set_by_id;
                $request->customer_confirmation_exception_set_by_name = $locked->customer_confirmation_exception_set_by_name;
                $request->customer_confirmation_exception_set_at = $locked->customer_confirmation_exception_set_at
                    ? CarbonImmutable::instance($locked->customer_confirmation_exception_set_at)
                    : null;
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
                        'customer_confirmation_without_upload' => (bool) $request->customer_confirmation_without_upload,
                        'customer_confirmation_exception_reason' => $request->customer_confirmation_exception_reason,
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

    public function approve(
        DispoOrder $order,
        User $user,
        int $expectedLockVersion,
        ?string $note = null,
        bool $customerConfirmationExceptionAcknowledged = false,
    ): DispoOrder {
        return DB::transaction(function () use (
            $order,
            $user,
            $expectedLockVersion,
            $note,
            $customerConfirmationExceptionAcknowledged,
        ): DispoOrder {
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

            if ($request->hasCustomerConfirmationExceptionSnapshot()) {
                if (! $customerConfirmationExceptionAcknowledged) {
                    throw ValidationException::withMessages([
                        'customer_confirmation_exception_acknowledged' => 'Die Ausnahme ohne Kundenbestätigungs-Upload muss ausdrücklich mitfreigegeben werden.',
                    ]);
                }

                $request->customer_confirmation_exception_acknowledged = true;
                $request->customer_confirmation_exception_acknowledged_by_id = $user->id;
                $request->customer_confirmation_exception_acknowledged_by_name = $user->name;
                $request->customer_confirmation_exception_acknowledged_at = CarbonImmutable::now();
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

            $newValues = [
                'status' => $fresh->status->value,
                'lock_version' => $fresh->lock_version,
                'approval_kind' => $request->kind->value,
                'approval_request_id' => $request->id,
                'decision_note' => $trimmedNote,
                'decided_at' => $request->decided_at->toIso8601String(),
            ];

            if ($request->customer_confirmation_exception_acknowledged) {
                $newValues['customer_confirmation_exception_acknowledged'] = true;
                $newValues['customer_confirmation_exception_acknowledged_by_id'] = $request->customer_confirmation_exception_acknowledged_by_id;
                $newValues['customer_confirmation_exception_acknowledged_by_name'] = $request->customer_confirmation_exception_acknowledged_by_name;
                $newValues['customer_confirmation_exception_acknowledged_at'] = $request->customer_confirmation_exception_acknowledged_at?->toIso8601String();
                $newValues['customer_confirmation_exception_reason'] = $request->customer_confirmation_exception_reason;
            }

            $this->audit->record(
                $fresh,
                'dispo_order.approved',
                $user,
                [
                    'status' => $previousStatus->value,
                    'lock_version' => $expectedLockVersion,
                    'approval_request_id' => $request->id,
                ],
                $newValues,
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
