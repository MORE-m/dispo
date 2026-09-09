<?php

namespace App\Support\Calculation;

use App\Enums\EngineCapabilityStatus;
use InvalidArgumentException;

/**
 * ADV-001c1: rein codebasierte, nicht administrierbare Engine-Capability-Registry.
 *
 * Späterer Runtime-Dispatch:
 * (engine_profile_key, calculation_method_key, algorithm_version)
 *
 * Mehrversionsmodell je Profil-/Methodenpaar:
 * - pair_status (für neue Vorgänge)
 * - current_released_version (explizit, nie aus Array-Reihenfolge)
 * - versions[version] => status
 *
 * In diesem Slice nicht an CatalogResolver / CalculationWriter angebunden.
 * Keine Handler-Auflösung.
 */
final class EngineProfileRegistry
{
    public const PROFILE_SPOT_CLASSIC = 'spot_classic';

    /**
     * Kanonischer Katalog. Altversionen dürfen später nicht entfernt werden.
     *
     * @var array<string, array<string, array{
     *     pair_status: EngineCapabilityStatus,
     *     current_released_version: string|null,
     *     versions: array<string, EngineCapabilityStatus>
     * }>>
     */
    private const CATALOG = [
        self::PROFILE_SPOT_CLASSIC => [
            'average' => [
                'pair_status' => EngineCapabilityStatus::Released,
                'current_released_version' => 'v1',
                'versions' => [
                    'v1' => EngineCapabilityStatus::Released,
                ],
            ],
            'calendar' => [
                'pair_status' => EngineCapabilityStatus::Planned,
                'current_released_version' => null,
                'versions' => [],
            ],
            'fixed_price' => [
                'pair_status' => EngineCapabilityStatus::Planned,
                'current_released_version' => null,
                'versions' => [],
            ],
        ],
    ];

    public static function hasProfile(string $engineProfileKey): bool
    {
        return array_key_exists($engineProfileKey, self::catalog());
    }

    public static function hasMethodForProfile(string $engineProfileKey, string $calculationMethodKey): bool
    {
        $catalog = self::catalog();

        return isset($catalog[$engineProfileKey][$calculationMethodKey]);
    }

    /**
     * Fachlicher Pair-Status für neue Vorgänge.
     */
    public static function pairStatus(string $engineProfileKey, string $calculationMethodKey): EngineCapabilityStatus
    {
        return self::pair($engineProfileKey, $calculationMethodKey)['pair_status'];
    }

    /**
     * Explizite aktuelle freigegebene Algorithmusversion (nie aus Reihenfolge abgeleitet).
     */
    public static function currentReleasedVersion(string $engineProfileKey, string $calculationMethodKey): ?string
    {
        return self::pair($engineProfileKey, $calculationMethodKey)['current_released_version'];
    }

    /**
     * @return list<string>
     */
    public static function knownAlgorithmVersions(string $engineProfileKey, string $calculationMethodKey): array
    {
        return array_keys(self::pair($engineProfileKey, $calculationMethodKey)['versions']);
    }

    /**
     * Fail-closed Auflösung einer konkreten historischen Version.
     * Unbekannte Versionen werfen – kein stiller Fallback.
     */
    public static function statusForVersion(
        string $engineProfileKey,
        string $calculationMethodKey,
        string $algorithmVersion,
    ): EngineCapabilityStatus {
        $pair = self::pair($engineProfileKey, $calculationMethodKey);
        if (! array_key_exists($algorithmVersion, $pair['versions'])) {
            throw new InvalidArgumentException(
                "Unbekannte algorithm_version „{$algorithmVersion}“ für "
                ."{$engineProfileKey}/{$calculationMethodKey} (fail-closed, kein Fallback).",
            );
        }

        return $pair['versions'][$algorithmVersion];
    }

    public static function assertKnownProfile(string $engineProfileKey): void
    {
        if (! self::hasProfile($engineProfileKey)) {
            throw new InvalidArgumentException(
                "Unbekanntes engine_profile_key „{$engineProfileKey}“ (fail-closed).",
            );
        }
    }

    public static function assertKnownMethodForProfile(string $engineProfileKey, string $calculationMethodKey): void
    {
        if (! self::hasMethodForProfile($engineProfileKey, $calculationMethodKey)) {
            throw new InvalidArgumentException(
                "Unbekannte calculation_method_key „{$calculationMethodKey}“ für Profil "
                ."„{$engineProfileKey}“ (fail-closed).",
            );
        }
    }

    /**
     * Zentrale Invariantenprüfung für Katalogdefinitionen (auch für Tests mit
     * synthetischen Strukturen). Wirft bei Inkonsistenz – fail-closed.
     *
     * @param  array<mixed>  $definitions
     */
    public static function validateDefinitions(array $definitions): void
    {
        foreach ($definitions as $profileKey => $methods) {
            if (! is_string($profileKey) || $profileKey === '' || ! is_array($methods)) {
                throw new InvalidArgumentException(
                    'ADV-001c1 Registry: ungültiger engine_profile_key oder Methodenblock.',
                );
            }

            foreach ($methods as $methodKey => $pair) {
                if (! is_string($methodKey) || $methodKey === '' || ! is_array($pair)) {
                    throw new InvalidArgumentException(
                        "ADV-001c1 Registry: ungültige Methode unter Profil „{$profileKey}“.",
                    );
                }

                if (! isset($pair['pair_status']) || ! $pair['pair_status'] instanceof EngineCapabilityStatus) {
                    throw new InvalidArgumentException(
                        "ADV-001c1 Registry: pair_status fehlt/ungültig für {$profileKey}/{$methodKey}.",
                    );
                }

                if (! array_key_exists('current_released_version', $pair)) {
                    throw new InvalidArgumentException(
                        "ADV-001c1 Registry: current_released_version fehlt für {$profileKey}/{$methodKey}.",
                    );
                }

                $current = $pair['current_released_version'];
                if ($current !== null && (! is_string($current) || $current === '')) {
                    throw new InvalidArgumentException(
                        "ADV-001c1 Registry: current_released_version muss string|null sein ({$profileKey}/{$methodKey}).",
                    );
                }

                if (! isset($pair['versions']) || ! is_array($pair['versions'])) {
                    throw new InvalidArgumentException(
                        "ADV-001c1 Registry: versions fehlt/ungültig für {$profileKey}/{$methodKey}.",
                    );
                }

                $seenVersions = [];
                foreach ($pair['versions'] as $versionKey => $versionStatus) {
                    if (! is_string($versionKey) || $versionKey === '') {
                        throw new InvalidArgumentException(
                            "ADV-001c1 Registry: leerer/ungültiger Versionskey für {$profileKey}/{$methodKey}.",
                        );
                    }
                    if (isset($seenVersions[$versionKey])) {
                        throw new InvalidArgumentException(
                            "ADV-001c1 Registry: doppelte Version „{$versionKey}“ für {$profileKey}/{$methodKey}.",
                        );
                    }
                    $seenVersions[$versionKey] = true;

                    if (! $versionStatus instanceof EngineCapabilityStatus) {
                        throw new InvalidArgumentException(
                            "ADV-001c1 Registry: Versionsstatus ungültig für {$profileKey}/{$methodKey}/{$versionKey}.",
                        );
                    }
                }

                if ($current !== null) {
                    if (! array_key_exists($current, $pair['versions'])) {
                        throw new InvalidArgumentException(
                            "ADV-001c1 Registry: current_released_version „{$current}“ fehlt in versions "
                            ."({$profileKey}/{$methodKey}).",
                        );
                    }
                    if ($pair['versions'][$current] !== EngineCapabilityStatus::Released) {
                        throw new InvalidArgumentException(
                            "ADV-001c1 Registry: aktuelle Version „{$current}“ muss released sein "
                            ."({$profileKey}/{$methodKey}).",
                        );
                    }
                    if ($pair['pair_status'] !== EngineCapabilityStatus::Released) {
                        throw new InvalidArgumentException(
                            'ADV-001c1 Registry: pair_status muss released sein, wenn current_released_version gesetzt ist '
                            ."({$profileKey}/{$methodKey}).",
                        );
                    }
                } elseif ($pair['pair_status'] === EngineCapabilityStatus::Released) {
                    throw new InvalidArgumentException(
                        'ADV-001c1 Registry: pair_status=released erfordert current_released_version '
                        ."({$profileKey}/{$methodKey}).",
                    );
                }
            }
        }
    }

    /**
     * @return array{
     *     pair_status: EngineCapabilityStatus,
     *     current_released_version: string|null,
     *     versions: array<string, EngineCapabilityStatus>
     * }
     */
    private static function pair(string $engineProfileKey, string $calculationMethodKey): array
    {
        self::assertKnownProfile($engineProfileKey);
        self::assertKnownMethodForProfile($engineProfileKey, $calculationMethodKey);

        return self::catalog()[$engineProfileKey][$calculationMethodKey];
    }

    /**
     * @return array<string, array<string, array{
     *     pair_status: EngineCapabilityStatus,
     *     current_released_version: string|null,
     *     versions: array<string, EngineCapabilityStatus>
     * }>>
     */
    private static function catalog(): array
    {
        static $validated = false;
        if (! $validated) {
            self::validateDefinitions(self::CATALOG);
            $validated = true;
        }

        return self::CATALOG;
    }
}
