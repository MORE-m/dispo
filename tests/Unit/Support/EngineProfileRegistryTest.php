<?php

namespace Tests\Unit\Support;

use App\Enums\EngineCapabilityStatus;
use App\Support\Calculation\EngineProfileRegistry;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * ADV-001c1: EngineProfileRegistry Mehrversionsvertrag (ohne Runtime-/Handler-Anbindung).
 */
class EngineProfileRegistryTest extends TestCase
{
    #[Test]
    public function spot_classic_average_v1_is_explicitly_current_released(): void
    {
        $this->assertSame(
            EngineCapabilityStatus::Released,
            EngineProfileRegistry::pairStatus(
                EngineProfileRegistry::PROFILE_SPOT_CLASSIC,
                'average',
            ),
        );
        $this->assertSame(
            'v1',
            EngineProfileRegistry::currentReleasedVersion(
                EngineProfileRegistry::PROFILE_SPOT_CLASSIC,
                'average',
            ),
        );
        $this->assertSame(
            EngineCapabilityStatus::Released,
            EngineProfileRegistry::statusForVersion(
                EngineProfileRegistry::PROFILE_SPOT_CLASSIC,
                'average',
                'v1',
            ),
        );
        $this->assertSame(
            ['v1'],
            EngineProfileRegistry::knownAlgorithmVersions(
                EngineProfileRegistry::PROFILE_SPOT_CLASSIC,
                'average',
            ),
        );
    }

    #[Test]
    public function current_released_version_is_explicit_not_array_order(): void
    {
        // Synthetisch: v2 steht vor v1 in versions, current bleibt explizit v1.
        EngineProfileRegistry::validateDefinitions([
            'synthetic_profile' => [
                'average' => [
                    'pair_status' => EngineCapabilityStatus::Released,
                    'current_released_version' => 'v1',
                    'versions' => [
                        'v2' => EngineCapabilityStatus::Implemented,
                        'v1' => EngineCapabilityStatus::Released,
                    ],
                ],
            ],
        ]);

        $this->assertSame(
            'v1',
            EngineProfileRegistry::currentReleasedVersion(
                EngineProfileRegistry::PROFILE_SPOT_CLASSIC,
                'average',
            ),
            'Produktive current_released_version darf nicht von Array-Reihenfolge abhängen.',
        );
    }

    #[Test]
    public function planned_pairs_have_no_current_version_and_no_invented_versions(): void
    {
        foreach (['calendar', 'fixed_price'] as $method) {
            $this->assertSame(
                EngineCapabilityStatus::Planned,
                EngineProfileRegistry::pairStatus(
                    EngineProfileRegistry::PROFILE_SPOT_CLASSIC,
                    $method,
                ),
            );
            $this->assertNull(
                EngineProfileRegistry::currentReleasedVersion(
                    EngineProfileRegistry::PROFILE_SPOT_CLASSIC,
                    $method,
                ),
            );
            $this->assertSame(
                [],
                EngineProfileRegistry::knownAlgorithmVersions(
                    EngineProfileRegistry::PROFILE_SPOT_CLASSIC,
                    $method,
                ),
            );
        }
    }

    #[Test]
    public function historical_version_remains_resolvable_when_configured(): void
    {
        EngineProfileRegistry::validateDefinitions([
            'hist_profile' => [
                'average' => [
                    'pair_status' => EngineCapabilityStatus::Released,
                    'current_released_version' => 'v2',
                    'versions' => [
                        'v1' => EngineCapabilityStatus::Implemented,
                        'v2' => EngineCapabilityStatus::Released,
                    ],
                ],
            ],
        ]);

        // Produktiver Katalog kennt bisher nur v1 – konkret auflösbar.
        $this->assertSame(
            EngineCapabilityStatus::Released,
            EngineProfileRegistry::statusForVersion(
                EngineProfileRegistry::PROFILE_SPOT_CLASSIC,
                'average',
                'v1',
            ),
        );
    }

    #[Test]
    public function unknown_profile_method_and_version_are_fail_closed(): void
    {
        $this->expectException(InvalidArgumentException::class);
        EngineProfileRegistry::pairStatus('unknown_profile', 'average');
    }

    #[Test]
    public function unknown_method_is_fail_closed(): void
    {
        $this->expectException(InvalidArgumentException::class);
        EngineProfileRegistry::pairStatus(
            EngineProfileRegistry::PROFILE_SPOT_CLASSIC,
            'tkp',
        );
    }

    #[Test]
    public function unknown_version_is_fail_closed_without_current_fallback(): void
    {
        $this->expectException(InvalidArgumentException::class);
        EngineProfileRegistry::statusForVersion(
            EngineProfileRegistry::PROFILE_SPOT_CLASSIC,
            'average',
            'v999',
        );
    }

    #[Test]
    public function inconsistent_definitions_are_rejected_fail_closed(): void
    {
        $this->expectException(InvalidArgumentException::class);
        EngineProfileRegistry::validateDefinitions([
            'bad_profile' => [
                'average' => [
                    'pair_status' => EngineCapabilityStatus::Released,
                    'current_released_version' => 'v2',
                    'versions' => [
                        'v1' => EngineCapabilityStatus::Released,
                    ],
                ],
            ],
        ]);
    }

    #[Test]
    public function current_version_must_be_released_in_versions(): void
    {
        $this->expectException(InvalidArgumentException::class);
        EngineProfileRegistry::validateDefinitions([
            'bad_profile' => [
                'average' => [
                    'pair_status' => EngineCapabilityStatus::Released,
                    'current_released_version' => 'v1',
                    'versions' => [
                        'v1' => EngineCapabilityStatus::Implemented,
                    ],
                ],
            ],
        ]);
    }

    #[Test]
    public function productive_catalog_passes_validation_and_exposes_no_handler_api(): void
    {
        EngineProfileRegistry::validateDefinitions([
            EngineProfileRegistry::PROFILE_SPOT_CLASSIC => [
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
        ]);

        $this->assertTrue(EngineProfileRegistry::hasProfile(EngineProfileRegistry::PROFILE_SPOT_CLASSIC));
        $this->assertFalse(EngineProfileRegistry::hasProfile('swf_classic'));
        $this->assertFalse(method_exists(EngineProfileRegistry::class, 'resolveHandler'));
        $this->assertFalse(method_exists(EngineProfileRegistry::class, 'engineClass'));
        $this->assertFalse(method_exists(EngineProfileRegistry::class, 'dispatch'));
        $this->assertFalse(method_exists(EngineProfileRegistry::class, 'preferStatus'));
    }
}
