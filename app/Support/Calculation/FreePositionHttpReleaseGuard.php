<?php

namespace App\Support\Calculation;

use App\Enums\EngineCapabilityStatus;
use App\Models\Calculation;
use App\Models\CalculationPosition;
use App\Services\Calculation\FreePosition\FreePositionWriterPositionResolver;
use Illuminate\Validation\ValidationException;

/**
 * NATIVE-2: HTTP-Gates für Freie Preisposition.
 *
 * Preview, Store und Update sind ausschließlich bei Registry-Release
 * (free_position × free_position) offen – kein Soft-Open / keine Wizard-Capability.
 * Produktiv bleibt das Pair `planned` → jede Free-Position im Payload oder Bestand wird abgewiesen.
 */
final class FreePositionHttpReleaseGuard
{
    public const MESSAGE = 'Kalkulationsart Freie Preisposition ist noch nicht freigegeben.';

    public static function pairIsReleased(): bool
    {
        return EngineProfileRegistry::pairStatus(
            EngineProfileRegistry::PROFILE_FREE_POSITION,
            FreePositionWriterPositionResolver::METHOD_KEY,
        ) === EngineCapabilityStatus::Released;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public static function assertPreviewAllowed(array $payload, ?Calculation $existing = null): void
    {
        self::rejectIfFreePresentAndNotReleased($payload, $existing);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public static function assertMutationAllowed(array $payload, ?Calculation $existing = null): void
    {
        self::rejectIfFreePresentAndNotReleased($payload, $existing);
    }

    /**
     * Budgetvorschlag/-übernahme ist Spot-only: Free im Payload oder in Bestandspositionen
     * wird unabhängig vom Release abgewiesen.
     *
     * @param  array<string, mixed>  $payload
     */
    public static function assertBudgetNotFreePosition(array $payload, ?Calculation $existing = null): void
    {
        foreach (is_array($payload['positions'] ?? null) ? $payload['positions'] : [] as $position) {
            if (is_array($position) && FreePositionWriterPositionResolver::payloadSignals($position)) {
                self::rejectBudget();
            }
        }

        if ($existing !== null) {
            $existing->loadMissing('positions');
            if ($existing->positions->contains(
                fn (CalculationPosition $position): bool => FreePositionWriterPositionResolver::isStored($position),
            )) {
                self::rejectBudget();
            }
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private static function rejectIfFreePresentAndNotReleased(array $payload, ?Calculation $existing): void
    {
        if (self::pairIsReleased()) {
            return;
        }

        foreach (is_array($payload['positions'] ?? null) ? $payload['positions'] : [] as $position) {
            if (is_array($position) && FreePositionWriterPositionResolver::payloadSignals($position)) {
                self::reject();
            }
        }

        if ($existing !== null) {
            $existing->loadMissing('positions');
            if ($existing->positions->contains(
                fn (CalculationPosition $position): bool => FreePositionWriterPositionResolver::isStored($position),
            )) {
                self::reject();
            }
        }
    }

    private static function rejectBudget(): never
    {
        throw ValidationException::withMessages([
            'positions' => 'Budgetvorschläge sind für Freie Preispositionen nicht verfügbar.',
        ]);
    }

    private static function reject(): never
    {
        throw ValidationException::withMessages([
            'positions' => self::MESSAGE,
        ]);
    }
}
