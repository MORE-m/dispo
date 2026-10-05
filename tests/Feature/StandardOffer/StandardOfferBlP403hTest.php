<?php

namespace Tests\Feature\StandardOffer;

use App\Enums\ComponentCalculationStrategy;
use App\Enums\PriceListStatus;
use App\Enums\Role;
use App\Enums\StandardOfferVersionStatus;
use App\Models\AdvertisingMedium;
use App\Models\Calculation;
use App\Models\CalculationPosition;
use App\Models\CalculationPositionPlannerEntry;
use App\Models\Inventory;
use App\Models\InventoryMediumRule;
use App\Models\PriceList;
use App\Models\PriceListItem;
use App\Models\StandardOffer;
use App\Models\StandardOfferVersion;
use App\Models\User;
use App\Services\Calculation\CalculationWriter;
use App\Services\StandardOffer\FrozenCalculationPersistenceContract;
use App\Services\StandardOffer\StandardOfferMaterializer;
use App\Services\StandardOffer\StandardOfferWriter;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\Concerns\CreatesSpotClassicCatalog;
use Tests\TestCase;

/**
 * BL-P4-03h / PO-BLP403H-1 / A1 (+ B1+C1 aus 03g):
 * Calendar × Spot Classic × normal × optional Hauptspot+Allonge.
 */
class StandardOfferBlP403hTest extends TestCase
{
    use CreatesSpotClassicCatalog;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-06-15 12:00:00', 'Europe/Berlin'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_write_version_remains_four(): void
    {
        $this->assertSame(4, FrozenCalculationPersistenceContract::CURRENT_WRITE_VERSION);
        $this->assertSame(4, StandardOfferMaterializer::MATERIALIZATION_VERSION);
        $this->assertSame([1, 2, 3, 4], FrozenCalculationPersistenceContract::SUPPORTED_VERSIONS);
    }

    public function test_shared_total_length_draft_preview_publish_adopt_reload_parity(): void
    {
        $this->assertStrategyRoundtrip(ComponentCalculationStrategy::SharedTotalLength, '2.4000');
    }

    public function test_individual_draft_preview_publish_adopt_reload_parity(): void
    {
        $this->assertStrategyRoundtrip(ComponentCalculationStrategy::Individual, '2.4000');
    }

    public function test_price_list_change_after_publish_keeps_adopt_parity_then_calc_edit_rebinds(): void
    {
        $catalog = $this->createSpotClassicCatalog();
        $pm = User::factory()->role(Role::ProductManagement)->create();
        $sales = User::factory()->role(Role::Sales)->create();
        $this->setRuleStrategy($catalog, ComponentCalculationStrategy::SharedTotalLength);
        $this->setSecondPrice($catalog, '2.4000');

        $published = $this->publishCalendarComponents(
            $catalog,
            $pm,
            ComponentCalculationStrategy::SharedTotalLength,
            [['date' => '2026-03-02', 'hour' => 8, 'spot_count' => 5]],
        );
        $nnFrozen = (string) ($published->frozen_materialization['nn_invest'] ?? '');
        $componentsFrozen = $published->frozen_materialization['positions'][0]['components'] ?? [];

        $adopted = $this->writer()->adopt($published, 'Parity Kunde', null, null, $sales);
        $nnAdopted = (string) $adopted->nn_invest;
        $this->assertSame($nnFrozen, $nnAdopted);
        $this->assertCount(2, $adopted->positions->first()->components);

        $this->setSecondPrice($catalog, '9.0000');
        $this->assertSame($nnFrozen, (string) ($published->fresh()->frozen_materialization['nn_invest'] ?? ''));
        $this->assertSame($nnAdopted, (string) $adopted->fresh()->nn_invest);
        $this->assertSame(
            $componentsFrozen[0]['media_gross'] ?? null,
            $published->fresh()->frozen_materialization['positions'][0]['components'][0]['media_gross'] ?? null,
        );

        $payload = app(CalculationWriter::class)->payloadFromCalculation($adopted->fresh([
            'positions',
            'positions.components',
            'positions.plannerEntries',
            'positions.planRows',
            'positions.timeRanges',
            'positions.discounts',
            'orderDiscounts',
        ]));
        $payload['lock_version'] = $adopted->fresh()->lock_version;
        // Neue Zelle ohne Snapshot-Pin → Live-Auflösung gegen aktualisierte Preisliste.
        $payload['positions'][0]['planner_entries'] = [
            ['date' => '2026-03-02', 'hour' => 9, 'spot_count' => 5],
        ];
        $updated = app(CalculationWriter::class)->update($adopted->fresh(), $payload, $sales);
        $this->assertNotSame($nnAdopted, (string) $updated->nn_invest);
        $this->assertSame('9.0000', (string) $updated->positions->first()->plannerEntries->first()->second_price);
    }

    public function test_wrong_inventory_strategy_fails_on_create_preview_publish(): void
    {
        $catalog = $this->createSpotClassicCatalog();
        $pm = User::factory()->role(Role::ProductManagement)->create();
        $this->setRuleStrategy($catalog, ComponentCalculationStrategy::SharedTotalLength);
        $this->setSecondPrice($catalog, '2.0000');

        $payload = $this->calendarComponentsDraftPayload(
            $catalog,
            [['date' => '2026-03-02', 'hour' => 8, 'spot_count' => 2]],
            ComponentCalculationStrategy::Individual,
        );

        try {
            $this->writer()->create('Wrong strategy', $payload, $pm);
            $this->fail('Create mit Strategie-Mismatch hätte scheitern müssen.');
        } catch (ValidationException $exception) {
            $this->assertTrue(
                collect($exception->errors())->keys()->contains(
                    fn ($key) => str_contains((string) $key, 'component_calculation_strategy')
                        || str_contains((string) $key, 'components'),
                ),
                json_encode($exception->errors()),
            );
        }

        $this->actingAs($pm)
            ->postJson(route('standard-offers.preview'), [...$payload, 'title' => 'Wrong strategy preview'])
            ->assertStatus(422);

        $this->assertSame(0, StandardOffer::query()->count());
    }

    public function test_rejects_calendar_festpreis_tandem_budget_still(): void
    {
        $catalog = $this->createSpotClassicCatalog();
        $pm = User::factory()->role(Role::ProductManagement)->create();
        $this->setRuleStrategy($catalog, ComponentCalculationStrategy::SharedTotalLength);
        $this->setSecondPrice($catalog, '2.0000');

        try {
            $this->writer()->create('Cal FP', $this->calendarComponentsDraftPayload(
                $catalog,
                [['date' => '2026-03-02', 'hour' => 8, 'spot_count' => 1]],
                ComponentCalculationStrategy::SharedTotalLength,
                [
                    'pricing_settlement_mode' => 'fixed_price',
                    'fixed_price_nn' => '500.00',
                ],
            ), $pm);
            $this->fail('Calendar+Festpreis hätte scheitern müssen.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('positions.0.pricing_settlement_mode', $exception->errors());
        }

        try {
            $this->writer()->create('Cal Tandem', $this->calendarComponentsDraftPayload(
                $catalog,
                [['date' => '2026-03-02', 'hour' => 8, 'spot_count' => 1]],
                ComponentCalculationStrategy::SharedTotalLength,
                ['component_profile' => 'tandem'],
            ), $pm);
            $this->fail('Calendar+Tandem hätte scheitern müssen.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('positions.0.component_profile', $exception->errors());
        }

        $this->actingAs($pm)->post(route('standard-offers.store'), [
            ...$this->calendarComponentsDraftPayload(
                $catalog,
                [['date' => '2026-03-02', 'hour' => 8, 'spot_count' => 1]],
                ComponentCalculationStrategy::SharedTotalLength,
            ),
            'title' => 'Budget Reject',
            'planning_mode' => 'budget',
        ])->assertSessionHasErrors();
    }

    public function test_from_calc_calendar_components_succeeds_and_mix_fails(): void
    {
        $catalog = $this->createSpotClassicCatalog();
        $sales = User::factory()->role(Role::Sales)->create();
        $pm = User::factory()->role(Role::ProductManagement)->create();
        $this->setRuleStrategy($catalog, ComponentCalculationStrategy::SharedTotalLength);
        $this->setSecondPrice($catalog, '2.0000');

        $calcPayload = $this->withLiveSchemaFingerprint([
            'planning_mode' => 'manual',
            'customer_name' => 'Calendar Comp Quelle',
            'order_discount_percent' => '0',
            'ae_enabled' => false,
            'order_discounts' => [],
            'dynamic_field_values' => [],
            'positions' => [[
                'inventory_id' => $catalog['hamburg']->id,
                'advertising_medium_id' => $catalog['medium']->id,
                'spot_method' => 'calendar',
                'calculation_method_key' => 'calendar',
                'length_seconds' => 30,
                'position_discount_percent' => '0',
                'ae_percent' => '0',
                'pricing_settlement_mode' => 'normal',
                'component_calculation_strategy' => ComponentCalculationStrategy::SharedTotalLength->value,
                'components' => [
                    ['role' => 'main_spot', 'label' => 'Hauptspot', 'length_seconds' => 20, 'sort' => 0],
                    ['role' => 'allonge', 'label' => 'Allonge', 'length_seconds' => 10, 'sort' => 1],
                ],
                'planner_entries' => [[
                    'date' => '2026-03-02',
                    'hour' => 8,
                    'spot_count' => 2,
                ]],
                'plan_rows' => [],
                'time_ranges' => [],
                'position_discounts' => [],
                'dynamic_field_values' => ['period_open' => true],
            ]],
        ]);
        $calculation = app(CalculationWriter::class)->create($calcPayload, $sales);
        $offer = $this->writer()->createFromCalculation(
            $calculation->fresh(['positions', 'positions.components', 'positions.plannerEntries']),
            $sales,
        );
        $this->assertNotNull($offer->draftVersion);
        $this->assertSame('calendar', $offer->draftVersion->draft_payload['positions'][0]['spot_method']);
        $this->assertCount(2, $offer->draftVersion->draft_payload['positions'][0]['components']);
        $this->assertStringStartsWith('SA-', $offer->number);

        $mixed = $this->withLiveSchemaFingerprint([
            'planning_mode' => 'manual',
            'customer_name' => 'Mix',
            'order_discount_percent' => '0',
            'ae_enabled' => false,
            'order_discounts' => [],
            'dynamic_field_values' => [],
            'positions' => [
                [
                    'client_key' => 'avg',
                    'inventory_id' => $catalog['hamburg']->id,
                    'advertising_medium_id' => $catalog['medium']->id,
                    'spot_method' => 'average',
                    'calculation_method_key' => 'average',
                    'length_seconds' => 30,
                    'total_spot_count' => 5,
                    'position_discount_percent' => '0',
                    'ae_percent' => '0',
                    'pricing_settlement_mode' => 'normal',
                    'components' => [],
                    'plan_rows' => [['hour' => 8, 'day_group' => 'mo_fr']],
                    'time_ranges' => [[
                        'start_hour' => 8,
                        'end_hour_exclusive' => 9,
                        'day_group' => 'mo_fr',
                        'spot_count' => 5,
                    ]],
                    'planner_entries' => [],
                    'position_discounts' => [],
                    'dynamic_field_values' => ['period_open' => true],
                ],
                [
                    'client_key' => 'cal',
                    'inventory_id' => $catalog['hamburg']->id,
                    'advertising_medium_id' => $catalog['medium']->id,
                    'spot_method' => 'calendar',
                    'calculation_method_key' => 'calendar',
                    'length_seconds' => 30,
                    'position_discount_percent' => '0',
                    'ae_percent' => '0',
                    'pricing_settlement_mode' => 'normal',
                    'component_calculation_strategy' => ComponentCalculationStrategy::SharedTotalLength->value,
                    'components' => [
                        ['role' => 'main_spot', 'label' => 'Hauptspot', 'length_seconds' => 20, 'sort' => 0],
                        ['role' => 'allonge', 'label' => 'Allonge', 'length_seconds' => 10, 'sort' => 1],
                    ],
                    'plan_rows' => [],
                    'time_ranges' => [],
                    'planner_entries' => [[
                        'date' => '2026-03-02',
                        'hour' => 8,
                        'spot_count' => 1,
                    ]],
                    'position_discounts' => [],
                    'dynamic_field_values' => ['period_open' => true],
                ],
            ],
        ]);
        $mixedCalc = app(CalculationWriter::class)->create($mixed, $sales);
        $before = StandardOffer::query()->count();
        $this->actingAs($sales)
            ->post(route('standard-offers.from-calculation', $mixedCalc))
            ->assertSessionHasErrors();
        $this->assertSame($before, StandardOffer::query()->count());

        // PM-Scope-Note erwähnt Komponenten und Calendar.
        $this->actingAs($pm)
            ->get(route('standard-offers.create'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('standardOffer.allowed_spot_methods', ['average', 'calendar'])
                ->where('standardOffer.scope_note', fn ($note) => is_string($note)
                    && str_contains($note, 'Calendar')
                    && str_contains($note, 'Hauptspot')));
    }

    public function test_frozen_calendar_component_inconsistencies_fail_closed_independently(): void
    {
        $catalog = $this->createSpotClassicCatalog();
        $pm = User::factory()->role(Role::ProductManagement)->create();
        $sales = User::factory()->role(Role::Sales)->create();
        $this->setRuleStrategy($catalog, ComponentCalculationStrategy::SharedTotalLength);
        $this->setSecondPrice($catalog, '2.4000');

        $published = $this->publishCalendarComponents(
            $catalog,
            $pm,
            ComponentCalculationStrategy::SharedTotalLength,
            [['date' => '2026-03-02', 'hour' => 8, 'spot_count' => 3]],
        );

        /** @var array<string, mixed> $baseline */
        $baseline = json_decode(json_encode($published->frozen_materialization, JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR);
        app(FrozenCalculationPersistenceContract::class)->assertHydratable($baseline);

        $cases = [
            'missing component media_gross' => [
                'needle' => 'components.0.media_gross',
                'mutate' => static function (array $mat): array {
                    unset($mat['positions'][0]['components'][0]['media_gross']);

                    return $mat;
                },
            ],
            'invalid component role' => [
                'needle' => 'components.0.role',
                'mutate' => static function (array $mat): array {
                    $mat['positions'][0]['components'][0]['role'] = 'reminder';

                    return $mat;
                },
            ],
            'strategy without components' => [
                'needle' => 'component_calculation_strategy',
                'mutate' => static function (array $mat): array {
                    $mat['positions'][0]['components'] = [];
                    $mat['positions'][0]['component_calculation_strategy'] = 'not-a-strategy';

                    return $mat;
                },
            ],
            'components without strategy' => [
                'needle' => 'component_calculation_strategy',
                'mutate' => static function (array $mat): array {
                    $mat['positions'][0]['component_calculation_strategy'] = null;

                    return $mat;
                },
            ],
            'calendar profile forbidden' => [
                'needle' => 'component_profile ist für Calendar nicht erlaubt',
                'mutate' => static function (array $mat): array {
                    $mat['positions'][0]['component_profile'] = 'tandem';

                    return $mat;
                },
            ],
        ];

        $beforeCalcs = Calculation::query()->count();
        $beforePositions = CalculationPosition::query()->count();
        $beforePlanner = CalculationPositionPlannerEntry::query()->count();

        foreach ($cases as $label => $case) {
            $mat = ($case['mutate'])(json_decode(json_encode($baseline, JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR));
            $published->forceFill(['frozen_materialization' => $mat])->save();

            try {
                $this->writer()->adopt($published->fresh(), 'Corrupt '.$label, null, null, $sales);
                $this->fail("Adopt hätte für {$label} scheitern müssen.");
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey('frozen_materialization', $exception->errors(), $label);
                $joined = implode(' ', $exception->errors()['frozen_materialization']);
                $this->assertStringContainsString($case['needle'], $joined, $label.': '.$joined);
            }

            $this->assertSame($beforeCalcs, Calculation::query()->count(), $label);
            $this->assertSame($beforePositions, CalculationPosition::query()->count(), $label);
            $this->assertSame($beforePlanner, CalculationPositionPlannerEntry::query()->count(), $label);
        }

        // Baseline wiederherstellen und hydratisieren.
        $published->forceFill(['frozen_materialization' => $baseline])->save();
        app(FrozenCalculationPersistenceContract::class)->assertHydratable($baseline);
        $ok = $this->writer()->adopt($published->fresh(), 'Baseline OK', null, null, $sales);
        $this->assertCount(2, $ok->positions->first()->components);
    }

    public function test_legacy_einzelspot_calendar_and_average_components_still_adoptable(): void
    {
        $catalog = $this->createSpotClassicCatalog();
        $pm = User::factory()->role(Role::ProductManagement)->create();
        $sales = User::factory()->role(Role::Sales)->create();
        $this->setRuleStrategy($catalog, ComponentCalculationStrategy::SharedTotalLength);
        $this->setSecondPrice($catalog, '2.0000');

        // Calendar Einzelspot (03g-Form) bleibt gültig.
        $einzel = $this->writer()->create(
            'Einzelspot Calendar',
            $this->withLiveSchemaFingerprint([
                'planning_mode' => 'manual',
                'order_discount_percent' => '0',
                'ae_enabled' => false,
                'order_discounts' => [],
                'dynamic_field_values' => ['campaign_period' => null],
                'positions' => [[
                    'client_key' => 'cal',
                    'inventory_id' => $catalog['hamburg']->id,
                    'advertising_medium_id' => $catalog['medium']->id,
                    'spot_method' => 'calendar',
                    'calculation_method_key' => 'calendar',
                    'length_seconds' => 30,
                    'position_discount_percent' => '0',
                    'ae_percent' => '0',
                    'pricing_settlement_mode' => 'normal',
                    'components' => [],
                    'planner_entries' => [['date' => '2026-03-02', 'hour' => 8, 'spot_count' => 2]],
                    'plan_rows' => [],
                    'time_ranges' => [],
                    'position_discounts' => [],
                    'dynamic_field_values' => ['period_open' => true],
                ]],
            ]),
            $pm,
        );
        $einzelPublished = $this->writer()->publish(
            $einzel->draftVersion,
            (int) $einzel->draftVersion->lock_version,
            $pm,
        );
        $einzelAdopted = $this->writer()->adopt($einzelPublished, 'Einzel Kunde', null, null, $sales);
        $this->assertCount(0, $einzelAdopted->positions->first()->components);
        $this->assertCount(1, $einzelAdopted->positions->first()->plannerEntries);

        // Average + Komponenten (03c) Regression.
        $avg = $this->writer()->create(
            'Average Komponenten',
            $this->withLiveSchemaFingerprint([
                'planning_mode' => 'manual',
                'order_discount_percent' => '0',
                'ae_enabled' => false,
                'order_discounts' => [],
                'dynamic_field_values' => ['campaign_period' => null],
                'positions' => [[
                    'client_key' => 'avg',
                    'inventory_id' => $catalog['hamburg']->id,
                    'advertising_medium_id' => $catalog['medium']->id,
                    'spot_method' => 'average',
                    'length_seconds' => 30,
                    'total_spot_count' => 10,
                    'position_discount_percent' => '0',
                    'ae_percent' => '0',
                    'pricing_settlement_mode' => 'normal',
                    'component_calculation_strategy' => ComponentCalculationStrategy::SharedTotalLength->value,
                    'components' => [
                        ['role' => 'main_spot', 'label' => 'Hauptspot', 'length_seconds' => 20, 'sort' => 0],
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
            ]),
            $pm,
        );
        $avgPublished = $this->writer()->publish(
            $avg->draftVersion,
            (int) $avg->draftVersion->lock_version,
            $pm,
        );
        $avgAdopted = $this->writer()->adopt($avgPublished, 'Avg Kunde', null, null, $sales);
        $this->assertCount(2, $avgAdopted->positions->first()->components);
    }

    /**
     * @param  array{hamburg: Inventory, medium: AdvertisingMedium}  $catalog
     * @param  list<array{date: string, hour: int, spot_count: int}>  $entries
     */
    private function assertStrategyRoundtrip(
        ComponentCalculationStrategy $strategy,
        string $secondPrice,
    ): void {
        $catalog = $this->createSpotClassicCatalog();
        $pm = User::factory()->role(Role::ProductManagement)->create();
        $sales = User::factory()->role(Role::Sales)->create();
        $this->setRuleStrategy($catalog, $strategy);
        $this->setSecondPrice($catalog, $secondPrice);

        $entries = [
            ['date' => '2026-03-02', 'hour' => 8, 'spot_count' => 3],
            ['date' => '2026-03-03', 'hour' => 8, 'spot_count' => 2],
        ];
        $payload = $this->calendarComponentsDraftPayload($catalog, $entries, $strategy);

        $this->actingAs($pm)
            ->postJson(route('standard-offers.preview'), [...$payload, 'title' => 'Preview '.$strategy->value])
            ->assertOk();

        $offer = $this->writer()->create('Calendar '.$strategy->value, $payload, $pm);
        $draft = $offer->draftVersion;
        $this->assertNotNull($draft);
        $this->assertSame('calendar', $draft->draft_payload['positions'][0]['spot_method']);
        $this->assertSame($strategy->value, $draft->draft_payload['positions'][0]['component_calculation_strategy']);
        $this->assertCount(2, $draft->draft_payload['positions'][0]['components']);
        $this->assertCount(2, $draft->draft_payload['positions'][0]['planner_entries']);

        $published = $this->writer()->publish($draft, (int) $draft->lock_version, $pm);
        $this->assertSame(4, (int) ($published->frozen_materialization['materialization_version'] ?? 0));
        $frozenPos = $published->frozen_materialization['positions'][0];
        $this->assertSame('calendar', $frozenPos['spot_method']);
        $this->assertSame($strategy->value, $frozenPos['component_calculation_strategy']);
        $this->assertCount(2, $frozenPos['components']);
        $this->assertCount(2, $frozenPos['planner_entries']);
        $this->assertArrayHasKey('media_gross', $frozenPos['components'][0]);
        $nnFrozen = (string) ($published->frozen_materialization['nn_invest'] ?? '');

        $adopted = $this->writer()->adopt($published, 'Adopt '.$strategy->value, null, null, $sales);
        $position = $adopted->positions->first();
        $this->assertSame('calendar', $position->spot_method?->value ?? $position->spot_method);
        $this->assertSame($strategy->value, $position->component_calculation_strategy?->value ?? $position->component_calculation_strategy);
        $this->assertCount(2, $position->components);
        $this->assertCount(2, $position->plannerEntries);
        $this->assertSame($nnFrozen, (string) $adopted->nn_invest);
        $this->assertSame($published->id, $adopted->origin_standard_offer_version_id);
        $this->assertSame(
            StandardOfferVersionStatus::Published,
            $published->fresh()->status,
        );

        $reloaded = app(CalculationWriter::class)->payloadFromCalculation($adopted->fresh([
            'positions',
            'positions.components',
            'positions.plannerEntries',
            'positions.planRows',
            'positions.timeRanges',
            'positions.discounts',
            'orderDiscounts',
        ]));
        $this->assertSame('calendar', $reloaded['positions'][0]['spot_method']);
        $this->assertCount(2, $reloaded['positions'][0]['components']);
        $this->assertCount(2, $reloaded['positions'][0]['planner_entries']);
        $this->assertSame($strategy->value, $reloaded['positions'][0]['component_calculation_strategy']);
    }

    private function writer(): StandardOfferWriter
    {
        return app(StandardOfferWriter::class);
    }

    /**
     * @param  array{hamburg: Inventory, medium: AdvertisingMedium}  $catalog
     * @param  list<array{date: string, hour: int, spot_count: int}>  $entries
     */
    private function publishCalendarComponents(
        array $catalog,
        User $pm,
        ComponentCalculationStrategy $strategy,
        array $entries,
    ): StandardOfferVersion {
        $offer = $this->writer()->create(
            'Calendar components fixture',
            $this->calendarComponentsDraftPayload($catalog, $entries, $strategy),
            $pm,
        );
        $draft = $offer->draftVersion;
        $this->assertNotNull($draft);

        return $this->writer()->publish($draft, (int) $draft->lock_version, $pm);
    }

    /**
     * @param  array{hamburg: Inventory, medium: AdvertisingMedium}  $catalog
     * @param  list<array{date: string, hour: int, spot_count: int}>  $entries
     * @param  array<string, mixed>  $positionOverrides
     * @return array<string, mixed>
     */
    private function calendarComponentsDraftPayload(
        array $catalog,
        array $entries,
        ComponentCalculationStrategy $strategy,
        array $positionOverrides = [],
    ): array {
        return $this->withLiveSchemaFingerprint([
            'planning_mode' => 'manual',
            'order_discount_percent' => '0',
            'ae_enabled' => false,
            'order_discounts' => [],
            'dynamic_field_values' => ['campaign_period' => null],
            'positions' => [[
                'client_key' => 'cal-comp',
                'inventory_id' => $catalog['hamburg']->id,
                'advertising_medium_id' => $catalog['medium']->id,
                'spot_method' => 'calendar',
                'calculation_method_key' => 'calendar',
                'length_seconds' => 30,
                'position_discount_percent' => '0',
                'ae_percent' => '0',
                'pricing_settlement_mode' => 'normal',
                'component_calculation_strategy' => $strategy->value,
                'components' => [
                    ['role' => 'main_spot', 'label' => 'Hauptspot', 'length_seconds' => 20, 'sort' => 0],
                    ['role' => 'allonge', 'label' => 'Allonge', 'length_seconds' => 10, 'sort' => 1],
                ],
                'planner_entries' => $entries,
                'plan_rows' => [],
                'time_ranges' => [],
                'position_discounts' => [],
                'dynamic_field_values' => ['period_open' => true],
                ...$positionOverrides,
            ]],
        ]);
    }

    /**
     * @param  array{hamburg: Inventory, medium: AdvertisingMedium}  $catalog
     */
    private function setRuleStrategy(array $catalog, ComponentCalculationStrategy $strategy): void
    {
        InventoryMediumRule::query()
            ->where('inventory_id', $catalog['hamburg']->id)
            ->where('advertising_medium_id', $catalog['medium']->id)
            ->update(['component_calculation_strategy' => $strategy->value]);
    }

    /**
     * @param  array{hamburg: Inventory}  $catalog
     */
    private function setSecondPrice(array $catalog, string $price): void
    {
        $list = PriceList::query()
            ->where('inventory_id', $catalog['hamburg']->id)
            ->where('status', PriceListStatus::Active)
            ->where('year', 2026)
            ->firstOrFail();

        PriceListItem::query()
            ->where('price_list_id', $list->id)
            ->update(['second_price' => $price]);
    }
}
