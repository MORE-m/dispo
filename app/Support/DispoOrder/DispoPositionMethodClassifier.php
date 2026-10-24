<?php

namespace App\Support\DispoOrder;

use App\Models\CalculationPosition;
use App\Models\DispoOrderPosition;
use App\Services\Calculation\OaTkp\OaTkpWriterPositionResolver;
use App\Support\Calculation\EngineProfileRegistry;
use Illuminate\Validation\ValidationException;

/**
 * PO-CALC-TKP-1D3d-1: einheitliche Klassifikation Method × Engine für Dispo.
 *
 * Gültig OA: oa_tkp × tkp (Registry-Paar).
 * Gültig Spot-Familie: bekanntes Nicht-OA-Registry-Paar oder Legacy-NULL/NULL.
 * Sonst inkonsistent – weder Spot- noch OA-Pfad.
 */
final class DispoPositionMethodClassifier
{
    public const string KIND_OA_TKP = 'oa_tkp';

    public const string KIND_SPOT_FAMILY = 'spot_family';

    public const string KIND_INCONSISTENT = 'inconsistent';

    public const string MESSAGE_INCONSISTENT = 'Die Methoden-/Engine-Kombination dieser Position ist ungültig und kann weder als Spot noch als Online-Audio übernommen werden.';

    public static function classify(?string $engineProfileKey, ?string $calculationMethodKey): string
    {
        $profile = self::normalize($engineProfileKey);
        $method = self::normalize($calculationMethodKey);

        if ($profile === null && $method === null) {
            // Historischer Legacy-Freeze (spot_classic/average) – zulässig als Spot-Familie.
            return self::KIND_SPOT_FAMILY;
        }

        if ($profile === null || $method === null) {
            return self::KIND_INCONSISTENT;
        }

        $isOaProfile = $profile === EngineProfileRegistry::PROFILE_OA_TKP;
        $isOaMethod = $method === OaTkpWriterPositionResolver::METHOD_KEY;

        if ($isOaProfile || $isOaMethod) {
            if (
                $isOaProfile
                && $isOaMethod
                && EngineProfileRegistry::hasMethodForProfile($profile, $method)
            ) {
                return self::KIND_OA_TKP;
            }

            return self::KIND_INCONSISTENT;
        }

        if (EngineProfileRegistry::hasMethodForProfile($profile, $method)) {
            return self::KIND_SPOT_FAMILY;
        }

        return self::KIND_INCONSISTENT;
    }

    public static function classifyPosition(CalculationPosition|DispoOrderPosition $position): string
    {
        return self::classify(
            is_string($position->engine_profile_key ?? null) ? $position->engine_profile_key : null,
            is_string($position->calculation_method_key ?? null) ? $position->calculation_method_key : null,
        );
    }

    public static function isOaTkp(?string $engineProfileKey, ?string $calculationMethodKey): bool
    {
        return self::classify($engineProfileKey, $calculationMethodKey) === self::KIND_OA_TKP;
    }

    public static function isSpotFamily(?string $engineProfileKey, ?string $calculationMethodKey): bool
    {
        return self::classify($engineProfileKey, $calculationMethodKey) === self::KIND_SPOT_FAMILY;
    }

    public static function isInconsistent(?string $engineProfileKey, ?string $calculationMethodKey): bool
    {
        return self::classify($engineProfileKey, $calculationMethodKey) === self::KIND_INCONSISTENT;
    }

    /**
     * Fail-closed vor Dispo-Handoff (Mapper/Writer).
     */
    public static function assertAdoptable(CalculationPosition $position): void
    {
        if (self::classifyPosition($position) === self::KIND_INCONSISTENT) {
            throw ValidationException::withMessages([
                'position_ids' => self::MESSAGE_INCONSISTENT,
            ]);
        }
    }

    public static function inconsistencyMessage(): string
    {
        return self::MESSAGE_INCONSISTENT;
    }

    private static function normalize(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }
}
