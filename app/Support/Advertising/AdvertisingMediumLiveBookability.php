<?php

namespace App\Support\Advertising;

use App\Enums\CalculationKind;
use App\Enums\CalculationMethodMode;
use App\Enums\EngineCapabilityStatus;
use App\Enums\SpotCalculationMethod;
use App\Models\AdvertisingCategoryCalculationMethod;
use App\Models\AdvertisingMedium;
use App\Models\AdvertisingMediumCalculationMethod;
use App\Models\CalculationMethod;
use App\Support\Calculation\CalculationMethodFreezeResolver;
use App\Support\Calculation\EngineProfileRegistry;
use InvalidArgumentException;
use ValueError;

/**
 * ADV-001c3a: zentrale Medium-Live-Buchbarkeit für neue Kalkulationspositionen.
 *
 * Einziger fachlicher Vertrag für Admin-Status, Wizard-Props und den Live-Pfad
 * in {@see CalculationMethodFreezeResolver}.
 * Inventarregeln und Preislisten bleiben kombinatorsiche Resolver-Prüfungen.
 *
 * Nutzt bereits eager-geladene Assignment-/Methoden-Relationen; keine
 * zusätzliche CalculationMethod::query() pro Medium.
 *
 * ADV-001c3b2: optionaler {@see CategoryMethodCatalogSnapshot} für Inherit-
 * Simulation ohne DB-Mutation (expliziter Evaluationskontext).
 * ADV-001c3c: optionaler {@see MediumMethodCatalogSnapshot} für Override-
 * Simulation. Nie beide Snapshots gleichzeitig; Snapshot muss zum Mode passen.
 */
final class AdvertisingMediumLiveBookability
{
    public const SOURCE_CATEGORY = 'Oberkategorie';

    public const SOURCE_MEDIUM_OVERRIDE = 'Werbemittel-Override';

    public function evaluate(
        AdvertisingMedium $medium,
        ?string $requestedMethodKey = null,
        ?CategoryMethodCatalogSnapshot $inheritCatalog = null,
        ?MediumMethodCatalogSnapshot $overrideCatalog = null,
    ): AdvertisingMediumLiveBookabilityResult {
        $this->assertSnapshotContract($medium, $inheritCatalog, $overrideCatalog);

        if (! $medium->is_active) {
            return $this->blocked('Das Werbemittel ist im Katalog deaktiviert.');
        }

        $kindRaw = $medium->getAttributes()['kind'] ?? null;
        if ($kindRaw === null || trim((string) $kindRaw) === '') {
            return $this->blocked('Keine freigegebene Berechnungsmethode vorhanden.');
        }

        if ((string) $kindRaw !== CalculationKind::SpotClassic->value) {
            return $this->blocked('Nur Spot Classic ist derzeit für neue Kalkulationen freigegeben.');
        }

        $medium->loadMissing([
            'category',
            'defaultCalculationMethod',
            'category.defaultCalculationMethod',
            'calculationMethodAssignments.calculationMethod',
            'category.calculationMethodAssignments.calculationMethod',
        ]);

        $category = $medium->category;
        if ($category === null || ! $category->is_active) {
            return $this->blocked('Die Oberkategorie des Werbemittels ist unbekannt oder inaktiv.');
        }

        $requested = $requestedMethodKey !== null ? trim($requestedMethodKey) : '';
        if ($requested !== '') {
            $methodKey = $requested;
        } else {
            $defaultKey = $this->resolveDefaultMethodKey($medium, $inheritCatalog, $overrideCatalog);
            if ($defaultKey === null) {
                if ($medium->calculation_method_mode === CalculationMethodMode::Override) {
                    return $this->blocked('Für dieses Werbemittel ist keine Standard-Berechnungsmethode hinterlegt.');
                }

                return $this->blocked('Für die Oberkategorie ist keine Standard-Berechnungsmethode hinterlegt.');
            }
            $methodKey = $defaultKey;
        }

        $method = $this->resolveLoadedMethod($medium, $methodKey, $inheritCatalog, $overrideCatalog);
        if ($method === null || ! $method->is_active) {
            return $this->blocked('Die Berechnungsmethode ist unbekannt oder inaktiv.');
        }

        $assignmentProfile = $this->resolveActiveEngineProfileKey($medium, $methodKey, $inheritCatalog, $overrideCatalog);
        if ($assignmentProfile === null) {
            return $this->blocked('Die gewählte Berechnungsmethode ist für dieses Werbemittel nicht aktiv zugeordnet.');
        }

        $defaultKey = $this->resolveDefaultMethodKey($medium, $inheritCatalog, $overrideCatalog);
        if ($defaultKey !== null) {
            $defaultProfile = $this->resolveActiveEngineProfileKey($medium, $defaultKey, $inheritCatalog, $overrideCatalog);
            if ($defaultProfile === null) {
                return $this->blocked('Die Standard-Berechnungsmethode ist keiner aktiven Zuordnung zugeordnet.');
            }
        }

        try {
            EngineProfileRegistry::assertKnownProfile($assignmentProfile);
            EngineProfileRegistry::assertKnownMethodForProfile($assignmentProfile, $methodKey);
        } catch (InvalidArgumentException) {
            return $this->blocked('Die gewählte Berechnungsmethode ist technisch unbekannt.');
        }

        $pairStatus = EngineProfileRegistry::pairStatus($assignmentProfile, $methodKey);
        if ($pairStatus !== EngineCapabilityStatus::Released) {
            return $this->blocked('Kalkulationsart '.$this->methodLabel($methodKey).' ist noch nicht freigegeben.');
        }

        $version = EngineProfileRegistry::currentReleasedVersion($assignmentProfile, $methodKey);
        if ($version === null || trim($version) === '') {
            return $this->blocked('Für die gewählte Berechnungsmethode ist keine freigegebene Algorithmusversion hinterlegt.');
        }

        $name = trim((string) $method->name);
        if ($name === '') {
            return $this->blocked('Der Methodenname der Berechnungsmethode konnte nicht ermittelt werden.');
        }

        return new AdvertisingMediumLiveBookabilityResult(
            isBookableForNewPositions: true,
            unbookableReason: null,
            engineProfileKey: $assignmentProfile,
            calculationMethodKey: $methodKey,
            calculationMethodName: $name,
            algorithmVersion: $version,
        );
    }

    /**
     * Payload-Felder für Admin- und Wizard-Serialisierung (Default-Pfad, keine Request-Methode).
     *
     * @return array{is_bookable_for_new_positions: bool, unbookable_reason: string|null}
     */
    public function payloadForMedium(AdvertisingMedium $medium): array
    {
        return $this->evaluate($medium, null)->toPayload();
    }

    public function configurationSource(AdvertisingMedium $medium): string
    {
        return $medium->calculation_method_mode === CalculationMethodMode::Override
            ? self::SOURCE_MEDIUM_OVERRIDE
            : self::SOURCE_CATEGORY;
    }

    private function assertSnapshotContract(
        AdvertisingMedium $medium,
        ?CategoryMethodCatalogSnapshot $inheritCatalog,
        ?MediumMethodCatalogSnapshot $overrideCatalog,
    ): void {
        if ($inheritCatalog !== null && $overrideCatalog !== null) {
            throw new InvalidArgumentException(
                'CategoryMethodCatalogSnapshot und MediumMethodCatalogSnapshot dürfen nicht gleichzeitig gesetzt sein.',
            );
        }

        if ($inheritCatalog !== null && $medium->calculation_method_mode === CalculationMethodMode::Override) {
            throw new InvalidArgumentException(
                'CategoryMethodCatalogSnapshot darf nur für inherit-Medien verwendet werden.',
            );
        }

        if ($overrideCatalog !== null && $medium->calculation_method_mode === CalculationMethodMode::Inherit) {
            throw new InvalidArgumentException(
                'MediumMethodCatalogSnapshot darf nur für override-Medien verwendet werden.',
            );
        }
    }

    private function blocked(string $reason): AdvertisingMediumLiveBookabilityResult
    {
        return new AdvertisingMediumLiveBookabilityResult(
            isBookableForNewPositions: false,
            unbookableReason: $reason,
        );
    }

    private function resolveDefaultMethodKey(
        AdvertisingMedium $medium,
        ?CategoryMethodCatalogSnapshot $inheritCatalog,
        ?MediumMethodCatalogSnapshot $overrideCatalog,
    ): ?string {
        if ($medium->calculation_method_mode === CalculationMethodMode::Override) {
            if ($overrideCatalog !== null) {
                $key = $overrideCatalog->defaultCalculationMethod?->key;

                return ($key !== null && $key !== '') ? $key : null;
            }

            $medium->loadMissing(['defaultCalculationMethod']);
            $key = $medium->defaultCalculationMethod?->key;

            return ($key !== null && $key !== '') ? $key : null;
        }

        if ($inheritCatalog !== null) {
            $key = $inheritCatalog->defaultCalculationMethod?->key;

            return ($key !== null && $key !== '') ? $key : null;
        }

        $medium->loadMissing(['category.defaultCalculationMethod']);
        $key = $medium->category?->defaultCalculationMethod?->key;

        return ($key !== null && $key !== '') ? $key : null;
    }

    /**
     * Methode aus bereits geladenen Relationen bzw. Snapshot (keine CalculationMethod::query).
     */
    private function resolveLoadedMethod(
        AdvertisingMedium $medium,
        string $methodKey,
        ?CategoryMethodCatalogSnapshot $inheritCatalog,
        ?MediumMethodCatalogSnapshot $overrideCatalog,
    ): ?CalculationMethod {
        foreach ($this->assignmentCandidates($medium, $inheritCatalog, $overrideCatalog) as $assignment) {
            $method = $assignment->calculationMethod;
            if ($method !== null && $method->key === $methodKey) {
                return $method;
            }
        }

        if ($overrideCatalog !== null) {
            $default = $overrideCatalog->defaultCalculationMethod;
            if ($default !== null && $default->key === $methodKey) {
                return $default;
            }

            return null;
        }

        if ($inheritCatalog !== null && $medium->calculation_method_mode !== CalculationMethodMode::Override) {
            $default = $inheritCatalog->defaultCalculationMethod;
            if ($default !== null && $default->key === $methodKey) {
                return $default;
            }
        }

        if ($medium->calculation_method_mode === CalculationMethodMode::Override) {
            $medium->loadMissing(['defaultCalculationMethod']);
            $method = $medium->defaultCalculationMethod;
            if ($method !== null && $method->key === $methodKey) {
                return $method;
            }

            return null;
        }

        $medium->loadMissing(['category.defaultCalculationMethod']);
        $method = $medium->category?->defaultCalculationMethod;
        if ($method !== null && $method->key === $methodKey) {
            return $method;
        }

        return null;
    }

    /**
     * @return iterable<int, AdvertisingCategoryCalculationMethod|AdvertisingMediumCalculationMethod>
     */
    private function assignmentCandidates(
        AdvertisingMedium $medium,
        ?CategoryMethodCatalogSnapshot $inheritCatalog,
        ?MediumMethodCatalogSnapshot $overrideCatalog,
    ): iterable {
        if ($medium->calculation_method_mode === CalculationMethodMode::Override) {
            if ($overrideCatalog !== null) {
                yield from $overrideCatalog->assignments;

                return;
            }

            yield from $medium->calculationMethodAssignments;

            return;
        }

        if ($inheritCatalog !== null) {
            yield from $inheritCatalog->assignments;

            return;
        }

        $category = $medium->category;
        if ($category === null) {
            return;
        }

        yield from $category->calculationMethodAssignments;
    }

    private function resolveActiveEngineProfileKey(
        AdvertisingMedium $medium,
        string $methodKey,
        ?CategoryMethodCatalogSnapshot $inheritCatalog,
        ?MediumMethodCatalogSnapshot $overrideCatalog,
    ): ?string {
        if ($medium->calculation_method_mode === CalculationMethodMode::Override) {
            $assignments = $overrideCatalog !== null
                ? $overrideCatalog->assignments
                : $medium->calculationMethodAssignments;

            foreach ($assignments as $assignment) {
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

        $assignments = $inheritCatalog !== null
            ? $inheritCatalog->assignments
            : $medium->category?->calculationMethodAssignments;

        if ($assignments === null) {
            return null;
        }

        foreach ($assignments as $assignment) {
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

    private function methodLabel(string $methodKey): string
    {
        try {
            return SpotCalculationMethod::from($methodKey)->label();
        } catch (ValueError) {
            return $methodKey;
        }
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
