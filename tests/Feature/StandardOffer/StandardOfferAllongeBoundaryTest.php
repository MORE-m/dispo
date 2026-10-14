<?php

namespace Tests\Feature\StandardOffer;

use App\Enums\Role;
use App\Enums\StandardOfferVersionStatus;
use App\Models\Calculation;
use App\Models\StandardOffer;
use App\Models\User;
use App\Services\StandardOffer\StandardOfferWriter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\Concerns\CreatesAllongeAverageCatalog;
use Tests\Concerns\CreatesSpotClassicCatalog;
use Tests\TestCase;

/**
 * PO-ALLONGE-1 Review: Allonge bleibt aus dem Spot-Classic-Standardangebotsvertrag.
 */
class StandardOfferAllongeBoundaryTest extends TestCase
{
    use CreatesAllongeAverageCatalog;
    use CreatesSpotClassicCatalog;
    use RefreshDatabase;

    public function test_pure_allonge_source_is_rejected_without_a_draft(): void
    {
        $catalog = $this->createAllongeAverageCatalog();
        $sales = User::factory()->role(Role::Sales)->create();
        $calculation = $this->storeAllongeCalculation($catalog, $sales);

        $this->actingAs($sales)
            ->post(route('standard-offers.from-calculation', $calculation))
            ->assertSessionHasErrors('positions.0.advertising_medium_id');

        $this->assertSame(0, StandardOffer::query()->count());
        $this->assertStringContainsString(
            'nur Spot Classic',
            (string) session('errors')?->first('positions.0.advertising_medium_id'),
        );
    }

    public function test_mixed_spot_and_allonge_source_is_rejected_completely(): void
    {
        $allonge = $this->createAllongeAverageCatalog();
        $sales = User::factory()->role(Role::Sales)->create();

        $this->actingAs($sales)->post(route('calculations.store'), $this->withLiveSchemaFingerprint([
            'planning_mode' => 'manual',
            'customer_name' => 'Gemischt GmbH',
            'order_discount_percent' => '0',
            'positions' => [
                $this->spotPosition($allonge),
                $this->allongePosition($allonge['a'], $allonge['allonge'], 10, ['length_seconds' => 15]),
            ],
        ]))->assertRedirect();

        $calculation = Calculation::query()->firstOrFail();
        $this->assertCount(2, $calculation->positions);

        $this->actingAs($sales)
            ->post(route('standard-offers.from-calculation', $calculation))
            ->assertSessionHasErrors('positions.1.advertising_medium_id');

        $this->assertSame(0, StandardOffer::query()->count());
        $this->assertCount(2, $calculation->fresh()->positions);
    }

    public function test_direct_allonge_draft_payload_is_rejected_even_with_spoofed_profile(): void
    {
        $catalog = $this->createAllongeAverageCatalog();
        $pm = User::factory()->role(Role::ProductManagement)->create();
        $payload = $this->withLiveSchemaFingerprint([
            'title' => 'Allonge-Vorlage',
            'order_discount_percent' => '0',
            'positions' => [
                $this->allongePosition($catalog['a'], $catalog['allonge'], 10, [
                    'length_seconds' => 15,
                    'kind' => 'spot_classic',
                    'engine_profile_key' => 'spot_classic',
                ]),
            ],
        ]);

        $this->actingAs($pm)
            ->post(route('standard-offers.store'), $payload)
            ->assertSessionHasErrors('positions.0.advertising_medium_id');
        $this->assertSame(0, StandardOffer::query()->count());

        try {
            app(StandardOfferWriter::class)->create('Allonge-Vorlage', $payload, $pm);
            $this->fail('Manipuliertes Profil darf keinen Entwurf anlegen.');
        } catch (ValidationException $exception) {
            $this->assertStringContainsString(
                'nur Spot Classic',
                $exception->errors()['positions.0.advertising_medium_id'][0],
            );
        }

        $this->assertSame(0, StandardOffer::query()->count());
    }

    public function test_update_and_publish_cannot_bypass_the_spot_classic_boundary(): void
    {
        $catalog = $this->createAllongeAverageCatalog();
        $sales = User::factory()->role(Role::Sales)->create();
        $pm = User::factory()->role(Role::ProductManagement)->create();
        $calculation = $this->storeSpotCalculation($catalog, $sales);
        $offer = app(StandardOfferWriter::class)->createFromCalculation($calculation, $sales);
        $draft = $offer->draftVersion;
        $this->assertNotNull($draft);
        $storedMediumId = (int) $draft->draft_payload['positions'][0]['advertising_medium_id'];

        $bypass = $draft->draft_payload;
        $bypass['positions'][0]['advertising_medium_id'] = $catalog['allonge']->id;
        $bypass['positions'][0]['kind'] = 'spot_classic';
        $bypass['positions'][0]['engine_profile_key'] = 'spot_classic';

        try {
            app(StandardOfferWriter::class)->updateDraft(
                $draft,
                'Unzulässige Allonge',
                $bypass,
                (int) $draft->lock_version,
                $pm,
            );
            $this->fail('Update darf Allonge nicht in den Entwurf schreiben.');
        } catch (ValidationException $exception) {
            $this->assertStringContainsString('nur Spot Classic', $exception->errors()['positions.0.advertising_medium_id'][0]);
        }

        $draft->refresh();
        $this->assertSame($storedMediumId, (int) $draft->draft_payload['positions'][0]['advertising_medium_id']);
        $this->assertSame(StandardOfferVersionStatus::Draft, $draft->status);

        $draft->draft_payload = $bypass;
        $draft->save();

        try {
            app(StandardOfferWriter::class)->publish($draft, (int) $draft->lock_version, $pm);
            $this->fail('Publish darf einen Allonge-Entwurf nicht einfrieren.');
        } catch (ValidationException $exception) {
            $this->assertStringContainsString('nur Spot Classic', $exception->errors()['positions.0.advertising_medium_id'][0]);
        }

        $draft->refresh();
        $this->assertSame(StandardOfferVersionStatus::Draft, $draft->status);
        $this->assertNull($draft->frozen_materialization);
        $this->assertNull($draft->published_at);
    }

    public function test_spot_classic_average_source_remains_usable(): void
    {
        $spot = $this->createSpotClassicCatalog();
        $sales = User::factory()->role(Role::Sales)->create();
        $calculation = $this->storeSpotCalculation($spot, $sales);

        $offer = app(StandardOfferWriter::class)->createFromCalculation($calculation, $sales);

        $this->assertNotNull($offer->draftVersion);
        $this->assertSame(
            $spot['medium']->id,
            (int) $offer->draftVersion->draft_payload['positions'][0]['advertising_medium_id'],
        );
    }

    /**
     * @param  array<string, mixed>  $catalog
     */
    private function storeAllongeCalculation(array $catalog, User $sales): Calculation
    {
        $this->actingAs($sales)->post(route('calculations.store'), $this->allongePayload([
            $this->allongePosition($catalog['a'], $catalog['allonge'], 10, ['length_seconds' => 15]),
        ], ['customer_name' => 'Allonge Quelle GmbH']))->assertRedirect();

        return Calculation::query()->firstOrFail();
    }

    /**
     * @param  array{medium: mixed, hamburg: mixed}  $catalog
     */
    private function storeSpotCalculation(array $catalog, User $sales): Calculation
    {
        $this->actingAs($sales)->post(route('calculations.store'), $this->withLiveSchemaFingerprint([
            'planning_mode' => 'manual',
            'customer_name' => 'Spot Quelle GmbH',
            'order_discount_percent' => '0',
            'positions' => [$this->spotPosition($catalog)],
        ]))->assertRedirect();

        return Calculation::query()->latest('id')->firstOrFail();
    }

    /**
     * @param  array{medium?: mixed, hamburg?: mixed, spot: mixed, a: mixed}  $catalog
     * @return array<string, mixed>
     */
    private function spotPosition(array $catalog): array
    {
        $inventory = $catalog['hamburg'] ?? $catalog['a'];
        $medium = $catalog['medium'] ?? $catalog['spot'];

        return [
            'inventory_id' => $inventory->id,
            'advertising_medium_id' => $medium->id,
            'spot_method' => 'average',
            'length_seconds' => 30,
            'position_discount_percent' => '0',
            'ae_percent' => '0',
            'time_ranges' => [[
                'start_hour' => 8,
                'end_hour_exclusive' => 9,
                'day_group' => 'mo_fr',
                'spot_count' => 10,
            ]],
        ];
    }
}
