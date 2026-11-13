<?php

namespace Tests\Unit\Calculation\FreePosition;

use App\Enums\EngineCapabilityStatus;
use App\Models\CalculationPosition;
use App\Services\Calculation\FreePosition\FreePositionWriterPositionResolver;
use App\Support\Calculation\EngineProfileRegistry;
use App\Support\Calculation\FreePositionHttpReleaseGuard;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * NATIVE-2: Dispatch-Signale, Fremdfeld-Regeln und Registry-Fake/Guard (ohne DB).
 */
class FreePositionWriterPositionResolverTest extends TestCase
{
    protected function tearDown(): void
    {
        EngineProfileRegistry::clearFreePositionPairFakeForTests();
        parent::tearDown();
    }

    #[Test]
    public function payload_signals_only_for_free_position_key_or_spot_method_alias(): void
    {
        $this->assertTrue(FreePositionWriterPositionResolver::payloadSignals(['calculation_method_key' => 'free_position']));
        $this->assertTrue(FreePositionWriterPositionResolver::payloadSignals(['spot_method' => 'free_position']));
        $this->assertFalse(FreePositionWriterPositionResolver::payloadSignals(['calculation_method_key' => 'tkp']));
        $this->assertFalse(FreePositionWriterPositionResolver::payloadSignals(['spot_method' => 'average']));
        $this->assertFalse(FreePositionWriterPositionResolver::payloadSignals([]));
        $this->assertFalse(FreePositionWriterPositionResolver::payloadSignals(['spot_method' => null]));
    }

    #[Test]
    public function stored_requires_profile_and_method_key(): void
    {
        $this->assertFalse(FreePositionWriterPositionResolver::isStored(null));

        $free = new CalculationPosition;
        $free->engine_profile_key = 'free_position';
        $free->calculation_method_key = 'free_position';
        $this->assertTrue(FreePositionWriterPositionResolver::isStored($free));

        $oa = new CalculationPosition;
        $oa->engine_profile_key = 'oa_tkp';
        $oa->calculation_method_key = 'tkp';
        $this->assertFalse(FreePositionWriterPositionResolver::isStored($oa));

        $mixed = new CalculationPosition;
        $mixed->engine_profile_key = 'free_position';
        $mixed->calculation_method_key = 'average';
        $this->assertFalse(FreePositionWriterPositionResolver::isStored($mixed));
    }

    #[Test]
    public function request_dispatch_prefers_payload_intent_then_stored_freeze(): void
    {
        $free = new CalculationPosition;
        $free->engine_profile_key = 'free_position';
        $free->calculation_method_key = 'free_position';

        $this->assertTrue(FreePositionWriterPositionResolver::isFreePositionRequest(
            ['calculation_method_key' => 'free_position'],
            null,
        ));
        // Unveränderter Freeze ohne Methodenangabe
        $this->assertTrue(FreePositionWriterPositionResolver::isFreePositionRequest([], $free));
        $this->assertTrue(FreePositionWriterPositionResolver::isFreePositionRequest(['spot_method' => null], $free));
        // Methodenwechsel weg von Free wird nicht als Free-Request geroutet (Writer blockt separat).
        $this->assertFalse(FreePositionWriterPositionResolver::isFreePositionRequest(['spot_method' => 'average'], $free));
        $this->assertFalse(FreePositionWriterPositionResolver::isFreePositionRequest(['spot_method' => 'tkp'], $free));
        $this->assertFalse(FreePositionWriterPositionResolver::isFreePositionRequest([], null));
    }

    #[Test]
    public function foreign_field_rules_allow_reload_neutral_values_and_reject_real_values(): void
    {
        $this->assertSame([], FreePositionWriterPositionResolver::foreignFieldViolations([
            'free_price' => '5000.00',
            'plan_rows' => [],
            'time_ranges' => [],
            'planner_entries' => [],
            'components' => [],
            'production_lines' => [],
            'oa_platform_allocations' => [],
            'oa_targeting_selections' => [],
            'total_spot_count' => 0,
            'pricing_settlement_mode' => 'normal',
            'fixed_price_nn' => null,
            'basis_tkp' => null,
            'impressions' => null,
            'component_calculation_strategy' => null,
            'component_profile' => '',
        ]));

        $violations = FreePositionWriterPositionResolver::foreignFieldViolations([
            'plan_rows' => [['hour' => 8]],
            'basis_tkp' => '10',
            'impressions' => 5,
            'price_year' => 2026,
            'expected_oa_tkp_price_list_id' => 3,
            'total_spot_count' => 2,
            'pricing_settlement_mode' => 'fixed_price',
            'fixed_price_nn' => '100.00',
        ]);
        foreach ([
            'plan_rows', 'basis_tkp', 'impressions', 'price_year',
            'expected_oa_tkp_price_list_id', 'total_spot_count', 'pricing_settlement_mode', 'fixed_price_nn',
        ] as $field) {
            $this->assertArrayHasKey($field, $violations);
        }
    }

    #[Test]
    public function registry_fake_is_test_only_scoped_and_leaves_catalog_planned(): void
    {
        $profile = EngineProfileRegistry::PROFILE_FREE_POSITION;
        $this->assertFalse(FreePositionHttpReleaseGuard::pairIsReleased());
        $this->assertSame(EngineCapabilityStatus::Planned, EngineProfileRegistry::pairStatus($profile, 'free_position'));

        EngineProfileRegistry::fakeFreePositionPairReleasedForTests();
        $this->assertTrue(FreePositionHttpReleaseGuard::pairIsReleased());
        $this->assertSame('v1', EngineProfileRegistry::currentReleasedVersion($profile, 'free_position'));
        $this->assertSame(EngineCapabilityStatus::Released, EngineProfileRegistry::statusForVersion($profile, 'free_position', 'v1'));
        // Andere Paare bleiben unberührt.
        $this->assertSame(
            EngineCapabilityStatus::Planned,
            EngineProfileRegistry::pairStatus(EngineProfileRegistry::PROFILE_SPOT_CLASSIC, 'fixed_price'),
        );

        EngineProfileRegistry::clearFreePositionPairFakeForTests();
        $this->assertFalse(FreePositionHttpReleaseGuard::pairIsReleased());
        $this->assertNull(EngineProfileRegistry::currentReleasedVersion($profile, 'free_position'));
    }

    #[Test]
    public function http_guard_blocks_payload_signal_until_release_and_budget_guard_always_blocks(): void
    {
        $payload = ['positions' => [['calculation_method_key' => 'free_position']]];

        try {
            FreePositionHttpReleaseGuard::assertPreviewAllowed($payload);
            $this->fail('Preview ohne Release muss blockiert sein.');
        } catch (ValidationException $exception) {
            $this->assertSame(FreePositionHttpReleaseGuard::MESSAGE, $exception->errors()['positions'][0]);
        }
        try {
            FreePositionHttpReleaseGuard::assertMutationAllowed($payload);
            $this->fail('Mutation ohne Release muss blockiert sein.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('positions', $exception->errors());
        }

        // Nicht-Free-Payload bleibt unberührt.
        FreePositionHttpReleaseGuard::assertPreviewAllowed(['positions' => [['spot_method' => 'average']]]);

        EngineProfileRegistry::fakeFreePositionPairReleasedForTests();
        FreePositionHttpReleaseGuard::assertPreviewAllowed($payload);
        FreePositionHttpReleaseGuard::assertMutationAllowed($payload);

        // Budget bleibt auch bei Release fail-closed.
        $this->expectException(ValidationException::class);
        FreePositionHttpReleaseGuard::assertBudgetNotFreePosition($payload);
    }
}
