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

    private function isNotCreator(User $user, DispoOrder $dispoOrder): bool
    {
        return (int) $user->id !== (int) $dispoOrder->created_by_id;
    }
}
