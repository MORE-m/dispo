<?php

namespace App\Services\DispoOrder;

use App\Enums\DispoOrderStatus;
use App\Models\Calculation;
use App\Models\DispoOrder;

/**
 * Fachliche Invarianten für Nachbesserungs-Verknüpfungen.
 */
final class DispoOrderRevisionRules
{
    public static function predecessorStatusAllowed(DispoOrder $predecessor): bool
    {
        return $predecessor->status === DispoOrderStatus::ApprovalRejected;
    }

    public static function sameCalculation(DispoOrder $predecessor, Calculation|DispoOrder $other): bool
    {
        $otherCalculationId = $other instanceof Calculation
            ? $other->id
            : $other->calculation_id;

        return (int) $predecessor->calculation_id === (int) $otherCalculationId;
    }

    public static function isSelfReference(int $predecessorId, int $successorId): bool
    {
        return $predecessorId === $successorId;
    }

    /**
     * Prüft, ob die Verknüpfung successor→predecessor einen Zyklus erzeugen würde.
     * Walkt die bestehende revises-Kette des Vorgängers.
     */
    public static function wouldCreateCycle(DispoOrder $predecessor, int $successorId): bool
    {
        $current = $predecessor;
        $guard = 0;

        while ($current->revises_dispo_order_id !== null && $guard < 100) {
            if ((int) $current->revises_dispo_order_id === $successorId) {
                return true;
            }

            $current = $current->revises;
            if ($current === null) {
                break;
            }

            $guard++;
        }

        return false;
    }
}
