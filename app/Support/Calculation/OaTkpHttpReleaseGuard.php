<?php

namespace App\Support\Calculation;

use App\Enums\EngineCapabilityStatus;
use App\Models\Calculation;
use App\Models\CalculationPosition;
use App\Services\Calculation\OaTkp\OaTkpWriterPositionResolver;
use Illuminate\Validation\ValidationException;

/**
 * PO-CALC-TKP-1D1: HTTP bleibt fail-closed, solange das Pair oa_tkp × tkp nicht Released ist.
 *
 * Der CalculationWriter kann Normal-TKP intern verarbeiten (Tests/Services); Preview,
 * Store und Update über HTTP weisen TKP weiterhin mit 422 ab.
 */
final class OaTkpHttpReleaseGuard
{
    public const MESSAGE = 'Kalkulationsart TKP ist noch nicht freigegeben.';

    /**
     * @param  array<string, mixed>  $payload
     */
    public static function assertAllowed(array $payload, ?Calculation $existing = null): void
    {
        if (EngineProfileRegistry::pairStatus(EngineProfileRegistry::PROFILE_OA_TKP, OaTkpWriterPositionResolver::METHOD_KEY)
            === EngineCapabilityStatus::Released
        ) {
            return;
        }

        foreach (is_array($payload['positions'] ?? null) ? $payload['positions'] : [] as $position) {
            if (is_array($position) && OaTkpWriterPositionResolver::payloadSignalsOaTkp($position)) {
                self::reject();
            }
        }

        if ($existing !== null) {
            $existing->loadMissing('positions');
            if ($existing->positions->contains(
                fn (CalculationPosition $position): bool => OaTkpWriterPositionResolver::isStoredOaTkp($position),
            )) {
                self::reject();
            }
        }
    }

    /**
     * Budgetvorschlag/-übernahme ist Spot-only: TKP im Payload oder in Bestandspositionen
     * wird unabhängig vom Release-Status abgewiesen.
     *
     * @param  array<string, mixed>  $payload
     */
    public static function assertBudgetNotTkp(array $payload, ?Calculation $existing = null): void
    {
        foreach (is_array($payload['positions'] ?? null) ? $payload['positions'] : [] as $position) {
            if (is_array($position) && OaTkpWriterPositionResolver::payloadSignalsOaTkp($position)) {
                self::rejectBudget();
            }
        }

        if ($existing !== null) {
            $existing->loadMissing('positions');
            if ($existing->positions->contains(
                fn (CalculationPosition $position): bool => OaTkpWriterPositionResolver::isStoredOaTkp($position),
            )) {
                self::rejectBudget();
            }
        }
    }

    private static function rejectBudget(): never
    {
        throw ValidationException::withMessages([
            'positions' => 'Budgetvorschläge sind für TKP-Positionen nicht verfügbar.',
        ]);
    }

    private static function reject(): never
    {
        throw ValidationException::withMessages([
            'positions' => self::MESSAGE,
        ]);
    }
}
