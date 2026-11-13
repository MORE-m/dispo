<?php

namespace Tests\Feature\Calculation\FreePosition;

use App\Enums\CalculationKind;
use App\Models\AdvertisingMedium;
use App\Models\Calculation;
use App\Services\Calculation\CalculationWriter;
use App\Support\Calculation\EngineProfileRegistry;
use App\Support\Calculation\FreePositionHttpReleaseGuard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesFreePositionCatalog;
use Tests\TestCase;

/**
 * NATIVE-2: HTTP Preview/Create/Update/Reload für Free-Position über reguläre Controller.
 *
 * Produktiv ist das Pair planned (HTTP blockiert); Release wird nur über den Registry-Test-Fake simuliert.
 */
class FreePositionHttpCreateUpdateTest extends TestCase
{
    use CreatesFreePositionCatalog;
    use RefreshDatabase;

    protected function tearDown(): void
    {
        EngineProfileRegistry::clearFreePositionPairFakeForTests();
        parent::tearDown();
    }

    #[Test]
    public function without_fake_preview_store_and_update_are_blocked(): void
    {
        $ctx = $this->seedFreeContext();

        $this->assertFalse(FreePositionHttpReleaseGuard::pairIsReleased());

        $this->actingAs($ctx['user'])
            ->postJson(route('calculations.preview'), $this->payload([$this->freePosition($ctx)]))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['positions']);

        $this->actingAs($ctx['user'])
            ->postJson(route('calculations.store'), $this->payload([$this->freePosition($ctx)]))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['positions']);
        $this->assertSame(0, Calculation::query()->count());

        // Bestand mit Free bleibt auch bei Update/Preview gesperrt, sobald das Pair nicht released ist.
        EngineProfileRegistry::fakeFreePositionPairReleasedForTests();
        $this->actingAs($ctx['user'])
            ->post(route('calculations.store'), $this->payload([$this->freePosition($ctx)]))
            ->assertRedirect();
        $calculation = Calculation::query()->firstOrFail();
        EngineProfileRegistry::clearFreePositionPairFakeForTests();

        $update = $this->httpUpdatePayload($calculation);
        $update['positions'][0]['free_price'] = '7000';
        $this->actingAs($ctx['user'])
            ->putJson(route('calculations.update', $calculation), $update)
            ->assertStatus(422)
            ->assertJsonValidationErrors(['positions']);

        // Auch ein Header-only-Update (ohne positions) trifft den Bestands-Guard.
        $this->actingAs($ctx['user'])
            ->putJson(route('calculations.update', $calculation), [
                'lock_version' => $calculation->lock_version,
                'schema_fingerprint' => $this->httpUpdatePayload($calculation)['schema_fingerprint'],
                'planning_mode' => 'manual',
                'order_discount_percent' => '0',
                'campaign' => 'Neu',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['positions']);

        $this->actingAs($ctx['user'])
            ->postJson(route('calculations.preview'), [
                'calculation_id' => $calculation->id,
                'planning_mode' => 'manual',
                'order_discount_percent' => '0',
                'positions' => [],
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['positions']);

        $fresh = $calculation->fresh();
        $this->assertSame(1, (int) $fresh->lock_version);
        $this->assertSame('5000.00', (string) $fresh->positions->firstOrFail()->media_gross);
    }

    #[Test]
    public function with_fake_preview_returns_totals_and_method_result(): void
    {
        EngineProfileRegistry::fakeFreePositionPairReleasedForTests();
        $ctx = $this->seedFreeContext();

        $response = $this->actingAs($ctx['user'])->postJson(
            route('calculations.preview'),
            $this->payload(
                [$this->freePosition($ctx, ['position_discount_percent' => '10'])],
                ['order_discount_percent' => '20', 'ae_enabled' => true],
            ),
        );

        $response->assertOk();
        $this->assertSame('3060.00', $response->json('totals.nn_invest'));
        $this->assertSame('5000.00', $response->json('totals.media_gross'));
        $this->assertSame('3060.00', $response->json('totals.positions.0.nn_invest'));
        $this->assertSame('free_position', $response->json('totals.positions.0.method_result.method'));
        $this->assertSame('5000.00', $response->json('totals.positions.0.method_result.free_position.free_price'));
        $this->assertSame(0, Calculation::query()->count());
    }

    #[Test]
    public function with_fake_create_update_and_reload_persist_native_ad_as_free_position(): void
    {
        EngineProfileRegistry::fakeFreePositionPairReleasedForTests();
        $ctx = $this->seedFreeContext();
        $this->assertSame('native_ad', $ctx['medium']->code);
        $this->assertSame(CalculationKind::FreePosition, $ctx['medium']->kind);

        $response = $this->actingAs($ctx['user'])->post(
            route('calculations.store'),
            $this->payload(
                [$this->freePosition($ctx, [
                    'position_discount_percent' => '10',
                    // nicht autoritativ
                    'media_gross' => '1.00',
                    'is_discountable' => false,
                    'is_ae_eligible' => false,
                ])],
                ['order_discount_percent' => '20', 'ae_enabled' => true],
            ),
        );

        $calculation = Calculation::query()->firstOrFail();
        $response->assertRedirect(route('calculations.edit', $calculation));

        $position = $calculation->positions()->firstOrFail();
        $this->assertSame('native_ad', AdvertisingMedium::query()->findOrFail($position->advertising_medium_id)->code);
        $this->assertSame('free_position', $position->engine_profile_key);
        $this->assertSame('free_position', $position->calculation_method_key);
        $this->assertSame('v1', $position->algorithm_version);
        $this->assertSame('free_position', $position->kind->value);
        $this->assertSame('5000.00', (string) $position->media_gross);
        $this->assertTrue((bool) $position->is_discountable);
        $this->assertTrue((bool) $position->is_ae_eligible);
        $this->assertSame('3060.00', (string) $position->nn_invest);
        $this->assertSame('3060.00', (string) $calculation->nn_invest);
        $this->assertSame(0, (int) $position->length_seconds);

        // Reload liefert free_price = gespeichertes media_gross.
        $reloaded = app(CalculationWriter::class)->payloadFromCalculation($calculation->fresh());
        $this->assertSame('5000.00', $reloaded['positions'][0]['free_price']);
        $this->actingAs($ctx['user'])->get(route('calculations.edit', $calculation))->assertOk();

        // Update 5000 → 7000
        $payload = $this->httpUpdatePayload($calculation);
        $payload['positions'][0]['free_price'] = '7000';
        $payload['positions'][0]['media_gross'] = '2.00';
        $this->actingAs($ctx['user'])
            ->putJson(route('calculations.update', $calculation), $payload)
            ->assertRedirect(route('calculations.edit', $calculation));

        $fresh = $calculation->fresh('positions');
        $this->assertSame(2, (int) $fresh->lock_version);
        $this->assertSame('7000.00', (string) $fresh->positions->firstOrFail()->media_gross);
        $this->assertSame('4284.00', (string) $fresh->nn_invest);
        $this->assertSame(
            '7000.00',
            app(CalculationWriter::class)->payloadFromCalculation($fresh)['positions'][0]['free_price'],
        );

        // Reload-Roundtrip ohne Änderung bleibt stabil.
        $same = $this->httpUpdatePayload($fresh);
        $this->actingAs($ctx['user'])
            ->putJson(route('calculations.update', $fresh), $same)
            ->assertRedirect();
        $again = $fresh->fresh('positions');
        $this->assertSame('7000.00', (string) $again->positions->firstOrFail()->media_gross);
        $this->assertSame('4284.00', (string) $again->nn_invest);
    }

    #[Test]
    public function free_price_is_required_and_invalid_values_are_rejected(): void
    {
        EngineProfileRegistry::fakeFreePositionPairReleasedForTests();
        $ctx = $this->seedFreeContext();

        $missing = $this->freePosition($ctx);
        unset($missing['free_price']);

        foreach ([
            'missing' => $missing,
            'blank' => $this->freePosition($ctx, ['free_price' => '']),
            'zero' => $this->freePosition($ctx, ['free_price' => '0']),
            'negative' => $this->freePosition($ctx, ['free_price' => '-1']),
            'text' => $this->freePosition($ctx, ['free_price' => 'abc']),
            'three decimals' => $this->freePosition($ctx, ['free_price' => '1.234']),
        ] as $label => $position) {
            $this->actingAs($ctx['user'])
                ->postJson(route('calculations.store'), $this->payload([$position]))
                ->assertStatus(422)
                ->assertJsonValidationErrors(['positions.0.free_price']);
            $this->actingAs($ctx['user'])
                ->postJson(route('calculations.preview'), $this->payload([$position]))
                ->assertStatus(422)
                ->assertJsonValidationErrors(['positions.0.free_price']);
            $this->assertSame(0, Calculation::query()->count(), $label);
        }
    }

    #[Test]
    public function free_price_is_rejected_on_non_free_methods_and_foreign_fields_on_free(): void
    {
        EngineProfileRegistry::fakeFreePositionPairReleasedForTests();
        $ctx = $this->seedFreeContext();
        $spot = $this->createFreeSpotFixture();

        $spotPosition = $this->freeSpotPosition($spot);
        $spotPosition['free_price'] = '100.00';
        $this->actingAs($ctx['user'])
            ->postJson(route('calculations.store'), $this->payload([$spotPosition]))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['positions.0.free_price']);

        foreach ([
            'plan_rows' => [['hour' => 8, 'day_group' => 'mo_fr']],
            'basis_tkp' => '10.0000',
            'impressions' => 1000,
            'fixed_price_nn' => '100.00',
            'oa_platform_allocations' => [['platform_code' => 'x', 'impressions' => 1]],
        ] as $field => $value) {
            $this->actingAs($ctx['user'])
                ->postJson(route('calculations.store'), $this->payload([
                    $this->freePosition($ctx, [$field => $value]),
                ]))
                ->assertStatus(422)
                ->assertJsonValidationErrors(["positions.0.{$field}"]);
        }
        $this->assertSame(0, Calculation::query()->count());
    }

    #[Test]
    public function http_update_method_switch_from_free_is_blocked(): void
    {
        EngineProfileRegistry::fakeFreePositionPairReleasedForTests();
        $ctx = $this->seedFreeContext();
        $spot = $this->createFreeSpotFixture();

        $this->actingAs($ctx['user'])
            ->post(route('calculations.store'), $this->payload([$this->freePosition($ctx)]))
            ->assertRedirect();
        $calculation = Calculation::query()->firstOrFail();

        $payload = $this->httpUpdatePayload($calculation);
        $payload['positions'][0] = array_merge($payload['positions'][0], [
            'inventory_id' => $spot['inventory']->id,
            'advertising_medium_id' => $spot['medium']->id,
            'spot_method' => 'average',
            'calculation_method_key' => 'average',
            'length_seconds' => 30,
            'total_spot_count' => 10,
            'plan_rows' => [['hour' => 8, 'day_group' => 'mo_fr']],
        ]);
        unset($payload['positions'][0]['free_price']);
        $payload['positions'][0]['schema_fingerprint'] = $this->positionSchemaFingerprintFor(
            $calculation,
            (int) $spot['medium']->id,
        );

        $this->actingAs($ctx['user'])
            ->putJson(route('calculations.update', $calculation), $payload)
            ->assertStatus(422);

        $fresh = $calculation->fresh('positions');
        $this->assertSame(1, (int) $fresh->lock_version);
        $this->assertSame('free_position', $fresh->positions->firstOrFail()->engine_profile_key);
    }

    /**
     * @param  list<array<string, mixed>>  $positions
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function payload(array $positions, array $overrides = []): array
    {
        return $this->withLiveSchemaFingerprint(array_merge([
            'planning_mode' => 'manual',
            'order_discount_percent' => '0',
            'ae_enabled' => false,
            'positions' => $positions,
        ], $overrides));
    }

    /**
     * @return array<string, mixed>
     */
    private function httpUpdatePayload(Calculation $calculation): array
    {
        $payload = app(CalculationWriter::class)->payloadFromCalculation($calculation->fresh());
        $payload['lock_version'] = $calculation->lock_version;
        foreach ($payload['positions'] as $index => $position) {
            unset(
                $payload['positions'][$index]['engine_profile_key'],
                $payload['positions'][$index]['length_seconds'],
                $payload['positions'][$index]['total_spot_count'],
            );
            if (empty($payload['positions'][$index]['client_key'])) {
                $payload['positions'][$index]['client_key'] = (string) str()->uuid();
            }
        }
        $payload['positions'] = $this->withPositionSchemaFingerprints($calculation, $payload['positions']);

        return $payload;
    }
}
