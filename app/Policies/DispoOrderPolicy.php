<?php

namespace App\Policies;

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
