<?php

namespace Tests\Feature\StandardOffer;

use App\Enums\ComponentCalculationStrategy;
use App\Enums\Role;
use App\Enums\SpotCalculationMethod;
use App\Enums\StandardOfferVersionStatus;
use App\Models\AuditEvent;
use App\Models\Calculation;
use App\Models\Inventory;
use App\Models\InventoryMediumRule;
use App\Models\StandardOffer;
use App\Models\User;
use App\Services\Calculation\CalculationWriter;
use App\Services\StandardOffer\StandardOfferMaterializer;
use App\Services\StandardOffer\StandardOfferWriter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\CreatesSpotClassicCatalog;
use Tests\TestCase;

/**
 * BL-P4-03b / PO-BLP403B-1 / STD-001–STD-009 / AUTH-006/007 / VER-004 / SPT-014.
 */
class StandardOfferBlP403bTest extends TestCase
{
    use CreatesSpotClassicCatalog;
    use RefreshDatabase;

    public function test_sales_proposes_average_draft_without_customer_data_and_without_manage(): void
    {
        $catalog = $this->createSpotClassicCatalog();
        $sales = User::factory()->role(Role::Sales)->create();
        $calculation = $this->createAverageCalculation($catalog, $sales, [
            'customer_name' => 'Geheimkunde GmbH',
            'agency_name' => 'Agentur X',
            'campaign' => 'Kundenkampagne Q1',
            'briefing' => 'Internes Briefing mit Kundennamen',
        ]);

        $this->actingAs($sales)
            ->post(route('standard-offers.from-calculation', $calculation))
            ->assertRedirect(route('calculations.edit', $calculation));

        $offer = StandardOffer::query()->firstOrFail();
        $draft = $offer->draftVersion;
        $this->assertNotNull($draft);
        $this->assertSame(StandardOfferVersionStatus::Draft, $draft->status);
        $this->assertStringContainsString($calculation->number, $offer->title);
        $this->assertNull($draft->draft_payload['campaign'] ?? null);
        $this->assertNull($draft->draft_payload['briefing'] ?? null);
        $this->assertArrayNotHasKey('customer_name', $draft->draft_payload);
        $this->assertArrayNotHasKey('agency_name', $draft->draft_payload);
        $this->assertTrue((bool) ($draft->proposal_review['review_required'] ?? false));
        $this->assertSame(['campaign', 'briefing'], $draft->proposal_review['field_keys_requiring_review'] ?? null);
        $this->assertArrayNotHasKey('free_text', $draft->proposal_review ?? []);
        $this->assertArrayNotHasKey('stripped_dynamic_field_values', $draft->proposal_review ?? []);
        $this->assertSame($calculation->id, $draft->source_calculation_id);

        $audit = AuditEvent::query()->where('action', 'standard_offer.proposed_from_calculation')->first();
        $this->assertNotNull($audit);
        $auditJson = json_encode($audit->new_values ?? []);
        $this->assertIsString($auditJson);
        $this->assertStringNotContainsString('Kundenkampagne', $auditJson);
        $this->assertStringNotContainsString('Geheimkunde', $auditJson);
        $this->assertStringNotContainsString('Internes Briefing', $auditJson);

        $this->actingAs($sales)->get(route('standard-offers.show', [
            'standardOffer' => $offer,
            'version' => $draft->id,
        ]))->assertForbidden();

        $this->actingAs($sales)->put(route('standard-offers.update', [
            'standardOffer' => $offer,
            'version' => $draft->id,
        ]), [
            'title' => 'Manipuliert',
            'lock_version' => $draft->lock_version,
            ...$draft->draft_payload,
        ])->assertForbidden();

        $this->actingAs($sales)->post(route('standard-offers.publish', [
            'standardOffer' => $offer,
            'version' => $draft->id,
        ]), ['lock_version' => $draft->lock_version])->assertForbidden();

        $this->assertTrue(AuditEvent::query()->where('action', 'standard_offer.proposed_from_calculation')->exists());
    }

    public function test_pm_reviews_publishes_and_sales_adopts_independently(): void
    {
        $catalog = $this->createSpotClassicCatalog();
        $sales = User::factory()->role(Role::Sales)->create();
        $pm = User::factory()->role(Role::ProductManagement)->create();
        $calculation = $this->createAverageCalculation($catalog, $sales, [
            'customer_name' => 'Kunde',
            'campaign' => 'Kampagne',
        ]);

        $offer = $this->writer()->createFromCalculation($calculation, $sales);
        $draft = $offer->draftVersion;
        $this->assertNotNull($draft);

        $this->actingAs($pm)->get(route('calculations.edit', $calculation))->assertForbidden();
        $this->actingAs($pm)
            ->get(route('standard-offers.show', ['standardOffer' => $offer, 'version' => $draft->id]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('calculations/wizard')
                ->where('standardOffer.proposal_review.review_required', true)
                ->where('standardOffer.proposal_review.field_keys_requiring_review', ['campaign'])
                ->missing('standardOffer.proposal_review.free_text')
                ->where('calculation.customer_name', null)
                ->where('calculation.campaign', null));

        // Speichern ohne Bestätigung lässt die Prüfstufe bestehen.
        $this->actingAs($pm)->put(route('standard-offers.update', [
            'standardOffer' => $offer,
            'version' => $draft->id,
        ]), [
            'title' => 'Geprüft ohne Bestätigung',
            'lock_version' => $draft->lock_version,
            ...$draft->draft_payload,
            'campaign' => 'Kundenfreie Kampagne',
        ])->assertRedirect();
        $draft = $draft->fresh();
        $this->assertNotNull($draft);
        $this->assertTrue((bool) ($draft->proposal_review['review_required'] ?? false));
        $this->assertNull($draft->proposal_review['acknowledged_at'] ?? null);

        // Publish ohne ausdrückliche Bestätigung → abgelehnt.
        $this->actingAs($pm)->post(route('standard-offers.publish', [
            'standardOffer' => $offer,
            'version' => $draft->id,
        ]), [
            'lock_version' => $draft->lock_version,
            'acknowledge_proposal_review' => true,
        ])->assertSessionHasErrors('proposal_review');

        $acknowledged = $this->writer()->acknowledgeProposalReview($draft, (int) $draft->lock_version, $pm);
        $this->assertNotNull($acknowledged->proposal_review['acknowledged_at'] ?? null);

        $published = $this->writer()->publish(
            $acknowledged,
            (int) $acknowledged->lock_version,
            $pm,
        );
        $this->assertSame(StandardOfferVersionStatus::Published, $published->status);
        $this->assertSame(
            StandardOfferMaterializer::MATERIALIZATION_VERSION,
            (int) ($published->frozen_materialization['materialization_version'] ?? 0),
        );
        $this->assertSame([], $published->proposal_review['field_keys_requiring_review'] ?? ['x']);
        $this->assertArrayNotHasKey('free_text', $published->proposal_review ?? []);
        $this->assertSame('Kundenfreie Kampagne', $published->frozen_materialization['campaign'] ?? null);
        $this->assertArrayNotHasKey('customer_name', $published->frozen_materialization['draft_payload'] ?? []);

        $adopted = $this->writer()->adopt($published, 'Neuer Kunde', null, 'Neue Kampagne', $sales);
        $this->assertSame('Neuer Kunde', $adopted->customer_name);
        $this->assertSame($published->id, $adopted->origin_standard_offer_version_id);
        $this->assertNotSame($calculation->id, $adopted->id);

        $sourceAfter = $calculation->fresh();
        $this->assertSame('Kunde', $sourceAfter->customer_name);
        $this->assertSame('Kampagne', $sourceAfter->campaign);
    }

    public function test_rejects_unsupported_methods_without_silent_reduction(): void
    {
        $catalog = $this->createSpotClassicCatalog();
        $sales = User::factory()->role(Role::Sales)->create();
        $calculation = $this->createAverageCalculation($catalog, $sales);
        $calculation->positions()->firstOrFail()->update([
            'spot_method' => SpotCalculationMethod::Calendar,
        ]);

        try {
            $this->writer()->createFromCalculation($calculation->fresh(['positions']), $sales);
            $this->fail('Expected ValidationException');
        } catch (ValidationException $exception) {
            $messages = $exception->errors();
            $this->assertArrayHasKey('positions.0.spot_method', $messages);
            $this->assertStringContainsString('Position 1', $messages['positions.0.spot_method'][0]);
        }

        $this->assertSame(0, StandardOffer::query()->count());

        $this->actingAs($sales)
            ->from(route('calculations.edit', $calculation))
            ->post(route('standard-offers.from-calculation', $calculation))
            ->assertRedirect(route('calculations.edit', $calculation))
            ->assertSessionHasErrors('positions.0.spot_method');
    }

    public function test_components_roundtrip_from_calculation(): void
    {
        $catalog = $this->createSpotClassicCatalog();
        $this->setRuleStrategy($catalog, ComponentCalculationStrategy::SharedTotalLength);
        $sales = User::factory()->role(Role::Sales)->create();
        $pm = User::factory()->role(Role::ProductManagement)->create();

        $payload = $this->withLiveSchemaFingerprint([
            'planning_mode' => 'manual',
            'customer_name' => 'Comp Kunde',
            'agency_name' => null,
            'campaign' => null,
            'product_title' => null,
            'briefing' => null,
            'order_discount_percent' => '0',
            'ae_enabled' => false,
            'order_discounts' => [],
            'dynamic_field_values' => [],
            'positions' => [[
                'client_key' => 'p1',
                'inventory_id' => $catalog['hamburg']->id,
                'advertising_medium_id' => $catalog['medium']->id,
                'spot_method' => 'average',
                'length_seconds' => 40,
                'total_spot_count' => 10,
                'position_discount_percent' => '0',
                'ae_percent' => '0',
                'pricing_settlement_mode' => 'normal',
                'component_calculation_strategy' => ComponentCalculationStrategy::SharedTotalLength->value,
                'components' => [
                    ['role' => 'main_spot', 'label' => 'Hauptspot', 'length_seconds' => 30, 'sort' => 0],
                    ['role' => 'allonge', 'label' => 'Allonge', 'length_seconds' => 10, 'sort' => 1],
                ],
                'plan_rows' => [['hour' => 8, 'day_group' => 'mo_fr']],
                'time_ranges' => [[
                    'start_hour' => 8,
                    'end_hour_exclusive' => 9,
                    'day_group' => 'mo_fr',
                    'spot_count' => 10,
                ]],
                'position_discounts' => [],
                'dynamic_field_values' => ['period_open' => true],
            ]],
        ]);

        $calculation = app(CalculationWriter::class)->create($payload, $sales);
        $offer = $this->writer()->createFromCalculation($calculation, $sales);
        $draft = $offer->draftVersion;
        $this->assertNotNull($draft);
        $this->assertCount(2, $draft->draft_payload['positions'][0]['components'] ?? []);

        $published = $this->writer()->publish($draft, (int) $draft->lock_version, $pm);
        $adopted = $this->writer()->adopt($published, 'Adopt Kunde', null, null, $sales);
        $this->assertCount(2, $adopted->positions->first()->components);
    }

    public function test_legacy_published_without_materialization_version_remains_adoptable(): void
    {
        $catalog = $this->createSpotClassicCatalog();
        $pm = User::factory()->role(Role::ProductManagement)->create();
        $sales = User::factory()->role(Role::Sales)->create();

        $offer = $this->writer()->create('Legacy', $this->averageDraftPayload($catalog), $pm);
        $draft = $offer->draftVersion;
        $this->assertNotNull($draft);
        $published = $this->writer()->publish($draft, (int) $draft->lock_version, $pm);

        $materialization = $published->frozen_materialization;
        unset($materialization['materialization_version']);
        $published->frozen_materialization = $materialization;
        $published->save();

        $adopted = $this->writer()->adopt($published->fresh(), 'Legacy Kunde', null, null, $sales);
        $this->assertInstanceOf(Calculation::class, $adopted);
        $this->assertSame($published->id, $adopted->origin_standard_offer_version_id);
    }

    public function test_disposition_and_pm_cannot_propose_from_calculation(): void
    {
        $catalog = $this->createSpotClassicCatalog();
        $sales = User::factory()->role(Role::Sales)->create();
        $pm = User::factory()->role(Role::ProductManagement)->create();
        $disposition = User::factory()->role(Role::Disposition)->create();
        $calculation = $this->createAverageCalculation($catalog, $sales);

        // Disposition darf Calc sehen (AUTH-002-Rolle), aber nicht vorschlagen.
        $this->actingAs($disposition)->get(route('calculations.edit', $calculation))->assertOk();
        $this->actingAs($disposition)
            ->post(route('standard-offers.from-calculation', $calculation))
            ->assertForbidden();

        // PM ohne Calc-View → Propose abgeglichen mit view()-Vertrag.
        $this->actingAs($pm)->get(route('calculations.edit', $calculation))->assertForbidden();
        $this->actingAs($pm)
            ->post(route('standard-offers.from-calculation', $calculation))
            ->assertForbidden();
        $this->assertSame(0, StandardOffer::query()->count());
    }

    public function test_sales_can_propose_from_any_viewable_calculation_per_auth_002(): void
    {
        $catalog = $this->createSpotClassicCatalog();
        $owner = User::factory()->role(Role::Sales)->create();
        $otherSales = User::factory()->role(Role::Sales)->create();
        $calculation = $this->createAverageCalculation($catalog, $owner, [
            'customer_name' => 'Fremde Calc GmbH',
        ]);

        // AUTH-002: Vertrieb sieht alle Calc – Propose folgt view(), nicht Eigentum.
        $this->actingAs($otherSales)->get(route('calculations.edit', $calculation))->assertOk();
        $this->actingAs($otherSales)
            ->post(route('standard-offers.from-calculation', $calculation))
            ->assertRedirect();
        $this->assertSame(1, StandardOffer::query()->count());
    }

    public function test_manipulated_request_cannot_inject_customer_into_draft_update(): void
    {
        $catalog = $this->createSpotClassicCatalog();
        $pm = User::factory()->role(Role::ProductManagement)->create();
        $offer = $this->writer()->create('Leak', $this->averageDraftPayload($catalog), $pm);
        $draft = $offer->draftVersion;
        $this->assertNotNull($draft);

        $this->actingAs($pm)->put(route('standard-offers.update', [
            'standardOffer' => $offer,
            'version' => $draft->id,
        ]), [
            'title' => 'Leak Versuch',
            'lock_version' => $draft->lock_version,
            'customer_name' => 'Einschleusen',
            ...$this->averageDraftPayload($catalog),
        ])->assertSessionHasErrors();

        $fresh = $draft->fresh();
        $this->assertArrayNotHasKey('customer_name', $fresh->draft_payload ?? []);
    }

    public function test_wizard_exposes_propose_flag_for_sales(): void
    {
        $catalog = $this->createSpotClassicCatalog();
        $sales = User::factory()->role(Role::Sales)->create();
        $calculation = $this->createAverageCalculation($catalog, $sales);

        $this->actingAs($sales)
            ->get(route('calculations.edit', $calculation))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('calculations/wizard')
                ->where('canProposeAsStandardOffer', true));
    }

    /**
     * @param  array{hamburg: Inventory, medium: mixed}  $catalog
     * @param  array<string, mixed>  $overrides
     */
    private function createAverageCalculation(array $catalog, User $user, array $overrides = []): Calculation
    {
        $payload = $this->withLiveSchemaFingerprint([
            'planning_mode' => 'manual',
            'customer_name' => $overrides['customer_name'] ?? 'Kunde',
            'agency_name' => $overrides['agency_name'] ?? null,
            'campaign' => $overrides['campaign'] ?? null,
            'product_title' => $overrides['product_title'] ?? null,
            'briefing' => $overrides['briefing'] ?? null,
            'order_discount_percent' => '0',
            'ae_enabled' => false,
            'order_discounts' => [],
            'dynamic_field_values' => [],
            'positions' => [[
                'client_key' => 'p1',
                'inventory_id' => $catalog['hamburg']->id,
                'advertising_medium_id' => $catalog['medium']->id,
                'spot_method' => 'average',
                'length_seconds' => 30,
                'total_spot_count' => 10,
                'position_discount_percent' => '0',
                'ae_percent' => '0',
                'pricing_settlement_mode' => 'normal',
                'components' => [],
                'plan_rows' => [['hour' => 8, 'day_group' => 'mo_fr']],
                'time_ranges' => [[
                    'start_hour' => 8,
                    'end_hour_exclusive' => 9,
                    'day_group' => 'mo_fr',
                    'spot_count' => 10,
                ]],
                'position_discounts' => [],
                'dynamic_field_values' => ['period_open' => true],
            ]],
        ]);

        return app(CalculationWriter::class)->create($payload, $user);
    }

    /**
     * @param  array{hamburg: Inventory, medium: mixed}  $catalog
     * @return array<string, mixed>
     */
    private function averageDraftPayload(array $catalog): array
    {
        return $this->withLiveSchemaFingerprint([
            'planning_mode' => 'manual',
            'order_discount_percent' => '0',
            'ae_enabled' => false,
            'positions' => [[
                'inventory_id' => $catalog['hamburg']->id,
                'advertising_medium_id' => $catalog['medium']->id,
                'spot_method' => 'average',
                'length_seconds' => 30,
                'total_spot_count' => 10,
                'position_discount_percent' => '0',
                'ae_percent' => '0',
                'pricing_settlement_mode' => 'normal',
                'plan_rows' => [['hour' => 8, 'day_group' => 'mo_fr']],
                'time_ranges' => [[
                    'start_hour' => 8,
                    'end_hour_exclusive' => 9,
                    'day_group' => 'mo_fr',
                    'spot_count' => 10,
                ]],
            ]],
        ]);
    }

    private function setRuleStrategy(array $catalog, ComponentCalculationStrategy $strategy): void
    {
        InventoryMediumRule::query()
            ->where('inventory_id', $catalog['hamburg']->id)
            ->where('advertising_medium_id', $catalog['medium']->id)
            ->update(['component_calculation_strategy' => $strategy->value]);
    }

    private function writer(): StandardOfferWriter
    {
        return app(StandardOfferWriter::class);
    }
}
