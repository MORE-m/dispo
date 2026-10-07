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
use App\Services\DynamicField\ConfigurationSnapshotFreezeService;
use App\Services\PriceList\Admin\PriceListAdminWriter;
use App\Services\PriceList\Admin\PriceListImpactPreviewService;
use App\Services\StandardOffer\FrozenCalculationPersistenceContract;
use App\Services\StandardOffer\StandardOfferMaterializer;
use App\Services\StandardOffer\StandardOfferWriter;
use App\Support\InventoryMediumRule\InventoryMediumRuleOperativeContract;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\Concerns\CreatesSpotClassicCatalog;
use Tests\TestCase;

/**
 * BL-P4-03k / PO-BLP403K-1: Calendar × Tandem/Tridem × normal/fixed_price.
 */
class StandardOfferBlP403kTest extends TestCase
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

    public function test_four_combinations_calendar_tandem_tridem_normal_fixed_price(): void
    {
        $catalog = $this->createSpotClassicCatalog();
        $tandem = $this->attachProfileMedium($catalog, AdvertisingMedium::factory()->tandem()->create());
        $tridem = $this->attachProfileMedium($catalog, AdvertisingMedium::factory()->tridem()->create());
        $pm = User::factory()->role(Role::ProductManagement)->create();
        $sales = User::factory()->role(Role::Sales)->create();
        $this->setSecondPrice($catalog, '2.0000');

        $entries = [['date' => '2026-03-02', 'hour' => 8, 'spot_count' => 10]];

        $cases = [
            [
                'medium' => $tandem,
                'profile' => 'tandem',
                'components' => $this->tandemComponents(),
                'length' => 30,
                'mode' => 'normal',
                'nn' => null,
                'media' => '600.00',
                'nn_invest' => '600.00',
                'component_gross' => ['', ''],
            ],
            [
                'medium' => $tandem,
                'profile' => 'tandem',
                'components' => $this->tandemComponents(),
                'length' => 30,
                'mode' => 'fixed_price',
                'nn' => '888.50',
                'media' => '600.00',
                'nn_invest' => '888.50',
                'component_gross' => ['', ''],
            ],
            [
                'medium' => $tridem,
                'profile' => 'tridem',
                'components' => $this->tridemComponents(),
                'length' => 40,
                'mode' => 'normal',
                'nn' => null,
                'media' => '760.00',
                'nn_invest' => '760.00',
                'component_gross' => ['', '', ''],
            ],
            [
                'medium' => $tridem,
                'profile' => 'tridem',
                'components' => $this->tridemComponents(),
                'length' => 40,
                'mode' => 'fixed_price',
                'nn' => '1200.00',
                'media' => '760.00',
                'nn_invest' => '1200.00',
                'component_gross' => ['', '', ''],
            ],
        ];

        foreach ($cases as $index => $case) {
            $payload = $this->calendarProfilePayload(
                $catalog,
                $case['medium'],
                $case['profile'],
                $case['components'],
                $case['length'],
                $case['mode'],
                $case['nn'],
                $entries,
            );

            $preview = $this->actingAs($pm)
                ->postJson(route('standard-offers.preview'), [...$payload, 'title' => "Preview 03k {$index}"])
                ->assertOk()
                ->json();
            $this->assertSame($case['nn_invest'], $preview['totals']['nn_invest'] ?? null, "case {$index} preview nn");
            $this->assertSame($case['media'], $preview['totals']['media_gross'] ?? null, "case {$index} preview media");

            $offer = $this->writer()->create("03k case {$index}", $payload, $pm);
            $draft = $offer->draftVersion;
            $this->assertNotNull($draft);
            $this->assertSame('calendar', $draft->draft_payload['positions'][0]['spot_method']);
            $this->assertSame($case['profile'], $draft->draft_payload['positions'][0]['component_profile'] ?? null);
            $this->assertSame('shared_total_length', $draft->draft_payload['positions'][0]['component_calculation_strategy'] ?? null);

            $published = $this->writer()->publish($draft, (int) $draft->lock_version, $pm);
            $this->assertSame(StandardOfferVersionStatus::Published, $published->status);
            $frozen = $published->frozen_materialization;
            $this->assertSame(4, (int) ($frozen['materialization_version'] ?? 0));
            $this->assertSame($case['media'], (string) ($frozen['media_gross'] ?? ''));
            $this->assertSame($case['nn_invest'], (string) ($frozen['nn_invest'] ?? ''));
            $frozenPos = $frozen['positions'][0];
            $this->assertSame($case['profile'], $frozenPos['component_profile'] ?? null);
            $this->assertSame($case['mode'], $frozenPos['pricing_settlement_mode'] ?? null);
            $this->assertSame(
                $case['component_gross'],
                collect($frozenPos['components'])->sortBy('sort')->pluck('media_gross')->map(fn ($v) => (string) $v)->values()->all(),
            );
            $this->assertSame('2.0000', (string) ($frozenPos['planner_entries'][0]['second_price'] ?? ''));
            $this->assertSame('2026-03-02', (string) ($frozenPos['planner_entries'][0]['date'] ?? ''));
            $this->assertSame(8, (int) ($frozenPos['planner_entries'][0]['hour'] ?? 0));
            $this->assertSame(10, (int) ($frozenPos['planner_entries'][0]['spot_count'] ?? 0));
            $frozenPin = (int) $frozenPos['price_list_id'];
            $frozenVersion = (string) ($frozenPos['price_list_version'] ?? '');
            $frozenSnapshot = json_encode($frozen, JSON_THROW_ON_ERROR);

            $adopted = $this->writer()->adopt($published, "Kunde {$index}", null, null, $sales);
            $position = $adopted->fresh(['positions.plannerEntries', 'positions.components'])->positions->firstOrFail();
            $this->assertSame($case['nn_invest'], (string) $adopted->nn_invest);
            $this->assertSame($case['media'], (string) $adopted->media_gross);
            $this->assertSame($case['profile'], $position->component_profile?->value);
            $this->assertSame($frozenPin, (int) $position->price_list_id);
            $this->assertSame($frozenVersion, (string) $position->price_list_version);
            $this->assertSame('2.0000', (string) $position->plannerEntries->first()->second_price);
            $this->assertSame(
                $case['component_gross'],
                $position->components->sortBy('sort')->values()->map(fn ($c) => (string) ($c->media_gross ?? ''))->all(),
            );
            $this->assertSame($published->id, $adopted->origin_standard_offer_version_id);

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
            $this->assertSame($case['profile'], $reloaded['positions'][0]['component_profile'] ?? null);
            $this->assertSame($case['mode'], $reloaded['positions'][0]['pricing_settlement_mode']);
            if ($case['nn'] !== null) {
                $this->assertSame($case['nn'], (string) $reloaded['positions'][0]['fixed_price_nn']);
            }

            $templateStill = $published->fresh();
            $this->assertSame($frozenSnapshot, json_encode($templateStill->frozen_materialization, JSON_THROW_ON_ERROR));
        }
    }

    public function test_first_adopt_after_live_price_change_keeps_frozen_parity(): void
    {
        $catalog = $this->createSpotClassicCatalog();
        $tandem = $this->attachProfileMedium($catalog, AdvertisingMedium::factory()->tandem()->create());
        $pm = User::factory()->role(Role::ProductManagement)->create();
        $sales = User::factory()->role(Role::Sales)->create();
        $this->setSecondPrice($catalog, '2.0000');

        $published = $this->publishCalendarProfile(
            $catalog,
            $tandem,
            'tandem',
            $this->tandemComponents(),
            30,
            'fixed_price',
            '888.50',
            $pm,
        );
        $frozen = $published->frozen_materialization;
        $frozenPin = (int) $frozen['positions'][0]['price_list_id'];

        $this->setSecondPrice($catalog, '9.0000');
        $this->assertSame('600.00', (string) ($published->fresh()->frozen_materialization['media_gross'] ?? ''));
        $this->assertSame('888.50', (string) ($published->fresh()->frozen_materialization['nn_invest'] ?? ''));
        $this->assertSame('2.0000', (string) ($published->fresh()->frozen_materialization['positions'][0]['planner_entries'][0]['second_price'] ?? ''));
        $this->assertSame($frozenPin, (int) $published->fresh()->frozen_materialization['positions'][0]['price_list_id']);

        $adopted = $this->writer()->adopt($published->fresh(), 'Adopt Freeze 03k', null, null, $sales);
        $position = $adopted->fresh(['positions.plannerEntries', 'positions.components'])->positions->firstOrFail();
        $this->assertSame('600.00', (string) $adopted->media_gross);
        $this->assertSame('888.50', (string) $adopted->nn_invest);
        $this->assertSame('2.0000', (string) $position->plannerEntries->first()->second_price);
        $this->assertSame($frozenPin, (int) $position->price_list_id);
        $this->assertSame('tandem', $position->component_profile?->value);
        $this->assertCount(2, $position->components);

        // Erneuter DB-Reload: Frozen-Nachweis ohne Live-Neuberechnung.
        $reloadedCalc = Calculation::query()->with(['positions.plannerEntries', 'positions.components'])->findOrFail($adopted->id);
        $reloadedPos = $reloadedCalc->positions->firstOrFail();
        $this->assertSame('600.00', (string) $reloadedCalc->media_gross);
        $this->assertSame('888.50', (string) $reloadedCalc->nn_invest);
        $this->assertSame('2.0000', (string) $reloadedPos->plannerEntries->first()->second_price);
        $this->assertSame($frozenPin, (int) $reloadedPos->price_list_id);
        $this->assertSame('tandem', $reloadedPos->component_profile?->value);
        $this->assertSame(
            ['', ''],
            $reloadedPos->components->sortBy('sort')->values()->map(fn ($c) => (string) ($c->media_gross ?? ''))->all(),
        );
        $this->assertSame('2.0000', (string) ($published->fresh()->frozen_materialization['positions'][0]['planner_entries'][0]['second_price'] ?? ''));
    }

    public function test_cell_edit_after_successor_list_keeps_pinned_list_a_prices(): void
    {
        $catalog = $this->createSpotClassicCatalog();
        $tandem = $this->attachProfileMedium($catalog, AdvertisingMedium::factory()->tandem()->create());
        $pm = User::factory()->role(Role::ProductManagement)->create();
        $sales = User::factory()->role(Role::Sales)->create();
        $admin = User::factory()->role(Role::Admin)->create();
        $this->setSecondPrice($catalog, '2.0000');

        $listA = PriceList::query()
            ->where('inventory_id', $catalog['hamburg']->id)
            ->where('status', PriceListStatus::Active)
            ->where('year', 2026)
            ->firstOrFail();

        $published = $this->publishCalendarProfile(
            $catalog,
            $tandem,
            'tandem',
            $this->tandemComponents(),
            30,
            'fixed_price',
            '888.50',
            $pm,
        );
        $frozenVersion = (string) $published->frozen_materialization['positions'][0]['price_list_version'];

        $adopted = $this->writer()->adopt($published->fresh(), 'Pin 03k Kunde', null, null, $sales);
        $this->assertSame($listA->id, (int) $adopted->positions->first()->price_list_id);

        $listB = $this->activateSuccessorList($catalog['hamburg']->id, 2026, '9.0000', $admin);
        $this->assertNotSame($listA->id, $listB->id);

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
        $this->assertSame($frozenVersion, (string) $edited->price_list_version);
        $this->assertSame('2.0000', (string) $edited->plannerEntries->first()->second_price);
        $this->assertSame(9, (int) $edited->plannerEntries->first()->hour);
        $this->assertSame('888.50', (string) $updated->nn_invest);
        $this->assertSame('600.00', (string) $updated->media_gross);
        $this->assertSame('888.50', (string) $edited->fixed_price_nn);
    }

    public function test_normal_quantity_edit_after_successor_list_keeps_pin_and_follows_quantity(): void
    {
        $catalog = $this->createSpotClassicCatalog();
        $tandem = $this->attachProfileMedium($catalog, AdvertisingMedium::factory()->tandem()->create());
        $pm = User::factory()->role(Role::ProductManagement)->create();
        $sales = User::factory()->role(Role::Sales)->create();
        $admin = User::factory()->role(Role::Admin)->create();
        $this->setSecondPrice($catalog, '2.0000');

        $listA = PriceList::query()
            ->where('inventory_id', $catalog['hamburg']->id)
            ->where('status', PriceListStatus::Active)
            ->where('year', 2026)
            ->firstOrFail();

        $published = $this->publishCalendarProfile(
            $catalog,
            $tandem,
            'tandem',
            $this->tandemComponents(),
            30,
            'normal',
            null,
            $pm,
        );
        $frozenVersion = (string) $published->frozen_materialization['positions'][0]['price_list_version'];
        $adopted = $this->writer()->adopt($published->fresh(), 'Pin Normal 03k', null, null, $sales);
        $this->assertSame($listA->id, (int) $adopted->positions->first()->price_list_id);
        $this->assertSame('600.00', (string) $adopted->media_gross);
        $this->assertSame('600.00', (string) $adopted->nn_invest);

        $listB = $this->activateSuccessorList($catalog['hamburg']->id, 2026, '9.0000', $admin);
        $this->assertNotSame($listA->id, $listB->id);

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
            ['date' => '2026-03-02', 'hour' => 8, 'spot_count' => 20],
        ];
        $updated = app(CalculationWriter::class)->update($adopted->fresh(), $reloaded, $sales);
        $edited = $updated->fresh(['positions.plannerEntries'])->positions->firstOrFail();
        $this->assertSame($listA->id, (int) $edited->price_list_id);
        $this->assertSame($frozenVersion, (string) $edited->price_list_version);
        $this->assertSame('2.0000', (string) $edited->plannerEntries->first()->second_price);
        $this->assertSame(20, (int) $edited->plannerEntries->first()->spot_count);
        // 20 × 2 × 30 × 1.00 = 1200.00; N/N folgt der Menge (normal).
        $this->assertSame('1200.00', (string) $updated->media_gross);
        $this->assertSame('1200.00', (string) $updated->nn_invest);
    }

    public function test_from_calc_each_allowed_calendar_profile_source_and_mix_fails(): void
    {
        $catalog = $this->createSpotClassicCatalog();
        $tandem = $this->attachProfileMedium($catalog, AdvertisingMedium::factory()->tandem()->create());
        $tridem = $this->attachProfileMedium($catalog, AdvertisingMedium::factory()->tridem()->create());
        $sales = User::factory()->role(Role::Sales)->create();
        $this->setSecondPrice($catalog, '2.0000');

        $sources = [
            [
                'label' => 'tandem normal',
                'medium' => $tandem,
                'profile' => 'tandem',
                'components' => $this->tandemComponents(),
                'length' => 30,
                'mode' => 'normal',
                'nn' => null,
                'customer' => 'Geheimkunde Tandem Normal',
            ],
            [
                'label' => 'tandem fixed_price',
                'medium' => $tandem,
                'profile' => 'tandem',
                'components' => $this->tandemComponents(),
                'length' => 30,
                'mode' => 'fixed_price',
                'nn' => '888.50',
                'customer' => 'Geheimkunde Tandem Festpreis',
            ],
            [
                'label' => 'tridem normal',
                'medium' => $tridem,
                'profile' => 'tridem',
                'components' => $this->tridemComponents(),
                'length' => 40,
                'mode' => 'normal',
                'nn' => null,
                'customer' => 'Geheimkunde Tridem Normal',
            ],
            [
                'label' => 'tridem fixed_price',
                'medium' => $tridem,
                'profile' => 'tridem',
                'components' => $this->tridemComponents(),
                'length' => 40,
                'mode' => 'fixed_price',
                'nn' => '1200.00',
                'customer' => 'Geheimkunde Tridem Festpreis',
            ],
        ];

        foreach ($sources as $source) {
            $calcPayload = $this->withLiveSchemaFingerprint([
                'planning_mode' => 'manual',
                'customer_name' => $source['customer'],
                'order_discount_percent' => '0',
                'ae_enabled' => false,
                'order_discounts' => [],
                'dynamic_field_values' => [],
                'positions' => [[
                    ...$this->calendarProfilePosition(
                        $catalog,
                        $source['medium'],
                        $source['profile'],
                        $source['components'],
                        $source['length'],
                        $source['mode'],
                        $source['nn'],
                        [['date' => '2026-03-02', 'hour' => 8, 'spot_count' => 10]],
                    ),
                ]],
            ]);
            $calc = app(CalculationWriter::class)->create($calcPayload, $sales);
            $offer = $this->writer()->createFromCalculation(
                $calc->fresh(['positions', 'positions.components', 'positions.plannerEntries']),
                $sales,
            );
            $draft = $offer->draftVersion;
            $this->assertNotNull($draft, $source['label']);
            $pos = $draft->draft_payload['positions'][0];
            $this->assertSame($source['profile'], $pos['component_profile'] ?? null, $source['label']);
            $this->assertSame($source['mode'], $pos['pricing_settlement_mode'] ?? null, $source['label']);
            $this->assertSame('calendar', $pos['spot_method'] ?? null, $source['label']);
            $this->assertSame('shared_total_length', $pos['component_calculation_strategy'] ?? null, $source['label']);
            $this->assertCount(count($source['components']), $pos['components'] ?? [], $source['label']);
            $this->assertSame('2026-03-02', $pos['planner_entries'][0]['date'] ?? null, $source['label']);
            $this->assertSame(8, (int) ($pos['planner_entries'][0]['hour'] ?? 0), $source['label']);
            $this->assertSame(10, (int) ($pos['planner_entries'][0]['spot_count'] ?? 0), $source['label']);
            if ($source['nn'] !== null) {
                $this->assertSame($source['nn'], $pos['fixed_price_nn'] ?? null, $source['label']);
            } else {
                $this->assertTrue(
                    ! array_key_exists('fixed_price_nn', $pos) || $pos['fixed_price_nn'] === null || $pos['fixed_price_nn'] === '',
                    $source['label'],
                );
            }
            $this->assertArrayNotHasKey('customer_name', $draft->draft_payload, $source['label']);
            $this->assertStringNotContainsString('Geheimkunde', json_encode($draft->draft_payload, JSON_THROW_ON_ERROR), $source['label']);
        }

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
                $this->calendarProfilePosition(
                    $catalog,
                    $tandem,
                    'tandem',
                    $this->tandemComponents(),
                    30,
                    'normal',
                    null,
                    [['date' => '2026-03-02', 'hour' => 8, 'spot_count' => 1]],
                    'cal-tandem',
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

    public function test_rejects_individual_invalid_slots_budget(): void
    {
        $catalog = $this->createSpotClassicCatalog();
        $tandem = $this->attachProfileMedium($catalog, AdvertisingMedium::factory()->tandem()->create());
        $pm = User::factory()->role(Role::ProductManagement)->create();
        $this->setSecondPrice($catalog, '2.0000');
        $entries = [['date' => '2026-03-02', 'hour' => 8, 'spot_count' => 10]];

        $individual = $this->calendarProfilePayload(
            $catalog,
            $tandem,
            'tandem',
            $this->tandemComponents(),
            30,
            'normal',
            null,
            $entries,
        );
        $individual['positions'][0]['component_calculation_strategy'] = 'individual';
        $this->assertRejectedCreatePreviewPublishForCalendarProfile(
            $catalog,
            $tandem,
            $pm,
            $individual,
            static function (array $draftPayload): array {
                $draftPayload['positions'][0]['component_calculation_strategy'] = 'individual';

                return $draftPayload;
            },
            'component_calculation_strategy',
        );

        $invalidProfile = $this->calendarProfilePayload(
            $catalog,
            $tandem,
            'tandem',
            $this->tandemComponents(),
            30,
            'normal',
            null,
            $entries,
        );
        $invalidProfile['positions'][0]['component_profile'] = 'not-a-profile';
        $this->assertRejectedCreatePreviewPublishForCalendarProfile(
            $catalog,
            $tandem,
            $pm,
            $invalidProfile,
            static function (array $draftPayload): array {
                $draftPayload['positions'][0]['component_profile'] = 'not-a-profile';

                return $draftPayload;
            },
            'component_profile',
        );

        $wrongRoles = $this->calendarProfilePayload(
            $catalog,
            $tandem,
            'tandem',
            [
                ['role' => 'main_spot', 'label' => 'Hauptspot', 'length_seconds' => 20, 'sort' => 1],
                ['role' => 'allonge', 'label' => 'Allonge', 'length_seconds' => 10, 'sort' => 2],
            ],
            30,
            'normal',
            null,
            $entries,
        );
        $this->assertRejectedCreatePreviewPublishForCalendarProfile(
            $catalog,
            $tandem,
            $pm,
            $wrongRoles,
            static function (array $draftPayload): array {
                $draftPayload['positions'][0]['components'] = [
                    ['role' => 'main_spot', 'label' => 'Hauptspot', 'length_seconds' => 20, 'sort' => 1],
                    ['role' => 'allonge', 'label' => 'Allonge', 'length_seconds' => 10, 'sort' => 2],
                ];

                return $draftPayload;
            },
            'components',
        );

        $wrongSort = $this->calendarProfilePayload(
            $catalog,
            $tandem,
            'tandem',
            [
                ['role' => 'main_spot', 'label' => 'Hauptspot', 'length_seconds' => 20, 'sort' => 2],
                ['role' => 'reminder', 'label' => 'Reminder', 'length_seconds' => 10, 'sort' => 1],
            ],
            30,
            'normal',
            null,
            $entries,
        );
        $this->assertRejectedCreatePreviewPublishForCalendarProfile(
            $catalog,
            $tandem,
            $pm,
            $wrongSort,
            static function (array $draftPayload): array {
                $draftPayload['positions'][0]['components'] = [
                    ['role' => 'main_spot', 'label' => 'Hauptspot', 'length_seconds' => 20, 'sort' => 2],
                    ['role' => 'reminder', 'label' => 'Reminder', 'length_seconds' => 10, 'sort' => 1],
                ];

                return $draftPayload;
            },
            'components',
        );

        $badLength = $this->calendarProfilePayload(
            $catalog,
            $tandem,
            'tandem',
            $this->tandemComponents(),
            30,
            'normal',
            null,
            $entries,
        );
        $badLength['positions'][0]['length_seconds'] = 99;
        $this->assertRejectedCreatePreviewPublishForCalendarProfile(
            $catalog,
            $tandem,
            $pm,
            $badLength,
            static function (array $draftPayload): array {
                $draftPayload['positions'][0]['length_seconds'] = 99;

                return $draftPayload;
            },
            'length',
        );

        $offersBefore = StandardOffer::query()->count();
        $this->actingAs($pm)->post(route('standard-offers.store'), [
            ...$this->calendarProfilePayload(
                $catalog,
                $tandem,
                'tandem',
                $this->tandemComponents(),
                30,
                'normal',
                null,
                [['date' => '2026-03-02', 'hour' => 8, 'spot_count' => 1]],
            ),
            'title' => 'Budget Reject 03k',
            'planning_mode' => 'budget',
        ])->assertSessionHasErrors();
        $this->assertSame($offersBefore, StandardOffer::query()->count());
    }

    public function test_publish_after_inventory_rule_change_fails_closed(): void
    {
        $catalog = $this->createSpotClassicCatalog();
        $tandem = $this->attachProfileMedium($catalog, AdvertisingMedium::factory()->tandem()->create());
        $pm = User::factory()->role(Role::ProductManagement)->create();
        $this->setSecondPrice($catalog, '2.0000');

        $offer = $this->writer()->create(
            'Draft vor Regelwechsel 03k',
            $this->calendarProfilePayload(
                $catalog,
                $tandem,
                'tandem',
                $this->tandemComponents(),
                30,
                'normal',
                null,
                [['date' => '2026-03-02', 'hour' => 8, 'spot_count' => 10]],
            ),
            $pm,
        );
        $draft = $offer->draftVersion;
        $this->assertNotNull($draft);
        $draftId = $draft->id;
        $lock = (int) $draft->lock_version;

        InventoryMediumRule::query()
            ->where('inventory_id', $catalog['hamburg']->id)
            ->where('advertising_medium_id', $tandem->id)
            ->update(['is_active' => false]);

        try {
            $this->writer()->publish($draft->fresh(), $lock, $pm);
            $this->fail('Publish nach deaktivierter Inventarregel hätte scheitern müssen.');
        } catch (ValidationException $exception) {
            $this->assertNotEmpty($exception->errors());
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

    public function test_publish_after_planning_rule_change_fails_closed(): void
    {
        // Forced-Profile ignorieren Inventar-Strategie Individual (shared_total_length bleibt Pflicht).
        // Relevante Regeländerung: Einplanung auf must_not_plan – Publish fail-closed ohne Freeze.
        $catalog = $this->createSpotClassicCatalog();
        $tandem = $this->attachProfileMedium($catalog, AdvertisingMedium::factory()->tandem()->create());
        $pm = User::factory()->role(Role::ProductManagement)->create();
        $this->setSecondPrice($catalog, '2.0000');

        $offer = $this->writer()->create(
            'Draft vor Planungsregelwechsel 03k',
            $this->calendarProfilePayload(
                $catalog,
                $tandem,
                'tandem',
                $this->tandemComponents(),
                30,
                'normal',
                null,
                [['date' => '2026-03-02', 'hour' => 8, 'spot_count' => 10]],
            ),
            $pm,
        );
        $draft = $offer->draftVersion;
        $this->assertNotNull($draft);
        $draftId = $draft->id;
        $lock = (int) $draft->lock_version;

        InventoryMediumRule::query()
            ->where('inventory_id', $catalog['hamburg']->id)
            ->where('advertising_medium_id', $tandem->id)
            ->update([
                'planning_responsibility_key' => InventoryMediumRuleOperativeContract::PLANNING_MUST_NOT_PLAN,
            ]);

        try {
            $this->writer()->publish($draft->fresh(), $lock, $pm);
            $this->fail('Publish nach Planungsregelwechsel hätte scheitern müssen.');
        } catch (ValidationException $exception) {
            $joined = collect($exception->errors())->flatten()->implode(' ');
            $this->assertStringContainsString('darf nicht geplant werden', $joined);
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

    public function test_missing_price_cell_and_dates_outside_price_year_and_year_crossing(): void
    {
        $catalog = $this->createSpotClassicCatalog();
        $tandem = $this->attachProfileMedium($catalog, AdvertisingMedium::factory()->tandem()->create());
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

        $missing = $this->calendarProfilePayload(
            $catalog,
            $tandem,
            'tandem',
            $this->tandemComponents(),
            30,
            'normal',
            null,
            [['date' => '2026-03-02', 'hour' => 22, 'spot_count' => 1]],
        );
        try {
            $this->writer()->create('Missing price 03k', $missing, $pm);
            $this->fail('Create mit fehlender Preiszelle hätte scheitern müssen.');
        } catch (ValidationException $exception) {
            $this->assertNotEmpty($exception->errors());
        }
        $this->actingAs($pm)
            ->postJson(route('standard-offers.preview'), [...$missing, 'title' => 'Missing preview 03k'])
            ->assertStatus(422);

        $validForPublish = $this->writer()->create(
            'Missing price publish baseline 03k',
            $this->calendarProfilePayload(
                $catalog,
                $tandem,
                'tandem',
                $this->tandemComponents(),
                30,
                'normal',
                null,
                [['date' => '2026-03-02', 'hour' => 8, 'spot_count' => 10]],
            ),
            $pm,
        );
        $publishDraft = $validForPublish->draftVersion;
        $this->assertNotNull($publishDraft);
        PriceListItem::query()
            ->where('price_list_id', $active->id)
            ->where('hour', 8)
            ->delete();
        try {
            $this->writer()->publish($publishDraft->fresh(), (int) $publishDraft->lock_version, $pm);
            $this->fail('Publish mit fehlender Preiszelle hätte scheitern müssen.');
        } catch (ValidationException $exception) {
            $this->assertNotEmpty($exception->errors());
        }
        $this->assertSame(StandardOfferVersionStatus::Draft, $publishDraft->fresh()->status);
        $this->assertTrue(
            $publishDraft->fresh()->frozen_materialization === null
            || $publishDraft->fresh()->frozen_materialization === [],
        );
        $this->assertSame(0, StandardOfferVersion::query()
            ->where('standard_offer_id', $validForPublish->id)
            ->where('status', StandardOfferVersionStatus::Published)
            ->count());
        // Stunde 8 wiederherstellen für Folgeasserts im gleichen Test.
        foreach ([DayGroup::MoFr, DayGroup::Sa, DayGroup::So] as $group) {
            PriceListItem::factory()->create([
                'price_list_id' => $active->id,
                'hour' => 8,
                'day_group' => $group,
                'second_price' => '2.0000',
            ]);
        }

        $outsideYear = $this->calendarProfilePayload(
            $catalog,
            $tandem,
            'tandem',
            $this->tandemComponents(),
            30,
            'normal',
            null,
            [['date' => '2027-03-01', 'hour' => 8, 'spot_count' => 10]],
            [
                'price_year' => 2026,
                'expected_price_list_id' => $active->id,
            ],
        );
        $this->assertCalendarProfileRejectedOnCreatePreviewPublish(
            $catalog,
            $tandem,
            $pm,
            $outsideYear,
            'positions.0.planner_entries.0.date',
            'getrennte Position',
            '2027-03-01',
        );

        $yearBoundary = $this->calendarProfilePayload(
            $catalog,
            $tandem,
            'tandem',
            $this->tandemComponents(),
            30,
            'normal',
            null,
            [
                ['date' => '2026-12-31', 'hour' => 8, 'spot_count' => 1],
                ['date' => '2027-01-01', 'hour' => 9, 'spot_count' => 1],
            ],
            [
                'price_year' => 2026,
                'expected_price_list_id' => $active->id,
            ],
        );
        $this->assertCalendarProfileRejectedOnCreatePreviewPublish(
            $catalog,
            $tandem,
            $pm,
            $yearBoundary,
            'positions.0.planner_entries.1.date',
            'getrennte Position',
            '2027-01-01',
        );
    }

    public function test_frozen_calendar_profile_inconsistencies_fail_closed_independently(): void
    {
        $catalog = $this->createSpotClassicCatalog();
        $tandem = $this->attachProfileMedium($catalog, AdvertisingMedium::factory()->tandem()->create());
        $pm = User::factory()->role(Role::ProductManagement)->create();
        $sales = User::factory()->role(Role::Sales)->create();
        $this->setSecondPrice($catalog, '2.0000');

        $published = $this->publishCalendarProfile(
            $catalog,
            $tandem,
            'tandem',
            $this->tandemComponents(),
            30,
            'normal',
            null,
            $pm,
        );
        /** @var array<string, mixed> $baseline */
        $baseline = json_decode(json_encode($published->frozen_materialization, JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR);
        app(FrozenCalculationPersistenceContract::class)->assertHydratable($baseline);

        $cases = [
            'invalid profile' => [
                'needle' => 'component_profile',
                'mutate' => static function (array $mat): array {
                    $mat['positions'][0]['component_profile'] = 'invalid';

                    return $mat;
                },
            ],
            'slot mismatch' => [
                'needle' => 'Komponenten passen nicht zu tandem',
                'mutate' => static function (array $mat): array {
                    $mat['positions'][0]['components'] = [
                        $mat['positions'][0]['components'][0],
                    ];

                    return $mat;
                },
            ],
            'individual strategy' => [
                'needle' => 'shared_total_length',
                'mutate' => static function (array $mat): array {
                    $mat['positions'][0]['component_calculation_strategy'] = 'individual';

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
            'festpreis without nn' => [
                'needle' => 'fixed_price_nn',
                'mutate' => static function (array $mat): array {
                    $mat['positions'][0]['pricing_settlement_mode'] = 'fixed_price';
                    $mat['positions'][0]['fixed_price_nn'] = null;
                    $mat['nn_invest'] = '888.50';

                    return $mat;
                },
            ],
            'planner spot sum mismatch' => [
                'needle' => 'Spot-Summe',
                'mutate' => static function (array $mat): array {
                    $mat['positions'][0]['total_spot_count'] = 99;

                    return $mat;
                },
            ],
            'duplicate planner cell' => [
                'needle' => 'doppelte planner_entries-Zelle',
                'mutate' => static function (array $mat): array {
                    $mat['positions'][0]['planner_entries'][] = $mat['positions'][0]['planner_entries'][0];
                    $mat['positions'][0]['total_spot_count'] = 20;

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
        $ok = $this->writer()->adopt($published->fresh(), 'Baseline OK 03k', null, null, $sales);
        $this->assertSame('600.00', (string) $ok->media_gross);
        $this->assertCount(2, $ok->positions->first()->components);
    }

    public function test_post_adopt_year_inventory_and_medium_rebind_calendar_profile(): void
    {
        $catalog = $this->createSpotClassicCatalog();
        $tandem = $this->attachProfileMedium($catalog, AdvertisingMedium::factory()->tandem()->create());
        $tridem = $this->attachProfileMedium($catalog, AdvertisingMedium::factory()->tridem()->create());
        $this->attachProfileMediumToInventory($catalog['rock']->id, $tandem);
        $pm = User::factory()->role(Role::ProductManagement)->create();
        $sales = User::factory()->role(Role::Sales)->create();
        $this->setSecondPrice($catalog, '2.0000');
        $this->setSecondPriceForInventory($catalog['rock']->id, '4.0000');
        $list2027 = $this->createActiveYearList($catalog, 2027, '3.0000');

        $publishedYear = $this->publishCalendarProfile(
            $catalog,
            $tandem,
            'tandem',
            $this->tandemComponents(),
            30,
            'fixed_price',
            '888.50',
            $pm,
        );
        $frozenPin = (int) $publishedYear->frozen_materialization['positions'][0]['price_list_id'];
        $adoptedYear = $this->writer()->adopt($publishedYear->fresh(), 'Rebind Year 03k', null, null, $sales);
        $yearPayload = app(CalculationWriter::class)->payloadFromCalculation($adoptedYear->fresh([
            'positions.plannerEntries',
            'positions.planRows',
            'positions.timeRanges',
            'positions.discounts',
            'positions.components',
            'orderDiscounts',
        ]));
        $yearPayload['lock_version'] = $adoptedYear->fresh()->lock_version;
        $yearPayload['positions'][0]['price_year'] = 2027;
        $yearPayload['positions'][0]['expected_price_list_id'] = $list2027->id;
        $yearPayload['positions'][0]['planner_entries'] = [
            ['date' => '2027-03-01', 'hour' => 8, 'spot_count' => 10],
        ];
        $afterYear = app(CalculationWriter::class)->update($adoptedYear->fresh(), $yearPayload, $sales);
        $yearPos = $afterYear->fresh(['positions.plannerEntries', 'positions.components'])->positions->firstOrFail();
        $this->assertNotSame($frozenPin, (int) $yearPos->price_list_id);
        $this->assertSame($list2027->id, (int) $yearPos->price_list_id);
        $this->assertSame('3.0000', (string) $yearPos->plannerEntries->first()->second_price);
        $this->assertSame('888.50', (string) $afterYear->nn_invest);
        $this->assertSame('tandem', $yearPos->component_profile?->value);
        $this->assertCount(2, $yearPos->components);

        $publishedInv = $this->publishCalendarProfile(
            $catalog,
            $tandem,
            'tandem',
            $this->tandemComponents(),
            30,
            'fixed_price',
            '888.50',
            $pm,
        );
        $adoptedInv = $this->writer()->adopt($publishedInv->fresh(), 'Rebind Inv 03k', null, null, $sales);
        $rockList = PriceList::query()
            ->where('inventory_id', $catalog['rock']->id)
            ->where('status', PriceListStatus::Active)
            ->where('year', 2026)
            ->firstOrFail();
        $invPayload = app(CalculationWriter::class)->payloadFromCalculation($adoptedInv->fresh([
            'positions.plannerEntries',
            'positions.planRows',
            'positions.timeRanges',
            'positions.discounts',
            'positions.components',
            'orderDiscounts',
        ]));
        $invPayload['lock_version'] = $adoptedInv->fresh()->lock_version;
        $invPayload['positions'][0]['inventory_id'] = $catalog['rock']->id;
        $invPayload['positions'][0]['expected_price_list_id'] = $rockList->id;
        $afterInv = app(CalculationWriter::class)->update($adoptedInv->fresh(), $invPayload, $sales);
        $invPos = $afterInv->fresh(['positions.plannerEntries', 'positions.components'])->positions->firstOrFail();
        $this->assertSame($rockList->id, (int) $invPos->price_list_id);
        $this->assertSame('4.0000', (string) $invPos->plannerEntries->first()->second_price);
        $this->assertSame('888.50', (string) $afterInv->nn_invest);
        $this->assertSame('tandem', $invPos->component_profile?->value);

        $publishedMedium = $this->publishCalendarProfile(
            $catalog,
            $tandem,
            'tandem',
            $this->tandemComponents(),
            30,
            'fixed_price',
            '888.50',
            $pm,
        );
        $adoptedMedium = $this->writer()->adopt($publishedMedium->fresh(), 'Rebind Medium 03k', null, null, $sales);
        $adoptedMedium->load('configurationSnapshot');
        $base = $adoptedMedium->configurationSnapshot;
        $mediumPayload = app(CalculationWriter::class)->payloadFromCalculation($adoptedMedium->fresh([
            'positions.plannerEntries',
            'positions.planRows',
            'positions.timeRanges',
            'positions.discounts',
            'positions.components',
            'orderDiscounts',
        ]));
        $mediumPayload['lock_version'] = $adoptedMedium->fresh()->lock_version;
        $mediumPayload['positions'][0]['advertising_medium_id'] = $tridem->id;
        $mediumPayload['positions'][0]['component_profile'] = 'tridem';
        $mediumPayload['positions'][0]['components'] = $this->tridemComponents();
        $mediumPayload['positions'][0]['length_seconds'] = 40;
        $mediumPayload['positions'][0]['schema_fingerprint'] = app(ConfigurationSnapshotFreezeService::class)
            ->resolvePositionSchemaFromBase($base, (int) $tridem->id)['schema_fingerprint'];
        $afterMedium = app(CalculationWriter::class)->update($adoptedMedium->fresh(), $mediumPayload, $sales);
        $medPos = $afterMedium->fresh(['positions.components', 'positions.plannerEntries'])->positions->firstOrFail();
        $this->assertSame('tridem', $medPos->component_profile?->value);
        $this->assertCount(3, $medPos->components);
        $this->assertSame(40, (int) $medPos->length_seconds);
        $entryDate = $medPos->plannerEntries->first()->date;
        $this->assertSame(
            '2026-03-02',
            $entryDate instanceof CarbonInterface ? $entryDate->toDateString() : (string) $entryDate,
        );
        $this->assertSame(10, (int) $medPos->plannerEntries->first()->spot_count);
        $this->assertSame('888.50', (string) $afterMedium->nn_invest);
    }

    public function test_calendar_medium_switch_tandem_to_tridem_rebuilds_slots_keeps_timing(): void
    {
        $catalog = $this->createSpotClassicCatalog();
        $tandem = $this->attachProfileMedium($catalog, AdvertisingMedium::factory()->tandem()->create());
        $tridem = $this->attachProfileMedium($catalog, AdvertisingMedium::factory()->tridem()->create());
        $sales = User::factory()->role(Role::Sales)->create();
        $this->setSecondPrice($catalog, '2.0000');

        $payload = $this->withLiveSchemaFingerprint([
            'planning_mode' => 'manual',
            'customer_name' => 'Switch 03k',
            'order_discount_percent' => '0',
            'ae_enabled' => false,
            'order_discounts' => [],
            'dynamic_field_values' => [],
            'positions' => [[
                ...$this->calendarProfilePosition(
                    $catalog,
                    $tandem,
                    'tandem',
                    $this->tandemComponents(),
                    30,
                    'normal',
                    null,
                    [['date' => '2026-03-02', 'hour' => 8, 'spot_count' => 10]],
                ),
            ]],
        ]);
        $calc = app(CalculationWriter::class)->create($payload, $sales);
        $this->assertSame('600.00', (string) $calc->media_gross);
        $calc->load('configurationSnapshot');
        $base = $calc->configurationSnapshot;

        $update = app(CalculationWriter::class)->payloadFromCalculation($calc->fresh([
            'positions.plannerEntries',
            'positions.planRows',
            'positions.timeRanges',
            'positions.discounts',
            'positions.components',
            'orderDiscounts',
        ]));
        $update['lock_version'] = $calc->fresh()->lock_version;
        $update['positions'][0]['advertising_medium_id'] = $tridem->id;
        $update['positions'][0]['component_profile'] = 'tridem';
        $update['positions'][0]['components'] = $this->tridemComponents();
        $update['positions'][0]['length_seconds'] = 40;
        $update['positions'][0]['schema_fingerprint'] = app(ConfigurationSnapshotFreezeService::class)
            ->resolvePositionSchemaFromBase($base, (int) $tridem->id)['schema_fingerprint'];

        $preview = app(CalculationWriter::class)->preview($update, $sales)->toArray();
        $this->assertSame('760.00', $preview['media_gross']);
        $this->assertSame('760.00', $preview['nn_invest']);

        $updated = app(CalculationWriter::class)->update($calc->fresh(), $update, $sales);
        $position = $updated->fresh(['positions.components', 'positions.plannerEntries'])->positions->firstOrFail();
        $this->assertSame('tridem', $position->component_profile?->value);
        $this->assertCount(3, $position->components);
        $this->assertSame(
            [20, 10, 10],
            $position->components->sortBy('sort')->values()->map(fn ($c) => (int) $c->length_seconds)->all(),
        );
        $this->assertSame(40, (int) $position->length_seconds);
        $this->assertSame(10, (int) $position->plannerEntries->first()->spot_count);
        $this->assertSame(8, (int) $position->plannerEntries->first()->hour);
        $this->assertSame('760.00', (string) $updated->media_gross);

        $reloaded = app(CalculationWriter::class)->payloadFromCalculation($updated->fresh([
            'positions.components',
            'positions.plannerEntries',
            'positions.planRows',
            'positions.timeRanges',
            'positions.discounts',
            'orderDiscounts',
        ]));
        $this->assertSame('tridem', $reloaded['positions'][0]['component_profile'] ?? null);
        $this->assertCount(3, $reloaded['positions'][0]['components']);
        $this->assertSame(10, (int) $reloaded['positions'][0]['planner_entries'][0]['spot_count']);
    }

    public function test_normal_and_fixed_price_calendar_profiles_side_by_side(): void
    {
        $catalog = $this->createSpotClassicCatalog();
        $tandem = $this->attachProfileMedium($catalog, AdvertisingMedium::factory()->tandem()->create());
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
                $this->calendarProfilePosition(
                    $catalog,
                    $tandem,
                    'tandem',
                    $this->tandemComponents(),
                    30,
                    'fixed_price',
                    '888.50',
                    [['date' => '2026-03-02', 'hour' => 8, 'spot_count' => 10]],
                    'cal-fp-tandem',
                ),
                $this->calendarProfilePosition(
                    $catalog,
                    $tandem,
                    'tandem',
                    $this->tandemComponents(),
                    30,
                    'normal',
                    null,
                    [['date' => '2026-03-03', 'hour' => 8, 'spot_count' => 10]],
                    'cal-normal-tandem',
                ),
            ],
        ]);

        $offer = $this->writer()->create('Mix Settlement Calendar 03k', $payload, $pm);
        $draft = $offer->draftVersion;
        $this->assertNotNull($draft);
        $this->assertSame('fixed_price', $draft->draft_payload['positions'][0]['pricing_settlement_mode']);
        $this->assertSame('normal', $draft->draft_payload['positions'][1]['pricing_settlement_mode']);
        $this->assertSame('tandem', $draft->draft_payload['positions'][0]['component_profile'] ?? null);
        $this->assertSame('tandem', $draft->draft_payload['positions'][1]['component_profile'] ?? null);

        $published = $this->writer()->publish($draft, (int) $draft->lock_version, $pm);
        $adopted = $this->writer()->adopt($published, 'Mix 03k Kunde', null, null, $sales);
        $sorted = $adopted->fresh(['positions.components'])->positions->sortBy('sort')->values();
        $this->assertSame('fixed_price', $sorted[0]->pricing_settlement_mode?->value ?? $sorted[0]->pricing_settlement_mode);
        $this->assertSame('888.50', (string) $sorted[0]->fixed_price_nn);
        $this->assertCount(2, $sorted[0]->components);
        $this->assertSame('normal', $sorted[1]->pricing_settlement_mode?->value ?? $sorted[1]->pricing_settlement_mode);
        $this->assertCount(2, $sorted[1]->components);
    }

    private function writer(): StandardOfferWriter
    {
        return app(StandardOfferWriter::class);
    }

    /**
     * @param  array{hamburg: Inventory, medium: AdvertisingMedium}  $catalog
     */
    private function attachProfileMedium(array $catalog, AdvertisingMedium $medium): AdvertisingMedium
    {
        return $this->attachProfileMediumToInventory($catalog['hamburg']->id, $medium);
    }

    private function attachProfileMediumToInventory(int $inventoryId, AdvertisingMedium $medium): AdvertisingMedium
    {
        InventoryMediumRule::factory()->create([
            'inventory_id' => $inventoryId,
            'advertising_medium_id' => $medium->id,
            'component_calculation_strategy' => ComponentCalculationStrategy::SharedTotalLength,
        ]);

        return $medium;
    }

    /**
     * @param  array{hamburg: Inventory}  $catalog
     * @param  array<string, mixed>  $invalidPayload
     * @param  callable(array<string, mixed>): array<string, mixed>  $corruptDraft
     */
    private function assertRejectedCreatePreviewPublishForCalendarProfile(
        array $catalog,
        AdvertisingMedium $medium,
        User $pm,
        array $invalidPayload,
        callable $corruptDraft,
        string $errorNeedle,
    ): void {
        $offersBefore = StandardOffer::query()->count();
        try {
            $this->writer()->create('Invalid profile create 03k', $invalidPayload, $pm);
            $this->fail('Create hätte scheitern müssen ('.$errorNeedle.').');
        } catch (ValidationException $exception) {
            $joined = collect($exception->errors())->keys()->implode(' ').' '.collect($exception->errors())->flatten()->implode(' ');
            $this->assertStringContainsString($errorNeedle, $joined, $joined);
        }
        $this->assertSame($offersBefore, StandardOffer::query()->count());

        $this->actingAs($pm)
            ->postJson(route('standard-offers.preview'), [...$invalidPayload, 'title' => 'Invalid profile preview 03k'])
            ->assertStatus(422);

        $valid = $this->writer()->create(
            'Profile publish baseline 03k',
            $this->calendarProfilePayload(
                $catalog,
                $medium,
                'tandem',
                $this->tandemComponents(),
                30,
                'normal',
                null,
                [['date' => '2026-03-02', 'hour' => 8, 'spot_count' => 10]],
            ),
            $pm,
        );
        $draft = $valid->draftVersion;
        $this->assertNotNull($draft);
        $draft->draft_payload = $corruptDraft($draft->draft_payload);
        $draft->save();

        try {
            $this->writer()->publish($draft->fresh(), (int) $draft->lock_version, $pm);
            $this->fail('Publish hätte scheitern müssen ('.$errorNeedle.').');
        } catch (ValidationException $exception) {
            $joined = collect($exception->errors())->keys()->implode(' ').' '.collect($exception->errors())->flatten()->implode(' ');
            $this->assertStringContainsString($errorNeedle, $joined, $joined);
        }

        $this->assertSame(StandardOfferVersionStatus::Draft, $draft->fresh()->status);
        $this->assertTrue(
            $draft->fresh()->frozen_materialization === null
            || $draft->fresh()->frozen_materialization === [],
        );
        $this->assertSame(0, StandardOfferVersion::query()
            ->where('standard_offer_id', $valid->id)
            ->where('status', StandardOfferVersionStatus::Published)
            ->count());
    }

    /**
     * @return list<array{role: string, label: string, length_seconds: int, sort: int}>
     */
    private function tandemComponents(): array
    {
        return [
            ['role' => 'main_spot', 'label' => 'Hauptspot', 'length_seconds' => 20, 'sort' => 1],
            ['role' => 'reminder', 'label' => 'Reminder', 'length_seconds' => 10, 'sort' => 2],
        ];
    }

    /**
     * @return list<array{role: string, label: string, length_seconds: int, sort: int}>
     */
    private function tridemComponents(): array
    {
        return [
            ['role' => 'main_spot', 'label' => 'Hauptspot', 'length_seconds' => 20, 'sort' => 1],
            ['role' => 'reminder', 'label' => 'Reminder', 'length_seconds' => 10, 'sort' => 2],
            ['role' => 'reminder', 'label' => 'Reminder', 'length_seconds' => 10, 'sort' => 3],
        ];
    }

    /**
     * @param  array{hamburg: Inventory}  $catalog
     * @param  list<array{date: string, hour: int, spot_count: int}>  $entries
     * @param  array<string, mixed>  $positionOverrides
     * @return array<string, mixed>
     */
    private function calendarProfilePayload(
        array $catalog,
        AdvertisingMedium $medium,
        string $profile,
        array $components,
        int $length,
        string $mode,
        ?string $nn,
        array $entries,
        array $positionOverrides = [],
    ): array {
        return $this->withLiveSchemaFingerprint([
            'planning_mode' => 'manual',
            'order_discount_percent' => '0',
            'ae_enabled' => false,
            'order_discounts' => [],
            'dynamic_field_values' => ['campaign_period' => null],
            'positions' => [[
                ...$this->calendarProfilePosition($catalog, $medium, $profile, $components, $length, $mode, $nn, $entries),
                ...$positionOverrides,
            ]],
        ]);
    }

    /**
     * @param  array{hamburg: Inventory}  $catalog
     * @param  list<array{date: string, hour: int, spot_count: int}>  $entries
     * @return array<string, mixed>
     */
    private function calendarProfilePosition(
        array $catalog,
        AdvertisingMedium $medium,
        string $profile,
        array $components,
        int $length,
        string $mode,
        ?string $nn,
        array $entries,
        string $clientKey = 'cal-profile',
    ): array {
        $row = [
            'client_key' => $clientKey,
            'inventory_id' => $catalog['hamburg']->id,
            'advertising_medium_id' => $medium->id,
            'spot_method' => 'calendar',
            'calculation_method_key' => 'calendar',
            'length_seconds' => $length,
            'position_discount_percent' => '0',
            'ae_percent' => '0',
            'pricing_settlement_mode' => $mode,
            'component_profile' => $profile,
            'component_calculation_strategy' => ComponentCalculationStrategy::SharedTotalLength->value,
            'components' => $components,
            'planner_entries' => $entries,
            'plan_rows' => [],
            'time_ranges' => [],
            'position_discounts' => [],
            'dynamic_field_values' => ['period_open' => true],
        ];
        if ($nn !== null) {
            $row['fixed_price_nn'] = $nn;
        }

        return $row;
    }

    /**
     * @param  array{hamburg: Inventory}  $catalog
     * @param  list<array{role: string, label: string, length_seconds: int, sort: int}>  $components
     */
    private function publishCalendarProfile(
        array $catalog,
        AdvertisingMedium $medium,
        string $profile,
        array $components,
        int $length,
        string $mode,
        ?string $nn,
        User $pm,
    ): StandardOfferVersion {
        $offer = $this->writer()->create(
            'Calendar profile fixture',
            $this->calendarProfilePayload(
                $catalog,
                $medium,
                $profile,
                $components,
                $length,
                $mode,
                $nn,
                [['date' => '2026-03-02', 'hour' => 8, 'spot_count' => 10]],
            ),
            $pm,
        );
        $draft = $offer->draftVersion;
        $this->assertNotNull($draft);

        return $this->writer()->publish($draft, (int) $draft->lock_version, $pm);
    }

    /**
     * @param  array{hamburg: Inventory}  $catalog
     * @param  array<string, mixed>  $invalidPayload
     */
    private function assertCalendarProfileRejectedOnCreatePreviewPublish(
        array $catalog,
        AdvertisingMedium $medium,
        User $pm,
        array $invalidPayload,
        string $errorKey,
        string $needle,
        string $dateNeedle,
    ): void {
        $offersBefore = StandardOffer::query()->count();
        try {
            $this->writer()->create('Invalid year create 03k', $invalidPayload, $pm);
            $this->fail('Create hätte an der Jahresgrenze scheitern müssen.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey($errorKey, $exception->errors());
            $joined = implode(' ', $exception->errors()[$errorKey]);
            $this->assertStringContainsString($needle, $joined);
            $this->assertStringContainsString($dateNeedle, $joined);
        }
        $this->assertSame($offersBefore, StandardOffer::query()->count());

        $this->actingAs($pm)
            ->postJson(route('standard-offers.preview'), [...$invalidPayload, 'title' => 'Invalid year preview 03k'])
            ->assertStatus(422)
            ->assertJsonValidationErrors($errorKey);

        $valid = $this->writer()->create(
            'Year publish baseline 03k',
            $this->calendarProfilePayload(
                $catalog,
                $medium,
                'tandem',
                $this->tandemComponents(),
                30,
                'normal',
                null,
                [['date' => '2026-03-02', 'hour' => 8, 'spot_count' => 10]],
            ),
            $pm,
        );
        $draft = $valid->draftVersion;
        $this->assertNotNull($draft);

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
        }

        $this->assertSame(StandardOfferVersionStatus::Draft, $draft->fresh()->status);
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
            'name' => 'Nachfolger 03k '.$secondPrice,
            'items' => $items,
        ], $admin);
        $preview = app(PriceListImpactPreviewService::class)->previewActivate($draft);

        return app(PriceListAdminWriter::class)->activate($draft, [
            'lock_version' => $draft->lock_version,
            'fingerprint' => (string) $preview['fingerprint'],
        ], $admin);
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
            'version' => 'e2e-03k-'.$year,
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
}
