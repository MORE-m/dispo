<?php

namespace Tests\Feature\StandardOffer;

use App\Enums\ComponentCalculationStrategy;
use App\Enums\DiscountType;
use App\Enums\Role;
use App\Enums\StandardOfferVersionStatus;
use App\Models\Calculation;
use App\Models\CalculationPosition;
use App\Models\Inventory;
use App\Models\InventoryMediumRule;
use App\Models\PriceListItem;
use App\Models\StandardOfferVersion;
use App\Models\User;
use App\Services\Calculation\CalculationWriter;
use App\Services\DispoOrder\DispoOrderWriter;
use App\Services\StandardOffer\FrozenCalculationPersistenceContract;
use App\Services\StandardOffer\StandardOfferMaterializer;
use App\Services\StandardOffer\StandardOfferWriter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\Concerns\CreatesSpotClassicCatalog;
use Tests\TestCase;

/**
 * BL-P4-03d / VER-004 / STD-004 / STD-005 / STD-006 / SPT-014.
 *
 * Gemeinsamer versionierter Persistenzvertrag Freeze↔Hydrate; Parity Adopt.
 */
class StandardOfferBlP403dTest extends TestCase
{
    use CreatesSpotClassicCatalog;
    use RefreshDatabase;

    public function test_plain_and_multipart_average_parity_including_both_strategies(): void
    {
        $catalog = $this->createSpotClassicCatalog();
        $pm = User::factory()->role(Role::ProductManagement)->create();
        $sales = User::factory()->role(Role::Sales)->create();
        $this->setRuleStrategy($catalog, ComponentCalculationStrategy::SharedTotalLength);

        $plain = $this->publish($catalog, $pm, $this->richPlainPayload($catalog));
        $plainAdopted = $this->writer()->adopt($plain, 'Kunde Plain', 'Agentur X', 'Kampagne Adopt', $sales);
        $this->assertAdoptMatchesFrozen($plain, $plainAdopted, expectComponents: 0);
        $this->assertSame('Kunde Plain', $plainAdopted->customer_name);
        $this->assertSame('Agentur X', $plainAdopted->agency_name);
        $this->assertSame('Kampagne Adopt', $plainAdopted->campaign);
        $this->assertSame($sales->id, $plainAdopted->advisor_id);

        $shared = $this->publish(
            $catalog,
            $pm,
            $this->componentPayload($catalog, ComponentCalculationStrategy::SharedTotalLength, multiPosition: true),
        );
        $sharedAdopted = $this->writer()->adopt($shared, 'Kunde Shared', null, null, $sales);
        $this->assertAdoptMatchesFrozen($shared, $sharedAdopted, expectComponents: 2);
        $this->assertCount(2, $sharedAdopted->positions);
        $this->assertSame(
            [0, 1],
            $sharedAdopted->positions->sortBy('sort')->pluck('sort')->values()->all(),
        );
        $this->assertSame(
            'shared_total_length',
            $sharedAdopted->positions->first()->component_calculation_strategy,
        );

        $this->setRuleStrategy($catalog, ComponentCalculationStrategy::Individual);
        $individual = $this->publish(
            $catalog,
            $pm,
            $this->componentPayload($catalog, ComponentCalculationStrategy::Individual, multiPosition: false),
        );
        $individualAdopted = $this->writer()->adopt($individual, 'Kunde Individual', null, null, $sales);
        $this->assertAdoptMatchesFrozen($individual, $individualAdopted, expectComponents: 2);
        $this->assertSame(
            'individual',
            $individualAdopted->positions->first()->component_calculation_strategy,
        );

        $dispo = app(DispoOrderWriter::class)->createFromCalculation(
            $individualAdopted->fresh(['positions.components']),
            [(int) $individualAdopted->positions()->firstOrFail()->id],
            $sales,
        );
        $this->assertSame(
            'individual',
            $dispo->order->positions()->firstOrFail()->component_calculation_strategy,
        );
        $this->assertCount(2, $dispo->order->positions()->firstOrFail()->components_snapshot ?? []);
    }

    public function test_legacy_without_materialization_version_and_unknown_version_fail_closed(): void
    {
        $catalog = $this->createSpotClassicCatalog();
        $pm = User::factory()->role(Role::ProductManagement)->create();
        $sales = User::factory()->role(Role::Sales)->create();

        $legacy = $this->publish($catalog, $pm, $this->richPlainPayload($catalog));
        $materialization = $legacy->frozen_materialization;
        unset($materialization['materialization_version']);
        $legacy->frozen_materialization = $materialization;
        $legacy->save();

        $contract = app(FrozenCalculationPersistenceContract::class);
        $this->assertSame(
            FrozenCalculationPersistenceContract::LEGACY_IMPLICIT_VERSION,
            $contract->resolveVersion($legacy->fresh()->frozen_materialization),
        );
        $adopted = $this->writer()->adopt($legacy->fresh(), 'Legacy Kunde', null, null, $sales);
        $this->assertInstanceOf(Calculation::class, $adopted);
        $this->assertSame($legacy->id, $adopted->origin_standard_offer_version_id);

        $future = $this->publish($catalog, $pm, $this->richPlainPayload($catalog));
        $futureMat = $future->frozen_materialization;
        $futureMat['materialization_version'] = 99;
        $future->frozen_materialization = $futureMat;
        $future->save();

        $beforeCount = Calculation::query()->count();
        try {
            $this->writer()->adopt($future->fresh(), 'Future Kunde', null, null, $sales);
            $this->fail('Expected ValidationException for unknown materialization_version');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('frozen_materialization', $exception->errors());
            $this->assertStringContainsString('99', $exception->errors()['frozen_materialization'][0]);
        }
        $this->assertSame($beforeCount, Calculation::query()->count());
    }

    public function test_incomplete_frozen_data_rolls_back_without_partial_calculation(): void
    {
        $catalog = $this->createSpotClassicCatalog();
        $pm = User::factory()->role(Role::ProductManagement)->create();
        $sales = User::factory()->role(Role::Sales)->create();

        $published = $this->publish($catalog, $pm, $this->richPlainPayload($catalog));
        $broken = $published->frozen_materialization;
        $broken['positions'][0]['price_list_id'] = null;
        $published->frozen_materialization = $broken;
        $published->save();

        $beforeCalc = Calculation::query()->count();
        $beforePositions = CalculationPosition::query()->count();

        try {
            $this->writer()->adopt($published->fresh(), 'Broken Kunde', null, null, $sales);
            $this->fail('Expected ValidationException for incomplete frozen data');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('frozen_materialization', $exception->errors());
            $this->assertStringContainsString('unvollständig', $exception->errors()['frozen_materialization'][0]);
        }

        $this->assertSame($beforeCalc, Calculation::query()->count());
        $this->assertSame($beforePositions, CalculationPosition::query()->count());
    }

    public function test_manipulated_method_settlement_strategy_and_child_rows_fail_closed(): void
    {
        $catalog = $this->createSpotClassicCatalog();
        $pm = User::factory()->role(Role::ProductManagement)->create();
        $sales = User::factory()->role(Role::Sales)->create();
        $this->setRuleStrategy($catalog, ComponentCalculationStrategy::SharedTotalLength);

        $cases = [
            ['path' => 'spot_method', 'mutate' => fn (array &$m) => $m['positions'][0]['spot_method'] = 'calendar', 'needle' => 'spot_method'],
            ['path' => 'settlement', 'mutate' => fn (array &$m) => $m['positions'][0]['pricing_settlement_mode'] = 'fixed_price', 'needle' => 'pricing_settlement_mode'],
            ['path' => 'profile', 'mutate' => fn (array &$m) => $m['positions'][0]['component_profile'] = ['kind' => 'tandem'], 'needle' => 'component_profile'],
            ['path' => 'strategy', 'mutate' => fn (array &$m) => $m['positions'][0]['component_calculation_strategy'] = 'not_a_strategy', 'needle' => 'component_calculation_strategy'],
            ['path' => 'component_role', 'mutate' => function (array &$m): void {
                $m['positions'][0]['components'][0]['role'] = 'reminder';
            }, 'needle' => 'components.0.role'],
            ['path' => 'time_range', 'mutate' => function (array &$m): void {
                unset($m['positions'][0]['time_ranges'][0]['spot_count']);
            }, 'needle' => 'time_ranges.0.spot_count'],
            ['path' => 'plan_row', 'mutate' => function (array &$m): void {
                unset($m['positions'][0]['plan_rows'][0]['second_price']);
            }, 'needle' => 'plan_rows.0.second_price'],
            ['path' => 'discount_type', 'mutate' => function (array &$m): void {
                $m['positions'][0]['position_discounts'] = [[
                    'type' => 'not_a_discount',
                    'custom_label' => null,
                    'percent' => '1',
                ]];
            }, 'needle' => 'type'],
        ];

        foreach ($cases as $case) {
            $published = $this->publish(
                $catalog,
                $pm,
                $this->componentPayload($catalog, ComponentCalculationStrategy::SharedTotalLength),
            );
            $materialization = $published->frozen_materialization;
            ($case['mutate'])($materialization);
            $published->frozen_materialization = $materialization;
            $published->save();

            $beforeCalc = Calculation::query()->count();
            $beforePositions = CalculationPosition::query()->count();

            try {
                $this->writer()->adopt($published->fresh(), 'Manip '.$case['path'], null, null, $sales);
                $this->fail('Expected ValidationException for manipulated '.$case['path']);
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey('frozen_materialization', $exception->errors());
                $this->assertStringContainsString(
                    $case['needle'],
                    $exception->errors()['frozen_materialization'][0],
                );
            }

            $this->assertSame($beforeCalc, Calculation::query()->count(), $case['path']);
            $this->assertSame($beforePositions, CalculationPosition::query()->count(), $case['path']);
        }
    }

    public function test_child_list_presence_missing_null_empty_and_legacy_rollback(): void
    {
        $catalog = $this->createSpotClassicCatalog();
        $pm = User::factory()->role(Role::ProductManagement)->create();
        $sales = User::factory()->role(Role::Sales)->create();

        $failCases = [
            ['path' => 'time_ranges_missing', 'mutate' => function (array &$m): void {
                unset($m['positions'][0]['time_ranges']);
            }, 'needle' => 'time_ranges fehlt'],
            ['path' => 'plan_rows_missing', 'mutate' => function (array &$m): void {
                unset($m['positions'][0]['plan_rows']);
            }, 'needle' => 'plan_rows fehlt'],
            ['path' => 'time_ranges_null', 'mutate' => function (array &$m): void {
                $m['positions'][0]['time_ranges'] = null;
            }, 'needle' => 'time_ranges ist null'],
            ['path' => 'plan_rows_null', 'mutate' => function (array &$m): void {
                $m['positions'][0]['plan_rows'] = null;
            }, 'needle' => 'plan_rows ist null'],
            ['path' => 'components_null', 'mutate' => function (array &$m): void {
                $m['positions'][0]['components'] = null;
            }, 'needle' => 'components ist null'],
            ['path' => 'position_discounts_null', 'mutate' => function (array &$m): void {
                $m['positions'][0]['position_discounts'] = null;
            }, 'needle' => 'position_discounts ist null'],
            ['path' => 'order_discounts_null', 'mutate' => function (array &$m): void {
                $m['order_discounts'] = null;
            }, 'needle' => 'order_discounts ist null'],
        ];

        foreach ($failCases as $case) {
            $published = $this->publish($catalog, $pm, $this->richPlainPayload($catalog));
            $materialization = $published->frozen_materialization;
            ($case['mutate'])($materialization);
            $published->frozen_materialization = $materialization;
            $published->save();

            $beforeCalc = Calculation::query()->count();
            $beforePositions = CalculationPosition::query()->count();

            try {
                $this->writer()->adopt($published->fresh(), 'Child '.$case['path'], null, null, $sales);
                $this->fail('Expected ValidationException for '.$case['path']);
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey('frozen_materialization', $exception->errors());
                $this->assertStringContainsString(
                    $case['needle'],
                    $exception->errors()['frozen_materialization'][0],
                );
            }

            $this->assertSame($beforeCalc, Calculation::query()->count(), $case['path']);
            $this->assertSame($beforePositions, CalculationPosition::query()->count(), $case['path']);
        }

        // Gültiger Legacy-Stand: kein Versionsfeld; optionale Kindlisten fehlen
        // (components/position_discounts/order_discounts); Pflichtlisten bleiben
        // als echte 03a-Freeze-Arrays erhalten.
        $legacy = $this->publish($catalog, $pm, $this->richPlainPayload($catalog));
        $legacyMat = $legacy->frozen_materialization;
        $expectedRanges = count($legacyMat['positions'][0]['time_ranges']);
        $expectedPlanRows = count($legacyMat['positions'][0]['plan_rows']);
        unset($legacyMat['materialization_version']);
        unset($legacyMat['positions'][0]['components']);
        unset($legacyMat['positions'][0]['position_discounts']);
        unset($legacyMat['order_discounts']);
        $legacy->frozen_materialization = $legacyMat;
        $legacy->save();

        $adopted = $this->writer()->adopt($legacy->fresh(), 'Legacy Child Listen', null, null, $sales);
        $adopted->load(['positions.timeRanges', 'positions.planRows', 'positions.components', 'positions.discounts', 'orderDiscounts']);
        $this->assertCount($expectedRanges, $adopted->positions->first()->timeRanges);
        $this->assertCount($expectedPlanRows, $adopted->positions->first()->planRows);
        $this->assertCount(0, $adopted->positions->first()->components);
        $this->assertCount(0, $adopted->positions->first()->discounts);
        $this->assertCount(0, $adopted->orderDiscounts);

        // Gültige leere Pflichtlisten (bewusst [] ≠ fehlend) bleiben übernehmbar.
        $emptyLists = $this->publish($catalog, $pm, $this->richPlainPayload($catalog));
        $emptyMat = $emptyLists->frozen_materialization;
        $emptyMat['positions'][0]['time_ranges'] = [];
        $emptyMat['positions'][0]['plan_rows'] = [];
        $emptyMat['positions'][0]['components'] = [];
        $emptyMat['positions'][0]['position_discounts'] = [];
        $emptyMat['order_discounts'] = [];
        $emptyLists->frozen_materialization = $emptyMat;
        $emptyLists->save();

        $emptyAdopted = $this->writer()->adopt($emptyLists->fresh(), 'Empty Child Listen', null, null, $sales);
        $emptyAdopted->load(['positions.timeRanges', 'positions.planRows', 'positions.components', 'positions.discounts', 'orderDiscounts']);
        $this->assertCount(0, $emptyAdopted->positions->first()->timeRanges);
        $this->assertCount(0, $emptyAdopted->positions->first()->planRows);
        $this->assertCount(0, $emptyAdopted->positions->first()->components);
        $this->assertCount(0, $emptyAdopted->positions->first()->discounts);
        $this->assertCount(0, $emptyAdopted->orderDiscounts);
    }

    public function test_adopt_isolation_after_price_master_and_template_changes_and_remains_editable(): void
    {
        $catalog = $this->createSpotClassicCatalog();
        $pm = User::factory()->role(Role::ProductManagement)->create();
        $sales = User::factory()->role(Role::Sales)->create();
        $this->setRuleStrategy($catalog, ComponentCalculationStrategy::SharedTotalLength);

        $published = $this->publish(
            $catalog,
            $pm,
            $this->componentPayload($catalog, ComponentCalculationStrategy::SharedTotalLength),
        );
        $nnFrozen = (string) ($published->frozen_materialization['nn_invest'] ?? '');
        $secondPrice = (string) ($published->frozen_materialization['positions'][0]['plan_rows'][0]['second_price'] ?? '');
        $this->assertNotSame('', $secondPrice);

        $calculation = $this->writer()->adopt($published, 'Isolation Kunde', null, null, $sales);
        $this->assertSame($nnFrozen, (string) $calculation->nn_invest);

        $listId = (int) $calculation->positions()->firstOrFail()->price_list_id;
        PriceListItem::query()->where('price_list_id', $listId)->update(['second_price' => '9.9999']);
        $catalog['hamburg']->update(['name' => 'UMBENANNT-03d']);

        $draft = $this->writer()->createDraftFromPublished($published->offer, $pm);
        $payload = $draft->draft_payload;
        $payload['positions'][0]['total_spot_count'] = 99;
        $payload['positions'][0]['time_ranges'][0]['spot_count'] = 99;
        $this->writer()->updateDraft($draft, $draft->title, $payload, (int) $draft->lock_version, $pm);
        $this->writer()->publish($draft->fresh(), (int) $draft->fresh()->lock_version, $pm);

        $calculation->refresh()->load(['positions.planRows', 'positions.components']);
        $this->assertSame($nnFrozen, (string) $calculation->nn_invest);
        $this->assertSame(10, (int) $calculation->positions->first()->total_spot_count);
        $this->assertSame($secondPrice, (string) $calculation->positions->first()->planRows->first()->second_price);
        $this->assertSame('Radio Hamburg', $calculation->positions->first()->inventory_name);
        $this->assertCount(2, $calculation->positions->first()->components);

        $calcWriter = app(CalculationWriter::class);
        $editPayload = $calcWriter->payloadFromCalculation($calculation->fresh([
            'positions.planRows',
            'positions.timeRanges',
            'positions.components',
            'positions.discounts',
            'orderDiscounts',
            'configurationSnapshot',
            'fieldValues',
        ]));
        $editPayload['lock_version'] = $calculation->lock_version;
        $editPayload['positions'][0]['total_spot_count'] = 20;
        $editPayload['positions'][0]['time_ranges'][0]['spot_count'] = 20;
        $updated = $calcWriter->update($calculation->fresh(), $editPayload, $sales);

        $this->assertSame(20, (int) $updated->positions()->firstOrFail()->total_spot_count);
        $this->assertNotSame($nnFrozen, (string) $updated->nn_invest);
        $this->assertSame($published->id, $updated->origin_standard_offer_version_id);
        $this->assertCount(2, $updated->positions()->firstOrFail()->components);
        // Neu-Publish archiviert die Herkunftsversion; Frozen-Stand bleibt erhalten.
        $originAfter = StandardOfferVersion::query()->whereKey($published->id)->firstOrFail();
        $this->assertSame(StandardOfferVersionStatus::Archived, $originAfter->status);
        $this->assertSame($nnFrozen, (string) ($originAfter->frozen_materialization['nn_invest'] ?? ''));
    }

    public function test_materializer_writes_supported_contract_version(): void
    {
        $catalog = $this->createSpotClassicCatalog();
        $pm = User::factory()->role(Role::ProductManagement)->create();
        $published = $this->publish($catalog, $pm, $this->richPlainPayload($catalog));

        $this->assertSame(
            StandardOfferMaterializer::MATERIALIZATION_VERSION,
            (int) ($published->frozen_materialization['materialization_version'] ?? 0),
        );
        $this->assertContains(
            (int) $published->frozen_materialization['materialization_version'],
            FrozenCalculationPersistenceContract::SUPPORTED_VERSIONS,
        );
    }

    /**
     * @param  array{hamburg: Inventory, rock: Inventory, medium: mixed}  $catalog
     * @return array<string, mixed>
     */
    private function richPlainPayload(array $catalog): array
    {
        return $this->withLiveSchemaFingerprint([
            'planning_mode' => 'manual',
            'campaign' => 'Vorlagenkampagne',
            'product_title' => 'Produkt',
            'briefing' => 'Briefing ohne Kunde',
            'order_discount_percent' => '5',
            'ae_enabled' => true,
            'order_discounts' => [[
                'type' => DiscountType::Quantity->value,
                'custom_label' => null,
                'percent' => '5',
            ]],
            'dynamic_field_values' => [
                'campaign_period' => null,
            ],
            'positions' => [[
                'inventory_id' => $catalog['hamburg']->id,
                'advertising_medium_id' => $catalog['medium']->id,
                'spot_method' => 'average',
                'length_seconds' => 30,
                'total_spot_count' => 10,
                'position_discount_percent' => '2',
                'ae_percent' => '15',
                'pricing_settlement_mode' => 'normal',
                'components' => [],
                'component_calculation_strategy' => null,
                'plan_rows' => [['hour' => 8, 'day_group' => 'mo_fr']],
                'time_ranges' => [[
                    'start_hour' => 8,
                    'end_hour_exclusive' => 9,
                    'day_group' => 'mo_fr',
                    'spot_count' => 10,
                ]],
                'position_discounts' => [[
                    'type' => DiscountType::Special->value,
                    'label' => '',
                    'percent' => '2',
                ]],
                'dynamic_field_values' => [
                    'period_open' => true,
                    'position_flight_period' => null,
                ],
            ]],
        ]);
    }

    /**
     * @param  array{hamburg: Inventory, rock: Inventory, medium: mixed}  $catalog
     * @return array<string, mixed>
     */
    private function componentPayload(
        array $catalog,
        ComponentCalculationStrategy $strategy,
        bool $multiPosition = false,
    ): array {
        $makePosition = function (Inventory $inventory, string $clientKey) use ($catalog, $strategy): array {
            return [
                'client_key' => $clientKey,
                'inventory_id' => $inventory->id,
                'advertising_medium_id' => $catalog['medium']->id,
                'spot_method' => 'average',
                'length_seconds' => 30,
                'total_spot_count' => 10,
                'position_discount_percent' => '0',
                'ae_percent' => '0',
                'pricing_settlement_mode' => 'normal',
                'component_calculation_strategy' => $strategy->value,
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
                'dynamic_field_values' => [
                    'period_open' => true,
                    'position_flight_period' => null,
                ],
            ];
        };

        $positions = [$makePosition($catalog['hamburg'], 'p1')];
        if ($multiPosition) {
            $positions[] = $makePosition($catalog['rock'], 'p2');
        }

        return $this->withLiveSchemaFingerprint([
            'planning_mode' => 'manual',
            'campaign' => 'Komponenten',
            'order_discount_percent' => '0',
            'ae_enabled' => false,
            'order_discounts' => [],
            'dynamic_field_values' => [
                'campaign_period' => null,
            ],
            'positions' => $positions,
        ]);
    }

    /**
     * @param  array{hamburg: Inventory, medium: mixed}  $catalog
     * @param  array<string, mixed>  $payload
     */
    private function publish(array $catalog, User $pm, array $payload): StandardOfferVersion
    {
        unset($catalog);
        $offer = $this->writer()->create('03d Vorlage', $payload, $pm);
        $draft = $offer->draftVersion;
        $this->assertNotNull($draft);
        $published = $this->writer()->publish($draft, (int) $draft->lock_version, $pm);
        $this->assertSame(StandardOfferVersionStatus::Published, $published->status);

        return $published;
    }

    private function assertAdoptMatchesFrozen(
        StandardOfferVersion $published,
        Calculation $adopted,
        int $expectComponents,
    ): void {
        $frozen = $published->frozen_materialization;
        $this->assertIsArray($frozen);
        $this->assertSame((string) ($frozen['nn_invest'] ?? ''), (string) $adopted->nn_invest);
        $this->assertSame((string) ($frozen['media_gross'] ?? ''), (string) $adopted->media_gross);
        $this->assertSame((string) ($frozen['ae_total'] ?? ''), (string) $adopted->ae_total);
        $this->assertSame(
            (bool) ($frozen['ae_enabled'] ?? false),
            (bool) $adopted->ae_enabled,
        );
        $this->assertSame($published->id, $adopted->origin_standard_offer_version_id);
        $this->assertNotSame(
            (int) ($frozen['configuration_snapshot_id'] ?? 0),
            (int) $adopted->configuration_snapshot_id,
        );

        $adopted->load([
            'positions.components',
            'positions.timeRanges',
            'positions.planRows',
            'positions.discounts',
            'orderDiscounts',
        ]);
        $this->assertCount(count($frozen['positions'] ?? []), $adopted->positions);
        $this->assertCount(count($frozen['order_discounts'] ?? []), $adopted->orderDiscounts);

        foreach ($adopted->positions->sortBy('sort')->values() as $index => $position) {
            $frozenPosition = $frozen['positions'][$index];
            $this->assertSame((int) $frozenPosition['inventory_id'], (int) $position->inventory_id);
            $this->assertSame((int) $frozenPosition['price_list_id'], (int) $position->price_list_id);
            $this->assertSame((int) $frozenPosition['total_spot_count'], (int) $position->total_spot_count);
            $this->assertSame((int) $frozenPosition['length_seconds'], (int) $position->length_seconds);
            $this->assertSame(
                (float) ($frozenPosition['ae_percent'] ?? 0),
                (float) $position->ae_percent,
            );
            $this->assertSame(
                (string) ($frozenPosition['nn_invest'] ?? '0'),
                (string) $position->nn_invest,
            );
            $this->assertCount($expectComponents, $position->components);
            $this->assertCount(count($frozenPosition['time_ranges'] ?? []), $position->timeRanges);
            $this->assertCount(count($frozenPosition['plan_rows'] ?? []), $position->planRows);
            $this->assertCount(count($frozenPosition['position_discounts'] ?? []), $position->discounts);

            if ($expectComponents > 0) {
                $roles = $position->components->sortBy('sort')->map(
                    fn ($component): string => is_string($component->role)
                        ? $component->role
                        : (string) $component->role->value,
                )->values()->all();
                $this->assertSame(['main_spot', 'allonge'], $roles);
            }

            $this->assertSame(
                (string) ($frozenPosition['plan_rows'][0]['second_price'] ?? ''),
                (string) $position->planRows->first()->second_price,
            );
        }
    }

    /**
     * @param  array{hamburg: Inventory, medium: mixed}  $catalog
     */
    private function setRuleStrategy(array $catalog, ComponentCalculationStrategy $strategy): void
    {
        InventoryMediumRule::query()
            ->whereIn('inventory_id', [$catalog['hamburg']->id, $catalog['rock']->id])
            ->where('advertising_medium_id', $catalog['medium']->id)
            ->update(['component_calculation_strategy' => $strategy->value]);
    }

    private function writer(): StandardOfferWriter
    {
        return app(StandardOfferWriter::class);
    }
}
