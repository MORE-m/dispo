<?php

namespace App\Services\DispoOrder;

use App\Enums\DispoOrderApprovalStatus;
use App\Enums\DispoOrderStatus;
use App\Enums\DispoOrderUploadCategory;
use App\Models\DispoOrder;
use App\Models\DispoOrderApprovalRequest;
use App\Models\DispoOrderStatusEvent;
use App\Models\DispoOrderUpload;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use Illuminate\Validation\ValidationException;

/**
 * Freigabeinvalidierung nach CC-Archiv (BL-P7-02a / PO-AT13-CC-1).
 *
 * Muss innerhalb der umschließenden Archiv-Transaktion auf bereits
 * gesperrtem Auftrag aufgerufen werden. Mutiert den genehmigten
 * Approval-Request nicht.
 */
final class DispoOrderApprovalInvalidationService
{
    public const string AUDIT_ACTION = 'dispo_order.approval.invalidated';

    public const string STATUS_REASON = 'Kundenbestätigung archiviert – Freigabe ungültig; erneuter Freigabezyklus erforderlich.';

    public function __construct(
        private readonly AuditLogger $audit,
    ) {}

    /**
     * Prüft die PO-AT13-CC-1-Voraussetzungen (ohne Mutation).
     *
     * @throws ValidationException
     */
    public function assertEligibleForCustomerConfirmationArchiveInvalidation(
        DispoOrder $order,
        DispoOrderUpload $upload,
    ): DispoOrderApprovalRequest {
        if ($upload->category !== DispoOrderUploadCategory::CustomerConfirmation) {
            throw ValidationException::withMessages([
                'upload' => 'Nur Kundenbestätigungen können die Freigabe invalidieren.',
            ]);
        }

        if ($order->status !== DispoOrderStatus::AtDisposition) {
            throw ValidationException::withMessages([
                'upload' => 'Die Kundenbestätigung kann in diesem Status nicht archiviert werden.',
            ]);
        }

        $active = DispoOrderUpload::query()
            ->where('dispo_order_id', $order->id)
            ->where('category', DispoOrderUploadCategory::CustomerConfirmation->value)
            ->whereNull('archived_at')
            ->orderByDesc('id')
            ->first();

        if ($active === null || (int) $active->id !== (int) $upload->id) {
            throw ValidationException::withMessages([
                'upload' => 'Nur die aktive Kundenbestätigung kann die Freigabe invalidieren.',
            ]);
        }

        $approved = DispoOrderApprovalRequest::query()
            ->where('dispo_order_id', $order->id)
            ->where('status', DispoOrderApprovalStatus::Approved->value)
            ->orderByDesc('cycle_number')
            ->orderByDesc('id')
            ->first();

        if ($approved === null) {
            throw ValidationException::withMessages([
                'upload' => 'Ohne erteilte Freigabe kann die Kundenbestätigung in diesem Status nicht archiviert werden.',
            ]);
        }

        DispoOrderStatusTransition::assertCanTransition(
            DispoOrderStatus::AtDisposition,
            DispoOrderStatus::Draft,
        );

        return $approved;
    }

    /**
     * Setzt Status auf draft, schreibt Statusevent + Audit. Approval-Request unverändert.
     * Erwartet: Upload bereits archiviert; Order lockForUpdate; noch Status at_disposition.
     */
    public function applyAfterCustomerConfirmationArchived(
        DispoOrder $order,
        User $user,
        DispoOrderUpload $archivedUpload,
        DispoOrderApprovalRequest $approvedRequest,
        int $lockVersionBefore,
    ): DispoOrder {
        $from = $order->status;
        $to = DispoOrderStatus::Draft;

        if ($from !== DispoOrderStatus::AtDisposition) {
            throw ValidationException::withMessages([
                'order' => 'Der Auftrag ist nicht mehr im Status „Liegt bei Disposition“.',
            ]);
        }

        DispoOrderStatusTransition::assertCanTransition($from, $to);

        $order->status = $to;
        $order->lock_version = $lockVersionBefore + 1;
        $order->save();

        $event = new DispoOrderStatusEvent;
        $event->dispo_order_id = $order->id;
        $event->from_status = $from;
        $event->to_status = $to;
        $event->changed_by_id = $user->id;
        $event->changed_by_name = $user->name;
        $event->changed_at = now();
        $event->reason = self::STATUS_REASON;
        $event->is_reopen = false;
        $event->lock_version_after = $order->lock_version;
        $event->save();

        $this->audit->record(
            $order,
            self::AUDIT_ACTION,
            $user,
            [
                'status' => $from->value,
                'lock_version' => $lockVersionBefore,
                'approval_request_id' => $approvedRequest->id,
                'approval_cycle_number' => $approvedRequest->cycle_number,
                'approval_status' => $approvedRequest->status->value,
            ],
            [
                'status' => $to->value,
                'lock_version' => $order->lock_version,
                'cause' => 'customer_confirmation_archived',
                'upload_id' => $archivedUpload->id,
                'upload_category' => $archivedUpload->category->value,
                'approval_request_id' => $approvedRequest->id,
                'approval_cycle_number' => $approvedRequest->cycle_number,
                'approval_status' => $approvedRequest->status->value,
                'status_event_id' => $event->id,
                'reason' => self::STATUS_REASON,
            ],
        );

        return $order;
    }
}
