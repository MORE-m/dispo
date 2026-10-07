<?php

namespace Tests\Feature\StandardOffer;

use App\Enums\ComponentCalculationStrategy;
use App\Enums\DayGroup;
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
use App\Services\PriceList\Admin\PriceListAdminWriter;
use App\Services\PriceList\Admin\PriceListImpactPreviewService;
use App\Services\StandardOffer\FrozenCalculationPersistenceContract;
use App\Services\StandardOffer\StandardOfferMaterializer;
use App\Services\StandardOffer\StandardOfferWriter;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\Concerns\CreatesSpotClassicCatalog;
use Tests\TestCase;

/**
 * BL-P4-03j / PO-BLP403J-1 / A1:
 * Calendar × Spot Classic × Festpreis × optional Hauptspot+Allonge.
 */
class StandardOfferBlP403jTest extends TestCase
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
        $this->assertStrategyRoundtrip(
            ComponentCalculationStrategy::SharedTotalLength,
            '500.00',
            '600.00',
            ['', ''],
        );
    }

    public function test_individual_draft_preview_publish_adopt_reload_parity(): void
    {
        $this->assertStrategyRoundtrip(
            ComponentCalculationStrategy::Individual,
            '520.00',
            '640.00',
            ['420.00', '220.00'],
        );
    }

    public function test_first_adopt_after_live_price_change_keeps_frozen_parity(): void
    {
        $catalog = $this->createSpotClassicCatalog();
        $pm = User::factory()->role(Role::ProductManagement)->create();
        $sales = User::factory()->role(Role::Sales)->create();
        $this->setRuleStrategy($catalog, ComponentCalculationStrategy::SharedTotalLength);
        $this->setSecondPrice($catalog, '2.0000');

        $published = $this->publishCalendarFestpreisComponents(
            $catalog,
            $pm,
            ComponentCalculationStrategy::SharedTotalLength,
            '500.00',
        );
        $frozen = $published->frozen_materialization;
        $frozenPin = (int) $frozen['positions'][0]['price_list_id'];
        $frozenVersion = (string) ($frozen['positions'][0]['price_list_version'] ?? '');
        $this->assertSame('600.00', (string) ($frozen['media_gross'] ?? ''));
        $this->assertSame('500.00', (string) ($frozen['nn_invest'] ?? ''));
        $this->assertSame('2.0000', (string) ($frozen['positions'][0]['planner_entries'][0]['second_price'] ?? ''));
        $this->assertSame(
            ['', ''],
            collect($frozen['positions'][0]['components'])->sortBy('sort')->pluck('media_gross')->map(fn ($v) => (string) $v)->values()->all(),
        );

        $this->setSecondPrice($catalog, '9.0000');
        $this->assertSame('600.00', (string) ($published->fresh()->frozen_materialization['media_gross'] ?? ''));
        $this->assertSame('500.00', (string) ($published->fresh()->frozen_materialization['nn_invest'] ?? ''));
        $this->assertSame('2.0000', (string) ($published->fresh()->frozen_materialization['positions'][0]['planner_entries'][0]['second_price'] ?? ''));
        $this->assertSame($frozenPin, (int) $published->fresh()->frozen_materialization['positions'][0]['price_list_id']);

        $adopted = $this->writer()->adopt($published->fresh(), 'Adopt Freeze 03j', null, null, $sales);
        $position = $adopted->fresh(['positions.plannerEntries', 'positions.components'])->positions->firstOrFail();
        $this->assertSame($frozenPin, (int) $position->price_list_id);
        $this->assertSame($frozenVersion, (string) $position->price_list_version);
        $this->assertSame('2.0000', (string) $position->plannerEntries->first()->second_price);
        $this->assertSame('2026-03-02', $this->entryDate($position->plannerEntries->first()));
        $this->assertSame(8, (int) $position->plannerEntries->first()->hour);
        $this->assertSame(10, (int) $position->plannerEntries->first()->spot_count);
        $this->assertSame('600.00', (string) $adopted->media_gross);
        $this->assertSame('500.00', (string) $adopted->nn_invest);
        $this->assertSame('500.00', (string) $position->fixed_price_nn);
        $this->assertCount(2, $position->components);
        $this->assertSame(
            ['', ''],
            $position->components->sortBy('sort')->values()->map(fn ($c) => (string) ($c->media_gross ?? ''))->all(),
        );

        $reloaded = app(CalculationWriter::class)->payloadFromCalculation($adopted->fresh([
            'positions',
            'positions.plannerEntries',
            'positions.planRows',
            'positions.timeRanges',
            'positions.discounts',
            'positions.components',
            'orderDiscounts',
        ]));
        $this->assertSame('fixed_price', $reloaded['positions'][0]['pricing_settlement_mode']);
        $this->assertSame('500.00', (string) $reloaded['positions'][0]['fixed_price_nn']);
        $this->assertCount(2, $reloaded['positions'][0]['components']);
        $fresh = $adopted->fresh(['positions.plannerEntries', 'positions.components']);
        $this->assertSame('600.00', (string) $fresh->media_gross);
        $this->assertSame('500.00', (string) $fresh->nn_invest);
        $this->assertSame($frozenPin, (int) $fresh->positions->first()->price_list_id);
        $this->assertSame('2.0000', (string) $fresh->positions->first()->plannerEntries->first()->second_price);
    }

    public function test_cell_edit_after_successor_list_keeps_pinned_list_a_prices(): void
    {
        $catalog = $this->createSpotClassicCatalog();
        $pm = User::factory()->role(Role::ProductManagement)->create();
        $sales = User::factory()->role(Role::Sales)->create();
        $admin = User::factory()->role(Role::Admin)->create();
        $this->setRuleStrategy($catalog, ComponentCalculationStrategy::SharedTotalLength);
        $this->setSecondPrice($catalog, '2.0000');

        $listA = PriceList::query()
            ->where('inventory_id', $catalog['hamburg']->id)
            ->where('status', PriceListStatus::Active)
            ->where('year', 2026)
            ->firstOrFail();

        $published = $this->publishCalendarFestpreisComponents(
            $catalog,
            $pm,
            ComponentCalculationStrategy::SharedTotalLength,
            '500.00',
        );
        $this->assertSame($listA->id, (int) $published->frozen_materialization['positions'][0]['price_list_id']);
        $frozenVersion = (string) $published->frozen_materialization['positions'][0]['price_list_version'];

        $adopted = $this->writer()->adopt($published->fresh(), 'Pin 03j Kunde', null, null, $sales);
        $position = $adopted->fresh(['positions.plannerEntries', 'positions.components'])->positions->firstOrFail();
        $this->assertSame($listA->id, (int) $position->price_list_id);
        $this->assertSame('2.0000', (string) $position->plannerEntries->first()->second_price);
        $this->assertSame('500.00', (string) $adopted->nn_invest);
        $this->assertCount(2, $position->components);

        $listB = $this->activateSuccessorList($catalog['hamburg']->id, 2026, '9.0000', $admin);
        $this->assertNotSame($listA->id, $listB->id);
        $this->assertSame(PriceListStatus::Archived, $listA->fresh()->status);
        $this->assertSame(PriceListStatus::Active, $listB->fresh()->status);

        $reloaded = app(CalculationWriter::class)->payloadFromCalculation($adopted->fresh([
            'positions.plannerEntries',
            'positions.planRows',
            'positions.timeRanges',
            'positions.discounts',
            'positions.components',
            'orderDiscounts',
        ]));
        $reloaded['lock_version'] = $adopted->fresh()->lock_version;
        $reloaded['positions'][0]['planner_entries'] = [
            ['date' => '2026-03-02', 'hour' => 9, 'spot_count' => 10],
        ];
        $updated = app(CalculationWriter::class)->update($adopted->fresh(), $reloaded, $sales);
        $edited = $updated->fresh(['positions.plannerEntries', 'positions.components'])->positions->firstOrFail();
        $this->assertSame($listA->id, (int) $edited->price_list_id);
        $this->assertNotSame($listB->id, (int) $edited->price_list_id);
        $this->assertSame($frozenVersion, (string) $edited->price_list_version);
        $this->assertSame('2.0000', (string) $edited->plannerEntries->first()->second_price);
        $this->assertSame(9, (int) $edited->plannerEntries->first()->hour);
        $this->assertSame('500.00', (string) $updated->nn_invest);
        $this->assertSame('600.00', (string) $updated->media_gross);
        $this->assertSame('500.00', (string) $edited->fixed_price_nn);
        $this->assertCount(2, $edited->components);
        $this->assertSame(
            ['', ''],
            $edited->components->sortBy('sort')->values()->map(fn ($c) => (string) ($c->media_gross ?? ''))->all(),
        );
    }

    public function test_from_calc_calendar_festpreis_components_succeeds_and_mix_fails(): void
    {
        $catalog = $this->createSpotClassicCatalog();
        $sales = User::factory()->role(Role::Sales)->create();
        $this->setRuleStrategy($catalog, ComponentCalculationStrategy::SharedTotalLength);
        $this->setSecondPrice($catalog, '2.0000');

        $calcPayload = $this->withLiveSchemaFingerprint([
            'planning_mode' => 'manual',
            'customer_name' => 'Geheimkunde 03j',
            'order_discount_percent' => '0',
            'ae_enabled' => false,
            'order_discounts' => [],
            'dynamic_field_values' => [],
            'positions' => [[
                ...$this->calendarFestpreisComponentsPosition(
                    $catalog,
                    [['date' => '2026-03-02', 'hour' => 8, 'spot_count' => 10]],
                    ComponentCalculationStrategy::SharedTotalLength,
                    '500.00',
                ),
            ]],
        ]);
        $calculation = app(CalculationWriter::class)->create($calcPayload, $sales);
        $offer = $this->writer()->createFromCalculation(
            $calculation->fresh(['positions', 'positions.components', 'positions.plannerEntries']),
            $sales,
        );
        $draft = $offer->draftVersion;
        $this->assertNotNull($draft);
        $this->assertSame('fixed_price', $draft->draft_payload['positions'][0]['pricing_settlement_mode']);
        $this->assertSame('500.00', $draft->draft_payload['positions'][0]['fixed_price_nn']);
        $this->assertSame(
            ComponentCalculationStrategy::SharedTotalLength->value,
            $draft->draft_payload['positions'][0]['component_calculation_strategy'],
        );
        $this->assertCount(2, $draft->draft_payload['positions'][0]['components']);
        $this->assertArrayNotHasKey('customer_name', $draft->draft_payload);
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
                $this->calendarFestpreisComponentsPosition(
                    $catalog,
                    [['date' => '2026-03-02', 'hour' => 8, 'spot_count' => 1]],
                    ComponentCalculationStrategy::SharedTotalLength,
                    '50.00',
                ),
            ],
        ]);
        $mixedCalc = app(CalculationWriter::class)->create($mixed, $sales);
        $before = StandardOffer::query()->count();
        $this->actingAs($sales)
            ->post(route('standard-offers.from-calculation', $mixedCalc))
            ->assertSessionHasErrors();
        $this->assertSame($before, StandardOffer::query()->count());
    }

    public function test_normal_and_fixed_price_calendar_positions_side_by_side(): void
    {
        $catalog = $this->createSpotClassicCatalog();
        $pm = User::factory()->role(Role::ProductManagement)->create();
        $sales = User::factory()->role(Role::Sales)->create();
        $this->setRuleStrategy($catalog, ComponentCalculationStrategy::SharedTotalLength);
        $this->setSecondPrice($catalog, '2.0000');

        $payload = $this->withLiveSchemaFingerprint([
            'planning_mode' => 'manual',
            'order_discount_percent' => '0',
            'ae_enabled' => false,
            'order_discounts' => [],
            'dynamic_field_values' => ['campaign_period' => null],
            'positions' => [
                $this->calendarFestpreisComponentsPosition(
                    $catalog,
                    [['date' => '2026-03-02', 'hour' => 8, 'spot_count' => 10]],
                    ComponentCalculationStrategy::SharedTotalLength,
                    '500.00',
                    'cal-fp-comp',
                ),
                [
                    'client_key' => 'cal-normal',
                    'inventory_id' => $catalog['hamburg']->id,
                    'advertising_medium_id' => $catalog['medium']->id,
                    'spot_method' => 'calendar',
                    'calculation_method_key' => 'calendar',
                    'length_seconds' => 30,
                    'position_discount_percent' => '0',
                    'ae_percent' => '0',
                    'pricing_settlement_mode' => 'normal',
                    'components' => [],
                    'planner_entries' => [['date' => '2026-03-03', 'hour' => 8, 'spot_count' => 10]],
                    'plan_rows' => [],
                    'time_ranges' => [],
                    'position_discounts' => [],
                    'dynamic_field_values' => ['period_open' => true],
                ],
            ],
        ]);

        $offer = $this->writer()->create('Mix Settlement Calendar 03j', $payload, $pm);
        $draft = $offer->draftVersion;
        $this->assertNotNull($draft);
        $this->assertSame('fixed_price', $draft->draft_payload['positions'][0]['pricing_settlement_mode']);
        $this->assertCount(2, $draft->draft_payload['positions'][0]['components']);
        $this->assertSame('normal', $draft->draft_payload['positions'][1]['pricing_settlement_mode']);
        $this->assertSame([], $draft->draft_payload['positions'][1]['components']);

        $published = $this->writer()->publish($draft, (int) $draft->lock_version, $pm);
        $adopted = $this->writer()->adopt($published, 'Mix 03j Kunde', null, null, $sales);
        $sorted = $adopted->fresh(['positions.components'])->positions->sortBy('sort')->values();
        $this->assertSame('fixed_price', $sorted[0]->pricing_settlement_mode?->value ?? $sorted[0]->pricing_settlement_mode);
        $this->assertSame('500.00', (string) $sorted[0]->fixed_price_nn);
        $this->assertCount(2, $sorted[0]->components);
        $this->assertSame('normal', $sorted[1]->pricing_settlement_mode?->value ?? $sorted[1]->pricing_settlement_mode);
        $this->assertCount(0, $sorted[1]->components);
    }

    public function test_wrong_inventory_strategy_fails_on_create_preview(): void
    {
        $catalog = $this->createSpotClassicCatalog();
        $pm = User::factory()->role(Role::ProductManagement)->create();
        $this->setRuleStrategy($catalog, ComponentCalculationStrategy::SharedTotalLength);
        $this->setSecondPrice($catalog, '2.0000');

        $payload = $this->calendarFestpreisComponentsPayload(
            $catalog,
            [['date' => '2026-03-02', 'hour' => 8, 'spot_count' => 10]],
            ComponentCalculationStrategy::Individual,
            '520.00',
        );

        try {
            $this->writer()->create('Wrong strategy 03j', $payload, $pm);
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
            ->postJson(route('standard-offers.preview'), [...$payload, 'title' => 'Wrong strategy preview 03j'])
            ->assertStatus(422);

        $this->assertSame(0, StandardOffer::query()->count());
    }

    public function test_inventory_strategy_change_after_draft_blocks_publish(): void
    {
        $catalog = $this->createSpotClassicCatalog();
        $pm = User::factory()->role(Role::ProductManagement)->create();
        $this->setRuleStrategy($catalog, ComponentCalculationStrategy::SharedTotalLength);
        $this->setSecondPrice($catalog, '2.0000');

        $offer = $this->writer()->create(
            'Draft vor Regelwechsel 03j',
            $this->calendarFestpreisComponentsPayload(
                $catalog,
                [['date' => '2026-03-02', 'hour' => 8, 'spot_count' => 10]],
                ComponentCalculationStrategy::SharedTotalLength,
                '500.00',
            ),
            $pm,
        );
        $draft = $offer->draftVersion;
        $this->assertNotNull($draft);
        $draftId = $draft->id;
        $lock = (int) $draft->lock_version;
        $this->assertSame(StandardOfferVersionStatus::Draft, $draft->status);
        $this->assertTrue(
            $draft->frozen_materialization === null
            || $draft->frozen_materialization === [],
        );

        $this->setRuleStrategy($catalog, ComponentCalculationStrategy::Individual);

        try {
            $this->writer()->publish($draft->fresh(), $lock, $pm);
            $this->fail('Publish nach Inventarstrategie-Wechsel hätte scheitern müssen.');
        } catch (ValidationException $exception) {
            $this->assertTrue(
                collect($exception->errors())->keys()->contains(
                    fn ($key) => str_contains((string) $key, 'component_calculation_strategy')
                        || str_contains((string) $key, 'components'),
                ),
                json_encode($exception->errors()),
            );
        }

        $stillDraft = StandardOfferVersion::query()->findOrFail($draftId);
        $this->assertSame(StandardOfferVersionStatus::Draft, $stillDraft->status);
        $this->assertTrue(
            $stillDraft->frozen_materialization === null
            || $stillDraft->frozen_materialization === [],
        );
        $this->assertSame(0, StandardOfferVersion::query()
            ->where('standard_offer_id', $offer->id)
            ->where('status', StandardOfferVersionStatus::Published)
            ->count());
    }

    public function test_missing_price_and_year_mismatch_fail_on_create_preview_publish(): void
    {
        $catalog = $this->createSpotClassicCatalog();
        $pm = User::factory()->role(Role::ProductManagement)->create();
        $this->setRuleStrategy($catalog, ComponentCalculationStrategy::SharedTotalLength);
        $this->setSecondPrice($catalog, '2.0000');

        $active = PriceList::query()
            ->where('inventory_id', $catalog['hamburg']->id)
            ->where('status', PriceListStatus::Active)
            ->where('year', 2026)
            ->firstOrFail();
        PriceListItem::query()
            ->where('price_list_id', $active->id)
            ->where('hour', 22)
            ->delete();

        $missing = $this->calendarFestpreisComponentsPayload(
            $catalog,
            [['date' => '2026-03-02', 'hour' => 22, 'spot_count' => 1]],
            ComponentCalculationStrategy::SharedTotalLength,
            '50.00',
        );

        try {
            $this->writer()->create('Missing price 03j', $missing, $pm);
            $this->fail('Create mit fehlender Preiszelle hätte scheitern müssen.');
        } catch (ValidationException $exception) {
            $this->assertNotEmpty($exception->errors());
        }
        $this->assertSame(0, StandardOffer::query()->count());

        $this->actingAs($pm)
            ->postJson(route('standard-offers.preview'), [...$missing, 'title' => 'Missing preview 03j'])
            ->assertStatus(422);

        $valid = $this->writer()->create(
            'Publish base 03j',
            $this->calendarFestpreisComponentsPayload(
                $catalog,
                [['date' => '2026-03-02', 'hour' => 8, 'spot_count' => 10]],
                ComponentCalculationStrategy::SharedTotalLength,
                '500.00',
            ),
            $pm,
        );
        $draft = $valid->draftVersion;
        $this->assertNotNull($draft);
        $corrupted = $draft->draft_payload;
        $corrupted['positions'][0]['planner_entries'] = [
            ['date' => '2026-03-02', 'hour' => 22, 'spot_count' => 1],
        ];
        $draft->draft_payload = $corrupted;
        $draft->save();

        try {
            $this->writer()->publish($draft->fresh(), (int) $draft->lock_version, $pm);
            $this->fail('Publish mit fehlender Preiszelle hätte scheitern müssen.');
        } catch (ValidationException $exception) {
            $this->assertNotEmpty($exception->errors());
        }
        $this->assertSame(0, StandardOfferVersion::query()
            ->where('standard_offer_id', $valid->id)
            ->where('status', StandardOfferVersionStatus::Published)
            ->count());
        $this->assertSame(StandardOfferVersionStatus::Draft, $draft->fresh()->status);
        $this->assertTrue(
            $draft->fresh()->frozen_materialization === null
            || $draft->fresh()->frozen_materialization === [],
        );
    }

    public function test_planner_date_outside_price_year_fail_on_create_preview_publish(): void
    {
        $catalog = $this->createSpotClassicCatalog();
        $pm = User::factory()->role(Role::ProductManagement)->create();
        $this->setRuleStrategy($catalog, ComponentCalculationStrategy::SharedTotalLength);
        $this->setSecondPrice($catalog, '2.0000');

        $active2026 = PriceList::query()
            ->where('inventory_id', $catalog['hamburg']->id)
            ->where('status', PriceListStatus::Active)
            ->where('year', 2026)
            ->firstOrFail();

        $outsideYear = $this->calendarFestpreisComponentsPayload(
            $catalog,
            [['date' => '2027-03-01', 'hour' => 8, 'spot_count' => 10]],
            ComponentCalculationStrategy::SharedTotalLength,
            '50.00',
            [
                'price_year' => 2026,
                'expected_price_list_id' => $active2026->id,
            ],
        );

        $this->assertCalendarFestpreisComponentsRejectedOnCreatePreviewPublish(
            $catalog,
            $pm,
            $outsideYear,
            'positions.0.planner_entries.0.date',
            'getrennte Position',
            '2027-03-01',
        );
    }

    public function test_cells_across_year_boundary_fail_on_create_preview_publish(): void
    {
        $catalog = $this->createSpotClassicCatalog();
        $pm = User::factory()->role(Role::ProductManagement)->create();
        $this->setRuleStrategy($catalog, ComponentCalculationStrategy::SharedTotalLength);
        $this->setSecondPrice($catalog, '2.0000');

        $active2026 = PriceList::query()
            ->where('inventory_id', $catalog['hamburg']->id)
            ->where('status', PriceListStatus::Active)
            ->where('year', 2026)
            ->firstOrFail();

        $yearBoundary = $this->calendarFestpreisComponentsPayload(
            $catalog,
            [
                ['date' => '2026-12-31', 'hour' => 8, 'spot_count' => 1],
                ['date' => '2027-01-01', 'hour' => 9, 'spot_count' => 1],
            ],
            ComponentCalculationStrategy::SharedTotalLength,
            '50.00',
            [
                'price_year' => 2026,
                'expected_price_list_id' => $active2026->id,
            ],
        );

        $this->assertCalendarFestpreisComponentsRejectedOnCreatePreviewPublish(
            $catalog,
            $pm,
            $yearBoundary,
            'positions.0.planner_entries.1.date',
            'getrennte Position',
            '2027-01-01',
        );
    }

    public function test_frozen_calendar_festpreis_component_inconsistencies_fail_closed_independently(): void
    {
        $catalog = $this->createSpotClassicCatalog();
        $pm = User::factory()->role(Role::ProductManagement)->create();
        $sales = User::factory()->role(Role::Sales)->create();
        $this->setRuleStrategy($catalog, ComponentCalculationStrategy::SharedTotalLength);
        $this->setSecondPrice($catalog, '2.0000');

        $published = $this->publishCalendarFestpreisComponents(
            $catalog,
            $pm,
            ComponentCalculationStrategy::SharedTotalLength,
            '500.00',
        );
        /** @var array<string, mixed> $baseline */
        $baseline = json_decode(json_encode($published->frozen_materialization, JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR);
        app(FrozenCalculationPersistenceContract::class)->assertHydratable($baseline);

        $cases = [
            'festpreis without nn' => [
                'needle' => 'fixed_price_nn',
                'mutate' => static function (array $mat): array {
                    $mat['positions'][0]['fixed_price_nn'] = null;

                    return $mat;
                },
            ],
            'nn without festpreis' => [
                'needle' => 'fixed_price_nn ohne Festpreismodus',
                'mutate' => static function (array $mat): array {
                    $mat['positions'][0]['pricing_settlement_mode'] = 'normal';

                    return $mat;
                },
            ],
            'missing main spot' => [
                'needle' => 'Hauptspot fehlt',
                'mutate' => static function (array $mat): array {
                    $mat['positions'][0]['components'] = [
                        $mat['positions'][0]['components'][1],
                    ];
                    $mat['positions'][0]['components'][0]['sort'] = 0;
                    $mat['positions'][0]['components'][0]['length_index'] = 0;

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
            'invalid nn format' => [
                'needle' => 'fixed_price_nn',
                'mutate' => static function (array $mat): array {
                    $mat['positions'][0]['fixed_price_nn'] = '1e2';

                    return $mat;
                },
            ],
            'planner missing second_price' => [
                'needle' => 'planner_entries',
                'mutate' => static function (array $mat): array {
                    unset($mat['positions'][0]['planner_entries'][0]['second_price']);

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

        $published->forceFill(['frozen_materialization' => $baseline])->save();
        $ok = $this->writer()->adopt($published->fresh(), 'Baseline OK 03j', null, null, $sales);
        $this->assertSame('500.00', (string) $ok->nn_invest);
        $this->assertCount(2, $ok->positions->first()->components);
    }

    public function test_regression_calendar_normal_allonge_festpreis_einzelspot_and_average_festpreis(): void
    {
        $catalog = $this->createSpotClassicCatalog();
        $pm = User::factory()->role(Role::ProductManagement)->create();
        $sales = User::factory()->role(Role::Sales)->create();
        $this->setRuleStrategy($catalog, ComponentCalculationStrategy::SharedTotalLength);
        $this->setSecondPrice($catalog, '2.0000');

        $allonge = $this->writer()->create(
            '03h Regression 03j',
            $this->withLiveSchemaFingerprint([
                'planning_mode' => 'manual',
                'order_discount_percent' => '0',
                'ae_enabled' => false,
                'order_discounts' => [],
                'dynamic_field_values' => ['campaign_period' => null],
                'positions' => [[
                    'client_key' => 'cal-normal-comp',
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
                    'planner_entries' => [['date' => '2026-03-02', 'hour' => 8, 'spot_count' => 2]],
                    'plan_rows' => [],
                    'time_ranges' => [],
                    'position_discounts' => [],
                    'dynamic_field_values' => ['period_open' => true],
                ]],
            ]),
            $pm,
        );
        $publishedAllonge = $this->writer()->publish($allonge->draftVersion, (int) $allonge->draftVersion->lock_version, $pm);
        $adoptedAllonge = $this->writer()->adopt($publishedAllonge, 'Allonge Kunde', null, null, $sales);
        $this->assertCount(2, $adoptedAllonge->positions->first()->components);
        $this->assertSame('normal', $adoptedAllonge->positions->first()->pricing_settlement_mode?->value
            ?? $adoptedAllonge->positions->first()->pricing_settlement_mode);

        $einzel = $this->writer()->create(
            '03i Regression 03j',
            $this->withLiveSchemaFingerprint([
                'planning_mode' => 'manual',
                'order_discount_percent' => '0',
                'ae_enabled' => false,
                'order_discounts' => [],
                'dynamic_field_values' => ['campaign_period' => null],
                'positions' => [[
                    'client_key' => 'cal-fp',
                    'inventory_id' => $catalog['hamburg']->id,
                    'advertising_medium_id' => $catalog['medium']->id,
                    'spot_method' => 'calendar',
                    'calculation_method_key' => 'calendar',
                    'length_seconds' => 30,
                    'position_discount_percent' => '0',
                    'ae_percent' => '0',
                    'pricing_settlement_mode' => 'fixed_price',
                    'fixed_price_nn' => '500.00',
                    'components' => [],
                    'planner_entries' => [['date' => '2026-03-02', 'hour' => 8, 'spot_count' => 10]],
                    'plan_rows' => [],
                    'time_ranges' => [],
                    'position_discounts' => [],
                    'dynamic_field_values' => ['period_open' => true],
                ]],
            ]),
            $pm,
        );
        $publishedEinzel = $this->writer()->publish($einzel->draftVersion, (int) $einzel->draftVersion->lock_version, $pm);
        $adoptedEinzel = $this->writer()->adopt($publishedEinzel, 'Einzel FP Kunde', null, null, $sales);
        $this->assertSame('500.00', (string) $adoptedEinzel->positions->first()->fixed_price_nn);
        $this->assertCount(0, $adoptedEinzel->positions->first()->components);

        $average = $this->writer()->create(
            '03e Regression 03j',
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
                    'pricing_settlement_mode' => 'fixed_price',
                    'fixed_price_nn' => '180.00',
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
            ]),
            $pm,
        );
        $publishedAvg = $this->writer()->publish($average->draftVersion, (int) $average->draftVersion->lock_version, $pm);
        $adoptedAvg = $this->writer()->adopt($publishedAvg, 'Avg FP Kunde', null, null, $sales);
        $this->assertSame('180.00', (string) $adoptedAvg->positions->first()->fixed_price_nn);
    }

    public function test_rejects_budget_still(): void
    {
        $catalog = $this->createSpotClassicCatalog();
        $pm = User::factory()->role(Role::ProductManagement)->create();
        $this->setRuleStrategy($catalog, ComponentCalculationStrategy::SharedTotalLength);
        $this->setSecondPrice($catalog, '2.0000');

        // Calendar×Tandem: freigegeben in BL-P4-03k – Positivfälle dort.

        $this->actingAs($pm)->post(route('standard-offers.store'), [
            ...$this->calendarFestpreisComponentsPayload(
                $catalog,
                [['date' => '2026-03-02', 'hour' => 8, 'spot_count' => 1]],
                ComponentCalculationStrategy::SharedTotalLength,
                '50.00',
            ),
            'title' => 'Budget Reject 03j',
            'planning_mode' => 'budget',
        ])->assertSessionHasErrors();
    }

    private function assertStrategyRoundtrip(
        ComponentCalculationStrategy $strategy,
        string $fixedPriceNn,
        string $expectedMediaGross,
        array $expectedComponentGrosses,
    ): void {
        $catalog = $this->createSpotClassicCatalog();
        $pm = User::factory()->role(Role::ProductManagement)->create();
        $sales = User::factory()->role(Role::Sales)->create();
        $this->setRuleStrategy($catalog, $strategy);
        $this->setSecondPrice($catalog, '2.0000');

        $payload = $this->calendarFestpreisComponentsPayload(
            $catalog,
            [['date' => '2026-03-02', 'hour' => 8, 'spot_count' => 10]],
            $strategy,
            $fixedPriceNn,
        );

        $this->actingAs($pm)
            ->postJson(route('standard-offers.preview'), [...$payload, 'title' => 'Preview 03j '.$strategy->value])
            ->assertOk()
            ->assertJsonPath('totals.nn_invest', $fixedPriceNn)
            ->assertJsonPath('totals.media_gross', $expectedMediaGross)
            ->assertJsonPath('totals.positions.0.components.0.media_gross', $expectedComponentGrosses[0])
            ->assertJsonPath('totals.positions.0.components.1.media_gross', $expectedComponentGrosses[1]);

        $offer = $this->writer()->create('Calendar FP '.$strategy->value, $payload, $pm);
        $draft = $offer->draftVersion;
        $this->assertNotNull($draft);
        $this->assertSame('calendar', $draft->draft_payload['positions'][0]['spot_method']);
        $this->assertSame('fixed_price', $draft->draft_payload['positions'][0]['pricing_settlement_mode']);
        $this->assertSame($fixedPriceNn, $draft->draft_payload['positions'][0]['fixed_price_nn']);
        $this->assertSame($strategy->value, $draft->draft_payload['positions'][0]['component_calculation_strategy']);
        $this->assertCount(2, $draft->draft_payload['positions'][0]['components']);

        $published = $this->writer()->publish($draft, (int) $draft->lock_version, $pm);
        $this->assertSame(StandardOfferVersionStatus::Published, $published->status);
        $this->assertSame(4, (int) ($published->frozen_materialization['materialization_version'] ?? 0));
        $frozen = $published->frozen_materialization;
        $this->assertSame($expectedMediaGross, (string) ($frozen['media_gross'] ?? ''));
        $this->assertSame($fixedPriceNn, (string) ($frozen['nn_invest'] ?? ''));
        $this->assertSame('fixed_price', $frozen['positions'][0]['pricing_settlement_mode'] ?? null);
        $this->assertSame($fixedPriceNn, (string) ($frozen['positions'][0]['fixed_price_nn'] ?? ''));
        $this->assertSame($strategy->value, $frozen['positions'][0]['component_calculation_strategy'] ?? null);
        $this->assertSame(
            $expectedComponentGrosses,
            collect($frozen['positions'][0]['components'])->sortBy('sort')->pluck('media_gross')->map(fn ($v) => (string) $v)->values()->all(),
        );
        $this->assertSame('2.0000', (string) ($frozen['positions'][0]['planner_entries'][0]['second_price'] ?? ''));
        $frozenPin = (int) $frozen['positions'][0]['price_list_id'];
        $frozenVersion = (string) ($frozen['positions'][0]['price_list_version'] ?? '');

        $adopted = $this->writer()->adopt($published, '03j '.$strategy->value, null, null, $sales);
        $position = $adopted->fresh(['positions.plannerEntries', 'positions.components'])->positions->firstOrFail();
        $this->assertSame($fixedPriceNn, (string) $adopted->nn_invest);
        $this->assertSame($expectedMediaGross, (string) $adopted->media_gross);
        $this->assertSame('fixed_price', $position->pricing_settlement_mode?->value ?? $position->pricing_settlement_mode);
        $this->assertSame($fixedPriceNn, (string) $position->fixed_price_nn);
        $this->assertSame($strategy->value, $position->component_calculation_strategy?->value ?? $position->component_calculation_strategy);
        $this->assertSame($frozenPin, (int) $position->price_list_id);
        $this->assertSame($frozenVersion, (string) $position->price_list_version);
        $this->assertSame('2.0000', (string) $position->plannerEntries->first()->second_price);
        $this->assertSame('2026-03-02', $this->entryDate($position->plannerEntries->first()));
        $this->assertCount(2, $position->components);
        $this->assertSame(
            $expectedComponentGrosses,
            $position->components->sortBy('sort')->values()->map(fn ($c) => (string) ($c->media_gross ?? ''))->all(),
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
        $this->assertSame('fixed_price', $reloaded['positions'][0]['pricing_settlement_mode']);
        $this->assertSame($fixedPriceNn, (string) $reloaded['positions'][0]['fixed_price_nn']);
        $this->assertSame($strategy->value, $reloaded['positions'][0]['component_calculation_strategy']);
        $this->assertCount(2, $reloaded['positions'][0]['components']);
        $this->assertSame($fixedPriceNn, (string) $adopted->fresh()->nn_invest);
        $this->assertSame($expectedMediaGross, (string) $adopted->fresh()->media_gross);
        $this->assertSame(
            '2.0000',
            (string) $adopted->fresh(['positions.plannerEntries'])->positions->first()->plannerEntries->first()->second_price,
        );
    }

    private function writer(): StandardOfferWriter
    {
        return app(StandardOfferWriter::class);
    }

    /**
     * @param  array{hamburg: Inventory, medium: AdvertisingMedium}  $catalog
     * @param  array<string, mixed>  $invalidPayload
     */
    private function assertCalendarFestpreisComponentsRejectedOnCreatePreviewPublish(
        array $catalog,
        User $pm,
        array $invalidPayload,
        string $errorKey,
        string $needle,
        string $dateNeedle,
    ): void {
        $offersBefore = StandardOffer::query()->count();
        try {
            $this->writer()->create('Invalid year create 03j', $invalidPayload, $pm);
            $this->fail('Create hätte an der Jahresgrenze scheitern müssen.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey($errorKey, $exception->errors());
            $joined = implode(' ', $exception->errors()[$errorKey]);
            $this->assertStringContainsString($needle, $joined);
            $this->assertStringContainsString($dateNeedle, $joined);
        }
        $this->assertSame($offersBefore, StandardOffer::query()->count());

        $this->actingAs($pm)
            ->postJson(route('standard-offers.preview'), [...$invalidPayload, 'title' => 'Invalid year preview 03j'])
            ->assertStatus(422)
            ->assertJsonValidationErrors($errorKey);
        $this->assertSame($offersBefore, StandardOffer::query()->count());

        $valid = $this->writer()->create(
            'Year publish baseline 03j',
            $this->calendarFestpreisComponentsPayload(
                $catalog,
                [['date' => '2026-03-02', 'hour' => 8, 'spot_count' => 10]],
                ComponentCalculationStrategy::SharedTotalLength,
                '500.00',
            ),
            $pm,
        );
        $draft = $valid->draftVersion;
        $this->assertNotNull($draft);
        $this->assertSame(StandardOfferVersionStatus::Draft, $draft->status);
        $this->assertTrue(
            $draft->frozen_materialization === null
            || $draft->frozen_materialization === [],
        );

        $corrupted = $draft->draft_payload;
        $corrupted['positions'][0]['planner_entries'] = $invalidPayload['positions'][0]['planner_entries'];
        if (array_key_exists('price_year', $invalidPayload['positions'][0])) {
            $corrupted['positions'][0]['price_year'] = $invalidPayload['positions'][0]['price_year'];
        }
        if (array_key_exists('expected_price_list_id', $invalidPayload['positions'][0])) {
            $corrupted['positions'][0]['expected_price_list_id'] = $invalidPayload['positions'][0]['expected_price_list_id'];
        }
        $draft->draft_payload = $corrupted;
        $draft->save();

        try {
            $this->writer()->publish($draft->fresh(), (int) $draft->lock_version, $pm);
            $this->fail('Publish hätte an der Jahresgrenze scheitern müssen.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey($errorKey, $exception->errors());
            $joined = implode(' ', $exception->errors()[$errorKey]);
            $this->assertStringContainsString($needle, $joined);
            $this->assertStringContainsString($dateNeedle, $joined);
        }

        $fresh = $draft->fresh();
        $this->assertSame(StandardOfferVersionStatus::Draft, $fresh->status);
        $this->assertTrue(
            $fresh->frozen_materialization === null
            || $fresh->frozen_materialization === [],
        );
        $this->assertSame(0, StandardOfferVersion::query()
            ->where('standard_offer_id', $valid->id)
            ->where('status', StandardOfferVersionStatus::Published)
            ->count());
    }

    private function activateSuccessorList(int $inventoryId, int $year, string $secondPrice, User $admin): PriceList
    {
        $items = [];
        foreach (range(0, 23) as $hour) {
            foreach ([DayGroup::MoFr, DayGroup::Sa, DayGroup::So] as $group) {
                $items[] = [
                    'hour' => $hour,
                    'day_group' => $group->value,
                    'second_price' => $secondPrice,
                ];
            }
        }

        $draft = app(PriceListAdminWriter::class)->createDraft([
            'inventory_id' => $inventoryId,
            'year' => $year,
            'name' => 'Nachfolger 03j '.$secondPrice,
            'items' => $items,
        ], $admin);
        $preview = app(PriceListImpactPreviewService::class)->previewActivate($draft);

        return app(PriceListAdminWriter::class)->activate($draft, [
            'lock_version' => $draft->lock_version,
            'fingerprint' => (string) $preview['fingerprint'],
        ], $admin);
    }

    /**
     * @param  array{hamburg: Inventory, medium: AdvertisingMedium}  $catalog
     */
    private function publishCalendarFestpreisComponents(
        array $catalog,
        User $pm,
        ComponentCalculationStrategy $strategy,
        string $fixedPriceNn,
    ): StandardOfferVersion {
        $offer = $this->writer()->create(
            'Calendar festpreis components fixture',
            $this->calendarFestpreisComponentsPayload(
                $catalog,
                [['date' => '2026-03-02', 'hour' => 8, 'spot_count' => 10]],
                $strategy,
                $fixedPriceNn,
            ),
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
    private function calendarFestpreisComponentsPayload(
        array $catalog,
        array $entries,
        ComponentCalculationStrategy $strategy,
        string $fixedPriceNn,
        array $positionOverrides = [],
    ): array {
        return $this->withLiveSchemaFingerprint([
            'planning_mode' => 'manual',
            'order_discount_percent' => '0',
            'ae_enabled' => false,
            'order_discounts' => [],
            'dynamic_field_values' => ['campaign_period' => null],
            'positions' => [[
                ...$this->calendarFestpreisComponentsPosition($catalog, $entries, $strategy, $fixedPriceNn),
                ...$positionOverrides,
            ]],
        ]);
    }

    /**
     * @param  array{hamburg: Inventory, medium: AdvertisingMedium}  $catalog
     * @param  list<array{date: string, hour: int, spot_count: int}>  $entries
     * @return array<string, mixed>
     */
    private function calendarFestpreisComponentsPosition(
        array $catalog,
        array $entries,
        ComponentCalculationStrategy $strategy,
        string $fixedPriceNn,
        string $clientKey = 'cal-fp-comp',
    ): array {
        return [
            'client_key' => $clientKey,
            'inventory_id' => $catalog['hamburg']->id,
            'advertising_medium_id' => $catalog['medium']->id,
            'spot_method' => 'calendar',
            'calculation_method_key' => 'calendar',
            'length_seconds' => 30,
            'position_discount_percent' => '0',
            'ae_percent' => '0',
            'pricing_settlement_mode' => 'fixed_price',
            'fixed_price_nn' => $fixedPriceNn,
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
        ];
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

    private function entryDate(mixed $entry): string
    {
        $date = $entry->date;

        return $date instanceof CarbonInterface ? $date->toDateString() : (string) $date;
    }
}
