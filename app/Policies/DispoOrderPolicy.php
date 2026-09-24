<?php

namespace App\Policies;

use App\Enums\DispoOrderStatus;
use App\Enums\Role;
use App\Models\Calculation;
use App\Models\DispoOrder;
use App\Models\User;

class DispoOrderPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->canViewDispoOrders();
    }

    public function view(User $user, DispoOrder $dispoOrder): bool
    {
        return $user->canViewDispoOrders();
    }

    public function create(User $user, Calculation $calculation): bool
    {
        return $user->canManageDispoOrders()
            && $user->can('view', $calculation);
    }

    /**
     * Draft-Update der Dispo-only-Texte (DF-2).
     */
    public function update(User $user, DispoOrder $dispoOrder): bool
    {
        return $user->canManageDispoOrders()
            && $dispoOrder->status === DispoOrderStatus::Draft;
    }

    /**
     * Kundenbestätigungs-Ausnahme ohne Upload (BL-P8-02c / PO-BLP802C-1).
     * Gleiche Draft-Rollen wie {@see update()}; Disposition/PM ohne Extra-Recht.
     */
    public function updateCustomerConfirmation(User $user, DispoOrder $dispoOrder): bool
    {
        return $this->update($user, $dispoOrder);
    }

    /**
     * Nachbesserung: nur Ersteller, abgelehnter Auftrag, ohne Nachfolger.
     * Admin/GF erhalten dies nicht automatisch für fremde Aufträge.
     */
    public function revise(User $user, DispoOrder $dispoOrder): bool
    {
        if ($dispoOrder->status !== DispoOrderStatus::ApprovalRejected) {
            return false;
        }

        if ((int) $user->id !== (int) $dispoOrder->created_by_id) {
            return false;
        }

        $calculation = $dispoOrder->relationLoaded('calculation')
            ? $dispoOrder->calculation
            : $dispoOrder->calculation()->first();

        if ($calculation === null) {
            return false;
        }

        if (! $user->can('update', $calculation)) {
            return false;
        }

        return ! $dispoOrder->hasRevision();
    }

    /**
     * Anlage eines Korrektur-Drafts mit revises_dispo_order_id.
     */
    public function createRevision(User $user, DispoOrder $predecessor): bool
    {
        return $this->revise($user, $predecessor);
    }

    public function submit(User $user, DispoOrder $dispoOrder): bool
    {
        return $user->canManageDispoOrders();
    }

    public function approveRegular(User $user, DispoOrder $dispoOrder): bool
    {
        return $this->isNotCreator($user, $dispoOrder)
            && $user->hasAnyRole(Role::Admin, Role::Sales, Role::Management);
    }

    public function approveSpecial(User $user, DispoOrder $dispoOrder): bool
    {
        return $this->isNotCreator($user, $dispoOrder)
            && $user->hasAnyRole(Role::Admin, Role::Management);
    }

    public function rejectRegular(User $user, DispoOrder $dispoOrder): bool
    {
        return $this->approveRegular($user, $dispoOrder);
    }

    public function rejectSpecial(User $user, DispoOrder $dispoOrder): bool
    {
        return $this->approveSpecial($user, $dispoOrder);
    }

    public function approve(User $user, DispoOrder $dispoOrder): bool
    {
        return $dispoOrder->requiresSpecialApproval()
            ? $this->approveSpecial($user, $dispoOrder)
            : $this->approveRegular($user, $dispoOrder);
    }

    public function reject(User $user, DispoOrder $dispoOrder): bool
    {
        return $dispoOrder->requiresSpecialApproval()
            ? $this->rejectSpecial($user, $dispoOrder)
            : $this->rejectRegular($user, $dispoOrder);
    }

    /**
     * Operative Statusübergänge (BL-P8-02a / PO-BLP802A-1).
     * Unabhängig vom Draft-Update-Vertrag {@see update()}.
     */
    public function transitionOperationalStatus(User $user, DispoOrder $dispoOrder): bool
    {
        return $user->hasAnyRole(Role::Disposition, Role::Admin, Role::Management);
    }

    /**
     * Rechnung per Ende je Position (BL-P8-02d / PO-BLP802D-1).
     */
    public function updateInvoiceEndMonths(User $user, DispoOrder $dispoOrder): bool
    {
        return $user->hasAnyRole(Role::Disposition, Role::Admin, Role::Management);
    }

    /**
     * Abschluss disposed → completed (BL-P8-02d / PO-BLP802D-1).
     */
    public function complete(User $user, DispoOrder $dispoOrder): bool
    {
        return $user->hasAnyRole(Role::Disposition, Role::Admin, Role::Management);
    }

    /**
     * Admin-Override trotz verletzter Abschlussprüfungen (STA-006).
     */
    public function forceComplete(User $user, DispoOrder $dispoOrder): bool
    {
        return $user->hasRole(Role::Admin);
    }

    /**
     * Rückfrage an Vertrieb stellen (BL-P8-02b / PO-BLP802B-1).
     */
    public function askSalesInquiry(User $user, DispoOrder $dispoOrder): bool
    {
        return $user->hasAnyRole(Role::Disposition, Role::Admin, Role::Management);
    }

    /**
     * Rückfrage beantworten (BL-P8-02b / PO-BLP802B-1).
     */
    public function answerSalesInquiry(User $user, DispoOrder $dispoOrder): bool
    {
        return $user->hasAnyRole(Role::Sales, Role::Admin, Role::Management);
    }

    private function isNotCreator(User $user, DispoOrder $dispoOrder): bool
    {
        return (int) $user->id !== (int) $dispoOrder->created_by_id;
    }
}
