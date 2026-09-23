<?php

namespace Tests\Concerns;

use App\Models\DispoOrder;
use App\Models\User;
use App\Services\DispoOrder\DispoOrderApprovalService;
use App\Services\DispoOrder\DispoOrderCustomerConfirmationService;

/**
 * Test-Fixture für BL-P8-02c Submit-Gate (kein Production-Default).
 *
 * seed* setzt den Zustand ohne lock_version-Bump (Fixture).
 * ensure* nutzt den echten Service (inkl. +1 / Audit) für Service-Tests.
 */
trait EnsuresCustomerConfirmationException
{
    protected function seedCustomerConfirmationException(
        DispoOrder $order,
        User $actor,
        string $reason = 'Kundenfreigabe liegt per E-Mail vor; Upload wird nachgereicht.',
    ): DispoOrder {
        $order->forceFill([
            'customer_confirmation_without_upload' => true,
            'customer_confirmation_exception_reason' => $reason,
            'customer_confirmation_exception_set_by_id' => $actor->id,
            'customer_confirmation_exception_set_by_name' => $actor->name,
            'customer_confirmation_exception_set_at' => now(),
        ])->save();

        return $order->fresh();
    }

    protected function ensureCustomerConfirmationException(
        DispoOrder $order,
        User $actor,
        string $reason = 'Kundenfreigabe liegt per E-Mail vor; Upload wird nachgereicht.',
    ): DispoOrder {
        return app(DispoOrderCustomerConfirmationService::class)->update(
            $order,
            $actor,
            $order->lock_version,
            true,
            $reason,
        );
    }

    protected function approveWithExceptionAcknowledgement(
        DispoOrder $order,
        User $approver,
        ?string $note = null,
    ): DispoOrder {
        return app(DispoOrderApprovalService::class)->approve(
            $order,
            $approver,
            $order->lock_version,
            $note,
            true,
        );
    }
}
