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
use App\Services\StandardOffer\FrozenCalculationPersistenceContract;
use App\Services\StandardOffer\StandardOfferMaterializer;
use App\Services\StandardOffer\StandardOfferWriter;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\Concerns\CreatesSpotClassicCatalog;
use Tests\TestCase;

/**
 * BL-P4-03g / PO-BLP403G-1 / A1+B1+C1:
 * Calendar × Spot Classic × normal in Standardangeboten.
 */
class StandardOfferBlP403gTest extends TestCase
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

    public function test_write_version_is_four(): void
    {
        $this->assertSame(4, FrozenCalculationPersistenceContract::CURRENT_WRITE_VERSION);
        $this->assertSame(4, StandardOfferMaterializer::MATERIALIZATION_VERSION);
        $this->assertSame([1, 2, 3, 4], FrozenCalculationPersistenceContract::SUPPORTED_VERSIONS);
    }

    public function test_calendar_draft_publish_adopt_reload_parity(): void
    {
        $catalog = $this->createSpotClassicCatalog();
        $pm = User::factory()->role(Role::ProductManagement)->create();
        $sales = User::factory()->role(Role::Sales)->create();
        $this->setSecondPrice($catalog, '2.4000');

        $entries = [
            ['date' => '2026-03-02', 'hour' => 8, 'spot_count' => 3],
            ['date' => '2026-03-03', 'hour' => 8, 'spot_count' => 2],
            ['date' => '2026-03-04', 'hour' => 9, 'spot_count' => 1],
        ];
        $payload = $this->calendarDraftPayload($catalog, $entries);

        $offer = $this->writer()->create('Calendar März 2026', $payload, $pm);
        $draft = $offer->draftVersion;
        $this->assertNotNull($draft);
        $this->assertSame('calendar', $draft->draft_payload['positions'][0]['spot_method']);
        $this->assertCount(3, $draft->draft_payload['positions'][0]['planner_entries']);

        $published = $this->writer()->publish($draft, (int) $draft->lock_version, $pm);
        $this->assertSame(StandardOfferVersionStatus::Published, $published->status);
        $this->assertSame(4, (int) ($published->frozen_materialization['materialization_version'] ?? 0));

        $frozen = $published->frozen_materialization['positions'][0];
        $this->assertSame('calendar', $frozen['spot_method']);
        $this->assertSame('normal', $frozen['pricing_settlement_mode']);
        $this->assertSame(6, (int) $frozen['total_spot_count']);
        $this->assertCount(3, $frozen['planner_entries']);
        $this->assertSame('2026-03-02', $frozen['planner_entries'][0]['date']);
        $this->assertSame(8, (int) $frozen['planner_entries'][0]['hour']);
        $this->assertSame(3, (int) $frozen['planner_entries'][0]['spot_count']);
        $this->assertSame('2.4000', (string) $frozen['planner_entries'][0]['second_price']);
        $frozenNn = (string) ($published->frozen_materialization['nn_invest'] ?? '');
        $frozenPin = (int) $frozen['price_list_id'];
        $frozenVersion = $frozen['price_list_version'];

        $sourceNnBefore = $frozenNn;
        $adopted = $this->writer()->adopt($published, 'Adopt Kunde', null, null, $sales);
        $fresh = $adopted->fresh([
            'positions.plannerEntries',
            'positions.planRows',
            'positions.timeRanges',
        ]);
        $position = $fresh->positions->firstOrFail();
        $this->assertSame('calendar', $position->spot_method?->value ?? $position->spot_method);
        $this->assertSame('calendar', $position->calculation_method_key);
        $this->assertSame($frozenPin, (int) $position->price_list_id);
        $this->assertSame($frozenVersion, $position->price_list_version);
        $this->assertSame($frozenNn, (string) $fresh->nn_invest);
        $this->assertSame($published->id, $fresh->origin_standard_offer_version_id);
        $this->assertCount(3, $position->plannerEntries);
        $this->assertSame(
            ['2026-03-02', '2026-03-03', '2026-03-04'],
            $position->plannerEntries->pluck('date')->map(fn ($d) => $d instanceof CarbonInterface ? $d->toDateString() : (string) $d)->all(),
        );
        $this->assertSame([3, 2, 1], $position->plannerEntries->pluck('spot_count')->map(fn ($v) => (int) $v)->all());
        $this->assertSame(['2.4000', '2.4000', '2.4000'], $position->plannerEntries->pluck('second_price')->map(fn ($v) => (string) $v)->all());

        $publishedFresh = $published->fresh();
        $this->assertSame($sourceNnBefore, (string) ($publishedFresh->frozen_materialization['nn_invest'] ?? ''));
        $this->assertSame(3, count($publishedFresh->frozen_materialization['positions'][0]['planner_entries']));
        $this->assertSame('2026-03-02', $publishedFresh->frozen_materialization['positions'][0]['planner_entries'][0]['date']);
    }

    public function test_adopt_in_following_year_keeps_2026_dates_and_frozen_prices(): void
    {
        $catalog = $this->createSpotClassicCatalog();
        $pm = User::factory()->role(Role::ProductManagement)->create();
        $sales = User::factory()->role(Role::Sales)->create();
        $this->setSecondPrice($catalog, '2.4000');

        $offer = $this->writer()->create(
            'Calendar Vorjahr',
            $this->calendarDraftPayload($catalog, [
                ['date' => '2026-03-02', 'hour' => 8, 'spot_count' => 4],
            ]),
            $pm,
        );
        $draft = $offer->draftVersion;
        $published = $this->writer()->publish($draft, (int) $draft->lock_version, $pm);
        $frozen = $published->frozen_materialization['positions'][0];
        $frozenNn = (string) $published->frozen_materialization['nn_invest'];
        $frozenPin = (int) $frozen['price_list_id'];
        $frozenSecond = (string) $frozen['planner_entries'][0]['second_price'];

        // Aktives Folgejahr mit anderem Preis – darf Adopt nicht beeinflussen (C1).
        $this->createActiveYearList($catalog, 2027, '9.9000');
        Carbon::setTestNow(Carbon::parse('2027-02-01 10:00:00', 'Europe/Berlin'));

        $adopted = $this->writer()->adopt($published->fresh(), 'Kunde 2027', null, null, $sales);
        $position = $adopted->fresh(['positions.plannerEntries'])->positions->firstOrFail();

        $this->assertSame('2026-03-02', $this->entryDate($position->plannerEntries->first()));
        $this->assertSame(4, (int) $position->plannerEntries->first()->spot_count);
        $this->assertSame($frozenSecond, (string) $position->plannerEntries->first()->second_price);
        $this->assertSame($frozenPin, (int) $position->price_list_id);
        $this->assertSame($frozenNn, (string) $adopted->nn_invest);
        $this->assertSame(2026, (int) PriceList::query()->whereKey($frozenPin)->value('year'));
    }

    public function test_active_price_change_after_publish_does_not_affect_adopt(): void
    {
        $catalog = $this->createSpotClassicCatalog();
        $pm = User::factory()->role(Role::ProductManagement)->create();
        $sales = User::factory()->role(Role::Sales)->create();
        $this->setSecondPrice($catalog, '2.4000');

        $offer = $this->writer()->create(
            'Freeze Parity',
            $this->calendarDraftPayload($catalog, [
                ['date' => '2026-03-02', 'hour' => 8, 'spot_count' => 2],
            ]),
            $pm,
        );
        $draft = $offer->draftVersion;
        $published = $this->writer()->publish($draft, (int) $draft->lock_version, $pm);
        $frozenNn = (string) $published->frozen_materialization['nn_invest'];
        $frozenSecond = (string) $published->frozen_materialization['positions'][0]['planner_entries'][0]['second_price'];

        $this->setSecondPrice($catalog, '7.7000');

        $adopted = $this->writer()->adopt($published->fresh(), 'Parity Kunde', null, null, $sales);
        $position = $adopted->fresh(['positions.plannerEntries'])->positions->firstOrFail();
        $this->assertSame($frozenSecond, (string) $position->plannerEntries->first()->second_price);
        $this->assertSame($frozenNn, (string) $adopted->nn_invest);
        $this->assertSame('2.4000', $frozenSecond);
    }

    public function test_post_adopt_edit_with_new_dates_and_year_rebinds_live(): void
    {
        $catalog = $this->createSpotClassicCatalog();
        $pm = User::factory()->role(Role::ProductManagement)->create();
        $sales = User::factory()->role(Role::Sales)->create();
        $this->setSecondPrice($catalog, '2.4000');
        $list2027 = $this->createActiveYearList($catalog, 2027, '3.0000');

        $offer = $this->writer()->create(
            'Edit nach Adopt',
            $this->calendarDraftPayload($catalog, [
                ['date' => '2026-03-02', 'hour' => 8, 'spot_count' => 2],
            ]),
            $pm,
        );
        $published = $this->writer()->publish(
            $offer->draftVersion,
            (int) $offer->draftVersion->lock_version,
            $pm,
        );
        $adopted = $this->writer()->adopt($published, 'Edit Kunde', null, null, $sales);
        $frozenNn = (string) $published->frozen_materialization['nn_invest'];
        $this->assertNotSame('0', $frozenNn);

        $editPayload = app(CalculationWriter::class)->payloadFromCalculation(
            $adopted->fresh([
                'positions.plannerEntries',
                'positions.planRows',
                'positions.timeRanges',
                'positions.discounts',
                'positions.components',
                'orderDiscounts',
                'configurationSnapshot',
                'fieldValues',
            ]),
        );
        $editPayload['positions'][0]['price_year'] = 2027;
        $editPayload['positions'][0]['expected_price_list_id'] = $list2027->id;
        $editPayload['positions'][0]['planner_entries'] = [
            ['date' => '2027-03-01', 'hour' => 8, 'spot_count' => 2],
        ];
        $editPayload['lock_version'] = $adopted->lock_version;

        $updated = app(CalculationWriter::class)->update($adopted, $editPayload, $sales);
        $position = $updated->fresh(['positions.plannerEntries'])->positions->firstOrFail();
        $this->assertSame('2027-03-01', $this->entryDate($position->plannerEntries->first()));
        $this->assertSame($list2027->id, (int) $position->price_list_id);
        $this->assertSame('3.0000', (string) $position->plannerEntries->first()->second_price);
        $this->assertNotSame($frozenNn, (string) $updated->nn_invest);

        $templateStill = $published->fresh();
        $this->assertSame('2026-03-02', $templateStill->frozen_materialization['positions'][0]['planner_entries'][0]['date']);
        $this->assertSame($frozenNn, (string) $templateStill->frozen_materialization['nn_invest']);
    }

    public function test_missing_price_and_year_mismatch_fail_on_standard_offer_paths(): void
    {
        $catalog = $this->createSpotClassicCatalog();
        $pm = User::factory()->role(Role::ProductManagement)->create();
        $this->setSecondPrice($catalog, '2.4000');

        $active = PriceList::query()
            ->where('inventory_id', $catalog['hamburg']->id)
            ->where('status', PriceListStatus::Active)
            ->where('year', 2026)
            ->firstOrFail();
        PriceListItem::query()
            ->where('price_list_id', $active->id)
            ->where('hour', 22)
            ->delete();

        $missingPricePayload = $this->calendarDraftPayload($catalog, [
            ['date' => '2026-03-02', 'hour' => 22, 'spot_count' => 1],
        ]);

        // Create ruft assertResolvable (Calc-Preview) – fehlende Preiszelle fail-closed.
        try {
            $this->writer()->create('Missing price create', $missingPricePayload, $pm);
            $this->fail('Create mit fehlender Preiszelle hätte scheitern müssen.');
        } catch (ValidationException $exception) {
            $this->assertNotEmpty($exception->errors());
        }
        $this->assertSame(0, StandardOffer::query()->count());

        $this->actingAs($pm)
            ->postJson(route('standard-offers.preview'), [
                ...$missingPricePayload,
                'title' => 'Missing price preview',
            ])
            ->assertStatus(422);
        $this->assertSame(0, StandardOffer::query()->count());

        // Draft mit gültigen Daten anlegen, dann Payload auf fehlende Stunde korrumpieren:
        // Publish muss scheitern, ohne Published-Version / Freeze.
        $validOffer = $this->writer()->create(
            'Publish base',
            $this->calendarDraftPayload($catalog, [
                ['date' => '2026-03-02', 'hour' => 8, 'spot_count' => 1],
            ]),
            $pm,
        );
        $draft = $validOffer->draftVersion;
        $this->assertNotNull($draft);
        $corrupted = $draft->draft_payload;
        $corrupted['positions'][0]['planner_entries'] = [
            ['date' => '2026-03-02', 'hour' => 22, 'spot_count' => 1],
        ];
        $draft->forceFill(['draft_payload' => $corrupted])->save();

        $publishedBefore = StandardOfferVersion::query()
            ->where('status', StandardOfferVersionStatus::Published)
            ->count();
        try {
            $this->writer()->publish($draft->fresh(), (int) $draft->fresh()->lock_version, $pm);
            $this->fail('Publish mit fehlender Preiszelle hätte scheitern müssen.');
        } catch (ValidationException $exception) {
            $this->assertNotEmpty($exception->errors());
        }
        $this->assertSame(
            $publishedBefore,
            StandardOfferVersion::query()->where('status', StandardOfferVersionStatus::Published)->count(),
        );
        $this->assertTrue(
            $draft->fresh()->frozen_materialization === null
            || $draft->fresh()->frozen_materialization === [],
        );
        $this->assertSame(StandardOfferVersionStatus::Draft, $draft->fresh()->status);

        $yearMismatch = $this->calendarDraftPayload($catalog, [
            ['date' => '2027-03-01', 'hour' => 8, 'spot_count' => 1],
        ]);
        $yearMismatch['positions'][0]['price_year'] = 2026;

        try {
            $this->writer()->create('Year mismatch', $yearMismatch, $pm);
            $this->fail('Create mit Datum-/Preisjahr-Widerspruch hätte scheitern müssen.');
        } catch (ValidationException $exception) {
            $this->assertNotEmpty($exception->errors());
        }

        $this->actingAs($pm)
            ->postJson(route('standard-offers.preview'), [
                ...$yearMismatch,
                'title' => 'Year mismatch preview',
            ])
            ->assertStatus(422);
    }

    public function test_frozen_calendar_inconsistencies_fail_closed_without_partial_create(): void
    {
        $catalog = $this->createSpotClassicCatalog();
        $pm = User::factory()->role(Role::ProductManagement)->create();
        $sales = User::factory()->role(Role::Sales)->create();
        $this->setSecondPrice($catalog, '2.4000');

        $published = $this->publishCalendar($catalog, $pm, [
            ['date' => '2026-03-02', 'hour' => 8, 'spot_count' => 3],
            ['date' => '2026-03-03', 'hour' => 8, 'spot_count' => 2],
        ]);

        /** @var array<string, mixed> $baseline */
        $baseline = json_decode(json_encode($published->frozen_materialization, JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR);
        app(FrozenCalculationPersistenceContract::class)->assertHydratable($baseline);

        $cases = [
            'empty planner with positive total' => [
                'needle' => 'planner_entries leer trotz total_spot_count',
                'mutate' => static function (array $mat): array {
                    $mat['positions'][0]['planner_entries'] = [];
                    $mat['positions'][0]['total_spot_count'] = 5;

                    return $mat;
                },
            ],
            'spot sum mismatch' => [
                'needle' => 'Spot-Summe',
                'mutate' => static function (array $mat): array {
                    $mat['positions'][0]['total_spot_count'] = 99;

                    return $mat;
                },
            ],
            'day_group mismatch' => [
                'needle' => 'day_group passt nicht zum Datum',
                'mutate' => static function (array $mat): array {
                    // 2026-03-02 = Montag → mo_fr; sa ist widersprüchlich.
                    $mat['positions'][0]['planner_entries'][0]['day_group'] = 'sa';

                    return $mat;
                },
            ],
            'duplicate date/hour' => [
                'needle' => 'doppelte planner_entries-Zelle',
                'mutate' => static function (array $mat): array {
                    $mat['positions'][0]['planner_entries'][] = $mat['positions'][0]['planner_entries'][0];
                    $mat['positions'][0]['total_spot_count'] = 8;

                    return $mat;
                },
            ],
            'non-numeric second_price' => [
                'needle' => 'planner_entries.0.second_price',
                'mutate' => static function (array $mat): array {
                    $mat['positions'][0]['planner_entries'][0]['second_price'] = 'not-a-price';

                    return $mat;
                },
            ],
            'structurally invalid line_gross' => [
                'needle' => 'planner_entries.0.line_gross',
                'mutate' => static function (array $mat): array {
                    $mat['positions'][0]['planner_entries'][0]['line_gross'] = ['nested' => true];

                    return $mat;
                },
            ],
        ];

        foreach ($cases as $label => $case) {
            /** @var array<string, mixed> $mat */
            $mat = $case['mutate'](
                json_decode(json_encode($baseline, JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR),
            );
            $published->forceFill(['frozen_materialization' => $mat])->save();

            $beforeCalcs = Calculation::query()->count();
            $beforePositions = CalculationPosition::query()->count();
            $beforePlanner = CalculationPositionPlannerEntry::query()->count();

            try {
                $this->writer()->adopt($published->fresh(), "Fail {$label}", null, null, $sales);
                $this->fail("Fall „{$label}“ hätte als ValidationException scheitern müssen.");
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey('frozen_materialization', $exception->errors(), $label);
                $message = implode(' ', $exception->errors()['frozen_materialization']);
                $this->assertStringContainsString($case['needle'], $message, $label);
            }

            $this->assertSame($beforeCalcs, Calculation::query()->count(), $label);
            $this->assertSame($beforePositions, CalculationPosition::query()->count(), $label);
            $this->assertSame($beforePlanner, CalculationPositionPlannerEntry::query()->count(), $label);
        }
    }

    public function test_rejects_calendar_variants_and_mixed_from_calc(): void
    {
        $catalog = $this->createSpotClassicCatalog();
        $pm = User::factory()->role(Role::ProductManagement)->create();
        $sales = User::factory()->role(Role::Sales)->create();
        $this->setSecondPrice($catalog, '2.0000');

        // Calendar×Festpreis Einzelspot: freigegeben in BL-P4-03i.

        // Calendar+Komponenten: freigegeben in BL-P4-03h – Negativfälle dort.

        try {
            $this->writer()->create('Cal Tandem', $this->calendarDraftPayload($catalog, [
                ['date' => '2026-03-02', 'hour' => 8, 'spot_count' => 1],
            ], [
                'component_profile' => 'tandem',
            ]), $pm);
            $this->fail('Calendar+Tandem hätte scheitern müssen.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('positions.0.component_profile', $exception->errors());
        }

        try {
            $this->writer()->create('Cal Tridem', $this->calendarDraftPayload($catalog, [
                ['date' => '2026-03-02', 'hour' => 8, 'spot_count' => 1],
            ], [
                'component_profile' => 'tridem',
            ]), $pm);
            $this->fail('Calendar+Tridem hätte scheitern müssen.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('positions.0.component_profile', $exception->errors());
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
                    'components' => [],
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
        $calculation = app(CalculationWriter::class)->create($mixed, $sales);
        $this->actingAs($sales)
            ->post(route('standard-offers.from-calculation', $calculation))
            ->assertSessionHasErrors();
        $this->assertSame(0, StandardOffer::query()->count());
    }

    public function test_from_calculation_pure_calendar_succeeds(): void
    {
        $catalog = $this->createSpotClassicCatalog();
        $sales = User::factory()->role(Role::Sales)->create();
        $pm = User::factory()->role(Role::ProductManagement)->create();
        $this->setSecondPrice($catalog, '2.0000');

        $calcPayload = $this->withLiveSchemaFingerprint([
            'planning_mode' => 'manual',
            'customer_name' => 'FromCal Kunde',
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
                'components' => [],
                'planner_entries' => [[
                    'date' => '2026-03-02',
                    'hour' => 8,
                    'spot_count' => 5,
                ]],
                'plan_rows' => [],
                'time_ranges' => [],
                'position_discounts' => [],
                'dynamic_field_values' => ['period_open' => true],
            ]],
        ]);
        $calculation = app(CalculationWriter::class)->create($calcPayload, $sales);

        $this->actingAs($sales)
            ->post(route('standard-offers.from-calculation', $calculation))
            ->assertRedirect();

        $offer = StandardOffer::query()->firstOrFail();
        $draft = $offer->draftVersion;
        $this->assertNotNull($draft);
        $this->assertSame('calendar', $draft->draft_payload['positions'][0]['spot_method']);
        $this->assertSame('2026-03-02', $draft->draft_payload['positions'][0]['planner_entries'][0]['date']);

        $published = $this->writer()->publish($draft, (int) $draft->lock_version, $pm);
        $this->assertSame(4, (int) $published->frozen_materialization['materialization_version']);
    }

    public function test_v4_incomplete_and_unknown_version_fail_closed_without_partial_create(): void
    {
        $catalog = $this->createSpotClassicCatalog();
        $pm = User::factory()->role(Role::ProductManagement)->create();
        $sales = User::factory()->role(Role::Sales)->create();
        $this->setSecondPrice($catalog, '2.0000');

        $offer = $this->writer()->create(
            'Hydrate fail',
            $this->calendarDraftPayload($catalog, [
                ['date' => '2026-03-02', 'hour' => 8, 'spot_count' => 1],
            ]),
            $pm,
        );
        $published = $this->writer()->publish(
            $offer->draftVersion,
            (int) $offer->draftVersion->lock_version,
            $pm,
        );

        $before = Calculation::query()->count();
        $beforePositions = CalculationPosition::query()->count();

        $bad = $published->frozen_materialization;
        unset($bad['positions'][0]['planner_entries']);
        $published->forceFill(['frozen_materialization' => $bad])->save();

        try {
            $this->writer()->adopt($published->fresh(), 'Fail Kunde', null, null, $sales);
            $this->fail('Unvollständige v4-Planner-Daten hätten scheitern müssen.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('frozen_materialization', $exception->errors());
        }
        $this->assertSame($before, Calculation::query()->count());
        $this->assertSame($beforePositions, CalculationPosition::query()->count());

        $unknown = $published->fresh()->frozen_materialization;
        $unknown['materialization_version'] = 99;
        $unknown['positions'][0]['planner_entries'] = [[
            'date' => '2026-03-02',
            'hour' => 8,
            'day_group' => 'mo_fr',
            'spot_count' => 1,
            'second_price' => '2.0000',
            'line_gross' => '60.00',
        ]];
        $published->forceFill(['frozen_materialization' => $unknown])->save();

        try {
            $this->writer()->adopt($published->fresh(), 'Fail Version', null, null, $sales);
            $this->fail('Unbekannte Version hätte scheitern müssen.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('frozen_materialization', $exception->errors());
        }
        $this->assertSame($before, Calculation::query()->count());
    }

    public function test_average_v4_and_legacy_v1_v2_v3_remain_adoptable(): void
    {
        $catalog = $this->createSpotClassicCatalog();
        $pm = User::factory()->role(Role::ProductManagement)->create();
        $sales = User::factory()->role(Role::Sales)->create();
        $this->setSecondPrice($catalog, '2.0000');

        $averagePayload = $this->averageDraftPayload($catalog, 'normal');

        $offer = $this->writer()->create('Average v4', $averagePayload, $pm);
        $published = $this->writer()->publish(
            $offer->draftVersion,
            (int) $offer->draftVersion->lock_version,
            $pm,
        );
        $this->assertSame(4, (int) $published->frozen_materialization['materialization_version']);
        $this->assertSame([], $published->frozen_materialization['positions'][0]['planner_entries'] ?? []);
        $adopted = $this->writer()->adopt($published, 'Average Kunde', null, null, $sales);
        $this->assertSame('average', $adopted->positions->first()->spot_method?->value
            ?? $adopted->positions->first()->spot_method);

        // Legacy v1 (fehlendes Versionsfeld).
        $legacyV1 = $published->frozen_materialization;
        unset($legacyV1['materialization_version']);
        $legacyV1['positions'][0]['pricing_settlement_mode'] = 'normal';
        $legacyV1['positions'][0]['client_key'] = (string) Str::uuid();
        unset($legacyV1['positions'][0]['planner_entries']);
        unset($legacyV1['positions'][0]['component_profile']);
        $published->forceFill(['frozen_materialization' => $legacyV1])->save();
        $this->assertInstanceOf(
            Calculation::class,
            $this->writer()->adopt($published->fresh(), 'Legacy v1', null, null, $sales),
        );

        // Legacy v2 mit freigegebenem Festpreis (03e).
        $fpOffer = $this->writer()->create(
            'Legacy v2 FP',
            $this->averageDraftPayload($catalog, 'fixed_price', '150.00'),
            $pm,
        );
        $fpPublished = $this->writer()->publish(
            $fpOffer->draftVersion,
            (int) $fpOffer->draftVersion->lock_version,
            $pm,
        );
        $v2Mat = $fpPublished->frozen_materialization;
        $v2Mat['materialization_version'] = 2;
        $v2Mat['positions'][0]['component_profile'] = null;
        $v2Mat['positions'][0]['client_key'] = (string) Str::uuid();
        unset($v2Mat['positions'][0]['planner_entries']);
        $fpPublished->forceFill(['frozen_materialization' => $v2Mat])->save();
        $v2Adopted = $this->writer()->adopt($fpPublished->fresh(), 'Legacy v2', null, null, $sales);
        $this->assertSame(
            'fixed_price',
            $v2Adopted->positions->first()->pricing_settlement_mode?->value
                ?? $v2Adopted->positions->first()->pricing_settlement_mode,
        );
        $this->assertSame('150.00', (string) $v2Adopted->positions->first()->fixed_price_nn);

        // Legacy v3 mit Tandem (03f) – echte v3-Snapshot-Felder, nicht nur Versionszahl.
        $tandemMedium = AdvertisingMedium::factory()->tandem()->create();
        InventoryMediumRule::factory()->create([
            'inventory_id' => $catalog['hamburg']->id,
            'advertising_medium_id' => $tandemMedium->id,
            'component_calculation_strategy' => ComponentCalculationStrategy::SharedTotalLength,
        ]);
        $tandemPayload = $this->withLiveSchemaFingerprint([
            'planning_mode' => 'manual',
            'order_discount_percent' => '0',
            'ae_enabled' => false,
            'order_discounts' => [],
            'dynamic_field_values' => ['campaign_period' => null],
            'positions' => [[
                'client_key' => 'td',
                'inventory_id' => $catalog['hamburg']->id,
                'advertising_medium_id' => $tandemMedium->id,
                'spot_method' => 'average',
                'length_seconds' => 30,
                'total_spot_count' => 10,
                'position_discount_percent' => '0',
                'ae_percent' => '0',
                'pricing_settlement_mode' => 'normal',
                'component_profile' => 'tandem',
                'component_calculation_strategy' => ComponentCalculationStrategy::SharedTotalLength->value,
                'components' => [
                    ['role' => 'main_spot', 'label' => 'Hauptspot', 'length_seconds' => 20, 'sort' => 1],
                    ['role' => 'reminder', 'label' => 'Reminder', 'length_seconds' => 10, 'sort' => 2],
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
        $tandemOffer = $this->writer()->create('Legacy v3 Tandem', $tandemPayload, $pm);
        $tandemPublished = $this->writer()->publish(
            $tandemOffer->draftVersion,
            (int) $tandemOffer->draftVersion->lock_version,
            $pm,
        );
        $v3Mat = $tandemPublished->frozen_materialization;
        $this->assertSame('tandem', $v3Mat['positions'][0]['component_profile'] ?? null);
        $v3Mat['materialization_version'] = 3;
        $v3Mat['positions'][0]['client_key'] = (string) Str::uuid();
        unset($v3Mat['positions'][0]['planner_entries']);
        $tandemPublished->forceFill(['frozen_materialization' => $v3Mat])->save();
        $v3Adopted = $this->writer()->adopt($tandemPublished->fresh(), 'Legacy v3', null, null, $sales);
        $this->assertSame(
            'tandem',
            $v3Adopted->positions->first()->component_profile?->value
                ?? $v3Adopted->positions->first()->component_profile,
        );
    }

    public function test_http_wizard_allows_calendar_and_shows_scope(): void
    {
        $catalog = $this->createSpotClassicCatalog();
        $pm = User::factory()->role(Role::ProductManagement)->create();
        $this->setSecondPrice($catalog, '2.0000');
        $payload = $this->calendarDraftPayload($catalog, [
            ['date' => '2026-03-02', 'hour' => 8, 'spot_count' => 2],
        ]);
        $payload['title'] = 'HTTP Calendar';

        $this->actingAs($pm)->post(route('standard-offers.store'), $payload)
            ->assertRedirect();

        $offer = StandardOffer::query()->firstOrFail();
        $this->actingAs($pm)
            ->get(route('standard-offers.show', $offer))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('standardOffer.allowed_spot_methods', ['average', 'calendar'])
                ->where('standardOffer.scope_note', fn ($note) => is_string($note) && str_contains($note, 'Calendar')));
    }

    private function writer(): StandardOfferWriter
    {
        return app(StandardOfferWriter::class);
    }

    /**
     * @param  array{hamburg: Inventory, medium: AdvertisingMedium}  $catalog
     * @param  list<array{date: string, hour: int, spot_count: int}>  $entries
     */
    private function publishCalendar(array $catalog, User $pm, array $entries): StandardOfferVersion
    {
        $offer = $this->writer()->create(
            'Calendar fixture',
            $this->calendarDraftPayload($catalog, $entries),
            $pm,
        );
        $draft = $offer->draftVersion;
        $this->assertNotNull($draft);

        return $this->writer()->publish($draft, (int) $draft->lock_version, $pm);
    }

    /**
     * @param  array{hamburg: Inventory, medium: AdvertisingMedium}  $catalog
     * @return array<string, mixed>
     */
    private function averageDraftPayload(
        array $catalog,
        string $settlementMode = 'normal',
        ?string $fixedPriceNn = null,
    ): array {
        return $this->withLiveSchemaFingerprint([
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
                'pricing_settlement_mode' => $settlementMode,
                'fixed_price_nn' => $fixedPriceNn,
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
    }

    /**
     * @param  array{hamburg: Inventory, medium: AdvertisingMedium}  $catalog
     * @param  list<array{date: string, hour: int, spot_count: int}>  $entries
     * @param  array<string, mixed>  $positionOverrides
     * @return array<string, mixed>
     */
    private function calendarDraftPayload(array $catalog, array $entries, array $positionOverrides = []): array
    {
        return $this->withLiveSchemaFingerprint([
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
