<?php

namespace App\Services\StandardOffer;

use App\Enums\CalculationKind;
use App\Models\AdvertisingMedium;
use App\Models\Calculation;
use App\Support\Advertising\AdvertisingMediumLiveBookability;
use App\Support\Calculation\CalculationMethodFreezeResolver;
use App\Support\Calculation\EngineProfileRegistry;
use Illuminate\Validation\ValidationException;

/**
 * PO-ALLONGE-1 Review: Standardangebote bleiben beim Spot-Classic-Vertrag.
 *
 * Allonge × Average ist nur für normale Kalkulationen freigegeben.
 * Die Prüfung nutzt den gespeicherten Positions-Freeze bzw. das
 * serverseitig aufgelöste Werbemittel. Payload-Felder kind und
 * engine_profile_key werden nicht gelesen.
 */
final class StandardOfferSpotClassicBoundary
{
    public function __construct(
        private readonly CalculationMethodFreezeResolver $freezeResolver,
        private readonly AdvertisingMediumLiveBookability $liveBookability,
    ) {}

    public function assertSourceCalculation(Calculation $calculation): void
    {
        $calculation->loadMissing('positions');
        $errors = [];

        foreach ($calculation->positions as $index => $position) {
            $descriptor = $this->freezeResolver->resolveStoredPosition($position, forExecution: false);
            if ($descriptor->engineProfileKey !== EngineProfileRegistry::PROFILE_SPOT_CLASSIC) {
                $errors["positions.{$index}.advertising_medium_id"] = $this->message((int) $index);
            }
        }

        $this->throwIfAny($errors);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function assertDraftPayload(array $payload): void
    {
        $positions = $payload['positions'] ?? null;
        if (! is_array($positions)) {
            return;
        }

        $errors = [];
        foreach ($positions as $index => $position) {
            if (! is_array($position)) {
                continue;
            }

            $medium = AdvertisingMedium::query()->find((int) ($position['advertising_medium_id'] ?? 0));
            if (! $medium instanceof AdvertisingMedium) {
                $errors["positions.{$index}.advertising_medium_id"] = 'Position '.((int) $index + 1).': Das Werbemittel konnte nicht aufgelöst werden.';

                continue;
            }

            $kind = CalculationKind::tryFrom(trim((string) ($medium->getAttributes()['kind'] ?? '')));
            if ($kind !== CalculationKind::SpotClassic) {
                $errors["positions.{$index}.advertising_medium_id"] = $this->message((int) $index);

                continue;
            }

            $requested = $position['calculation_method_key'] ?? $position['spot_method'] ?? null;
            $requestedKey = is_string($requested) && $requested !== '' ? $requested : null;
            $evaluation = $this->liveBookability->evaluate($medium, $requestedKey);
            if ($evaluation->engineProfileKey !== null
                && $evaluation->engineProfileKey !== EngineProfileRegistry::PROFILE_SPOT_CLASSIC
            ) {
                $errors["positions.{$index}.advertising_medium_id"] = $this->message((int) $index);
            }
        }

        $this->throwIfAny($errors);
    }

    private function message(int $index): string
    {
        return 'Position '.($index + 1).': Standardangebote erlauben nur Spot Classic. Andere Berechnungsprofile, einschließlich Allonge, sind nicht freigegeben.';
    }

    /**
     * @param  array<string, string>  $errors
     */
    private function throwIfAny(array $errors): void
    {
        if ($errors === []) {
            return;
        }

        throw ValidationException::withMessages($errors);
    }
}
