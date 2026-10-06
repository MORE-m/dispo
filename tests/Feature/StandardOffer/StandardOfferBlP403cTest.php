<?php

namespace Tests\Feature\StandardOffer;

use App\Enums\ComponentCalculationStrategy;
use App\Enums\PriceListStatus;
use App\Enums\Role;
use App\Enums\StandardOfferVersionStatus;
use App\Models\Inventory;
use App\Models\InventoryMediumRule;
use App\Models\PriceList;
use App\Models\PriceListItem;
use App\Models\StandardOffer;
use App\Models\User;
use App\Services\Calculation\CalculationWriter;
use App\Services\DispoOrder\DispoOrderWriter;
use App\Services\StandardOffer\StandardOfferWriter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\CreatesSpotClassicCatalog;
use Tests\TestCase;

/**
 * BL-P4-03c / PO-BLP403C-1 / SPT-014 / STD-001–STD-009 / VER-004 / AUTH-006/007.
 */
class StandardOfferBlP403cTest extends TestCase
{
    use CreatesSpotClassicCatalog;
    use RefreshDatabase;

    public function test_draft_roundtrip_preserves_hauptspot_allonge_shared_total_length(): void
    {
        $catalog = $this->createSpotClassicCatalog();
        $pm = User::factory()->role(Role::ProductManagement)->create();
        $this->setRuleStrategy($catalog, ComponentCalculationStrategy::SharedTotalLength);
        $this->setSecondPrice($catalog, '2.0000');

        $offer = $this->writer()->create(
            '03c Shared Draft',
            $this->componentDraftPayload($catalog, ComponentCalculationStrategy::SharedTotalLength),
            $pm,
        );
        $draft = $offer->draftVersion;
        $this->assertNotNull($draft);
        $this->assertCount(2, $draft->draft_payload['positions'][0]['components'] ?? []);
        $this->assertSame(
            'shared_total_length',
            $draft->draft_payload['positions'][0]['component_calculation_strategy'] ?? null,
        );

        $this->actingAs($pm)
            ->get(route('standard-offers.show', [
                'standardOffer' => $offer,
                'version' => $draft->id,
            ]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('calculations/wizard')
                ->where('standardOffer.mode', 'edit')
                ->has('calculation.positions.0.components', 2)
                ->where('calculation.positions.0.component_calculation_strategy', 'shared_total_length')
                ->where('calculation.positions.0.components.0.role', 'main_spot')
                ->where('calculation.positions.0.components.1.role', 'allonge'));
    }

    public function test_publish_and_adopt_individual_strategy_with_calc_update_and_dispo_snapshot(): void
    {
        $catalog = $this->createSpotClassicCatalog();
        $pm = User::factory()->role(Role::ProductManagement)->create();
        $sales = User::factory()->role(Role::Sales)->create();
        $this->setRuleStrategy($catalog, ComponentCalculationStrategy::Individual);
        $this->setSecondPrice($catalog, '2.0000');

        $offer = $this->writer()->create(
            '03c Individual',
            $this->componentDraftPayload($catalog, ComponentCalculationStrategy::Individual),
            $pm,
        );
        $draft = $offer->draftVersion;
        $this->assertNotNull($draft);
        $published = $this->writer()->publish($draft, (int) $draft->lock_version, $pm);

        $frozen = $published->frozen_materialization;
        $this->assertIsArray($frozen);
        $this->assertSame('individual', $frozen['positions'][0]['component_calculation_strategy'] ?? null);
        $this->assertCount(2, $frozen['positions'][0]['components'] ?? []);
        $this->assertSame('640.00', (string) ($frozen['media_gross'] ?? ''));
        $nnBefore = (string) ($frozen['nn_invest'] ?? '');

        $calculation = $this->writer()->adopt($published, 'Kunde 03c', null, null, $sales);
        $position = $calculation->positions()->with('components')->firstOrFail();
        $this->assertSame('individual', $position->component_calculation_strategy);
        $this->assertCount(2, $position->components);
        $this->assertSame('Hauptspot', $position->components->firstWhere('role', 'main_spot')?->label);
        $this->assertSame('Allonge', $position->components->firstWhere('role', 'allonge')?->label);

        $calcWriter = app(CalculationWriter::class);
        $payload = $calcWriter->payloadFromCalculation($calculation->fresh([
            'positions.planRows',
            'positions.timeRanges',
            'positions.components',
            'positions.discounts',
            'orderDiscounts',
            'configurationSnapshot',
            'fieldValues',
        ]));
        $payload['lock_version'] = $calculation->lock_version;
        $payload['positions'][0]['total_spot_count'] = 20;
        $payload['positions'][0]['time_ranges'][0]['spot_count'] = 20;
        $updated = $calcWriter->update($calculation->fresh(), $payload, $sales);
        $this->assertSame(20, (int) $updated->positions()->firstOrFail()->total_spot_count);
        $this->assertCount(2, $updated->positions()->firstOrFail()->components);
        $this->assertNotSame($nnBefore, (string) $updated->nn_invest);

        $dispoResult = app(DispoOrderWriter::class)->createFromCalculation(
            $updated->fresh(['positions.components']),
            [(int) $updated->positions()->firstOrFail()->id],
            $sales,
        );
        $dispoPosition = $dispoResult->order->positions()->firstOrFail();
        $this->assertSame('individual', $dispoPosition->component_calculation_strategy);
        $this->assertCount(2, $dispoPosition->components_snapshot ?? []);
        $this->assertSame('main_spot', $dispoPosition->components_snapshot[0]['role'] ?? null);

        $publishedAfter = $published->fresh();
        $this->assertSame(
            10,
            (int) ($publishedAfter->frozen_materialization['positions'][0]['total_spot_count'] ?? 0),
        );
        $this->assertSame($nnBefore, (string) ($publishedAfter->frozen_materialization['nn_invest'] ?? ''));
        $this->assertCount(2, $publishedAfter->frozen_materialization['positions'][0]['components'] ?? []);
    }

    public function test_plain_average_without_components_still_works(): void
    {
        $catalog = $this->createSpotClassicCatalog();
        $pm = User::factory()->role(Role::ProductManagement)->create();
        $sales = User::factory()->role(Role::Sales)->create();

        $payload = $this->componentDraftPayload($catalog, null);
        $offer = $this->writer()->create('Plain Average', $payload, $pm);
        $draft = $offer->draftVersion;
        $this->assertNotNull($draft);
        $this->assertSame([], $draft->draft_payload['positions'][0]['components'] ?? null);
        $this->assertNull($draft->draft_payload['positions'][0]['component_calculation_strategy'] ?? null);

        $published = $this->writer()->publish($draft, (int) $draft->lock_version, $pm);
        $this->assertSame([], $published->frozen_materialization['positions'][0]['components'] ?? ['x']);
        $calculation = $this->writer()->adopt($published, 'Kunde Plain', null, null, $sales);
        $this->assertCount(0, $calculation->positions()->firstOrFail()->components);
        $this->assertNull($calculation->positions()->firstOrFail()->component_calculation_strategy);
    }

    public function test_null_components_and_invalid_strategy_and_profile_rejected(): void
    {
        $catalog = $this->createSpotClassicCatalog();
        $pm = User::factory()->role(Role::ProductManagement)->create();
        $this->setRuleStrategy($catalog, ComponentCalculationStrategy::SharedTotalLength);

        $nullComponents = $this->componentDraftPayload($catalog, ComponentCalculationStrategy::SharedTotalLength);
        $nullComponents['positions'][0]['components'] = null;
        try {
            $this->writer()->create('Null Components', $nullComponents, $pm);
            $this->fail('null components hätte abgelehnt werden müssen.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('positions.0.components', $exception->errors());
        }

        $strategyWithout = $this->componentDraftPayload($catalog, null);
        $strategyWithout['positions'][0]['component_calculation_strategy'] = 'shared_total_length';
        $strategyWithout['positions'][0]['components'] = [];
        try {
            $this->writer()->create('Strategie ohne Komponenten', $strategyWithout, $pm);
            $this->fail('Strategie ohne Komponenten hätte abgelehnt werden müssen.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('positions.0.component_calculation_strategy', $exception->errors());
        }

        $wrongStrategy = $this->componentDraftPayload($catalog, ComponentCalculationStrategy::Individual);
        try {
            $this->writer()->create('Falsche Strategie', $wrongStrategy, $pm);
            $this->fail('Strategie gegen Regel hätte abgelehnt werden müssen.');
        } catch (ValidationException $exception) {
            $this->assertTrue(
                collect($exception->errors())->keys()->contains(
                    fn ($key) => str_contains((string) $key, 'component_calculation_strategy')
                        || str_contains((string) $key, 'components'),
                ),
            );
        }

        $profile = $this->componentDraftPayload($catalog, ComponentCalculationStrategy::SharedTotalLength);
        $profile['positions'][0]['component_profile'] = 'tandem';
        try {
            $this->writer()->create('Tandem ohne Reminder', $profile, $pm);
            $this->fail('Tandem ohne Reminder-Komponenten hätte abgelehnt werden müssen.');
        } catch (ValidationException $exception) {
            $this->assertTrue(
                collect($exception->errors())->keys()->contains(
                    fn ($key) => str_contains((string) $key, 'component'),
                ),
            );
        }

        $this->assertSame(0, StandardOffer::query()->count());
    }

    public function test_http_accepts_valid_components_and_rejects_budget(): void
    {
        $catalog = $this->createSpotClassicCatalog();
        $pm = User::factory()->role(Role::ProductManagement)->create();
        $this->setRuleStrategy($catalog, ComponentCalculationStrategy::SharedTotalLength);
        $this->setSecondPrice($catalog, '2.0000');
        $base = $this->componentDraftPayload($catalog, ComponentCalculationStrategy::SharedTotalLength);
        $base['title'] = 'HTTP 03c';

        $this->actingAs($pm)->post(route('standard-offers.store'), $base)
            ->assertRedirect();
        $this->assertSame(1, StandardOffer::query()->count());
        $draft = StandardOffer::query()->firstOrFail()->draftVersion;
        $this->assertNotNull($draft);
        $this->assertCount(2, $draft->draft_payload['positions'][0]['components'] ?? []);

        // BL-P4-03h: Calendar + Komponenten ist erlaubt.
        $this->actingAs($pm)->post(route('standard-offers.store'), [
            ...$base,
            'title' => 'Calendar+Komponenten OK',
            'positions' => [[
                ...$base['positions'][0],
                'spot_method' => 'calendar',
                'calculation_method_key' => 'calendar',
                'plan_rows' => [],
                'time_ranges' => [],
                'planner_entries' => [[
                    'date' => '2026-03-02',
                    'hour' => 8,
                    'spot_count' => 1,
                ]],
            ]],
        ])->assertRedirect();
        $this->assertSame(2, StandardOffer::query()->count());

        $this->actingAs($pm)->post(route('standard-offers.store'), [
            ...$base,
            'title' => 'Budget Reject',
            'planning_mode' => 'budget',
        ])->assertSessionHasErrors();

        // BL-P4-03e: gültiger Festpreis mit Komponenten ist erlaubt.
        $this->actingAs($pm)->post(route('standard-offers.store'), [
            ...$base,
            'title' => 'Festpreis mit Komponenten',
            'positions' => [[
                ...$base['positions'][0],
                'pricing_settlement_mode' => 'fixed_price',
                'fixed_price_nn' => '100.00',
            ]],
        ])->assertRedirect();
        $this->assertSame(3, StandardOffer::query()->count());
    }

    public function test_price_list_change_after_publish_does_not_mutate_adopted_components(): void
    {
        $catalog = $this->createSpotClassicCatalog();
        $pm = User::factory()->role(Role::ProductManagement)->create();
        $sales = User::factory()->role(Role::Sales)->create();
        $this->setRuleStrategy($catalog, ComponentCalculationStrategy::SharedTotalLength);
        $this->setSecondPrice($catalog, '2.0000');

        $offer = $this->writer()->create(
            'Isolation 03c',
            $this->componentDraftPayload($catalog, ComponentCalculationStrategy::SharedTotalLength),
            $pm,
        );
        $draft = $offer->draftVersion;
        $this->assertNotNull($draft);
        $published = $this->writer()->publish($draft, (int) $draft->lock_version, $pm);
        $nnFrozen = (string) ($published->frozen_materialization['nn_invest'] ?? '');
        $calculation = $this->writer()->adopt($published, 'Isolation Kunde', null, null, $sales);
        $nnAdopted = (string) $calculation->nn_invest;

        $this->setSecondPrice($catalog, '9.0000');
        $publishedAfter = $published->fresh();
        $calculationAfter = $calculation->fresh(['positions.components']);
        $this->assertSame($nnFrozen, (string) ($publishedAfter->frozen_materialization['nn_invest'] ?? ''));
        $this->assertSame($nnAdopted, (string) $calculationAfter->nn_invest);
        $this->assertCount(2, $calculationAfter->positions->first()->components);
        $this->assertSame(StandardOfferVersionStatus::Published, $publishedAfter->status);
    }

    public function test_pm_create_scope_note_mentions_components(): void
    {
        $this->createSpotClassicCatalog();
        $pm = User::factory()->role(Role::ProductManagement)->create();

        $this->actingAs($pm)
            ->get(route('standard-offers.create'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('calculations/wizard')
                ->where('standardOffer.allowed_spot_methods.0', 'average')
                ->where('standardOffer.scope_note', fn ($note) => is_string($note) && str_contains($note, 'Komponenten') && str_contains($note, 'Festpreis') && str_contains($note, 'Calendar')));
    }

    /**
     * @param  array{hamburg: Inventory, medium: mixed}  $catalog
     */
    private function setRuleStrategy(array $catalog, ComponentCalculationStrategy $strategy): void
    {
        InventoryMediumRule::query()
            ->where('inventory_id', $catalog['hamburg']->id)
            ->where('advertising_medium_id', $catalog['medium']->id)
            ->update(['component_calculation_strategy' => $strategy->value]);
    }

    /**
     * @param  array{hamburg: mixed}  $catalog
     */
    private function setSecondPrice(array $catalog, string $price): void
    {
        $list = PriceList::query()
            ->where('inventory_id', $catalog['hamburg']->id)
            ->where('status', PriceListStatus::Active)
            ->firstOrFail();
        PriceListItem::query()->where('price_list_id', $list->id)->update(['second_price' => $price]);
    }

    /**
     * @param  array{hamburg: Inventory, medium: mixed}  $catalog
     * @return array<string, mixed>
     */
    private function componentDraftPayload(array $catalog, ?ComponentCalculationStrategy $strategy): array
    {
        $position = [
            'inventory_id' => $catalog['hamburg']->id,
            'advertising_medium_id' => $catalog['medium']->id,
            'spot_method' => 'average',
            'length_seconds' => 30,
            'total_spot_count' => 10,
            'position_discount_percent' => '0',
            'ae_percent' => '0',
            'pricing_settlement_mode' => 'normal',
            'components' => [],
            'component_calculation_strategy' => null,
            'plan_rows' => [[
                'hour' => 8,
                'day_group' => 'mo_fr',
            ]],
            'time_ranges' => [[
                'start_hour' => 8,
                'end_hour_exclusive' => 9,
                'day_group' => 'mo_fr',
                'spot_count' => 10,
            ]],
            'dynamic_field_values' => [
                'period_open' => true,
                'position_flight_period' => null,
            ],
        ];

        if ($strategy !== null) {
            $position['component_calculation_strategy'] = $strategy->value;
            $position['components'] = [
                ['role' => 'main_spot', 'label' => 'Hauptspot', 'length_seconds' => 20, 'sort' => 0],
                ['role' => 'allonge', 'label' => 'Allonge', 'length_seconds' => 10, 'sort' => 1],
            ];
            $position['length_seconds'] = 30;
        }

        return $this->withLiveSchemaFingerprint([
            'planning_mode' => 'manual',
            'order_discount_percent' => '0',
            'ae_enabled' => false,
            'campaign' => 'Kampagne 03c',
            'dynamic_field_values' => [
                'campaign_period' => null,
            ],
            'positions' => [$position],
        ]);
    }

    private function writer(): StandardOfferWriter
    {
        return app(StandardOfferWriter::class);
    }
}
