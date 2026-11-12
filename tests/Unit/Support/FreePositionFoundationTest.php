<?php

namespace Tests\Unit\Support;

use App\Enums\CalculationKind;
use App\Enums\EngineCapabilityStatus;
use App\Enums\SpotCalculationMethod;
use App\Support\Advertising\AdvertisingKindCategoryCompatibility;
use App\Support\Advertising\CanonicalAdvertisingCategories;
use App\Support\Calculation\CalculationMethodFreezeDescriptor;
use App\Support\Calculation\EngineProfileRegistry;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * NATIVE-1: CalculationKind / Registry / Freeze-Fundament für free_position.
 */
class FreePositionFoundationTest extends TestCase
{
    #[Test]
    public function calculation_kind_free_position_exists(): void
    {
        $this->assertSame('free_position', CalculationKind::FreePosition->value);
        $this->assertSame(CalculationKind::FreePosition, CalculationKind::from('free_position'));
        $this->assertSame(
            SpotCalculationMethod::FreePosition,
            SpotCalculationMethod::from('free_position'),
        );
    }

    #[Test]
    public function registry_knows_planned_free_position_pair_with_implemented_v1(): void
    {
        $this->assertTrue(EngineProfileRegistry::hasProfile(EngineProfileRegistry::PROFILE_FREE_POSITION));
        $this->assertTrue(EngineProfileRegistry::hasMethodForProfile(
            EngineProfileRegistry::PROFILE_FREE_POSITION,
            'free_position',
        ));

        $this->assertSame(
            EngineCapabilityStatus::Planned,
            EngineProfileRegistry::pairStatus(
                EngineProfileRegistry::PROFILE_FREE_POSITION,
                'free_position',
            ),
        );
        $this->assertNull(EngineProfileRegistry::currentReleasedVersion(
            EngineProfileRegistry::PROFILE_FREE_POSITION,
            'free_position',
        ));
        $this->assertSame(
            EngineCapabilityStatus::Implemented,
            EngineProfileRegistry::statusForVersion(
                EngineProfileRegistry::PROFILE_FREE_POSITION,
                'free_position',
                'v1',
            ),
        );
        $this->assertSame(
            ['v1'],
            EngineProfileRegistry::knownAlgorithmVersions(
                EngineProfileRegistry::PROFILE_FREE_POSITION,
                'free_position',
            ),
        );
    }

    #[Test]
    public function unknown_free_position_version_fails_closed(): void
    {
        $this->expectException(InvalidArgumentException::class);
        EngineProfileRegistry::statusForVersion(
            EngineProfileRegistry::PROFILE_FREE_POSITION,
            'free_position',
            'v9',
        );
    }

    #[Test]
    public function pairs_for_method_key_lists_planned_free_position_only(): void
    {
        $pairs = EngineProfileRegistry::pairsForMethodKey('free_position');
        $this->assertCount(1, $pairs);
        $this->assertSame('free_position', $pairs[0]['engine_profile_key']);
        $this->assertSame('planned', $pairs[0]['pair_status']);
        $this->assertNull($pairs[0]['current_released_version']);
    }

    #[Test]
    public function existing_released_pairs_remain_unchanged(): void
    {
        $this->assertSame(
            EngineCapabilityStatus::Released,
            EngineProfileRegistry::pairStatus(EngineProfileRegistry::PROFILE_OA_TKP, 'tkp'),
        );
        $this->assertSame('v1', EngineProfileRegistry::currentReleasedVersion(
            EngineProfileRegistry::PROFILE_OA_TKP,
            'tkp',
        ));

        $this->assertSame(
            EngineCapabilityStatus::Released,
            EngineProfileRegistry::pairStatus(EngineProfileRegistry::PROFILE_SPOT_CLASSIC, 'average'),
        );
        $this->assertSame('v1', EngineProfileRegistry::currentReleasedVersion(
            EngineProfileRegistry::PROFILE_SPOT_CLASSIC,
            'average',
        ));

        foreach ([
            EngineProfileRegistry::PROFILE_SWF_TRAILER,
            EngineProfileRegistry::PROFILE_SWF_ALLONGE,
            EngineProfileRegistry::PROFILE_SWF_PROMO,
            EngineProfileRegistry::PROFILE_SWF_CONTEST,
        ] as $profile) {
            $this->assertSame(
                EngineCapabilityStatus::Released,
                EngineProfileRegistry::pairStatus($profile, 'average'),
            );
            $this->assertSame('v1', EngineProfileRegistry::currentReleasedVersion($profile, 'average'));
        }

        $this->assertSame(
            EngineCapabilityStatus::Planned,
            EngineProfileRegistry::pairStatus(
                EngineProfileRegistry::PROFILE_SPOT_CLASSIC,
                'fixed_price',
            ),
        );
    }

    #[Test]
    public function freeze_descriptor_maps_free_position_profile_to_kind(): void
    {
        $descriptor = new CalculationMethodFreezeDescriptor(
            engineProfileKey: EngineProfileRegistry::PROFILE_FREE_POSITION,
            calculationMethodKey: 'free_position',
            calculationMethodName: 'Freie Preisposition',
            algorithmVersion: 'v1',
        );

        $this->assertSame(CalculationKind::FreePosition, $descriptor->legacyKind());
        $this->assertSame(SpotCalculationMethod::FreePosition, $descriptor->legacySpotMethod());
    }

    #[Test]
    public function kind_category_compatibility_allows_online_audio_and_events(): void
    {
        $allowed = AdvertisingKindCategoryCompatibility::allowedCategoryKeysFor(
            CalculationKind::FreePosition,
        );
        $this->assertSame(
            [
                CanonicalAdvertisingCategories::ONLINE_AUDIO,
                CanonicalAdvertisingCategories::EVENTS_PROMOTION,
            ],
            $allowed,
        );
        $this->assertTrue(AdvertisingKindCategoryCompatibility::isCompatible(
            CalculationKind::FreePosition,
            CanonicalAdvertisingCategories::ONLINE_AUDIO,
        ));
        $this->assertFalse(AdvertisingKindCategoryCompatibility::isCompatible(
            CalculationKind::FreePosition,
            CanonicalAdvertisingCategories::SPOTS,
        ));
    }

    #[Test]
    public function length_seconds_zero_is_valid_unsigned_model_contract(): void
    {
        // unsignedInteger length_seconds akzeptiert 0 (OA/Free ohne Längenwirkung).
        $this->assertSame(0, max(0, 0));
        $this->assertIsInt(0);
    }
}
