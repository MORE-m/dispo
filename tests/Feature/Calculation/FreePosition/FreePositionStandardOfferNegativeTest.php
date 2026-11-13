<?php

namespace Tests\Feature\Calculation\FreePosition;

use App\Enums\Role;
use App\Models\Calculation;
use App\Models\StandardOffer;
use App\Models\User;
use App\Services\Calculation\CalculationWriter;
use App\Services\StandardOffer\StandardOfferMaterializer;
use App\Services\StandardOffer\StandardOfferSpotClassicBoundary;
use App\Support\Calculation\EngineProfileRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesFreePositionCatalog;
use Tests\TestCase;

/**
 * NATIVE-2: Standardangebote (V2) bleiben ohne Free-Position – kein Öffnen der StandardOffer-Grenze.
 */
class FreePositionStandardOfferNegativeTest extends TestCase
{
    use CreatesFreePositionCatalog;
    use RefreshDatabase;

    protected function tearDown(): void
    {
        EngineProfileRegistry::clearFreePositionPairFakeForTests();
        parent::tearDown();
    }

    #[Test]
    public function free_position_source_calculation_cannot_become_a_standard_offer(): void
    {
        EngineProfileRegistry::fakeFreePositionPairReleasedForTests();
        $ctx = $this->seedFreeContext();
        $calculation = app(CalculationWriter::class)->create($this->payload([
            $this->freePosition($ctx),
        ]), $ctx['user']);

        $this->actingAs($ctx['user'])
            ->post(route('standard-offers.from-calculation', $calculation))
            ->assertSessionHasErrors('positions.0.advertising_medium_id');

        $this->assertSame(0, StandardOffer::query()->count());
        $this->assertStringContainsString(
            'nur Spot Classic',
            (string) session('errors')?->first('positions.0.advertising_medium_id'),
        );

        try {
            app(StandardOfferSpotClassicBoundary::class)->assertSourceCalculation(
                Calculation::query()->findOrFail($calculation->id),
            );
            $this->fail('Boundary muss Free-Quellkalkulation ablehnen.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('positions.0.advertising_medium_id', $exception->errors());
        }
    }

    #[Test]
    public function free_position_draft_payload_is_rejected_by_the_boundary_even_when_spoofed(): void
    {
        EngineProfileRegistry::fakeFreePositionPairReleasedForTests();
        $ctx = $this->seedFreeContext();

        $payload = $this->payload([
            $this->freePosition($ctx, [
                'kind' => 'spot_classic',
                'engine_profile_key' => 'spot_classic',
            ]),
        ]);

        try {
            app(StandardOfferSpotClassicBoundary::class)->assertDraftPayload($payload);
            $this->fail('Boundary muss Free-Draft ablehnen.');
        } catch (ValidationException $exception) {
            $this->assertStringContainsString(
                'nur Spot Classic',
                $exception->errors()['positions.0.advertising_medium_id'][0],
            );
        }

        $pm = User::factory()->role(Role::ProductManagement)->create();
        $this->actingAs($pm)
            ->post(route('standard-offers.store'), $payload + ['title' => 'Free-Vorlage'])
            ->assertSessionHasErrors(); // Standardangebots-Request ist Spot-Average-only.
        $this->assertSame(0, StandardOffer::query()->count());
    }

    #[Test]
    public function materializer_rejects_free_position_resolved_items(): void
    {
        EngineProfileRegistry::fakeFreePositionPairReleasedForTests();
        $ctx = $this->seedFreeContext();

        try {
            app(StandardOfferMaterializer::class)->freeze($this->payload([
                $this->freePosition($ctx),
            ]), $ctx['user']);
            $this->fail('Materializer muss Free ablehnen.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('positions.0', $exception->errors());
            $this->assertStringContainsString('Standardangeboten', $exception->errors()['positions.0'][0]);
        }
    }

    /**
     * @param  list<array<string, mixed>>  $positions
     * @return array<string, mixed>
     */
    private function payload(array $positions): array
    {
        return $this->withLiveSchemaFingerprint([
            'planning_mode' => 'manual',
            'order_discount_percent' => '0',
            'ae_enabled' => false,
            'positions' => $positions,
        ]);
    }
}
