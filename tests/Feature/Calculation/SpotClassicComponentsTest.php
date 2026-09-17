<?php

namespace Tests\Feature\Calculation;

use App\Enums\ComponentCalculationStrategy;
use App\Enums\PriceListStatus;
use App\Enums\Role;
use App\Models\AuditEvent;
use App\Models\InventoryMediumRule;
use App\Models\PriceList;
use App\Models\PriceListItem;
use App\Models\User;
use App\Services\Calculation\CalculationWriter;
use App\Services\DispoOrder\DispoOrderWriter;
use App\Services\DynamicField\ConfigurationSnapshotFreezeService;
use App\Support\PriceList\PriceListCalendar;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\CreatesSpotClassicCatalog;
use Tests\TestCase;

/**
 * BL-P4-02c / AT-04: Spot-Komponenten Feature-Abdeckung.
 */
class SpotClassicComponentsTest extends TestCase
{
    use CreatesSpotClassicCatalog;
    use RefreshDatabase;

    public function test_average_preview_matches_store_for_both_strategies(): void
    {
        $catalog = $this->createSpotClassicCatalog();
        $user = User::factory()->role(Role::Sales)->create();
        $this->setRuleStrategy($catalog, ComponentCalculationStrategy::SharedTotalLength);

        $sharedPayload = $this->averagePayload($catalog, ComponentCalculationStrategy::SharedTotalLength);
        $writer = app(CalculationWriter::class);
        $sharedPreview = $writer->preview($sharedPayload, $user)->toArray();
        $sharedCalc = $writer->create($sharedPayload, $user);
        $this->assertSame('600.00', $sharedPreview['media_gross']);
        $this->assertSame('600.00', (string) $sharedCalc->media_gross);
        $this->assertSame('shared_total_length', $sharedCalc->positions->first()->component_calculation_strategy);
        $this->assertCount(2, $sharedCalc->positions->first()->components);

        $this->setRuleStrategy($catalog, ComponentCalculationStrategy::Individual);
        $individualPayload = $this->averagePayload($catalog, ComponentCalculationStrategy::Individual);
        $individualPreview = $writer->preview($individualPayload, $user)->toArray();
        $individualCalc = $writer->create($individualPayload, $user);
        $this->assertSame('640.00', $individualPreview['media_gross']);
        $this->assertSame('640.00', (string) $individualCalc->media_gross);
        $this->assertSame('420.00', $individualPreview['positions'][0]['components'][0]['media_gross']);
        $this->assertSame('220.00', $individualPreview['positions'][0]['components'][1]['media_gross']);
    }

    public function test_calendar_components_with_two_cells(): void
    {
        $catalog = $this->createSpotClassicCatalog();
        $user = User::factory()->role(Role::Sales)->create();
        $this->setSecondPrice($catalog, '2.0000');
        $this->setRuleStrategy($catalog, ComponentCalculationStrategy::Individual);

        $year = PriceListCalendar::currentYear();
        $payload = $this->calendarPayload($catalog, [
            ['date' => "{$year}-09-14", 'hour' => 8, 'spot_count' => 10],
            ['date' => "{$year}-09-14", 'hour' => 14, 'spot_count' => 5],
        ], ComponentCalculationStrategy::Individual);

        // Override second cell price to 3.00
        $list = PriceList::query()->where('inventory_id', $catalog['hamburg']->id)->where('status', PriceListStatus::Active)->firstOrFail();
        PriceListItem::query()->where('price_list_id', $list->id)->where('hour', 14)->update(['second_price' => '3.0000']);

        $writer = app(CalculationWriter::class);
        $preview = $writer->preview($payload, $user)->toArray();
        $calc = $writer->create($payload, $user);

        $this->assertSame('1120.00', $preview['media_gross']);
        $this->assertSame('1120.00', (string) $calc->media_gross);
        $this->assertSame('2.3333', $preview['positions'][0]['average_second_price']);
        $this->assertSame('2.3333', (string) $calc->positions->first()->average_second_price);
        $this->assertSame(15, $calc->positions->first()->total_spot_count);
        $this->assertCount(2, $calc->positions->first()->plannerEntries);
    }

    public function test_components_roundtrip_add_edit_remove_allonge(): void
    {
        $catalog = $this->createSpotClassicCatalog();
        $user = User::factory()->role(Role::Sales)->create();
        $this->setRuleStrategy($catalog, ComponentCalculationStrategy::SharedTotalLength);
        $writer = app(CalculationWriter::class);

        $payload = $this->averagePayload($catalog, ComponentCalculationStrategy::SharedTotalLength);
        $calc = $writer->create($payload, $user);
        $roundtrip = $writer->payloadFromCalculation($calc);
        $this->assertCount(2, $roundtrip['positions'][0]['components']);

        $payload = $roundtrip;
        $payload['lock_version'] = $calc->lock_version;
        $payload['positions'][0]['id'] = $calc->positions->first()->id;
        $payload['positions'][0]['components'][1]['length_seconds'] = 12;
        $payload['positions'][0]['length_seconds'] = 32;
        $calc = $writer->update($calc, $payload, $user);
        $this->assertSame(12, $calc->positions->first()->components->firstWhere('role', 'allonge')->length_seconds);

        $payload = $writer->payloadFromCalculation($calc);
        $payload['lock_version'] = $calc->lock_version;
        $payload['positions'][0]['components'] = [[
            'role' => 'main_spot',
            'label' => 'Hauptspot',
            'length_seconds' => 20,
            'sort' => 0,
        ]];
        $payload['positions'][0]['length_seconds'] = 20;
        $calc = $writer->update($calc, $payload, $user);
        $this->assertCount(1, $calc->positions->first()->components);
    }

    public function test_admin_strategy_change_does_not_rewrite_existing_position(): void
    {
        $catalog = $this->createSpotClassicCatalog();
        $user = User::factory()->role(Role::Sales)->create();
        $this->setRuleStrategy($catalog, ComponentCalculationStrategy::SharedTotalLength);
        $writer = app(CalculationWriter::class);
        $calc = $writer->create($this->averagePayload($catalog, ComponentCalculationStrategy::SharedTotalLength), $user);
        $this->assertSame('600.00', (string) $calc->media_gross);

        $this->setRuleStrategy($catalog, ComponentCalculationStrategy::Individual);
        $payload = $writer->payloadFromCalculation($calc);
        $payload['lock_version'] = $calc->lock_version;
        $updated = $writer->update($calc, $payload, $user);
        $this->assertSame('shared_total_length', $updated->positions->first()->component_calculation_strategy);
        $this->assertSame('600.00', (string) $updated->media_gross);

        $newCalc = $writer->create($this->averagePayload($catalog, ComponentCalculationStrategy::Individual), $user);
        $this->assertSame('individual', $newCalc->positions->first()->component_calculation_strategy);
        $this->assertSame('640.00', (string) $newCalc->media_gross);
    }

    public function test_method_switch_keeps_components_strategy_and_pin(): void
    {
        $catalog = $this->createSpotClassicCatalog();
        $user = User::factory()->role(Role::Sales)->create();
        $this->setSecondPrice($catalog, '2.0000');
        $this->setRuleStrategy($catalog, ComponentCalculationStrategy::Individual);
        $writer = app(CalculationWriter::class);
        $calc = $writer->create($this->averagePayload($catalog, ComponentCalculationStrategy::Individual), $user);
        $pin = $calc->positions->first()->price_list_id;
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
        $payload['positions'][0]['total_spot_count'] = 10;

        $updated = $writer->update($calc, $payload, $user);
        $position = $updated->positions->first();
        $this->assertSame('calendar', $position->calculation_method_key);
        $this->assertSame($pin, $position->price_list_id);
        $this->assertSame('individual', $position->component_calculation_strategy);
        $this->assertCount(2, $position->components);
        $this->assertSame('640.00', (string) $position->media_gross);
    }

    public function test_legacy_position_without_components_unchanged(): void
    {
        $catalog = $this->createSpotClassicCatalog();
        $user = User::factory()->role(Role::Sales)->create();
        $this->setSecondPrice($catalog, '2.0000');
        $writer = app(CalculationWriter::class);
        $payload = $this->averagePayload($catalog, null);
        unset($payload['positions'][0]['components'], $payload['positions'][0]['component_calculation_strategy']);
        $payload['positions'][0]['length_seconds'] = 30;
        $calc = $writer->create($payload, $user);
        $this->assertSame('600.00', (string) $calc->media_gross);
        $this->assertCount(0, $calc->positions->first()->components);
        $this->assertNull($calc->positions->first()->component_calculation_strategy);
    }

    public function test_validation_rejects_missing_main_spot_and_unknown_role(): void
    {
        $catalog = $this->createSpotClassicCatalog();
        $user = User::factory()->role(Role::Sales)->create();
        $payload = $this->averagePayload($catalog, ComponentCalculationStrategy::SharedTotalLength);
        $payload['positions'][0]['components'] = [[
            'role' => 'allonge',
            'label' => 'Allonge',
            'length_seconds' => 10,
            'sort' => 0,
        ]];
        $payload['positions'][0]['length_seconds'] = 10;

        try {
            app(CalculationWriter::class)->preview($payload, $user);
            $this->fail('Expected validation exception');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('positions.0.components', $exception->errors());
        }

        $payload['positions'][0]['components'] = [
            ['role' => 'main_spot', 'label' => 'Hauptspot', 'length_seconds' => 20, 'sort' => 0],
            ['role' => 'abbinder', 'label' => 'Abbinder', 'length_seconds' => 5, 'sort' => 1],
        ];
        $payload['positions'][0]['length_seconds'] = 25;

        try {
            app(CalculationWriter::class)->preview($payload, $user);
            $this->fail('Expected validation exception');
        } catch (ValidationException $exception) {
            $this->assertTrue(collect($exception->errors())->keys()->contains(fn ($key) => str_contains((string) $key, 'components')));
        }
    }

    public function test_dispo_snapshot_retains_components_after_calc_change(): void
    {
        $catalog = $this->createSpotClassicCatalog();
        $user = User::factory()->role(Role::Sales)->create();
        $this->setRuleStrategy($catalog, ComponentCalculationStrategy::Individual);
        $writer = app(CalculationWriter::class);
        $calc = $writer->create($this->averagePayload($catalog, ComponentCalculationStrategy::Individual), $user);

        $result = app(DispoOrderWriter::class)->createFromCalculation(
            $calc,
            [$calc->positions->first()->id],
            $user,
        );
        $order = $result->order->load('positions');
        $snapshot = $order->positions->first()->components_snapshot;
        $this->assertCount(2, $snapshot);
        $this->assertSame('individual', $order->positions->first()->component_calculation_strategy);

        $payload = $writer->payloadFromCalculation($calc);
        $payload['lock_version'] = $calc->lock_version;
        $payload['positions'][0]['components'] = [];
        $payload['positions'][0]['component_calculation_strategy'] = null;
        $payload['positions'][0]['length_seconds'] = 30;
        $writer->update($calc, $payload, $user);

        $order->refresh()->load('positions');
        $this->assertSame($snapshot, $order->positions->first()->components_snapshot);
        $this->assertSame('individual', $order->positions->first()->component_calculation_strategy);

        $this->actingAs($user)
            ->get(route('dispo-orders.show', $order))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('dispo-orders/show')
                ->where('order.positions.0.components.0.role', 'main_spot')
                ->where('order.positions.0.component_calculation_strategy', 'individual'));
    }

    public function test_admin_can_update_component_strategy_on_rule(): void
    {
        $catalog = $this->createSpotClassicCatalog();
        $admin = User::factory()->role(Role::Admin)->create();
        $rule = InventoryMediumRule::query()
            ->where('inventory_id', $catalog['hamburg']->id)
            ->where('advertising_medium_id', $catalog['medium']->id)
            ->firstOrFail();

        $this->actingAs($admin)
            ->putJson(route('administration.inventories.medium-rules.update', [$catalog['hamburg'], $rule]), [
                'component_calculation_strategy' => 'individual',
                'lock_version' => $catalog['hamburg']->lock_version,
            ])
            ->assertOk()
            ->assertJsonPath('rule.component_calculation_strategy', 'individual')
            ->assertJsonPath('lock_version', $catalog['hamburg']->lock_version + 1);

        $this->assertSame('individual', $rule->fresh()->component_calculation_strategy->value);
        $this->assertSame($catalog['hamburg']->lock_version + 1, $catalog['hamburg']->fresh()->lock_version);
    }

    public function test_admin_strategy_update_conflict_keeps_value_and_skips_audit(): void
    {
        $catalog = $this->createSpotClassicCatalog();
        $admin = User::factory()->role(Role::Admin)->create();
        $rule = InventoryMediumRule::query()
            ->where('inventory_id', $catalog['hamburg']->id)
            ->where('advertising_medium_id', $catalog['medium']->id)
            ->firstOrFail();
        $this->setRuleStrategy($catalog, ComponentCalculationStrategy::SharedTotalLength);

        $beforeCount = AuditEvent::query()->count();

        $this->actingAs($admin)
            ->putJson(route('administration.inventories.medium-rules.update', [$catalog['hamburg'], $rule]), [
                'component_calculation_strategy' => 'individual',
                'lock_version' => $catalog['hamburg']->lock_version + 5,
            ])
            ->assertStatus(409);

        $this->assertSame('shared_total_length', $rule->fresh()->component_calculation_strategy->value);
        $this->assertSame($catalog['hamburg']->lock_version, $catalog['hamburg']->fresh()->lock_version);
        $this->assertSame($beforeCount, AuditEvent::query()->count());
    }

    public function test_missing_components_field_keeps_existing_components(): void
    {
        $catalog = $this->createSpotClassicCatalog();
        $user = User::factory()->role(Role::Sales)->create();
        $this->setRuleStrategy($catalog, ComponentCalculationStrategy::Individual);
        $writer = app(CalculationWriter::class);
        $calc = $writer->create($this->averagePayload($catalog, ComponentCalculationStrategy::Individual), $user);

        $payload = $writer->payloadFromCalculation($calc);
        $payload['lock_version'] = $calc->lock_version;
        $payload['customer_name'] = 'Header geändert';
        unset($payload['positions'][0]['components']);
        unset($payload['positions'][0]['component_calculation_strategy']);

        $calc = $writer->update($calc, $payload, $user);
        $this->assertSame('Header geändert', $calc->customer_name);
        $this->assertCount(2, $calc->positions->first()->components);
        $this->assertSame('individual', $calc->positions->first()->component_calculation_strategy);
    }

    public function test_null_components_fail_closed(): void
    {
        $catalog = $this->createSpotClassicCatalog();
        $user = User::factory()->role(Role::Sales)->create();
        $this->setRuleStrategy($catalog, ComponentCalculationStrategy::Individual);
        $writer = app(CalculationWriter::class);
        $calc = $writer->create($this->averagePayload($catalog, ComponentCalculationStrategy::Individual), $user);

        $payload = $writer->payloadFromCalculation($calc);
        $payload['lock_version'] = $calc->lock_version;
        $payload['positions'][0]['components'] = null;

        try {
            $writer->update($calc, $payload, $user);
            $this->fail('Expected ValidationException');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('positions.0.components', $exception->errors());
        }

        $this->assertCount(2, $calc->fresh()->positions->first()->components);
    }

    public function test_explicit_empty_components_deactivates(): void
    {
        $catalog = $this->createSpotClassicCatalog();
        $user = User::factory()->role(Role::Sales)->create();
        $this->setRuleStrategy($catalog, ComponentCalculationStrategy::SharedTotalLength);
        $writer = app(CalculationWriter::class);
        $calc = $writer->create($this->averagePayload($catalog, ComponentCalculationStrategy::SharedTotalLength), $user);

        $payload = $writer->payloadFromCalculation($calc);
        $payload['lock_version'] = $calc->lock_version;
        $payload['positions'][0]['components'] = [];
        $payload['positions'][0]['component_calculation_strategy'] = null;
        $payload['positions'][0]['length_seconds'] = 30;

        $calc = $writer->update($calc, $payload, $user);
        $this->assertCount(0, $calc->positions->first()->components);
        $this->assertNull($calc->positions->first()->component_calculation_strategy);
    }

    public function test_strategy_mismatch_on_create_is_rejected(): void
    {
        $catalog = $this->createSpotClassicCatalog();
        $user = User::factory()->role(Role::Sales)->create();
        $this->setRuleStrategy($catalog, ComponentCalculationStrategy::SharedTotalLength);
        $payload = $this->averagePayload($catalog, ComponentCalculationStrategy::Individual);

        try {
            app(CalculationWriter::class)->create($payload, $user);
            $this->fail('Expected ValidationException');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('positions.0.component_calculation_strategy', $exception->errors());
        }
    }

    public function test_missing_strategy_is_derived_from_rule(): void
    {
        $catalog = $this->createSpotClassicCatalog();
        $user = User::factory()->role(Role::Sales)->create();
        $this->setRuleStrategy($catalog, ComponentCalculationStrategy::Individual);
        $payload = $this->averagePayload($catalog, ComponentCalculationStrategy::Individual);
        unset($payload['positions'][0]['component_calculation_strategy']);

        $calc = app(CalculationWriter::class)->create($payload, $user);
        $this->assertSame('individual', $calc->positions->first()->component_calculation_strategy);
    }

    public function test_manipulated_label_is_rejected(): void
    {
        $catalog = $this->createSpotClassicCatalog();
        $user = User::factory()->role(Role::Sales)->create();
        $this->setRuleStrategy($catalog, ComponentCalculationStrategy::SharedTotalLength);
        $payload = $this->averagePayload($catalog, ComponentCalculationStrategy::SharedTotalLength);
        $payload['positions'][0]['components'][0]['label'] = 'Allonge';

        try {
            app(CalculationWriter::class)->create($payload, $user);
            $this->fail('Expected ValidationException');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('positions.0.components.0.label', $exception->errors());
        }
    }

    public function test_frozen_strategy_mismatch_is_rejected(): void
    {
        $catalog = $this->createSpotClassicCatalog();
        $user = User::factory()->role(Role::Sales)->create();
        $this->setRuleStrategy($catalog, ComponentCalculationStrategy::Individual);
        $writer = app(CalculationWriter::class);
        $calc = $writer->create($this->averagePayload($catalog, ComponentCalculationStrategy::Individual), $user);

        $this->setRuleStrategy($catalog, ComponentCalculationStrategy::SharedTotalLength);
        $payload = $writer->payloadFromCalculation($calc);
        $payload['lock_version'] = $calc->lock_version;
        $payload['positions'][0]['component_calculation_strategy'] = 'shared_total_length';

        try {
            $writer->update($calc, $payload, $user);
            $this->fail('Expected ValidationException');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('positions.0.component_calculation_strategy', $exception->errors());
        }
    }

    /**
     * @param  array{hamburg: mixed, medium: mixed}  $catalog
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
     * @param  array{hamburg: mixed, medium: mixed}  $catalog
     * @return array<string, mixed>
     */
    private function averagePayload(array $catalog, ?ComponentCalculationStrategy $strategy): array
    {
        $position = [
            'inventory_id' => $catalog['hamburg']->id,
            'advertising_medium_id' => $catalog['medium']->id,
            'schema_fingerprint' => app(ConfigurationSnapshotFreezeService::class)
                ->resolveLivePositionSchema((int) $catalog['medium']->id)['schema_fingerprint'],
            'spot_method' => 'average',
            'length_seconds' => 30,
            'total_spot_count' => 10,
            'position_discount_percent' => '0',
            'ae_percent' => '0',
            'plan_rows' => [['hour' => 8, 'day_group' => 'mo_fr']],
            'time_ranges' => [[
                'start_hour' => 8,
                'end_hour_exclusive' => 9,
                'day_group' => 'mo_fr',
                'spot_count' => 10,
            ]],
        ];

        if ($strategy !== null) {
            $position['component_calculation_strategy'] = $strategy->value;
            $position['components'] = [
                ['role' => 'main_spot', 'label' => 'Hauptspot', 'length_seconds' => 20, 'sort' => 0],
                ['role' => 'allonge', 'label' => 'Allonge', 'length_seconds' => 10, 'sort' => 1],
            ];
        }

        $this->setSecondPrice($catalog, '2.0000');

        return [
            'planning_mode' => 'manual',
            'customer_name' => 'Komponenten GmbH',
            'schema_fingerprint' => app(ConfigurationSnapshotFreezeService::class)
                ->resolveLiveSchemaForCalculationV3()['schema_fingerprint'],
            'positions' => [$position],
        ];
    }

    /**
     * @param  array{hamburg: mixed, medium: mixed}  $catalog
     * @param  list<array{date: string, hour: int, spot_count: int}>  $entries
     * @return array<string, mixed>
     */
    private function calendarPayload(array $catalog, array $entries, ComponentCalculationStrategy $strategy): array
    {
        $payload = $this->averagePayload($catalog, $strategy);
        $payload['positions'][0]['spot_method'] = 'calendar';
        $payload['positions'][0]['calculation_method_key'] = 'calendar';
        $payload['positions'][0]['time_ranges'] = [];
        $payload['positions'][0]['plan_rows'] = [];
        $payload['positions'][0]['planner_entries'] = $entries;
        $payload['positions'][0]['total_spot_count'] = array_sum(array_column($entries, 'spot_count'));

        return $payload;
    }
}
