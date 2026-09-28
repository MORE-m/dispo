<?php

namespace Tests\Feature\StandardOffer;

use App\Enums\ComponentCalculationStrategy;
use App\Enums\PriceListStatus;
use App\Enums\Role;
use App\Enums\StandardOfferVersionStatus;
use App\Models\Calculation;
use App\Models\CalculationPosition;
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
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\Concerns\CreatesSpotClassicCatalog;
use Tests\TestCase;

/**
 * BL-P4-03e / STD-001–STD-009 / AUTH-006/007 / VER-004 / SPT-014 / COM-009.
 *
 * N/N-Festpreis in Spot-Classic-Average-Standardangeboten (Calc-Logik 02d).
 */
class StandardOfferBlP403eTest extends TestCase
{
    use CreatesSpotClassicCatalog;
    use RefreshDatabase;

    public function test_normal_and_fixed_price_side_by_side_draft_publish_adopt_parity(): void
    {
        $catalog = $this->createSpotClassicCatalog();
        $pm = User::factory()->role(Role::ProductManagement)->create();
        $sales = User::factory()->role(Role::Sales)->create();
        $this->setSecondPrice($catalog, '2.0000');

        $payload = $this->multiPositionMixedSettlementPayload($catalog);
        $offer = $this->writer()->create('03e Normal+Festpreis', $payload, $pm);
        $draft = $offer->draftVersion;
        $this->assertNotNull($draft);
        $this->assertSame('normal', $draft->draft_payload['positions'][0]['pricing_settlement_mode'] ?? null);
        $this->assertSame('fixed_price', $draft->draft_payload['positions'][1]['pricing_settlement_mode'] ?? null);
        $this->assertSame('250.00', $draft->draft_payload['positions'][1]['fixed_price_nn'] ?? null);

        $published = $this->writer()->publish($draft, (int) $draft->lock_version, $pm);
        $this->assertSame(StandardOfferVersionStatus::Published, $published->status);
        $this->assertSame(
            FrozenCalculationPersistenceContract::CURRENT_WRITE_VERSION,
            (int) ($published->frozen_materialization['materialization_version'] ?? 0),
        );

        $frozenPositions = $published->frozen_materialization['positions'] ?? [];
        $this->assertSame('normal', $frozenPositions[0]['pricing_settlement_mode'] ?? null);
        $this->assertNull($frozenPositions[0]['fixed_price_nn'] ?? null);
        $this->assertSame('fixed_price', $frozenPositions[1]['pricing_settlement_mode'] ?? null);
        $this->assertSame('250.00', (string) ($frozenPositions[1]['fixed_price_nn'] ?? ''));
        $this->assertSame('250.00', (string) ($frozenPositions[1]['nn_invest'] ?? ''));

        $adopted = $this->writer()->adopt($published, 'Kunde 03e', 'Agentur Y', 'Kampagne 03e', $sales);
        $adopted->load('positions');
        $this->assertCount(2, $adopted->positions);
        $sorted = $adopted->positions->sortBy('sort')->values();
        $this->assertSame('normal', $sorted[0]->pricing_settlement_mode?->value ?? $sorted[0]->pricing_settlement_mode);
        $this->assertNull($sorted[0]->fixed_price_nn);
        $this->assertSame('fixed_price', $sorted[1]->pricing_settlement_mode?->value ?? $sorted[1]->pricing_settlement_mode);
        $this->assertSame('250.00', (string) $sorted[1]->fixed_price_nn);
        $this->assertSame('250.00', (string) $sorted[1]->nn_invest);
        $this->assertSame(
            (string) ($published->frozen_materialization['nn_invest'] ?? ''),
            (string) $adopted->nn_invest,
        );
    }

    public function test_fixed_price_with_components_ae_and_special_approval_uses_calc_engine(): void
    {
        $catalog = $this->createSpotClassicCatalog();
        $pm = User::factory()->role(Role::ProductManagement)->create([
            'discount_limit_percent' => '5.00',
        ]);
        $sales = User::factory()->role(Role::Sales)->create();
        $this->setRuleStrategy($catalog, ComponentCalculationStrategy::SharedTotalLength);
        $this->setSecondPrice($catalog, '2.0000');

        $payload = $this->withLiveSchemaFingerprint([
            'planning_mode' => 'manual',
            'campaign' => 'Festpreis Komponenten',
            'order_discount_percent' => '0',
            'ae_enabled' => true,
            'order_discounts' => [],
            'dynamic_field_values' => ['campaign_period' => null],
            'positions' => [[
                'client_key' => 'fp1',
                'inventory_id' => $catalog['hamburg']->id,
                'advertising_medium_id' => $catalog['medium']->id,
                'spot_method' => 'average',
                'length_seconds' => 30,
                'total_spot_count' => 10,
                'position_discount_percent' => '0',
                'ae_percent' => '15',
                'pricing_settlement_mode' => 'fixed_price',
                'fixed_price_nn' => '400.00',
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
                'position_discounts' => [
                    ['type' => 'special', 'percent' => '20', 'custom_label' => null],
                ],
                'dynamic_field_values' => [
                    'period_open' => true,
                    'position_flight_period' => null,
                ],
            ]],
        ]);

        $totals = app(CalculationWriter::class)->totalsFromPayload($payload, $pm);
        $this->assertTrue($totals->requiresSpecialApproval);
        $this->assertSame('400.00', (string) $totals->nnInvest);
        $this->assertSame('400.00', (string) $totals->positions[0]->nnInvest);
        $this->assertSame('fixed_price', $totals->positions[0]->pricingSettlementMode->value);
        $this->assertNotNull($totals->positions[0]->effectivePayFactorPercent);

        $offer = $this->writer()->create('03e FP Komponenten', $payload, $pm);
        $draft = $offer->draftVersion;
        $this->assertNotNull($draft);
        $published = $this->writer()->publish($draft, (int) $draft->lock_version, $pm);

        $this->assertTrue((bool) ($published->frozen_materialization['requires_special_approval'] ?? false));
        $this->assertSame('400.00', (string) ($published->frozen_materialization['nn_invest'] ?? ''));
        $this->assertSame(
            'fixed_price',
            $published->frozen_materialization['positions'][0]['pricing_settlement_mode'] ?? null,
        );
        $this->assertSame(
            '400.00',
            (string) ($published->frozen_materialization['positions'][0]['fixed_price_nn'] ?? ''),
        );
        $this->assertCount(2, $published->frozen_materialization['positions'][0]['components'] ?? []);

        $adopted = $this->writer()->adopt($published, 'Kunde FP', null, null, $sales);
        $position = $adopted->fresh(['positions.components'])->positions->first();
        $this->assertSame('fixed_price', $position->pricing_settlement_mode?->value ?? $position->pricing_settlement_mode);
        $this->assertSame('400.00', (string) $position->fixed_price_nn);
        $this->assertSame('400.00', (string) $position->nn_invest);
        $this->assertCount(2, $position->components);
        $this->assertTrue((bool) $adopted->requires_special_approval);
    }

    public function test_from_calculation_preserves_fixed_price_without_customer_leak(): void
    {
        $catalog = $this->createSpotClassicCatalog();
        $sales = User::factory()->role(Role::Sales)->create();
        $this->setSecondPrice($catalog, '2.0000');

        $calculation = $this->createFixedPriceCalculation($catalog, $sales, [
            'customer_name' => 'Geheimkunde Festpreis',
            'agency_name' => 'Agentur Geheim',
            'campaign' => 'Kundenkampagne FP',
            'briefing' => 'Briefing mit Kundennamen',
        ]);

        $this->actingAs($sales)
            ->post(route('standard-offers.from-calculation', $calculation))
            ->assertRedirect(route('calculations.edit', $calculation));

        $offer = StandardOffer::query()->firstOrFail();
        $draft = $offer->draftVersion;
        $this->assertNotNull($draft);
        $this->assertArrayNotHasKey('customer_name', $draft->draft_payload);
        $this->assertArrayNotHasKey('agency_name', $draft->draft_payload);
        $this->assertNull($draft->draft_payload['campaign'] ?? null);
        $this->assertSame('fixed_price', $draft->draft_payload['positions'][0]['pricing_settlement_mode'] ?? null);
        $this->assertSame('300.00', $draft->draft_payload['positions'][0]['fixed_price_nn'] ?? null);
    }

    public function test_snapshot_isolation_after_price_list_change_keeps_frozen_fixed_price(): void
    {
        $catalog = $this->createSpotClassicCatalog();
        $pm = User::factory()->role(Role::ProductManagement)->create();
        $sales = User::factory()->role(Role::Sales)->create();
        $this->setSecondPrice($catalog, '2.0000');

        $payload = $this->fixedPricePayload($catalog, '180.00');
        $offer = $this->writer()->create('Isolation FP', $payload, $pm);
        $draft = $offer->draftVersion;
        $this->assertNotNull($draft);
        $published = $this->writer()->publish($draft, (int) $draft->lock_version, $pm);
        $nnFrozen = (string) ($published->frozen_materialization['nn_invest'] ?? '');
        $fpFrozen = (string) ($published->frozen_materialization['positions'][0]['fixed_price_nn'] ?? '');

        $adopted = $this->writer()->adopt($published, 'Isolation Kunde', null, null, $sales);
        $this->setSecondPrice($catalog, '9.0000');

        $publishedAfter = $published->fresh();
        $adoptedAfter = $adopted->fresh(['positions']);
        $this->assertSame($nnFrozen, (string) ($publishedAfter->frozen_materialization['nn_invest'] ?? ''));
        $this->assertSame($fpFrozen, (string) ($publishedAfter->frozen_materialization['positions'][0]['fixed_price_nn'] ?? ''));
        $this->assertSame($nnFrozen, (string) $adoptedAfter->nn_invest);
        $this->assertSame('fixed_price', $adoptedAfter->positions->first()->pricing_settlement_mode?->value
            ?? $adoptedAfter->positions->first()->pricing_settlement_mode);
        $this->assertSame('180.00', (string) $adoptedAfter->positions->first()->fixed_price_nn);
    }

    public function test_legacy_v1_average_remains_adoptable_and_v1_fixed_price_fails(): void
    {
        $catalog = $this->createSpotClassicCatalog();
        $pm = User::factory()->role(Role::ProductManagement)->create();
        $sales = User::factory()->role(Role::Sales)->create();

        $published = $this->publish($catalog, $pm, $this->fixedPricePayload($catalog, '120.00'));
        $materialization = $published->frozen_materialization;
        unset($materialization['materialization_version']);
        unset($materialization['positions'][0]['pricing_settlement_mode']);
        $materialization['positions'][0]['fixed_price_nn'] = null;
        // Legacy-v1 ohne Settlement-Feld; Summen bleiben aus Freeze.
        $published->frozen_materialization = $materialization;
        $published->save();

        $adopted = $this->writer()->adopt($published->fresh(), 'Legacy Kunde', null, null, $sales);
        $this->assertInstanceOf(Calculation::class, $adopted);

        $v1Fixed = $this->publish($catalog, $pm, $this->fixedPricePayload($catalog, '120.00'));
        $bad = $v1Fixed->frozen_materialization;
        $bad['materialization_version'] = 1;
        $v1Fixed->frozen_materialization = $bad;
        $v1Fixed->save();

        $before = Calculation::query()->count();
        $beforePositions = CalculationPosition::query()->count();
        try {
            $this->writer()->adopt($v1Fixed->fresh(), 'Fail', null, null, $sales);
            $this->fail('v1 mit Festpreis hätte scheitern müssen.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('frozen_materialization', $exception->errors());
            $this->assertStringContainsString('pricing_settlement_mode', $exception->errors()['frozen_materialization'][0]);
        }
        $this->assertSame($before, Calculation::query()->count());
        $this->assertSame($beforePositions, CalculationPosition::query()->count());
    }

    public function test_v2_incomplete_or_invalid_settlement_fails_closed_without_partial_create(): void
    {
        $catalog = $this->createSpotClassicCatalog();
        $pm = User::factory()->role(Role::ProductManagement)->create();
        $sales = User::factory()->role(Role::Sales)->create();

        $cases = [
            [
                'path' => 'missing_mode',
                'mutate' => static function (array &$m): void {
                    unset($m['positions'][0]['pricing_settlement_mode']);
                },
                'needle' => 'pricing_settlement_mode',
            ],
            [
                'path' => 'empty_mode',
                'mutate' => static function (array &$m): void {
                    $m['positions'][0]['pricing_settlement_mode'] = '';
                },
                'needle' => 'pricing_settlement_mode',
            ],
            [
                'path' => 'exponential_nn',
                'mutate' => static function (array &$m): void {
                    $m['positions'][0]['pricing_settlement_mode'] = 'fixed_price';
                    $m['positions'][0]['fixed_price_nn'] = '1e2';
                },
                'needle' => 'fixed_price_nn',
            ],
            [
                'path' => 'too_many_decimals',
                'mutate' => static function (array &$m): void {
                    $m['positions'][0]['pricing_settlement_mode'] = 'fixed_price';
                    $m['positions'][0]['fixed_price_nn'] = '12.345';
                },
                'needle' => 'fixed_price_nn',
            ],
            [
                'path' => 'non_scalar_nn',
                'mutate' => static function (array &$m): void {
                    $m['positions'][0]['pricing_settlement_mode'] = 'fixed_price';
                    $m['positions'][0]['fixed_price_nn'] = ['12.00'];
                },
                'needle' => 'fixed_price_nn',
            ],
            [
                'path' => 'float_nn',
                'mutate' => static function (array &$m): void {
                    $m['positions'][0]['pricing_settlement_mode'] = 'fixed_price';
                    $m['positions'][0]['fixed_price_nn'] = 12.5;
                },
                'needle' => 'fixed_price_nn',
            ],
        ];

        foreach ($cases as $case) {
            $published = $this->publish($catalog, $pm, $this->fixedPricePayload($catalog, '120.00'));
            $materialization = $published->frozen_materialization;
            ($case['mutate'])($materialization);
            $published->frozen_materialization = $materialization;
            $published->save();

            $beforeCalc = Calculation::query()->count();
            $beforePositions = CalculationPosition::query()->count();

            try {
                $this->writer()->adopt($published->fresh(), 'Fail '.$case['path'], null, null, $sales);
                $this->fail('Expected ValidationException for '.$case['path']);
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey('frozen_materialization', $exception->errors(), $case['path']);
                $this->assertStringContainsString(
                    $case['needle'],
                    $exception->errors()['frozen_materialization'][0],
                    $case['path'],
                );
            }

            $this->assertSame($beforeCalc, Calculation::query()->count(), $case['path']);
            $this->assertSame($beforePositions, CalculationPosition::query()->count(), $case['path']);
        }
    }

    public function test_contradictory_settlement_payload_is_rejected_without_coercion(): void
    {
        $catalog = $this->createSpotClassicCatalog();
        $pm = User::factory()->role(Role::ProductManagement)->create();
        $base = $this->fixedPricePayload($catalog, '100.00');

        $normalWithNn = $base;
        $normalWithNn['positions'][0]['pricing_settlement_mode'] = 'normal';
        $normalWithNn['positions'][0]['fixed_price_nn'] = '100.00';
        try {
            $this->writer()->create('Widerspruch', $normalWithNn, $pm);
            $this->fail('normal + fixed_price_nn hätte abgelehnt werden müssen.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('positions.0.fixed_price_nn', $exception->errors());
        }

        $fixedWithoutNn = $base;
        $fixedWithoutNn['positions'][0]['fixed_price_nn'] = null;
        try {
            $this->writer()->create('Ohne NN', $fixedWithoutNn, $pm);
            $this->fail('Festpreis ohne NN hätte abgelehnt werden müssen.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('positions.0.fixed_price_nn', $exception->errors());
        }

        $this->assertSame(0, StandardOffer::query()->count());
    }

    public function test_draft_roundtrip_keeps_fixed_price_and_writes_version_two(): void
    {
        $catalog = $this->createSpotClassicCatalog();
        $pm = User::factory()->role(Role::ProductManagement)->create();
        $this->setSecondPrice($catalog, '2.0000');

        $offer = $this->writer()->create('Roundtrip', $this->fixedPricePayload($catalog, '210.00'), $pm);
        $draft = $offer->draftVersion;
        $this->assertNotNull($draft);

        $updatedPayload = $draft->draft_payload;
        $updatedPayload['positions'][0]['fixed_price_nn'] = '215.00';
        $this->writer()->updateDraft($draft, 'Roundtrip edit', $updatedPayload, (int) $draft->lock_version, $pm);

        $fresh = $draft->fresh();
        $this->assertNotNull($fresh);
        $this->assertSame('fixed_price', $fresh->draft_payload['positions'][0]['pricing_settlement_mode'] ?? null);
        $this->assertSame('215.00', $fresh->draft_payload['positions'][0]['fixed_price_nn'] ?? null);

        $published = $this->writer()->publish($fresh, (int) $fresh->lock_version, $pm);
        $this->assertSame(
            StandardOfferMaterializer::MATERIALIZATION_VERSION,
            (int) ($published->frozen_materialization['materialization_version'] ?? 0),
        );
        $this->assertSame(2, StandardOfferMaterializer::MATERIALIZATION_VERSION);
        $this->assertSame('215.00', (string) ($published->frozen_materialization['positions'][0]['fixed_price_nn'] ?? ''));
        $this->assertSame('215.00', (string) ($published->frozen_materialization['positions'][0]['nn_invest'] ?? ''));
    }

    /**
     * @param  array{hamburg: Inventory, rock: Inventory, medium: mixed}  $catalog
     * @return array<string, mixed>
     */
    private function multiPositionMixedSettlementPayload(array $catalog): array
    {
        return $this->withLiveSchemaFingerprint([
            'planning_mode' => 'manual',
            'campaign' => 'Mixed',
            'order_discount_percent' => '0',
            'ae_enabled' => false,
            'order_discounts' => [],
            'dynamic_field_values' => ['campaign_period' => null],
            'positions' => [
                [
                    'client_key' => 'n1',
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
                ],
                [
                    'client_key' => 'f1',
                    'inventory_id' => $catalog['rock']->id,
                    'advertising_medium_id' => $catalog['medium']->id,
                    'spot_method' => 'average',
                    'length_seconds' => 30,
                    'total_spot_count' => 10,
                    'position_discount_percent' => '0',
                    'ae_percent' => '0',
                    'pricing_settlement_mode' => 'fixed_price',
                    'fixed_price_nn' => '250.00',
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
                ],
            ],
        ]);
    }

    /**
     * @param  array{hamburg: Inventory, medium: mixed}  $catalog
     * @return array<string, mixed>
     */
    private function fixedPricePayload(array $catalog, string $nn): array
    {
        return $this->withLiveSchemaFingerprint([
            'planning_mode' => 'manual',
            'order_discount_percent' => '0',
            'ae_enabled' => false,
            'order_discounts' => [],
            'dynamic_field_values' => ['campaign_period' => null],
            'positions' => [[
                'client_key' => 'fp',
                'inventory_id' => $catalog['hamburg']->id,
                'advertising_medium_id' => $catalog['medium']->id,
                'spot_method' => 'average',
                'length_seconds' => 30,
                'total_spot_count' => 10,
                'position_discount_percent' => '0',
                'ae_percent' => '0',
                'pricing_settlement_mode' => 'fixed_price',
                'fixed_price_nn' => $nn,
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
     * @param  array{hamburg: Inventory, medium: mixed}  $catalog
     * @param  array<string, mixed>  $overrides
     */
    private function createFixedPriceCalculation(array $catalog, User $user, array $overrides = []): Calculation
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
                'pricing_settlement_mode' => 'fixed_price',
                'fixed_price_nn' => '300.00',
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
     * @param  array<string, mixed>  $payload
     */
    private function publish(array $catalog, User $pm, array $payload): StandardOfferVersion
    {
        unset($catalog);
        $offer = $this->writer()->create('03e Publish', $payload, $pm);
        $draft = $offer->draftVersion;
        $this->assertNotNull($draft);

        return $this->writer()->publish($draft, (int) $draft->lock_version, $pm);
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
     * @param  array{hamburg: Inventory, medium: mixed}  $catalog
     */
    private function setSecondPrice(array $catalog, string $price): void
    {
        $list = PriceList::query()
            ->where('inventory_id', $catalog['hamburg']->id)
            ->where('status', PriceListStatus::Active)
            ->firstOrFail();
        PriceListItem::query()->where('price_list_id', $list->id)->update(['second_price' => $price]);

        if (isset($catalog['rock'])) {
            $rockList = PriceList::query()
                ->where('inventory_id', $catalog['rock']->id)
                ->where('status', PriceListStatus::Active)
                ->firstOrFail();
            PriceListItem::query()->where('price_list_id', $rockList->id)->update(['second_price' => $price]);
        }
    }

    private function writer(): StandardOfferWriter
    {
        return app(StandardOfferWriter::class);
    }
}
