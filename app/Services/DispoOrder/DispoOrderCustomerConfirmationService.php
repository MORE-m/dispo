<?php

namespace App\Services\DispoOrder;

use App\Enums\DispoOrderStatus;
use App\Exceptions\DispoOrderConflictException;
use App\Models\DispoOrder;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Draft-Ausnahmeweg Kundenbestätigung ohne Upload (BL-P8-02c / PO-BLP802C-1).
 * Getrennt vom Freigabe-Service.
 */
final class DispoOrderCustomerConfirmationService
{
    public const EXCEPTION_REASON_MAX = 2000;

    public function __construct(
        private readonly AuditLogger $audit,
    ) {}

    public function update(
        DispoOrder $order,
        User $user,
        int $expectedLockVersion,
        bool $withoutUpload,
        ?string $exceptionReason,
    ): DispoOrder {
        if (! Gate::forUser($user)->allows('updateCustomerConfirmation', $order)) {
            abort(403);
        }

        $trimmedReason = $exceptionReason === null ? null : trim($exceptionReason);

        if ($withoutUpload) {
            if ($trimmedReason === null || $trimmedReason === '') {
                throw ValidationException::withMessages([
                    'exception_reason' => 'Ein Ausnahmegrund ist erforderlich, wenn die Bestätigung ohne Upload bestätigt wird.',
                ]);
            }

            if (mb_strlen($trimmedReason) > self::EXCEPTION_REASON_MAX) {
                throw ValidationException::withMessages([
                    'exception_reason' => 'Der Ausnahmegrund darf maximal 2000 Zeichen haben.',
                ]);
            }
        }

        return DB::transaction(function () use ($order, $user, $expectedLockVersion, $withoutUpload, $trimmedReason): DispoOrder {
            $locked = $this->lockOrder($order);
            $this->assertLockVersion($locked, $expectedLockVersion);

            if ($locked->status !== DispoOrderStatus::Draft) {
                throw ValidationException::withMessages([
                    'order' => 'Die Kundenbestätigungs-Ausnahme kann nur im Entwurf bearbeitet werden.',
                ]);
            }

            if (! Gate::forUser($user)->allows('updateCustomerConfirmation', $locked)) {
                abort(403);
            }

            $previousActive = $this->isExceptionActive($locked);

            if ($withoutUpload) {
                $locked->customer_confirmation_without_upload = true;
                $locked->customer_confirmation_exception_reason = $trimmedReason;
                $locked->customer_confirmation_exception_set_by_id = $user->id;
                $locked->customer_confirmation_exception_set_by_name = $user->name;
                $locked->customer_confirmation_exception_set_at = Carbon::now();
            } else {
                $locked->customer_confirmation_without_upload = false;
                $locked->customer_confirmation_exception_reason = null;
                $locked->customer_confirmation_exception_set_by_id = null;
                $locked->customer_confirmation_exception_set_by_name = null;
                $locked->customer_confirmation_exception_set_at = null;
            }

            $locked->lock_version = $locked->lock_version + 1;
            $locked->save();

            $fresh = $this->reload($locked);
            $nowActive = $this->isExceptionActive($fresh);

            if ($nowActive && ! $previousActive) {
                $this->audit->record(
                    $fresh,
                    'dispo_order.customer_confirmation_exception_set',
                    $user,
                    [
                        'customer_confirmation_without_upload' => false,
                        'lock_version' => $expectedLockVersion,
                    ],
                    [
                        'customer_confirmation_without_upload' => true,
                        'customer_confirmation_exception_reason' => $fresh->customer_confirmation_exception_reason,
                        'customer_confirmation_exception_set_by_id' => $fresh->customer_confirmation_exception_set_by_id,
                        'customer_confirmation_exception_set_by_name' => $fresh->customer_confirmation_exception_set_by_name,
                        'customer_confirmation_exception_set_at' => $fresh->customer_confirmation_exception_set_at?->toIso8601String(),
                        'lock_version' => $fresh->lock_version,
                    ],
                );
            } elseif (! $nowActive && $previousActive) {
                $this->audit->record(
                    $fresh,
                    'dispo_order.customer_confirmation_exception_cleared',
                    $user,
                    [
                        'customer_confirmation_without_upload' => true,
                        'lock_version' => $expectedLockVersion,
                    ],
                    [
                        'customer_confirmation_without_upload' => false,
                        'customer_confirmation_exception_reason' => null,
                        'lock_version' => $fresh->lock_version,
                    ],
                );
            } elseif ($nowActive && $previousActive) {
                $this->audit->record(
                    $fresh,
                    'dispo_order.customer_confirmation_exception_set',
                    $user,
                    [
                        'customer_confirmation_without_upload' => true,
                        'lock_version' => $expectedLockVersion,
                    ],
                    [
                        'customer_confirmation_without_upload' => true,
                        'customer_confirmation_exception_reason' => $fresh->customer_confirmation_exception_reason,
                        'customer_confirmation_exception_set_by_id' => $fresh->customer_confirmation_exception_set_by_id,
                        'customer_confirmation_exception_set_by_name' => $fresh->customer_confirmation_exception_set_by_name,
                        'customer_confirmation_exception_set_at' => $fresh->customer_confirmation_exception_set_at?->toIso8601String(),
                        'lock_version' => $fresh->lock_version,
                    ],
                );
            }

            return $fresh;
        });
    }

    public function isExceptionActive(DispoOrder $order): bool
    {
        if (! (bool) $order->customer_confirmation_without_upload) {
            return false;
        }

        $reason = $order->customer_confirmation_exception_reason;

        return is_string($reason) && trim($reason) !== '';
    }

    /**
     * Submit-Gate UPL-001 Variante B (Upload folgt später als OR).
     */
    public function assertReadyForSubmit(DispoOrder $order): void
    {
        if ($this->isExceptionActive($order)) {
            return;
        }

        throw ValidationException::withMessages([
            'customer_confirmation' => 'Vor der Einreichung muss eine Kundenbestätigung vorliegen. '
                .'Wenn aktuell kein Upload hinterlegt ist, bestätige dies mit einem Ausnahmegrund.',
        ]);
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
        ]);

        return $order;
    }
}
