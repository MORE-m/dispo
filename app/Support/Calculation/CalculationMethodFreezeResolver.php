<?php

namespace App\Support\Calculation;

use App\Enums\CalculationKind;
use App\Enums\CalculationMethodMode;
use App\Enums\EngineCapabilityStatus;
use App\Enums\SpotCalculationMethod;
use App\Models\AdvertisingMedium;
use App\Models\CalculationMethod;
use App\Models\CalculationPosition;
use App\Models\DispoOrderPosition;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use ValueError;

/**
 * ADV-001c2: zentrale Descriptor-/Freeze-Auflösung (Dual-Read).
 *
 * Neue/fachlich geänderte Kombinationen: Live-Katalog + freigegebene Registry-Version.
 * Unveränderte bestehende Positionen: eingefrorener Descriptor (historisch stabil).
 * Legacy-Fallback nur bei vollständigem NULL-Freeze und spot_classic/average.
 */
class CalculationMethodFreezeResolver
{
    public const LEGACY_SPOT_CLASSIC_AVERAGE = [
        'engine_profile_key' => EngineProfileRegistry::PROFILE_SPOT_CLASSIC,
        'calculation_method_key' => 'average',
        'calculation_method_name' => 'Durchschnitt',
        'algorithm_version' => 'v1',
    ];

    /**
     * Descriptor für neue oder fachlich geänderte Kombinationen (Live-Pfad).
     */
    public function resolveForNewCombination(
        AdvertisingMedium $medium,
        ?string $requestedMethodKey,
    ): CalculationMethodFreezeDescriptor {
        $kindRaw = $medium->getAttributes()['kind'] ?? null;
        if ($kindRaw === null || trim((string) $kindRaw) === '') {
            throw ValidationException::withMessages([
                'positions' => 'Das Werbemittel hat keine Berechnungsart und kann in diesem Umfang nicht neu kalkuliert werden.',
            ]);
        }

        if ((string) $kindRaw !== CalculationKind::SpotClassic->value) {
            throw ValidationException::withMessages([
                'positions' => 'Nur Spot Classic ist in diesem Umfang zulässig.',
            ]);
        }

        $medium->loadMissing(['category', 'defaultCalculationMethod', 'category.defaultCalculationMethod']);
        $category = $medium->category;
        if ($category === null || ! $category->is_active) {
            throw ValidationException::withMessages([
                'positions' => 'Die Oberkategorie des Werbemittels ist unbekannt oder inaktiv.',
            ]);
        }

        $requested = $requestedMethodKey !== null ? trim($requestedMethodKey) : '';
        $methodKey = $requested !== ''
            ? $requested
            : $this->resolveDefaultMethodKey($medium);

        $this->assertLiveMethodAndAssignment($medium, $methodKey);

        $assignmentProfile = $this->resolveActiveEngineProfileKey($medium, $methodKey);
        if ($assignmentProfile === null || trim($assignmentProfile) === '') {
            throw ValidationException::withMessages([
                'positions' => 'Für dieses Werbemittel ist keine aktive Berechnungsmethoden-Zuordnung hinterlegt.',
            ]);
        }

        try {
            EngineProfileRegistry::assertKnownProfile($assignmentProfile);
            EngineProfileRegistry::assertKnownMethodForProfile($assignmentProfile, $methodKey);
        } catch (InvalidArgumentException) {
            throw ValidationException::withMessages([
                'positions' => 'Die gewählte Berechnungsmethode ist technisch unbekannt.',
            ]);
        }

        $pairStatus = EngineProfileRegistry::pairStatus($assignmentProfile, $methodKey);
        if ($pairStatus !== EngineCapabilityStatus::Released) {
            $label = $this->methodLabel($methodKey);
            throw ValidationException::withMessages([
                'positions' => 'Kalkulationsart '.$label.' ist noch nicht freigegeben.',
            ]);
        }

        $version = EngineProfileRegistry::currentReleasedVersion($assignmentProfile, $methodKey);
        if ($version === null || trim($version) === '') {
            throw ValidationException::withMessages([
                'positions' => 'Für die gewählte Berechnungsmethode ist keine freigegebene Algorithmusversion hinterlegt.',
            ]);
        }

        $name = $this->methodNameFromCatalog($methodKey);

        return new CalculationMethodFreezeDescriptor(
            engineProfileKey: $assignmentProfile,
            calculationMethodKey: $methodKey,
            calculationMethodName: $name,
            algorithmVersion: $version,
        );
    }

    /**
     * Descriptor für bestehende Positionen (historischer Freeze oder Legacy-Fallback).
     *
     * @param  CalculationPosition|DispoOrderPosition|object{
     *     engine_profile_key?: string|null,
     *     calculation_method_key?: string|null,
     *     calculation_method_name?: string|null,
     *     algorithm_version?: string|null,
     *     kind?: mixed,
     *     spot_method?: mixed
     * }  $position
     */
    public function resolveStoredPosition(object $position, bool $forExecution = true): CalculationMethodFreezeDescriptor
    {
        $freeze = $this->readFreezeFields($position);
        $completeness = $this->freezeCompleteness($freeze);

        if ($completeness === 'partial') {
            throw ValidationException::withMessages([
                'positions' => 'Die eingefrorenen Berechnungsdaten der Position sind unvollständig und können nicht verwendet werden.',
            ]);
        }

        if ($completeness === 'complete') {
            $descriptor = new CalculationMethodFreezeDescriptor(
                engineProfileKey: (string) $freeze['engine_profile_key'],
                calculationMethodKey: (string) $freeze['calculation_method_key'],
                calculationMethodName: (string) $freeze['calculation_method_name'],
                algorithmVersion: (string) $freeze['algorithm_version'],
            );

            $this->assertLegacyMirrorsConsistent($position, $descriptor);
            $this->assertKnownFrozenCombination($descriptor, $forExecution);

            return $descriptor;
        }

        return $this->resolveLegacyFallback($position, $forExecution);
    }

    /**
     * Unveränderlichkeit: abweichendes spot_method an bestehender Position ablehnen.
     */
    public function assertMethodUnchangedOnExisting(
        object $existing,
        ?string $incomingSpotMethod,
    ): void {
        if ($incomingSpotMethod === null || $incomingSpotMethod === '') {
            return;
        }

        $stored = $this->resolveStoredPosition($existing, forExecution: false);
        if ($incomingSpotMethod !== $stored->calculationMethodKey) {
            throw ValidationException::withMessages([
                'positions' => 'Die Berechnungsmethode einer bestehenden Position kann in diesem Umfang nicht geändert werden.',
            ]);
        }
    }

    /**
     * @param  array{
     *     engine_profile_key: string|null,
     *     calculation_method_key: string|null,
     *     calculation_method_name: string|null,
     *     algorithm_version: string|null
     * }  $freeze
     * @return 'empty'|'complete'|'partial'
     */
    public function freezeCompleteness(array $freeze): string
    {
        $nullCount = 0;
        $blankCount = 0;
        $filledCount = 0;

        foreach ([
            $freeze['engine_profile_key'],
            $freeze['calculation_method_key'],
            $freeze['calculation_method_name'],
            $freeze['algorithm_version'],
        ] as $value) {
            if ($value === null) {
                $nullCount++;
            } elseif ($value === '') {
                $blankCount++;
            } else {
                $filledCount++;
            }
        }

        // Leerstrings/Whitespace gelten nie als gültiger NULL-Freeze.
        if ($blankCount > 0) {
            return 'partial';
        }
        if ($nullCount === 4) {
            return 'empty';
        }
        if ($filledCount === 4) {
            return 'complete';
        }

        return 'partial';
    }

    /**
     * @return array{
     *     engine_profile_key: string|null,
     *     calculation_method_key: string|null,
     *     calculation_method_name: string|null,
     *     algorithm_version: string|null
     * }
     */
    public function readFreezeFields(object $position): array
    {
        $attrs = method_exists($position, 'getAttributes')
            ? $position->getAttributes()
            : (array) $position;

        return [
            'engine_profile_key' => $this->freezeFieldValue($attrs['engine_profile_key'] ?? null),
            'calculation_method_key' => $this->freezeFieldValue($attrs['calculation_method_key'] ?? null),
            'calculation_method_name' => $this->freezeFieldValue($attrs['calculation_method_name'] ?? null),
            'algorithm_version' => $this->freezeFieldValue($attrs['algorithm_version'] ?? null),
        ];
    }

    private function resolveLegacyFallback(object $position, bool $forExecution): CalculationMethodFreezeDescriptor
    {
        $kind = $this->rawKind($position);
        $spotMethod = $this->rawSpotMethod($position);

        if ($kind === CalculationKind::SpotClassic->value
            && $spotMethod === SpotCalculationMethod::Average->value) {
            $descriptor = new CalculationMethodFreezeDescriptor(
                engineProfileKey: self::LEGACY_SPOT_CLASSIC_AVERAGE['engine_profile_key'],
                calculationMethodKey: self::LEGACY_SPOT_CLASSIC_AVERAGE['calculation_method_key'],
                calculationMethodName: self::LEGACY_SPOT_CLASSIC_AVERAGE['calculation_method_name'],
                algorithmVersion: self::LEGACY_SPOT_CLASSIC_AVERAGE['algorithm_version'],
            );
            $this->assertKnownFrozenCombination($descriptor, $forExecution);

            return $descriptor;
        }

        throw ValidationException::withMessages([
            'positions' => 'Die Position besitzt keinen gültigen Berechnungs-Freeze und keinen bekannten Legacy-Fallback.',
        ]);
    }

    private function assertLegacyMirrorsConsistent(object $position, CalculationMethodFreezeDescriptor $descriptor): void
    {
        $kind = $this->rawKind($position);
        $spotMethod = $this->rawSpotMethod($position);

        if ($kind === null || $spotMethod === null) {
            throw ValidationException::withMessages([
                'positions' => 'Legacy-Berechnungsfelder der Position fehlen trotz vollständigem Freeze.',
            ]);
        }

        try {
            $expectedKind = $descriptor->legacyKind()->value;
            $expectedMethod = $descriptor->legacySpotMethod()->value;
        } catch (InvalidArgumentException|ValueError) {
            throw ValidationException::withMessages([
                'positions' => 'Der eingefrorene Berechnungs-Descriptor ist fachlich ungültig.',
            ]);
        }

        if ($kind !== $expectedKind || $spotMethod !== $expectedMethod) {
            throw ValidationException::withMessages([
                'positions' => 'Eingefrorene Berechnungsdaten widersprechen den Legacy-Feldern der Position.',
            ]);
        }
    }

    private function assertKnownFrozenCombination(
        CalculationMethodFreezeDescriptor $descriptor,
        bool $forExecution,
    ): void {
        try {
            EngineProfileRegistry::assertKnownProfile($descriptor->engineProfileKey);
            EngineProfileRegistry::assertKnownMethodForProfile(
                $descriptor->engineProfileKey,
                $descriptor->calculationMethodKey,
            );
            $status = EngineProfileRegistry::statusForVersion(
                $descriptor->engineProfileKey,
                $descriptor->calculationMethodKey,
                $descriptor->algorithmVersion,
            );
        } catch (InvalidArgumentException) {
            if (! $forExecution) {
                // Lesen historischer unbekannter Versionen bleibt möglich, solange nicht ausgeführt.
                return;
            }

            throw ValidationException::withMessages([
                'positions' => 'Die eingefrorene Berechnungsmethode oder Algorithmusversion ist unbekannt und kann nicht ausgeführt werden.',
            ]);
        }

        if (! $forExecution) {
            return;
        }

        if ($status !== EngineCapabilityStatus::Released
            && $status !== EngineCapabilityStatus::Implemented) {
            throw ValidationException::withMessages([
                'positions' => 'Die eingefrorene Algorithmusversion ist nicht ausführbar.',
            ]);
        }
    }

    private function resolveDefaultMethodKey(AdvertisingMedium $medium): string
    {
        $medium->loadMissing(['defaultCalculationMethod', 'category.defaultCalculationMethod']);

        if ($medium->calculation_method_mode === CalculationMethodMode::Override) {
            $key = $medium->defaultCalculationMethod?->key;
            if ($key !== null && $key !== '') {
                return $key;
            }

            throw ValidationException::withMessages([
                'positions' => 'Für dieses Werbemittel ist keine Standard-Berechnungsmethode hinterlegt.',
            ]);
        }

        $key = $medium->category?->defaultCalculationMethod?->key;
        if ($key !== null && $key !== '') {
            return $key;
        }

        throw ValidationException::withMessages([
            'positions' => 'Für die Oberkategorie ist keine Standard-Berechnungsmethode hinterlegt.',
        ]);
    }

    private function assertLiveMethodAndAssignment(AdvertisingMedium $medium, string $methodKey): void
    {
        $method = CalculationMethod::query()->where('key', $methodKey)->first();
        if ($method === null || ! $method->is_active) {
            throw ValidationException::withMessages([
                'positions' => 'Die Berechnungsmethode ist unbekannt oder inaktiv.',
            ]);
        }

        $profile = $this->resolveActiveEngineProfileKey($medium, $methodKey);
        if ($profile === null || trim($profile) === '') {
            throw ValidationException::withMessages([
                'positions' => 'Die gewählte Berechnungsmethode ist für dieses Werbemittel nicht aktiv zugeordnet.',
            ]);
        }

        // Defaultmethode muss zu einer aktiven zulässigen Zuordnung gehören.
        $defaultKey = null;
        if ($medium->calculation_method_mode === CalculationMethodMode::Override) {
            $defaultKey = $medium->defaultCalculationMethod?->key;
        } else {
            $defaultKey = $medium->category?->defaultCalculationMethod?->key;
        }
        if ($defaultKey !== null && $defaultKey !== '') {
            $defaultProfile = $this->resolveActiveEngineProfileKey($medium, $defaultKey);
            if ($defaultProfile === null || trim($defaultProfile) === '') {
                throw ValidationException::withMessages([
                    'positions' => 'Die Standard-Berechnungsmethode ist keiner aktiven Zuordnung zugeordnet.',
                ]);
            }
        }
    }

    private function resolveActiveEngineProfileKey(AdvertisingMedium $medium, string $methodKey): ?string
    {
        $medium->loadMissing([
            'calculationMethodAssignments.calculationMethod',
            'category.calculationMethodAssignments.calculationMethod',
        ]);

        if ($medium->calculation_method_mode === CalculationMethodMode::Override) {
            foreach ($medium->calculationMethodAssignments as $assignment) {
                if (! $assignment->is_active) {
                    continue;
                }
                if ($assignment->calculationMethod?->key !== $methodKey) {
                    continue;
                }
                if (! $assignment->calculationMethod->is_active) {
                    continue;
                }
                $profile = $this->nullableString($assignment->engine_profile_key);
                if ($profile === null) {
                    continue;
                }

                return $profile;
            }

            return null;
        }

        $category = $medium->category;
        if ($category === null) {
            return null;
        }

        foreach ($category->calculationMethodAssignments as $assignment) {
            if (! $assignment->is_active) {
                continue;
            }
            if ($assignment->calculationMethod?->key !== $methodKey) {
                continue;
            }
            if (! $assignment->calculationMethod->is_active) {
                continue;
            }
            $profile = $this->nullableString($assignment->engine_profile_key);
            if ($profile === null) {
                continue;
            }

            return $profile;
        }

        return null;
    }

    private function methodNameFromCatalog(string $methodKey): string
    {
        $method = CalculationMethod::query()->where('key', $methodKey)->where('is_active', true)->first();
        $name = $method !== null ? trim((string) $method->name) : '';
        if ($name !== '') {
            return $name;
        }

        throw ValidationException::withMessages([
            'positions' => 'Der Methodenname der Berechnungsmethode konnte nicht ermittelt werden.',
        ]);
    }

    private function methodLabel(string $methodKey): string
    {
        try {
            return SpotCalculationMethod::from($methodKey)->label();
        } catch (ValueError) {
            return $methodKey;
        }
    }

    private function rawKind(object $position): ?string
    {
        if (method_exists($position, 'getAttributes')) {
            return $this->nullableString($position->getAttributes()['kind'] ?? null);
        }

        $kind = $position->kind ?? null;
        if ($kind instanceof CalculationKind) {
            return $kind->value;
        }

        return $this->nullableString($kind);
    }

    private function rawSpotMethod(object $position): ?string
    {
        if (method_exists($position, 'getAttributes')) {
            return $this->nullableString($position->getAttributes()['spot_method'] ?? null);
        }

        $method = $position->spot_method ?? null;
        if ($method instanceof SpotCalculationMethod) {
            return $method->value;
        }

        return $this->nullableString($method);
    }

    /**
     * Freeze-Feld: null bleibt null; Blank wird als '' markiert (nicht auf null normalisiert).
     */
    private function freezeFieldValue(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $string = (string) $value;
        if (trim($string) === '') {
            return '';
        }

        return trim($string);
    }

    private function nullableString(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $string = trim((string) $value);

        return $string === '' ? null : $string;
    }
}
