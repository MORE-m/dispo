<?php

namespace App\Policies;

use App\Models\Calculation;
use App\Models\User;

/**
 * AUTH-001, AUTH-002, AUTH-003, AUTH-006, AUTH-007
 */
class CalculationPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->canAccessCalculations();
    }

    public function view(User $user, Calculation $calculation): bool
    {
        return $user->canAccessCalculations();
    }

    /**
     * BL-P4-03b: Vorschlag nur bei Calc-View-Recht und schmalem Propose-Recht
     * (AUTH-002: Vertrieb sieht alle Calc; kein separates Eigentumsmodell).
     */
    public function proposeAsStandardOffer(User $user, Calculation $calculation): bool
    {
        return $this->view($user, $calculation)
            && $user->canProposeStandardOfferFromCalculation();
    }

    public function create(User $user): bool
    {
        return $user->canManageCalculations();
    }

    public function update(User $user, Calculation $calculation): bool
    {
        return $user->canManageCalculations();
    }
}
