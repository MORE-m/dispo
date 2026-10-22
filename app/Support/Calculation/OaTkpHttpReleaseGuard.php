<?php

namespace App\Support\Calculation;

use App\Models\Calculation;
use App\Models\CalculationPosition;
use App\Services\Calculation\OaTkp\OaTkpWriterPositionResolver;
use Illuminate\Validation\ValidationException;

/**
 * PO-CALC-TKP-1D1/1D2a: HTTP-Gates für Normal-TKP.
 *
 * Preview kann unter {@see OaTkpWizardCapability} soft-offen sein (Pair bleibt planned).
 * Store/Update bleiben bis Domain-Release fail-closed – Capability öffnet Persistenz nicht.
 */
final class OaTkpHttpReleaseGuard
{
    public const MESSAGE = 'Kalkulationsart TKP ist noch nicht freigegeben.';

    /**
     * @param  array<string, mixed>  $payload
     */
    public static function assertPreviewAllowed(array $payload, ?Calculation $existing = null): void
    {
        if (OaTkpWizardCapability::allowsWizardPreviewSurface()) {
            return;
        }

        self::rejectIfTkpPresent($payload, $existing);
    }

    /**
     * HTTP Create/Update: nur bei Registry-Release.
     *
     * @param  array<string, mixed>  $payload
     */
    public static function assertMutationAllowed(array $payload, ?Calculation $existing = null): void
    {
        if (OaTkpWizardCapability::allowsHttpMutation()) {
            return;
        }

        self::rejectIfTkpPresent($payload, $existing);
    }

    /**
     * @deprecated Use assertMutationAllowed / assertPreviewAllowed. Kept for call-site clarity during D2a.
     *
     * @param  array<string, mixed>  $payload
     */
    public static function assertAllowed(array $payload, ?Calculation $existing = null): void
    {
        self::assertMutationAllowed($payload, $existing);
    }

    /**
     * Budgetvorschlag/-übernahme ist Spot-only: TKP im Payload oder in Bestandspositionen
     * wird unabhängig von Release und Wizard-Capability abgewiesen.
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

    /**
     * @param  array<string, mixed>  $payload
     */
    private static function rejectIfTkpPresent(array $payload, ?Calculation $existing): void
    {
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
