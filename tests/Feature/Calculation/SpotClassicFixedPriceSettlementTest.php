<?php

namespace Tests\Feature\Calculation;

use App\Enums\DayGroup;
use App\Enums\PlanningMode;
use App\Enums\PriceListStatus;
use App\Enums\Role;
use App\Models\PriceList;
use App\Models\PriceListItem;
use App\Models\User;
use App\Services\Calculation\CalculationWriter;
use App\Services\DispoOrder\DispoOrderSnapshotMapper;
use App\Services\DispoOrder\DispoOrderWriter;
use App\Services\DynamicField\ConfigurationSnapshotFreezeService;
use App\Support\PriceList\PriceListCalendar;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\Concerns\CreatesSavedCalculation;
use Tests\Concerns\CreatesSpotClassicCatalog;
use Tests\TestCase;

/**
 * BL-P4-02d: Preisabschluss normal|fixed_price.
 */
class SpotClassicFixedPriceSettlementTest extends TestCase
{
    use CreatesSavedCalculation;
    use CreatesSpotClassicCatalog;
    use RefreshDatabase;

    public function test_fixed_price_preview_matches_store_and_roundtrip(): void
    {
        $catalog = $this->createSpotClassicCatalog();
        $user = User::factory()->role(Role::Sales)->create();
        $payload = $this->fixedAveragePayload($catalog, '250.00');

        $writer = app(CalculationWriter::class);
        $preview = $writer->preview($payload, $user)->toArray();
        $calc = $writer->create($payload, $user);

        $this->assertSame('300.00', $preview['media_gross']);
        $this->assertSame('250.00', $preview['nn_invest']);
        $this->assertSame('0.00', $preview['ae_total']);
        $this->assertSame('fixed_price', $preview['positions'][0]['pricing_settlement_mode']);
        $this->assertSame('250.00', $preview['positions'][0]['fixed_price_nn']);
        $this->assertSame('0.00', $preview['positions'][0]['position_discount_amount']);
        $this->assertSame('0.00', $preview['positions'][0]['ae_amount']);
        $this->assertSame('83.3333', $preview['positions'][0]['effective_pay_factor_percent']);

        $position = $calc->positions->firstOrFail();
        $this->assertSame('250.00', (string) $position->nn_invest);
        $this->assertSame('0.00', (string) $position->ae_amount);
        $this->assertSame('fixed_price', $position->pricing_settlement_mode->value);
        $this->assertSame('250.00', (string) $position->fixed_price_nn);

        $roundtrip = $writer->payloadFromCalculation($calc);
        $this->assertSame('fixed_price', $roundtrip['positions'][0]['pricing_settlement_mode']);
        $this->assertSame('250.00', $roundtrip['positions'][0]['fixed_price_nn']);
        $this->assertSame('83.3333', $roundtrip['positions'][0]['effective_pay_factor_percent']);
    }

    public function test_fixed_price_with_ae_preview_matches_store_reload_and_snapshot(): void
    {
        $catalog = $this->createSpotClassicCatalog();
        $user = User::factory()->role(Role::Sales)->create();
        $payload = $this->fixedAveragePayload($catalog, '10000.00');
        $payload['ae_enabled'] = true;

        $writer = app(CalculationWriter::class);
        $preview = $writer->preview($payload, $user)->toArray();
        $calc = $writer->create($payload, $user);

        $this->assertSame('300.00', $preview['media_gross']);
        $this->assertSame('10000.00', $preview['nn_invest']);
        $this->assertSame('1764.71', $preview['ae_total']);
        $this->assertSame('11764.71', $preview['positions'][0]['after_order_discount']);
        $this->assertSame('1764.71', $preview['positions'][0]['ae_amount']);
        $this->assertSame('10000.00', $preview['positions'][0]['nn_invest']);
        $this->assertSame('10000.00', $preview['positions'][0]['fixed_price_nn']);

        $position = $calc->positions->firstOrFail();
        $this->assertSame('10000.00', (string) $position->nn_invest);
        $this->assertSame('10000.00', (string) $position->fixed_price_nn);
        $this->assertSame('1764.71', (string) $position->ae_amount);
        $this->assertTrue((bool) $calc->ae_enabled);
        $this->assertSame('1764.71', (string) $calc->ae_total);

        $reloadPayload = $writer->payloadFromCalculation($calc->fresh([
            'positions.plannerEntries',
            'positions.planRows',
            'positions.timeRanges',
            'positions.discounts',
            'orderDiscounts',
            'configurationSnapshot',
            'fieldValues',
        ]));
        $reloadPreview = $writer->preview($reloadPayload, $user)->toArray();
        $this->assertSame('1764.71', $reloadPreview['ae_total']);
        $this->assertSame('10000.00', $reloadPreview['nn_invest']);
        $this->assertSame($preview['positions'][0]['ae_amount'], $reloadPreview['positions'][0]['ae_amount']);
        $this->assertSame(
            $preview['positions'][0]['after_order_discount'],
            $reloadPreview['positions'][0]['after_order_discount'],
        );

        $snapshot = app(DispoOrderSnapshotMapper::class)->positionFromCalculationPosition($position, 0);
        $this->assertSame('1764.71', $snapshot['ae_amount']);
        $this->assertSame('10000.00', $snapshot['nn_invest']);
        $this->assertSame('10000.00', $snapshot['fixed_price_nn']);

        $toggleOff = $reloadPayload;
        $toggleOff['ae_enabled'] = false;
        $toggleOff['lock_version'] = $calc->lock_version;
        $writer->update($calc, $toggleOff, $user);
        $calc = $calc->fresh(['positions']);
        $this->assertSame('0.00', (string) $calc->ae_total);
        $this->assertSame('0.00', (string) $calc->positions->firstOrFail()->ae_amount);
        $this->assertSame('10000.00', (string) $calc->positions->firstOrFail()->nn_invest);

        $toggleOn = $writer->payloadFromCalculation($calc);
        $toggleOn['ae_enabled'] = true;
        $toggleOn['lock_version'] = $calc->lock_version;
        $writer->update($calc, $toggleOn, $user);
        $calc = $calc->fresh(['positions']);
        $this->assertSame('1764.71', (string) $calc->ae_total);
        $this->assertSame('1764.71', (string) $calc->positions->firstOrFail()->ae_amount);
        $this->assertSame('10000.00', (string) $calc->positions->firstOrFail()->nn_invest);
    }

    public function test_mixed_normal_and_fixed_with_ae(): void
    {
        $catalog = $this->createSpotClassicCatalog();
        $user = User::factory()->role(Role::Sales)->create();
        $payload = $this->schemaPayload($catalog);
        $payload['ae_enabled'] = true;
        $payload['positions'][] = [
            ...$payload['positions'][0],
            'inventory_id' => $catalog['rock']->id,
            'pricing_settlement_mode' => 'fixed_price',
            'fixed_price_nn' => '10000.00',
        ];

        $preview = app(CalculationWriter::class)->preview($payload, $user)->toArray();

        $this->assertSame('normal', $preview['positions'][0]['pricing_settlement_mode']);
        $this->assertSame('45.00', $preview['positions'][0]['ae_amount']);
        $this->assertSame('255.00', $preview['positions'][0]['nn_invest']);

        $this->assertSame('fixed_price', $preview['positions'][1]['pricing_settlement_mode']);
        $this->assertSame('1764.71', $preview['positions'][1]['ae_amount']);
        $this->assertSame('10000.00', $preview['positions'][1]['nn_invest']);
        $this->assertSame('11764.71', $preview['positions'][1]['after_order_discount']);
    }

    public function test_settlement_toggle_keeps_price_list_pin(): void
    {
        $catalog = $this->createSpotClassicCatalog();
        $user = User::factory()->role(Role::Sales)->create();
        $calculation = $this->createSavedCalculation($catalog, [
            ['inventory_id' => $catalog['hamburg']->id],
        ], $user);

        $position = $calculation->positions()->firstOrFail();
        $pinnedId = $position->price_list_id;
        $pinnedVersion = $position->price_list_version;

        $this->activateNewerListForSameYear($catalog['hamburg']->id, $pinnedId);

        $writer = app(CalculationWriter::class);
        $payload = $writer->payloadFromCalculation($calculation);
        $payload['lock_version'] = $calculation->lock_version;
        $payload['positions'][0]['pricing_settlement_mode'] = 'fixed_price';
        $payload['positions'][0]['fixed_price_nn'] = '200.00';

        $writer->update($calculation, $payload, $user);

        $fresh = $position->fresh();
        $this->assertSame($pinnedId, $fresh->price_list_id);
        $this->assertSame($pinnedVersion, $fresh->price_list_version);
        $this->assertSame('fixed_price', $fresh->pricing_settlement_mode->value);
        $this->assertSame('200.00', (string) $fresh->nn_invest);
    }

    public function test_calendar_basis_with_fixed_settlement(): void
    {
        $catalog = $this->createSpotClassicCatalog();
        $user = User::factory()->role(Role::Sales)->create();
        $this->setSecondPrice($catalog, '2.0000');

        $year = PriceListCalendar::currentYear();
        $payload = $this->schemaPayload($catalog);
        $payload['positions'][0] = [
            ...$payload['positions'][0],
            'spot_method' => 'calendar',
            'calculation_method_key' => 'calendar',
            'length_seconds' => 30,
            'total_spot_count' => 10,
            'time_ranges' => [],
            'plan_rows' => [],
            'planner_entries' => [
                ['date' => "{$year}-09-14", 'hour' => 8, 'spot_count' => 10],
            ],
            'pricing_settlement_mode' => 'fixed_price',
            'fixed_price_nn' => '500.00',
        ];

        $writer = app(CalculationWriter::class);
        $preview = $writer->preview($payload, $user)->toArray();

        $this->assertSame('600.00', $preview['media_gross']);
        $this->assertSame('500.00', $preview['nn_invest']);
    }

    public function test_fixed_settlement_with_components_ignores_discount_pipeline(): void
    {
        $catalog = $this->createSpotClassicCatalog();
        $user = User::factory()->role(Role::Sales)->create();
        $this->setSecondPrice($catalog, '2.0000');

        $payload = $this->schemaPayload($catalog);
        $payload['order_discount_percent'] = '10';
        $payload['positions'][0] = [
            ...$payload['positions'][0],
            'component_calculation_strategy' => 'shared_total_length',
            'components' => [
                ['role' => 'main_spot', 'label' => 'Hauptspot', 'length_seconds' => 20, 'sort' => 0],
                ['role' => 'allonge', 'label' => 'Allonge', 'length_seconds' => 10, 'sort' => 1],
            ],
            'position_discount_percent' => '10',
            'position_discounts' => [['type' => 'quantity', 'percent' => '10']],
            'pricing_settlement_mode' => 'fixed_price',
            'fixed_price_nn' => '480.00',
        ];

        $preview = app(CalculationWriter::class)->preview($payload, $user)->toArray();

        $this->assertSame('600.00', $preview['media_gross']);
        $this->assertSame('480.00', $preview['nn_invest']);
        $this->assertSame('0.00', $preview['positions'][0]['order_discount_amount']);
    }

    public function test_dispo_snapshot_includes_settlement_fields(): void
    {
        $catalog = $this->createSpotClassicCatalog();
        $user = User::factory()->role(Role::Sales)->create();
        $calc = app(CalculationWriter::class)->create($this->fixedAveragePayload($catalog, '220.50'), $user);
        $position = $calc->positions->firstOrFail();

        $snapshot = app(DispoOrderSnapshotMapper::class)->positionFromCalculationPosition($position, 0);

        $this->assertSame('fixed_price', $snapshot['pricing_settlement_mode']);
        $this->assertSame('220.50', $snapshot['fixed_price_nn']);
        $this->assertSame('220.50', $snapshot['nn_invest']);
        $this->assertNotNull($snapshot['effective_pay_factor_percent']);

        $order = app(DispoOrderWriter::class)
            ->createFromCalculation($calc, [$position->id], $user)
            ->order
            ->load('positions');
        $this->assertSame('fixed_price', $order->positions->first()->pricing_settlement_mode->value);
    }

    public function test_mixed_normal_and_fixed_positions(): void
    {
        $catalog = $this->createSpotClassicCatalog();
        $user = User::factory()->role(Role::Sales)->create();
        $payload = $this->schemaPayload($catalog);
        $payload['positions'][] = [
            ...$payload['positions'][0],
            'inventory_id' => $catalog['rock']->id,
            'pricing_settlement_mode' => 'fixed_price',
            'fixed_price_nn' => '150.00',
        ];

        $preview = app(CalculationWriter::class)->preview($payload, $user)->toArray();

        $this->assertSame('normal', $preview['positions'][0]['pricing_settlement_mode']);
        $this->assertSame('fixed_price', $preview['positions'][1]['pricing_settlement_mode']);
        $this->assertSame('150.00', $preview['positions'][1]['nn_invest']);
    }

    public function test_validation_rejects_fixed_without_nn_and_normal_with_nn(): void
    {
        $catalog = $this->createSpotClassicCatalog();
        $user = User::factory()->role(Role::Sales)->create();
        $writer = app(CalculationWriter::class);

        $missingNn = $this->fixedAveragePayload($catalog, null);
        unset($missingNn['positions'][0]['fixed_price_nn']);
        try {
            $writer->preview($missingNn, $user);
            $this->fail('Expected ValidationException');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('positions.0.fixed_price_nn', $exception->errors());
        }

        $normalWithNn = $this->schemaPayload($catalog);
        $normalWithNn['positions'][0]['fixed_price_nn'] = '100.00';
        $response = $this->actingAs($user)->postJson(route('calculations.preview'), $normalWithNn);
        $response->assertUnprocessable();
        $response->assertJsonValidationErrors(['positions.0.fixed_price_nn']);

        $tooManyDecimals = $this->fixedAveragePayload($catalog, '100.123');
        try {
            $writer->preview($tooManyDecimals, $user);
            $this->fail('Expected ValidationException');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('positions.0.fixed_price_nn', $exception->errors());
        }
    }

    public function test_stale_lock_version_rejected_on_fixed_update(): void
    {
        $catalog = $this->createSpotClassicCatalog();
        $user = User::factory()->role(Role::Sales)->create();
        $writer = app(CalculationWriter::class);
        $calc = $writer->create($this->fixedAveragePayload($catalog, '250.00'), $user);

        $payload = $writer->payloadFromCalculation($calc->fresh([
            'positions.plannerEntries',
            'positions.planRows',
            'positions.timeRanges',
            'positions.discounts',
            'orderDiscounts',
            'configurationSnapshot',
            'fieldValues',
        ]));
        $payload['lock_version'] = 99;
        $payload['positions'][0]['fixed_price_nn'] = '240.00';

        try {
            $writer->update($calc->fresh(), $payload, $user);
            $this->fail('Erwartete ValidationException bei veraltetem lock_version.');
        } catch (ValidationException $exception) {
            $this->assertSame(
                ['Die Kalkulation wurde parallel geändert. Bitte neu laden.'],
                $exception->errors()['lock_version'] ?? null,
            );
        }
    }

    public function test_budget_apply_resets_settlement_to_normal(): void
    {
        $catalog = $this->createSpotClassicCatalog();
        $user = User::factory()->role(Role::Sales)->create();
        $writer = app(CalculationWriter::class);
        $calc = $writer->create($this->fixedAveragePayload($catalog, '250.00'), $user);
        $calc->update([
            'planning_mode' => PlanningMode::Budget,
            'target_budget_nn' => '500',
        ]);

        $propose = $this->actingAs($user)->postJson(route('calculations.budget-propose'), [
            'planning_mode' => PlanningMode::Budget->value,
            'schema_fingerprint' => $this->liveSchemaFingerprint(),
            'target_budget_nn' => '500',
            'calculation_id' => $calc->id,
            'budget_elements' => [
                [
                    'client_id' => 'hamburg',
                    'inventory_id' => $catalog['hamburg']->id,
                    'spot_length_seconds' => 30,
                    'distribution_ranges' => [
                        [
                            'start_hour' => 8,
                            'end_hour_exclusive' => 12,
                            'day_group' => DayGroup::MoFr->value,
                        ],
                    ],
                    'position_discounts' => [],
                ],
            ],
            'order_discount_percent' => '0',
            'order_discounts' => [],
            'ae_enabled' => false,
            'positions' => [],
        ]);
        $propose->assertOk();
        $proposalId = $propose->json('proposal.id');
        $this->assertNotNull($proposalId);

        $this->actingAs($user)
            ->post(route('calculations.budget-apply', [
                'calculation' => $calc,
                'proposal' => $proposalId,
            ]))
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $position = $calc->fresh()->positions->firstWhere('inventory_id', $catalog['hamburg']->id);
        $this->assertNotNull($position);
        $this->assertSame('normal', $position->pricing_settlement_mode->value);
        $this->assertNull($position->fixed_price_nn);
        $this->assertGreaterThan(0, $position->timeRanges->count());
    }

    public function test_fixed_to_normal_clears_fixed_nn_on_persist(): void
    {
        $catalog = $this->createSpotClassicCatalog();
        $user = User::factory()->role(Role::Sales)->create();
        $writer = app(CalculationWriter::class);
        $calc = $writer->create($this->fixedAveragePayload($catalog, '250.00'), $user);

        $payload = $writer->payloadFromCalculation($calc);
        $payload['lock_version'] = $calc->lock_version;
        $payload['positions'][0]['pricing_settlement_mode'] = 'normal';
        $payload['positions'][0]['fixed_price_nn'] = null;
        $payload['positions'][0]['position_discount_percent'] = '10';
        $payload['positions'][0]['position_discounts'] = [
            ['type' => 'quantity', 'percent' => '10'],
        ];
        $expectedNn = $writer->preview($payload, $user)->toArray()['nn_invest'];

        $writer->update($calc, $payload, $user);

        $position = $calc->positions()->firstOrFail()->fresh();
        $this->assertSame('normal', $position->pricing_settlement_mode->value);
        $this->assertNull($position->fixed_price_nn);
        $this->assertSame($expectedNn, (string) $position->nn_invest);
        $this->assertSame('30.00', (string) $position->position_discount_amount);
    }

    public function test_nn_equal_media_gross_pay_factor_100(): void
    {
        $catalog = $this->createSpotClassicCatalog();
        $user = User::factory()->role(Role::Sales)->create();
        $preview = app(CalculationWriter::class)
            ->preview($this->fixedAveragePayload($catalog, '300.00'), $user)
            ->toArray();

        $this->assertSame('300.00', $preview['media_gross']);
        $this->assertSame('300.00', $preview['nn_invest']);
        $this->assertSame('0.00', $preview['positions'][0]['position_discount_amount']);
        $this->assertSame('100.0000', $preview['positions'][0]['effective_pay_factor_percent']);
    }

    public function test_basis_switch_average_to_calendar_retains_fixed_nn(): void
    {
        $catalog = $this->createSpotClassicCatalog();
        $user = User::factory()->role(Role::Sales)->create();
        $writer = app(CalculationWriter::class);
        $calc = $writer->create($this->fixedAveragePayload($catalog, '275.00'), $user);
        $position = $calc->positions->firstOrFail();

        $year = PriceListCalendar::currentYear();
        $payload = $writer->payloadFromCalculation($calc);
        $payload['lock_version'] = $calc->lock_version;
        $payload['positions'][0]['spot_method'] = 'calendar';
        $payload['positions'][0]['calculation_method_key'] = 'calendar';
        $payload['positions'][0]['time_ranges'] = [];
        $payload['positions'][0]['plan_rows'] = [];
        $payload['positions'][0]['planner_entries'] = [
            ['date' => "{$year}-09-14", 'hour' => 8, 'spot_count' => 10],
        ];

        $writer->update($calc, $payload, $user);

        $fresh = $position->fresh();
        $this->assertSame('calendar', $fresh->spot_method->value);
        $this->assertSame('fixed_price', $fresh->pricing_settlement_mode->value);
        $this->assertSame('275.00', (string) $fresh->fixed_price_nn);
    }

    /**
     * @param  array{hamburg: mixed, rock?: mixed, medium: mixed}  $catalog
     * @return array<string, mixed>
     */
    private function schemaPayload(array $catalog): array
    {
        return [
            'planning_mode' => 'manual',
            'customer_name' => 'Festpreis GmbH',
            'order_discount_percent' => '0',
            'ae_enabled' => false,
            'schema_fingerprint' => app(ConfigurationSnapshotFreezeService::class)
                ->resolveLiveSchemaForCalculationV3()['schema_fingerprint'],
            'positions' => [[
                'inventory_id' => $catalog['hamburg']->id,
                'advertising_medium_id' => $catalog['medium']->id,
                'schema_fingerprint' => app(ConfigurationSnapshotFreezeService::class)
                    ->resolveLivePositionSchema((int) $catalog['medium']->id)['schema_fingerprint'],
                'spot_method' => 'average',
                'length_seconds' => 30,
                'total_spot_count' => 10,
                'plan_rows' => [['hour' => 8, 'day_group' => DayGroup::MoFr->value]],
                'time_ranges' => [[
                    'start_hour' => 8,
                    'end_hour_exclusive' => 9,
                    'day_group' => DayGroup::MoFr->value,
                    'spot_count' => 10,
                ]],
            ]],
        ];
    }

    /**
     * @param  array{hamburg: mixed, medium: mixed}  $catalog
     * @return array<string, mixed>
     */
    private function fixedAveragePayload(array $catalog, ?string $fixedNn): array
    {
        $payload = $this->schemaPayload($catalog);
        $payload['positions'][0]['pricing_settlement_mode'] = 'fixed_price';
        if ($fixedNn !== null) {
            $payload['positions'][0]['fixed_price_nn'] = $fixedNn;
        }

        return $payload;
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

    private function activateNewerListForSameYear(int $inventoryId, int $previousActiveId): PriceList
    {
        $year = PriceListCalendar::currentYear();
        /** @var PriceList $previous */
        $previous = PriceList::query()->whereKey($previousActiveId)->firstOrFail();
        $previous->update(['status' => PriceListStatus::Archived]);

        $newer = PriceList::factory()->create([
            'inventory_id' => $inventoryId,
            'year' => $year,
            'status' => PriceListStatus::Active,
            'version' => 'newer-RH',
            'revision_number' => ((int) $previous->revision_number) + 1,
            'valid_from' => $previous->valid_from,
        ]);
        foreach (range(0, 23) as $hour) {
            foreach ([DayGroup::MoFr, DayGroup::Sa, DayGroup::So] as $group) {
                PriceListItem::factory()->create([
                    'price_list_id' => $newer->id,
                    'hour' => $hour,
                    'day_group' => $group,
                    'second_price' => '9.0000',
                ]);
            }
        }

        return $newer;
    }
}
