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
 * BL-P4-03i / PO-BLP403I-1 / A1 (+ B1+C1):
 * Calendar × Spot Classic × Festpreis, nur Einzelspot.
 */
class StandardOfferBlP403iTest extends TestCase
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

    public function test_draft_preview_publish_adopt_reload_fixture_parity(): void
    {
        $catalog = $this->createSpotClassicCatalog();
        $pm = User::factory()->role(Role::ProductManagement)->create();
        $sales = User::factory()->role(Role::Sales)->create();
        $this->setSecondPrice($catalog, '2.0000');

        $payload = $this->calendarFestpreisPayload($catalog, [
            ['date' => '2026-03-02', 'hour' => 8, 'spot_count' => 10],
        ], '500.00');

        $this->actingAs($pm)
            ->postJson(route('standard-offers.preview'), [...$payload, 'title' => 'Preview 03i'])
            ->assertOk()
            ->assertJsonPath('totals.nn_invest', '500.00')
            ->assertJsonPath('totals.media_gross', '600.00');

        $offer = $this->writer()->create('Calendar Festpreis Einzelspot', $payload, $pm);
        $draft = $offer->draftVersion;
        $this->assertNotNull($draft);
        $this->assertSame('calendar', $draft->draft_payload['positions'][0]['spot_method']);
        $this->assertSame('fixed_price', $draft->draft_payload['positions'][0]['pricing_settlement_mode']);
        $this->assertSame('500.00', $draft->draft_payload['positions'][0]['fixed_price_nn']);
        $this->assertSame([], $draft->draft_payload['positions'][0]['components']);

        $published = $this->writer()->publish($draft, (int) $draft->lock_version, $pm);
        $this->assertSame(StandardOfferVersionStatus::Published, $published->status);
        $this->assertSame(4, (int) ($published->frozen_materialization['materialization_version'] ?? 0));
        $frozen = $published->frozen_materialization;
        $this->assertSame('600.00', (string) ($frozen['media_gross'] ?? ''));
        $this->assertSame('500.00', (string) ($frozen['nn_invest'] ?? ''));
        $this->assertSame('fixed_price', $frozen['positions'][0]['pricing_settlement_mode'] ?? null);
        $this->assertSame('500.00', (string) ($frozen['positions'][0]['fixed_price_nn'] ?? ''));
        $this->assertSame('2.0000', (string) ($frozen['positions'][0]['planner_entries'][0]['second_price'] ?? ''));
        $frozenPin = (int) $frozen['positions'][0]['price_list_id'];
        $frozenVersion = (string) ($frozen['positions'][0]['price_list_version'] ?? '');

        $adopted = $this->writer()->adopt($published, '03i Kunde', null, null, $sales);
        $position = $adopted->fresh(['positions.plannerEntries'])->positions->firstOrFail();
        $this->assertSame('500.00', (string) $adopted->nn_invest);
        $this->assertSame('600.00', (string) $adopted->media_gross);
        $this->assertSame('fixed_price', $position->pricing_settlement_mode?->value ?? $position->pricing_settlement_mode);
        $this->assertSame('500.00', (string) $position->fixed_price_nn);
        $this->assertSame($frozenPin, (int) $position->price_list_id);
        $this->assertSame($frozenVersion, (string) $position->price_list_version);
        $this->assertSame('2.0000', (string) $position->plannerEntries->first()->second_price);
        $this->assertSame('2026-03-02', $this->entryDate($position->plannerEntries->first()));
        $this->assertCount(0, $position->components);

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
        $this->assertSame('500.00', (string) $reloaded['positions'][0]['fixed_price_nn']);
        $this->assertSame('500.00', (string) $adopted->fresh()->nn_invest);
        $this->assertSame('2.0000', (string) $adopted->fresh(['positions.plannerEntries'])->positions->first()->plannerEntries->first()->second_price);
    }

    public function test_normal_and_fixed_price_calendar_positions_side_by_side(): void
    {
        $catalog = $this->createSpotClassicCatalog();
        $pm = User::factory()->role(Role::ProductManagement)->create();
        $sales = User::factory()->role(Role::Sales)->create();
        $this->setSecondPrice($catalog, '2.0000');

        $payload = $this->withLiveSchemaFingerprint([
            'planning_mode' => 'manual',
            'order_discount_percent' => '0',
            'ae_enabled' => false,
            'order_discounts' => [],
            'dynamic_field_values' => ['campaign_period' => null],
            'positions' => [
                $this->calendarPosition($catalog, [['date' => '2026-03-02', 'hour' => 8, 'spot_count' => 10]], 'normal'),
                $this->calendarPosition($catalog, [['date' => '2026-03-03', 'hour' => 8, 'spot_count' => 10]], 'fixed_price', '500.00', 'cal-fp'),
            ],
        ]);

        $offer = $this->writer()->create('Mix Settlement Calendar', $payload, $pm);
        $draft = $offer->draftVersion;
        $this->assertNotNull($draft);
        $this->assertSame('normal', $draft->draft_payload['positions'][0]['pricing_settlement_mode']);
        $this->assertSame('fixed_price', $draft->draft_payload['positions'][1]['pricing_settlement_mode']);

        $published = $this->writer()->publish($draft, (int) $draft->lock_version, $pm);
        $adopted = $this->writer()->adopt($published, 'Mix Kunde', null, null, $sales);
        $sorted = $adopted->fresh(['positions'])->positions->sortBy('sort')->values();
        $this->assertSame('normal', $sorted[0]->pricing_settlement_mode?->value ?? $sorted[0]->pricing_settlement_mode);
        $this->assertSame('fixed_price', $sorted[1]->pricing_settlement_mode?->value ?? $sorted[1]->pricing_settlement_mode);
        $this->assertSame('500.00', (string) $sorted[1]->fixed_price_nn);
        $this->assertSame('500.00', (string) $sorted[1]->nn_invest);
    }

    public function test_cell_edit_after_successor_list_keeps_pinned_list_a_prices(): void
    {
        $catalog = $this->createSpotClassicCatalog();
        $pm = User::factory()->role(Role::ProductManagement)->create();
        $sales = User::factory()->role(Role::Sales)->create();
        $admin = User::factory()->role(Role::Admin)->create();
        $this->setSecondPrice($catalog, '2.0000');

        $listA = PriceList::query()
            ->where('inventory_id', $catalog['hamburg']->id)
            ->where('status', PriceListStatus::Active)
            ->where('year', 2026)
            ->firstOrFail();

        $published = $this->publishCalendarFestpreis($catalog, $pm);
        $this->assertSame($listA->id, (int) $published->frozen_materialization['positions'][0]['price_list_id']);
        $frozenVersion = (string) $published->frozen_materialization['positions'][0]['price_list_version'];

        $adopted = $this->writer()->adopt($published->fresh(), 'Pin Kunde', null, null, $sales);
        $position = $adopted->fresh(['positions.plannerEntries'])->positions->firstOrFail();
        $this->assertSame($listA->id, (int) $position->price_list_id);
        $this->assertSame('2.0000', (string) $position->plannerEntries->first()->second_price);
        $this->assertSame('500.00', (string) $adopted->nn_invest);

        $listB = $this->activateSuccessorList($catalog['hamburg']->id, 2026, '9.0000', $admin);
        $this->assertNotSame($listA->id, $listB->id);
        $this->assertSame(PriceListStatus::Archived, $listA->fresh()->status);
        $this->assertSame(PriceListStatus::Active, $listB->fresh()->status);
        $this->assertSame('2.0000', (string) PriceListItem::query()
            ->where('price_list_id', $listA->id)
            ->where('hour', 9)
            ->where('day_group', DayGroup::MoFr)
            ->value('second_price'));
        $this->assertSame('9.0000', (string) PriceListItem::query()
            ->where('price_list_id', $listB->id)
            ->where('hour', 9)
            ->where('day_group', DayGroup::MoFr)
            ->value('second_price'));

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
        $edited = $updated->fresh(['positions.plannerEntries'])->positions->firstOrFail();
        $this->assertSame($listA->id, (int) $edited->price_list_id);
        $this->assertNotSame($listB->id, (int) $edited->price_list_id);
        $this->assertSame($frozenVersion, (string) $edited->price_list_version);
        $this->assertSame('2.0000', (string) $edited->plannerEntries->first()->second_price);
        $this->assertSame(9, (int) $edited->plannerEntries->first()->hour);
        $this->assertSame('500.00', (string) $updated->nn_invest);
        $this->assertSame('600.00', (string) $updated->media_gross);
    }

    public function test_first_adopt_after_live_price_change_keeps_frozen_parity(): void
    {
        $catalog = $this->createSpotClassicCatalog();
        $pm = User::factory()->role(Role::ProductManagement)->create();
        $sales = User::factory()->role(Role::Sales)->create();
        $this->setSecondPrice($catalog, '2.0000');

        $published = $this->publishCalendarFestpreis($catalog, $pm);
        $frozen = $published->frozen_materialization;
        $frozenPin = (int) $frozen['positions'][0]['price_list_id'];
        $frozenVersion = (string) ($frozen['positions'][0]['price_list_version'] ?? '');
        $this->assertSame('600.00', (string) ($frozen['media_gross'] ?? ''));
        $this->assertSame('500.00', (string) ($frozen['nn_invest'] ?? ''));
        $this->assertSame('2.0000', (string) ($frozen['positions'][0]['planner_entries'][0]['second_price'] ?? ''));
        $this->assertSame('2026-03-02', (string) ($frozen['positions'][0]['planner_entries'][0]['date'] ?? ''));
        $this->assertSame(8, (int) ($frozen['positions'][0]['planner_entries'][0]['hour'] ?? 0));
        $this->assertSame(10, (int) ($frozen['positions'][0]['planner_entries'][0]['spot_count'] ?? 0));

        $this->setSecondPrice($catalog, '9.0000');
        $this->assertSame('600.00', (string) ($published->fresh()->frozen_materialization['media_gross'] ?? ''));
        $this->assertSame('500.00', (string) ($published->fresh()->frozen_materialization['nn_invest'] ?? ''));
        $this->assertSame('2.0000', (string) ($published->fresh()->frozen_materialization['positions'][0]['planner_entries'][0]['second_price'] ?? ''));
        $this->assertSame($frozenPin, (int) $published->fresh()->frozen_materialization['positions'][0]['price_list_id']);

        $adopted = $this->writer()->adopt($published->fresh(), 'Adopt Freeze Kunde', null, null, $sales);
        $position = $adopted->fresh(['positions.plannerEntries'])->positions->firstOrFail();
        $this->assertSame($frozenPin, (int) $position->price_list_id);
        $this->assertSame($frozenVersion, (string) $position->price_list_version);
        $this->assertSame('2.0000', (string) $position->plannerEntries->first()->second_price);
        $this->assertSame('2026-03-02', $this->entryDate($position->plannerEntries->first()));
        $this->assertSame(8, (int) $position->plannerEntries->first()->hour);
        $this->assertSame(10, (int) $position->plannerEntries->first()->spot_count);
        $this->assertSame('600.00', (string) $adopted->media_gross);
        $this->assertSame('500.00', (string) $adopted->nn_invest);
        $this->assertSame('500.00', (string) $position->fixed_price_nn);

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
        $fresh = $adopted->fresh(['positions.plannerEntries']);
        $this->assertSame('600.00', (string) $fresh->media_gross);
        $this->assertSame('500.00', (string) $fresh->nn_invest);
        $this->assertSame($frozenPin, (int) $fresh->positions->first()->price_list_id);
        $this->assertSame('2.0000', (string) $fresh->positions->first()->plannerEntries->first()->second_price);
        $this->assertSame('2026-03-02', $this->entryDate($fresh->positions->first()->plannerEntries->first()));
        $this->assertSame(10, (int) $fresh->positions->first()->plannerEntries->first()->spot_count);
    }

    public function test_post_adopt_year_change_rebinds_price_list(): void
    {
        $catalog = $this->createSpotClassicCatalog();
        $pm = User::factory()->role(Role::ProductManagement)->create();
        $sales = User::factory()->role(Role::Sales)->create();
        $this->setSecondPrice($catalog, '2.0000');
        $list2027 = $this->createActiveYearList($catalog, 2027, '3.0000');

        $published = $this->publishCalendarFestpreis($catalog, $pm);
        $adopted = $this->writer()->adopt($published, 'Year Kunde', null, null, $sales);
        $frozenPin = (int) $published->frozen_materialization['positions'][0]['price_list_id'];

        $editPayload = app(CalculationWriter::class)->payloadFromCalculation($adopted->fresh([
            'positions.plannerEntries',
            'positions.planRows',
            'positions.timeRanges',
            'positions.discounts',
            'positions.components',
            'orderDiscounts',
        ]));
        $editPayload['lock_version'] = $adopted->lock_version;
        $editPayload['positions'][0]['price_year'] = 2027;
        $editPayload['positions'][0]['expected_price_list_id'] = $list2027->id;
        $editPayload['positions'][0]['planner_entries'] = [
            ['date' => '2027-03-01', 'hour' => 8, 'spot_count' => 10],
        ];

        $updated = app(CalculationWriter::class)->update($adopted, $editPayload, $sales);
        $position = $updated->fresh(['positions.plannerEntries'])->positions->firstOrFail();
        $this->assertNotSame($frozenPin, (int) $position->price_list_id);
        $this->assertSame($list2027->id, (int) $position->price_list_id);
        $this->assertSame('3.0000', (string) $position->plannerEntries->first()->second_price);
        $this->assertSame('500.00', (string) $updated->nn_invest);
        $this->assertSame('2026-03-02', $published->fresh()->frozen_materialization['positions'][0]['planner_entries'][0]['date']);
    }

    public function test_post_adopt_inventory_change_rebinds_price_list(): void
    {
        $catalog = $this->createSpotClassicCatalog();
        $pm = User::factory()->role(Role::ProductManagement)->create();
        $sales = User::factory()->role(Role::Sales)->create();
        $this->setSecondPrice($catalog, '2.0000');
        $this->setSecondPriceForInventory($catalog['rock']->id, '4.0000');

        $published = $this->publishCalendarFestpreis($catalog, $pm);
        $adopted = $this->writer()->adopt($published, 'Inv Kunde', null, null, $sales);
        $frozenPin = (int) $published->frozen_materialization['positions'][0]['price_list_id'];
        $rockList = PriceList::query()
            ->where('inventory_id', $catalog['rock']->id)
            ->where('status', PriceListStatus::Active)
            ->where('year', 2026)
            ->firstOrFail();

        $editPayload = app(CalculationWriter::class)->payloadFromCalculation($adopted->fresh([
            'positions.plannerEntries',
            'positions.planRows',
            'positions.timeRanges',
            'positions.discounts',
            'positions.components',
            'orderDiscounts',
        ]));
        $editPayload['lock_version'] = $adopted->lock_version;
        $editPayload['positions'][0]['inventory_id'] = $catalog['rock']->id;
        $editPayload['positions'][0]['expected_price_list_id'] = $rockList->id;

        $updated = app(CalculationWriter::class)->update($adopted, $editPayload, $sales);
        $position = $updated->fresh(['positions.plannerEntries'])->positions->firstOrFail();
        $this->assertNotSame($frozenPin, (int) $position->price_list_id);
        $this->assertSame($rockList->id, (int) $position->price_list_id);
        $this->assertSame('4.0000', (string) $position->plannerEntries->first()->second_price);
        $this->assertSame('500.00', (string) $updated->nn_invest);
    }

    public function test_from_calc_calendar_festpreis_succeeds_and_mix_fails(): void
    {
        $catalog = $this->createSpotClassicCatalog();
        $sales = User::factory()->role(Role::Sales)->create();
        $this->setSecondPrice($catalog, '2.0000');

        $calcPayload = $this->withLiveSchemaFingerprint([
            'planning_mode' => 'manual',
            'customer_name' => 'Geheimkunde 03i',
            'order_discount_percent' => '0',
            'ae_enabled' => false,
            'order_discounts' => [],
            'dynamic_field_values' => [],
            'positions' => [[
                ...$this->calendarPosition($catalog, [
                    ['date' => '2026-03-02', 'hour' => 8, 'spot_count' => 10],
                ], 'fixed_price', '500.00'),
            ]],
        ]);
        $calculation = app(CalculationWriter::class)->create($calcPayload, $sales);
        $offer = $this->writer()->createFromCalculation(
            $calculation->fresh(['positions', 'positions.plannerEntries']),
            $sales,
        );
        $draft = $offer->draftVersion;
        $this->assertNotNull($draft);
        $this->assertSame('fixed_price', $draft->draft_payload['positions'][0]['pricing_settlement_mode']);
        $this->assertSame('500.00', $draft->draft_payload['positions'][0]['fixed_price_nn']);
        $this->assertArrayNotHasKey('customer_name', $draft->draft_payload);
        $this->assertStringStartsWith('SA-', $offer->number);

        $fromCalcComponents = $this->withLiveSchemaFingerprint([
            'planning_mode' => 'manual',
            'customer_name' => 'FP Komponenten',
            'order_discount_percent' => '0',
            'ae_enabled' => false,
            'order_discounts' => [],
            'dynamic_field_values' => [],
            'positions' => [[
                ...$this->calendarPosition($catalog, [
                    ['date' => '2026-03-02', 'hour' => 8, 'spot_count' => 2],
                ], 'fixed_price', '100.00'),
                'component_calculation_strategy' => ComponentCalculationStrategy::SharedTotalLength->value,
                'components' => [
                    ['role' => 'main_spot', 'label' => 'Hauptspot', 'length_seconds' => 20, 'sort' => 0],
                    ['role' => 'allonge', 'label' => 'Allonge', 'length_seconds' => 10, 'sort' => 1],
                ],
            ]],
        ]);
        InventoryMediumRule::query()
            ->where('inventory_id', $catalog['hamburg']->id)
            ->where('advertising_medium_id', $catalog['medium']->id)
            ->update(['component_calculation_strategy' => ComponentCalculationStrategy::SharedTotalLength->value]);
        $compCalc = app(CalculationWriter::class)->create($fromCalcComponents, $sales);
        $beforeOffers = StandardOffer::query()->count();
        try {
            $this->writer()->createFromCalculation($compCalc->fresh(['positions', 'positions.components', 'positions.plannerEntries']), $sales);
            $this->fail('From-Calc Calendar×Festpreis×Komponenten hätte scheitern müssen.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('positions.0.pricing_settlement_mode', $exception->errors());
        }
        $this->assertSame($beforeOffers, StandardOffer::query()->count());

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
                $this->calendarPosition($catalog, [
                    ['date' => '2026-03-02', 'hour' => 8, 'spot_count' => 1],
                ], 'fixed_price', '50.00'),
            ],
        ]);
        $mixedCalc = app(CalculationWriter::class)->create($mixed, $sales);
        $before = StandardOffer::query()->count();
        $this->actingAs($sales)
            ->post(route('standard-offers.from-calculation', $mixedCalc))
            ->assertSessionHasErrors();
        $this->assertSame($before, StandardOffer::query()->count());
    }

    public function test_missing_price_and_year_mismatch_fail_on_create_preview_publish(): void
    {
        $catalog = $this->createSpotClassicCatalog();
        $pm = User::factory()->role(Role::ProductManagement)->create();
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

        $missing = $this->calendarFestpreisPayload($catalog, [
            ['date' => '2026-03-02', 'hour' => 22, 'spot_count' => 1],
        ], '50.00');

        try {
            $this->writer()->create('Missing price', $missing, $pm);
            $this->fail('Create mit fehlender Preiszelle hätte scheitern müssen.');
        } catch (ValidationException $exception) {
            $this->assertNotEmpty($exception->errors());
        }
        $this->assertSame(0, StandardOffer::query()->count());

        $this->actingAs($pm)
            ->postJson(route('standard-offers.preview'), [...$missing, 'title' => 'Missing preview'])
            ->assertStatus(422);

        $valid = $this->writer()->create(
            'Publish base',
            $this->calendarFestpreisPayload($catalog, [
                ['date' => '2026-03-02', 'hour' => 8, 'spot_count' => 10],
            ], '500.00'),
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
        $this->setSecondPrice($catalog, '2.0000');

        $active2026 = PriceList::query()
            ->where('inventory_id', $catalog['hamburg']->id)
            ->where('status', PriceListStatus::Active)
            ->where('year', 2026)
            ->firstOrFail();

        $invalid = $this->calendarFestpreisPayload($catalog, [
            ['date' => '2027-03-01', 'hour' => 8, 'spot_count' => 1],
        ], '50.00', [
            'price_year' => 2026,
            'expected_price_list_id' => $active2026->id,
        ]);

        $this->assertCalendarFestpreisRejectedOnCreatePreviewPublish(
            $catalog,
            $pm,
            $invalid,
            'positions.0.planner_entries.0.date',
            'getrennte Position',
            '2027-03-01',
        );
    }

    public function test_cells_across_year_boundary_fail_on_create_preview_publish(): void
    {
        $catalog = $this->createSpotClassicCatalog();
        $pm = User::factory()->role(Role::ProductManagement)->create();
        $this->setSecondPrice($catalog, '2.0000');

        $active2026 = PriceList::query()
            ->where('inventory_id', $catalog['hamburg']->id)
            ->where('status', PriceListStatus::Active)
            ->where('year', 2026)
            ->firstOrFail();

        $invalid = $this->calendarFestpreisPayload($catalog, [
            ['date' => '2026-12-31', 'hour' => 8, 'spot_count' => 1],
            ['date' => '2027-01-01', 'hour' => 9, 'spot_count' => 1],
        ], '50.00', [
            'price_year' => 2026,
            'expected_price_list_id' => $active2026->id,
        ]);

        $this->assertCalendarFestpreisRejectedOnCreatePreviewPublish(
            $catalog,
            $pm,
            $invalid,
            'positions.0.planner_entries.1.date',
            'getrennte Position',
            '2027-01-01',
        );
    }

    public function test_rejects_invalid_settlement_components_tandem_budget(): void
    {
        $catalog = $this->createSpotClassicCatalog();
        $pm = User::factory()->role(Role::ProductManagement)->create();
        InventoryMediumRule::query()
            ->where('inventory_id', $catalog['hamburg']->id)
            ->where('advertising_medium_id', $catalog['medium']->id)
            ->update(['component_calculation_strategy' => ComponentCalculationStrategy::SharedTotalLength->value]);
        $this->setSecondPrice($catalog, '2.0000');

        try {
            $this->writer()->create('NN without mode', $this->calendarFestpreisPayload(
                $catalog,
                [['date' => '2026-03-02', 'hour' => 8, 'spot_count' => 1]],
                '50.00',
                ['pricing_settlement_mode' => 'normal'],
            ), $pm);
            $this->fail('normal + fixed_price_nn hätte scheitern müssen.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('positions.0.fixed_price_nn', $exception->errors());
        }

        try {
            $this->writer()->create('NN zero', $this->calendarFestpreisPayload(
                $catalog,
                [['date' => '2026-03-02', 'hour' => 8, 'spot_count' => 1]],
                '0.00',
            ), $pm);
            $this->fail('N/N 0 hätte scheitern müssen.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('positions.0.fixed_price_nn', $exception->errors());
        }

        try {
            $this->writer()->create('FP Komponenten', $this->calendarFestpreisPayload(
                $catalog,
                [['date' => '2026-03-02', 'hour' => 8, 'spot_count' => 1]],
                '100.00',
                [
                    'component_calculation_strategy' => ComponentCalculationStrategy::SharedTotalLength->value,
                    'components' => [
                        ['role' => 'main_spot', 'label' => 'Hauptspot', 'length_seconds' => 20, 'sort' => 0],
                        ['role' => 'allonge', 'label' => 'Allonge', 'length_seconds' => 10, 'sort' => 1],
                    ],
                ],
            ), $pm);
            $this->fail('Calendar×Festpreis×Komponenten hätte scheitern müssen.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('positions.0.pricing_settlement_mode', $exception->errors());
        }

        try {
            $this->writer()->create('Cal Tandem', $this->calendarFestpreisPayload(
                $catalog,
                [['date' => '2026-03-02', 'hour' => 8, 'spot_count' => 1]],
                '100.00',
                ['component_profile' => 'tandem'],
            ), $pm);
            $this->fail('Calendar+Tandem hätte scheitern müssen.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('positions.0.component_profile', $exception->errors());
        }

        $this->actingAs($pm)->post(route('standard-offers.store'), [
            ...$this->calendarFestpreisPayload($catalog, [
                ['date' => '2026-03-02', 'hour' => 8, 'spot_count' => 1],
            ], '50.00'),
            'title' => 'Budget Reject',
            'planning_mode' => 'budget',
        ])->assertSessionHasErrors();
    }

    public function test_frozen_calendar_festpreis_inconsistencies_fail_closed_independently(): void
    {
        $catalog = $this->createSpotClassicCatalog();
        $pm = User::factory()->role(Role::ProductManagement)->create();
        $sales = User::factory()->role(Role::Sales)->create();
        $this->setSecondPrice($catalog, '2.0000');

        $published = $this->publishCalendarFestpreis($catalog, $pm);
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
            'festpreis with components' => [
                'needle' => 'Calendar erlaubt Festpreis nur ohne Komponenten',
                'mutate' => static function (array $mat): array {
                    $mat['positions'][0]['components'] = [
                        [
                            'role' => 'main_spot',
                            'label' => 'Hauptspot',
                            'length_seconds' => 20,
                            'sort' => 0,
                            'length_index' => 0,
                            'media_gross' => '400.00',
                        ],
                        [
                            'role' => 'allonge',
                            'label' => 'Allonge',
                            'length_seconds' => 10,
                            'sort' => 1,
                            'length_index' => 0,
                            'media_gross' => '200.00',
                        ],
                    ];
                    $mat['positions'][0]['component_calculation_strategy'] = ComponentCalculationStrategy::SharedTotalLength->value;

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
        $ok = $this->writer()->adopt($published->fresh(), 'Baseline OK', null, null, $sales);
        $this->assertSame('500.00', (string) $ok->nn_invest);
    }

    public function test_regression_calendar_normal_allonge_and_average_festpreis_remain(): void
    {
        $catalog = $this->createSpotClassicCatalog();
        $pm = User::factory()->role(Role::ProductManagement)->create();
        $sales = User::factory()->role(Role::Sales)->create();
        InventoryMediumRule::query()
            ->where('inventory_id', $catalog['hamburg']->id)
            ->where('advertising_medium_id', $catalog['medium']->id)
            ->update(['component_calculation_strategy' => ComponentCalculationStrategy::SharedTotalLength->value]);
        $this->setSecondPrice($catalog, '2.0000');

        $allonge = $this->writer()->create(
            '03h Regression',
            $this->withLiveSchemaFingerprint([
                'planning_mode' => 'manual',
                'order_discount_percent' => '0',
                'ae_enabled' => false,
                'order_discounts' => [],
                'dynamic_field_values' => ['campaign_period' => null],
                'positions' => [[
                    ...$this->calendarPosition($catalog, [
                        ['date' => '2026-03-02', 'hour' => 8, 'spot_count' => 2],
                    ], 'normal'),
                    'component_calculation_strategy' => ComponentCalculationStrategy::SharedTotalLength->value,
                    'components' => [
                        ['role' => 'main_spot', 'label' => 'Hauptspot', 'length_seconds' => 20, 'sort' => 0],
                        ['role' => 'allonge', 'label' => 'Allonge', 'length_seconds' => 10, 'sort' => 1],
                    ],
                ]],
            ]),
            $pm,
        );
        $publishedAllonge = $this->writer()->publish($allonge->draftVersion, (int) $allonge->draftVersion->lock_version, $pm);
        $adoptedAllonge = $this->writer()->adopt($publishedAllonge, 'Allonge Kunde', null, null, $sales);
        $this->assertCount(2, $adoptedAllonge->positions->first()->components);
        $this->assertSame('normal', $adoptedAllonge->positions->first()->pricing_settlement_mode?->value
            ?? $adoptedAllonge->positions->first()->pricing_settlement_mode);

        $average = $this->writer()->create(
            '03e Regression',
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

        $this->actingAs($pm)
            ->get(route('standard-offers.create'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('standardOffer.allowed_spot_methods', ['average', 'calendar'])
                ->where('standardOffer.scope_note', fn ($note) => is_string($note)
                    && str_contains($note, 'Festpreis')
                    && str_contains($note, 'Einzelspot')));
    }

    private function writer(): StandardOfferWriter
    {
        return app(StandardOfferWriter::class);
    }

    /**
     * @param  array{hamburg: Inventory, medium: AdvertisingMedium}  $catalog
     * @param  array<string, mixed>  $invalidPayload
     */
    private function assertCalendarFestpreisRejectedOnCreatePreviewPublish(
        array $catalog,
        User $pm,
        array $invalidPayload,
        string $errorKey,
        string $needle,
        string $dateNeedle,
    ): void {
        $offersBefore = StandardOffer::query()->count();
        try {
            $this->writer()->create('Invalid year create', $invalidPayload, $pm);
            $this->fail('Create hätte an der Jahresgrenze scheitern müssen.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey($errorKey, $exception->errors());
            $joined = implode(' ', $exception->errors()[$errorKey]);
            $this->assertStringContainsString($needle, $joined);
            $this->assertStringContainsString($dateNeedle, $joined);
        }
        $this->assertSame($offersBefore, StandardOffer::query()->count());

        $this->actingAs($pm)
            ->postJson(route('standard-offers.preview'), [...$invalidPayload, 'title' => 'Invalid year preview'])
            ->assertStatus(422)
            ->assertJsonValidationErrors($errorKey);
        $this->assertSame($offersBefore, StandardOffer::query()->count());

        $valid = $this->writer()->create(
            'Year publish baseline',
            $this->calendarFestpreisPayload($catalog, [
                ['date' => '2026-03-02', 'hour' => 8, 'spot_count' => 10],
            ], '500.00'),
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
            'name' => 'Nachfolger '.$secondPrice,
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
    private function publishCalendarFestpreis(array $catalog, User $pm): StandardOfferVersion
    {
        $offer = $this->writer()->create(
            'Calendar festpreis fixture',
            $this->calendarFestpreisPayload($catalog, [
                ['date' => '2026-03-02', 'hour' => 8, 'spot_count' => 10],
            ], '500.00'),
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
    private function calendarFestpreisPayload(
        array $catalog,
        array $entries,
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
                ...$this->calendarPosition($catalog, $entries, 'fixed_price', $fixedPriceNn),
                ...$positionOverrides,
            ]],
        ]);
    }

    /**
     * @param  array{hamburg: Inventory, medium: AdvertisingMedium}  $catalog
     * @param  list<array{date: string, hour: int, spot_count: int}>  $entries
     * @return array<string, mixed>
     */
    private function calendarPosition(
        array $catalog,
        array $entries,
        string $settlementMode,
        ?string $fixedPriceNn = null,
        string $clientKey = 'cal',
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
            'pricing_settlement_mode' => $settlementMode,
            'fixed_price_nn' => $fixedPriceNn,
            'components' => [],
            'planner_entries' => $entries,
            'plan_rows' => [],
            'time_ranges' => [],
            'position_discounts' => [],
            'dynamic_field_values' => ['period_open' => true],
        ];
    }

    /**
     * @param  array{hamburg: Inventory}  $catalog
     */
    private function setSecondPrice(array $catalog, string $price): void
    {
        $this->setSecondPriceForInventory($catalog['hamburg']->id, $price);
    }

    private function setSecondPriceForInventory(int $inventoryId, string $price): void
    {
        $list = PriceList::query()
            ->where('inventory_id', $inventoryId)
            ->where('status', PriceListStatus::Active)
            ->where('year', 2026)
            ->firstOrFail();

        PriceListItem::query()
            ->where('price_list_id', $list->id)
            ->update(['second_price' => $price]);
    }

    /**
     * @param  array{hamburg: Inventory}  $catalog
     */
    private function createActiveYearList(array $catalog, int $year, string $secondPrice): PriceList
    {
        $list = PriceList::factory()->create([
            'inventory_id' => $catalog['hamburg']->id,
            'status' => PriceListStatus::Active,
            'year' => $year,
            'version' => 'e2e-'.$year,
            'valid_from' => sprintf('%d-01-01', $year),
        ]);

        foreach (range(0, 23) as $hour) {
            foreach ([DayGroup::MoFr, DayGroup::Sa, DayGroup::So] as $group) {
                PriceListItem::factory()->create([
                    'price_list_id' => $list->id,
                    'hour' => $hour,
                    'day_group' => $group,
                    'second_price' => $secondPrice,
                ]);
            }
        }

        return $list;
    }

    private function entryDate(mixed $entry): string
    {
        $date = $entry->date;

        return $date instanceof CarbonInterface ? $date->toDateString() : (string) $date;
    }
}
