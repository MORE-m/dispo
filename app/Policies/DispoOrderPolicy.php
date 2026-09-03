<?php

namespace App\Policies;

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
}
