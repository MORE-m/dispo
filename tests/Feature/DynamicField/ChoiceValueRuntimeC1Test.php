<?php

namespace Tests\Feature\DynamicField;

use App\Enums\FieldAppliesTo;
use App\Enums\FieldScope;
use App\Enums\FieldType;
use App\Enums\Role;
use App\Models\Calculation;
use App\Models\CalculationFieldValue;
use App\Models\DispoOrderFieldValue;
use App\Models\FieldDefinition;
use App\Models\FieldSet;
use App\Models\FieldSetVersion;
use App\Models\SnapshotFieldDefinition;
use App\Models\User;
use App\Services\Calculation\CalculationWriter;
use App\Services\DispoOrder\DispoOrderWriter;
use App\Services\DynamicField\Admin\AdminFieldSetCatalog;
use App\Services\DynamicField\Admin\FieldDefinitionCustomWriter;
use App\Services\DynamicField\Admin\FieldDefinitionOptionsWriter;
use App\Services\DynamicField\Admin\FieldSetVersionAdminWriter;
use App\Services\DynamicField\CalculationDynamicFieldWriter;
use App\Services\DynamicField\ConfigurationSnapshotMaterializer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesSavedCalculation;
use Tests\Concerns\CreatesSpotClassicCatalog;
use Tests\TestCase;

/**
 * DF-3-REST-C1: Choice-Wertmodell serverseitig (ohne UI).
 */
class ChoiceValueRuntimeC1Test extends TestCase
{
    use CreatesSavedCalculation;
    use CreatesSpotClassicCatalog;
    use RefreshDatabase;

    public function test_calc_header_select_persist_export_reload_and_missing_key_keeps_value(): void
    {
        $admin = User::factory()->role(Role::Admin)->create();
        $definition = $this->activateChoiceOnCore(
            $admin,
            FieldType::Select,
            FieldScope::Header,
            FieldAppliesTo::Both,
            [
                ['key' => 'opt_a', 'label' => 'A', 'sort' => 1],
                ['key' => 'opt_b', 'label' => 'B', 'sort' => 2],
            ],
        );

        $user = User::factory()->role(Role::Sales)->create();
        $catalog = $this->createSpotClassicCatalog();
        $writer = app(CalculationWriter::class);
        $calculation = $writer->create($this->withLiveSchemaFingerprint([
            'planning_mode' => 'manual',
            'customer_name' => 'Kunde',
            'agency_name' => 'Agentur',
            'campaign' => 'Kampagne',
            'product_title' => 'Produkt',
            'order_discount_percent' => '0',
            'ae_enabled' => false,
            'dynamic_field_values' => [
                $definition->key => 'opt_a',
            ],
            'positions' => [$this->positionPayload($catalog)],
        ]), $user);

        $snapDef = SnapshotFieldDefinition::query()
            ->where('configuration_snapshot_id', $calculation->configuration_snapshot_id)
            ->where('key', $definition->key)
            ->firstOrFail();
        $this->assertNotNull($snapDef->options_json);

        $row = CalculationFieldValue::query()
            ->where('calculation_id', $calculation->id)
            ->where('snapshot_field_definition_id', $snapDef->id)
            ->firstOrFail();
        $this->assertSame('opt_a', $row->value_json);
        $this->assertNull($row->value_string);

        $exported = app(CalculationDynamicFieldWriter::class)
            ->headerValuesForPayload($calculation->fresh([
                'fieldValues.snapshotFieldDefinition',
                'configurationSnapshot.fieldDefinitions',
            ]));
        $this->assertSame('opt_a', $exported[$definition->key]);

        $payload = $writer->payloadFromCalculation($calculation->fresh([
            'positions.planRows',
            'positions.timeRanges',
            'positions.discounts',
            'orderDiscounts',
            'configurationSnapshot',
            'fieldValues',
        ]));
        $payload['lock_version'] = $calculation->fresh()->lock_version;
        unset($payload['dynamic_field_values'][$definition->key]);
        $writer->update($calculation->fresh(), $payload, $user);

        $row->refresh();
        $this->assertSame('opt_a', $row->value_json);
    }

    public function test_calc_header_multi_canonicalize_explicit_clear_and_invalid_atomic(): void
    {
        $admin = User::factory()->role(Role::Admin)->create();
        $definition = $this->activateChoiceOnCore(
            $admin,
            FieldType::MultiSelect,
            FieldScope::Header,
            FieldAppliesTo::Both,
            [
                ['key' => 'opt_b', 'label' => 'B', 'sort' => 2],
                ['key' => 'opt_a', 'label' => 'A', 'sort' => 1],
            ],
        );

        $user = User::factory()->role(Role::Sales)->create();
        $catalog = $this->createSpotClassicCatalog();
        $writer = app(CalculationWriter::class);
        $calculation = $writer->create($this->withLiveSchemaFingerprint([
            'planning_mode' => 'manual',
            'customer_name' => 'Kunde',
            'agency_name' => 'Agentur',
            'campaign' => 'Kampagne',
            'product_title' => 'Produkt',
            'order_discount_percent' => '0',
            'ae_enabled' => false,
            'dynamic_field_values' => [
                $definition->key => ['opt_b', 'opt_a'],
            ],
            'positions' => [$this->positionPayload($catalog)],
        ]), $user);

        $snapDef = SnapshotFieldDefinition::query()
            ->where('configuration_snapshot_id', $calculation->configuration_snapshot_id)
            ->where('key', $definition->key)
            ->firstOrFail();
        $row = CalculationFieldValue::query()
            ->where('calculation_id', $calculation->id)
            ->where('snapshot_field_definition_id', $snapDef->id)
            ->firstOrFail();
        $this->assertSame(['opt_a', 'opt_b'], $row->value_json);

        $payload = $this->updatePayload($writer, $calculation);
        $payload['dynamic_field_values'][$definition->key] = [];
        $writer->update($calculation->fresh(), $payload, $user);
        $row->refresh();
        $this->assertSame([], $row->value_json);

        $beforeLock = $calculation->fresh()->lock_version;
        $payload = $this->updatePayload($writer, $calculation->fresh());
        $payload['dynamic_field_values'][$definition->key] = ['opt_a', 'nope'];
        $this->actingAs($user)
            ->put(route('calculations.update', $calculation), $payload)
            ->assertSessionHasErrors('dynamic_field_values.'.$definition->key);

        $row->refresh();
        $this->assertSame([], $row->value_json);
        $this->assertSame($beforeLock, $calculation->fresh()->lock_version);
    }

    public function test_choice_snapshot_does_not_block_normal_calc_save_without_choice_payload(): void
    {
        $admin = User::factory()->role(Role::Admin)->create();
        $this->activateChoiceOnCore(
            $admin,
            FieldType::Select,
            FieldScope::Header,
            FieldAppliesTo::Calculation,
            [
                ['key' => 'opt_a', 'label' => 'A', 'sort' => 1],
            ],
            calc: true,
            dispo: false,
        );

        $user = User::factory()->role(Role::Sales)->create();
        $catalog = $this->createSpotClassicCatalog();
        $calculation = app(CalculationWriter::class)->create($this->withLiveSchemaFingerprint([
            'planning_mode' => 'manual',
            'customer_name' => 'Kunde',
            'agency_name' => 'Agentur',
            'campaign' => 'Kampagne',
            'product_title' => 'Produkt',
            'order_discount_percent' => '0',
            'ae_enabled' => false,
            'dynamic_field_values' => [],
            'positions' => [$this->positionPayload($catalog)],
        ]), $user);

        $this->assertNotNull($calculation->id);
    }

    public function test_historical_inactive_select_kept_and_readd_rejected(): void
    {
        $admin = User::factory()->role(Role::Admin)->create();
        $definition = $this->activateChoiceOnCore(
            $admin,
            FieldType::Select,
            FieldScope::Header,
            FieldAppliesTo::Both,
            [
                ['key' => 'opt_a', 'label' => 'A', 'sort' => 1],
                ['key' => 'opt_b', 'label' => 'B', 'sort' => 2],
            ],
        );

        $user = User::factory()->role(Role::Sales)->create();
        $catalog = $this->createSpotClassicCatalog();
        $writer = app(CalculationWriter::class);
        $calculation = $writer->create($this->withLiveSchemaFingerprint([
            'planning_mode' => 'manual',
            'customer_name' => 'Kunde',
            'agency_name' => 'Agentur',
            'campaign' => 'Kampagne',
            'product_title' => 'Produkt',
            'order_discount_percent' => '0',
            'ae_enabled' => false,
            'dynamic_field_values' => [
                $definition->key => 'opt_b',
            ],
            'positions' => [$this->positionPayload($catalog)],
        ]), $user);

        SnapshotFieldDefinition::query()
            ->where('configuration_snapshot_id', $calculation->configuration_snapshot_id)
            ->where('key', $definition->key)
            ->update([
                'options_json' => json_encode([
                    ['key' => 'opt_a', 'label' => 'A', 'sort' => 1, 'is_active' => true],
                    ['key' => 'opt_b', 'label' => 'B', 'sort' => 2, 'is_active' => false],
                ], JSON_THROW_ON_ERROR),
            ]);

        $payload = $this->updatePayload($writer, $calculation->fresh());
        $payload['dynamic_field_values'][$definition->key] = 'opt_b';
        $writer->update($calculation->fresh(), $payload, $user);

        $snapDef = SnapshotFieldDefinition::query()
            ->where('configuration_snapshot_id', $calculation->configuration_snapshot_id)
            ->where('key', $definition->key)
            ->firstOrFail();
        $row = CalculationFieldValue::query()
            ->where('calculation_id', $calculation->id)
            ->where('snapshot_field_definition_id', $snapDef->id)
            ->firstOrFail();
        $this->assertSame('opt_b', $row->value_json);

        $payload = $this->updatePayload($writer, $calculation->fresh());
        $payload['dynamic_field_values'][$definition->key] = 'opt_a';
        $writer->update($calculation->fresh(), $payload, $user);

        $payload = $this->updatePayload($writer, $calculation->fresh());
        $payload['dynamic_field_values'][$definition->key] = 'opt_b';
        $this->actingAs($user)
            ->put(route('calculations.update', $calculation->fresh()), $payload)
            ->assertSessionHasErrors('dynamic_field_values.'.$definition->key);
    }

    public function test_historical_inactive_multi_keep_remove_and_readd_rejected(): void
    {
        $admin = User::factory()->role(Role::Admin)->create();
        $definition = $this->activateChoiceOnCore(
            $admin,
            FieldType::MultiSelect,
            FieldScope::Header,
            FieldAppliesTo::Both,
            [
                ['key' => 'opt_a', 'label' => 'A', 'sort' => 1],
                ['key' => 'opt_b', 'label' => 'B', 'sort' => 2],
            ],
        );

        $user = User::factory()->role(Role::Sales)->create();
        $catalog = $this->createSpotClassicCatalog();
        $writer = app(CalculationWriter::class);
        $calculation = $writer->create($this->withLiveSchemaFingerprint([
            'planning_mode' => 'manual',
            'customer_name' => 'Kunde',
            'agency_name' => 'Agentur',
            'campaign' => 'Kampagne',
            'product_title' => 'Produkt',
            'order_discount_percent' => '0',
            'ae_enabled' => false,
            'dynamic_field_values' => [
                $definition->key => ['opt_b', 'opt_a'],
            ],
            'positions' => [$this->positionPayload($catalog)],
        ]), $user);

        SnapshotFieldDefinition::query()
            ->where('configuration_snapshot_id', $calculation->configuration_snapshot_id)
            ->where('key', $definition->key)
            ->update([
                'options_json' => json_encode([
                    ['key' => 'opt_a', 'label' => 'A', 'sort' => 1, 'is_active' => true],
                    ['key' => 'opt_b', 'label' => 'B', 'sort' => 2, 'is_active' => false],
                ], JSON_THROW_ON_ERROR),
            ]);

        $payload = $this->updatePayload($writer, $calculation->fresh());
        $payload['dynamic_field_values'][$definition->key] = ['opt_b', 'opt_a'];
        $writer->update($calculation->fresh(), $payload, $user);

        $snapDef = SnapshotFieldDefinition::query()
            ->where('configuration_snapshot_id', $calculation->configuration_snapshot_id)
            ->where('key', $definition->key)
            ->firstOrFail();
        $row = CalculationFieldValue::query()
            ->where('calculation_id', $calculation->id)
            ->where('snapshot_field_definition_id', $snapDef->id)
            ->firstOrFail();
        $this->assertSame(['opt_a', 'opt_b'], $row->value_json);

        $payload = $this->updatePayload($writer, $calculation->fresh());
        $payload['dynamic_field_values'][$definition->key] = ['opt_a'];
        $writer->update($calculation->fresh(), $payload, $user);
        $row->refresh();
        $this->assertSame(['opt_a'], $row->value_json);

        $payload = $this->updatePayload($writer, $calculation->fresh());
        $payload['dynamic_field_values'][$definition->key] = ['opt_a', 'opt_b'];
        $this->actingAs($user)
            ->put(route('calculations.update', $calculation->fresh()), $payload)
            ->assertSessionHasErrors('dynamic_field_values.'.$definition->key);
        $row->refresh();
        $this->assertSame(['opt_a'], $row->value_json);
    }

    public function test_dispo_create_copies_choice_and_completeness_gates(): void
    {
        $admin = User::factory()->role(Role::Admin)->create();
        $definition = $this->activateChoiceOnCore(
            $admin,
            FieldType::Select,
            FieldScope::Header,
            FieldAppliesTo::Both,
            [
                ['key' => 'opt_a', 'label' => 'A', 'sort' => 1],
            ],
            requiredOverride: true,
            visibleOverride: true,
        );

        $user = User::factory()->role(Role::Sales)->create();
        $catalog = $this->createSpotClassicCatalog();
        $writer = app(CalculationWriter::class);

        $incomplete = $writer->create($this->withLiveSchemaFingerprint([
            'planning_mode' => 'manual',
            'customer_name' => 'Kunde',
            'agency_name' => 'Agentur',
            'campaign' => 'Kampagne',
            'product_title' => 'Produkt',
            'order_discount_percent' => '0',
            'ae_enabled' => false,
            'dynamic_field_values' => [],
            'positions' => [$this->positionPayload($catalog)],
        ]), $user);

        $this->actingAs($user)
            ->post(route('dispo-orders.store', $incomplete), [
                'position_ids' => $incomplete->positions()->pluck('id')->all(),
            ])
            ->assertSessionHasErrors('dynamic_field_values.'.$definition->key);

        $calculation = $writer->create($this->withLiveSchemaFingerprint([
            'planning_mode' => 'manual',
            'customer_name' => 'Kunde',
            'agency_name' => 'Agentur',
            'campaign' => 'Kampagne',
            'product_title' => 'Produkt',
            'order_discount_percent' => '0',
            'ae_enabled' => false,
            'dynamic_field_values' => [
                $definition->key => 'opt_a',
            ],
            'positions' => [$this->positionPayload($catalog)],
        ]), $user);

        $order = app(DispoOrderWriter::class)
            ->createFromCalculation($calculation, $calculation->positions()->pluck('id')->all(), $user)
            ->order;

        $snapDef = SnapshotFieldDefinition::query()
            ->where('configuration_snapshot_id', $order->configuration_snapshot_id)
            ->where('key', $definition->key)
            ->firstOrFail();
        $row = DispoOrderFieldValue::query()
            ->where('dispo_order_id', $order->id)
            ->where('snapshot_field_definition_id', $snapDef->id)
            ->firstOrFail();
        $this->assertSame('opt_a', $row->value_json);
        $this->assertNotNull($snapDef->options_json);
    }

    public function test_native_dispo_choice_partial_save_and_submit_required(): void
    {
        $admin = User::factory()->role(Role::Admin)->create();
        $definition = $this->activateChoiceOnCore(
            $admin,
            FieldType::MultiSelect,
            FieldScope::Header,
            FieldAppliesTo::DispoOrder,
            [
                ['key' => 'opt_a', 'label' => 'A', 'sort' => 1],
                ['key' => 'opt_b', 'label' => 'B', 'sort' => 2],
            ],
            requiredOverride: true,
            visibleOverride: true,
            calc: false,
            dispo: true,
        );

        $catalog = $this->createSpotClassicCatalog();
        $calculation = $this->createSavedCalculation($catalog, [
            ['inventory_id' => $catalog['hamburg']->id],
        ]);
        $user = User::factory()->role(Role::Sales)->create();
        $order = app(DispoOrderWriter::class)
            ->createFromCalculation($calculation, $calculation->positions()->pluck('id')->all(), $user)
            ->order;

        $this->actingAs($user)
            ->post(route('dispo-orders.submit', $order), [
                'lock_version' => $order->lock_version,
            ])
            ->assertSessionHasErrors('dynamic_field_values.'.$definition->key);

        $this->actingAs($user)
            ->patch(route('dispo-orders.update', $order), [
                'lock_version' => $order->lock_version,
                'dynamic_field_values' => [
                    $definition->key => ['opt_b', 'opt_a'],
                ],
            ])
            ->assertRedirect();

        $snapDef = SnapshotFieldDefinition::query()
            ->where('configuration_snapshot_id', $order->configuration_snapshot_id)
            ->where('key', $definition->key)
            ->firstOrFail();
        $row = DispoOrderFieldValue::query()
            ->where('dispo_order_id', $order->id)
            ->where('snapshot_field_definition_id', $snapDef->id)
            ->firstOrFail();
        $this->assertSame(['opt_a', 'opt_b'], $row->value_json);

        $order->refresh();
        $this->actingAs($user)
            ->post(route('dispo-orders.submit', $order), [
                'lock_version' => $order->lock_version,
            ])
            ->assertRedirect();
    }

    public function test_schema_props_include_options_json_for_choice(): void
    {
        $admin = User::factory()->role(Role::Admin)->create();
        $definition = $this->activateChoiceOnCore(
            $admin,
            FieldType::Select,
            FieldScope::Header,
            FieldAppliesTo::Calculation,
            [
                ['key' => 'opt_a', 'label' => 'A', 'sort' => 1],
            ],
            calc: true,
            dispo: false,
        );

        $user = User::factory()->role(Role::Sales)->create();
        $catalog = $this->createSpotClassicCatalog();
        $calculation = app(CalculationWriter::class)->create($this->withLiveSchemaFingerprint([
            'planning_mode' => 'manual',
            'customer_name' => 'Kunde',
            'agency_name' => 'Agentur',
            'campaign' => 'Kampagne',
            'product_title' => 'Produkt',
            'order_discount_percent' => '0',
            'ae_enabled' => false,
            'dynamic_field_values' => [],
            'positions' => [$this->positionPayload($catalog)],
        ]), $user);

        $this->actingAs($user)
            ->get(route('calculations.edit', $calculation))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('fieldSchema.fields')
                ->where('fieldSchema.fields', function ($fields) use ($definition) {
                    $match = collect($fields)->firstWhere('key', $definition->key);

                    return is_array($match)
                        && $match['field_type'] === 'select'
                        && is_array($match['options_json'])
                        && ($match['options_json'][0]['key'] ?? null) === 'opt_a';
                }));
    }

    public function test_stale_lock_does_not_mutate_choice(): void
    {
        $admin = User::factory()->role(Role::Admin)->create();
        $definition = $this->activateChoiceOnCore(
            $admin,
            FieldType::Select,
            FieldScope::Header,
            FieldAppliesTo::Both,
            [
                ['key' => 'opt_a', 'label' => 'A', 'sort' => 1],
                ['key' => 'opt_b', 'label' => 'B', 'sort' => 2],
            ],
        );

        $user = User::factory()->role(Role::Sales)->create();
        $catalog = $this->createSpotClassicCatalog();
        $writer = app(CalculationWriter::class);
        $calculation = $writer->create($this->withLiveSchemaFingerprint([
            'planning_mode' => 'manual',
            'customer_name' => 'Kunde',
            'agency_name' => 'Agentur',
            'campaign' => 'Kampagne',
            'product_title' => 'Produkt',
            'order_discount_percent' => '0',
            'ae_enabled' => false,
            'dynamic_field_values' => [
                $definition->key => 'opt_a',
            ],
            'positions' => [$this->positionPayload($catalog)],
        ]), $user);

        $stale = $calculation->lock_version;
        $payload = $this->updatePayload($writer, $calculation);
        $payload['customer_name'] = 'Kunde 2';
        $writer->update($calculation->fresh(), $payload, $user);

        $payload = $this->updatePayload($writer, $calculation->fresh());
        $payload['lock_version'] = $stale;
        $payload['dynamic_field_values'][$definition->key] = 'opt_b';
        $this->actingAs($user)
            ->put(route('calculations.update', $calculation->fresh()), $payload)
            ->assertSessionHasErrors('lock_version');

        $snapDef = SnapshotFieldDefinition::query()
            ->where('configuration_snapshot_id', $calculation->configuration_snapshot_id)
            ->where('key', $definition->key)
            ->firstOrFail();
        $row = CalculationFieldValue::query()
            ->where('calculation_id', $calculation->id)
            ->where('snapshot_field_definition_id', $snapDef->id)
            ->firstOrFail();
        $this->assertSame('opt_a', $row->value_json);
    }

    public function test_freeze_isolation_ignores_live_options_admin_changes(): void
    {
        $admin = User::factory()->role(Role::Admin)->create();
        $definition = $this->activateChoiceOnCore(
            $admin,
            FieldType::Select,
            FieldScope::Header,
            FieldAppliesTo::Both,
            [
                ['key' => 'opt_a', 'label' => 'Label Alt', 'sort' => 1],
            ],
        );

        $user = User::factory()->role(Role::Sales)->create();
        $catalog = $this->createSpotClassicCatalog();
        $writer = app(CalculationWriter::class);
        $calculation = $writer->create($this->withLiveSchemaFingerprint([
            'planning_mode' => 'manual',
            'customer_name' => 'Kunde',
            'agency_name' => 'Agentur',
            'campaign' => 'Kampagne',
            'product_title' => 'Produkt',
            'order_discount_percent' => '0',
            'ae_enabled' => false,
            'dynamic_field_values' => [
                $definition->key => 'opt_a',
            ],
            'positions' => [$this->positionPayload($catalog)],
        ]), $user);

        $frozen = SnapshotFieldDefinition::query()
            ->where('configuration_snapshot_id', $calculation->configuration_snapshot_id)
            ->where('key', $definition->key)
            ->firstOrFail();
        $this->assertSame('Label Alt', $frozen->options_json[0]['label'] ?? null);
        $this->assertTrue((bool) ($frozen->options_json[0]['is_active'] ?? false));

        app(FieldDefinitionOptionsWriter::class)->replace($definition->fresh(), [
            'lock_version' => $definition->fresh()->lock_version,
            'options' => [
                ['key' => 'opt_a', 'label' => 'Label Neu', 'sort' => 1, 'is_active' => false],
                ['key' => 'opt_b', 'label' => 'Neu B', 'sort' => 2],
            ],
        ], $admin);
        $definition->refresh();

        // Bestehende Calc behält Freeze der alten Revision (Pin unverändert).
        $payload = $this->updatePayload($writer, $calculation->fresh());
        $payload['dynamic_field_values'][$definition->key] = 'opt_a';
        $writer->update($calculation->fresh(), $payload, $user);

        $frozenAfter = SnapshotFieldDefinition::query()
            ->where('configuration_snapshot_id', $calculation->configuration_snapshot_id)
            ->where('key', $definition->key)
            ->firstOrFail();
        $this->assertSame('Label Alt', $frozenAfter->options_json[0]['label'] ?? null);
        $this->assertTrue((bool) ($frozenAfter->options_json[0]['is_active'] ?? false));
        $this->assertCount(1, $frozenAfter->options_json);

        $payload = $this->updatePayload($writer, $calculation->fresh());
        $payload['dynamic_field_values'][$definition->key] = 'opt_b';
        $this->actingAs($user)
            ->put(route('calculations.update', $calculation->fresh()), $payload)
            ->assertSessionHasErrors('dynamic_field_values.'.$definition->key);

        // Neuer Snapshot: Pins auf aktuelle Options-Revision umstecken.
        $setWriter = app(FieldSetVersionAdminWriter::class);
        foreach ([AdminFieldSetCatalog::CALCULATION_CORE, AdminFieldSetCatalog::DISPO_ORDER_CORE] as $setKey) {
            $fieldSet = FieldSet::query()->where('key', $setKey)->firstOrFail();
            $source = FieldSetVersion::query()->whereKey($fieldSet->active_version_id)->firstOrFail();
            $draft = $setWriter->createDraftFromVersion($fieldSet, $source, $admin, $fieldSet->lock_version);
            $fieldSet->refresh();
            $setWriter->pinCurrentRevisionsOnDraft($fieldSet, $draft, $admin, $fieldSet->lock_version);
            $fieldSet->refresh();
            $setWriter->activateDraft($fieldSet, $draft, $admin, $fieldSet->lock_version);
        }
        app(ConfigurationSnapshotMaterializer::class)
            ->materializeFromActiveSet(AdminFieldSetCatalog::CALCULATION_CORE);

        $freshCalc = $writer->create($this->withLiveSchemaFingerprint([
            'planning_mode' => 'manual',
            'customer_name' => 'Kunde 2',
            'agency_name' => 'Agentur',
            'campaign' => 'Kampagne 2',
            'product_title' => 'Produkt',
            'order_discount_percent' => '0',
            'ae_enabled' => false,
            'dynamic_field_values' => [],
            'positions' => [$this->positionPayload($catalog)],
        ]), $user);
        $newFreeze = SnapshotFieldDefinition::query()
            ->where('configuration_snapshot_id', $freshCalc->configuration_snapshot_id)
            ->where('key', $definition->key)
            ->firstOrFail();
        $keys = array_column($newFreeze->options_json ?? [], 'key');
        $this->assertSame(['opt_a', 'opt_b'], $keys);
        $this->assertFalse((bool) ($newFreeze->options_json[0]['is_active'] ?? true));
        $this->assertSame('Label Neu', $newFreeze->options_json[0]['label'] ?? null);
    }

    public function test_invisible_required_choice_does_not_block_dispo_create(): void
    {
        $admin = User::factory()->role(Role::Admin)->create();
        $this->activateChoiceOnCore(
            $admin,
            FieldType::Select,
            FieldScope::Header,
            FieldAppliesTo::Both,
            [
                ['key' => 'opt_a', 'label' => 'A', 'sort' => 1],
            ],
            requiredOverride: true,
            visibleOverride: false,
        );

        $user = User::factory()->role(Role::Sales)->create();
        $catalog = $this->createSpotClassicCatalog();
        $calculation = app(CalculationWriter::class)->create($this->withLiveSchemaFingerprint([
            'planning_mode' => 'manual',
            'customer_name' => 'Kunde',
            'agency_name' => 'Agentur',
            'campaign' => 'Kampagne',
            'product_title' => 'Produkt',
            'order_discount_percent' => '0',
            'ae_enabled' => false,
            'dynamic_field_values' => [],
            'positions' => [$this->positionPayload($catalog)],
        ]), $user);

        $order = app(DispoOrderWriter::class)
            ->createFromCalculation($calculation, $calculation->positions()->pluck('id')->all(), $user)
            ->order;
        $this->assertNotNull($order->id);
    }

    /**
     * @param  list<array{key: string, label: string, sort: int}>  $options
     */
    private function activateChoiceOnCore(
        User $admin,
        FieldType $fieldType,
        FieldScope $scope,
        FieldAppliesTo $appliesTo,
        array $options,
        ?bool $requiredOverride = null,
        ?bool $visibleOverride = null,
        ?bool $calc = null,
        ?bool $dispo = null,
    ): FieldDefinition {
        $calc ??= $appliesTo !== FieldAppliesTo::DispoOrder;
        $dispo ??= $appliesTo !== FieldAppliesTo::Calculation;

        $definition = app(FieldDefinitionCustomWriter::class)->create([
            'label' => 'Choice '.$fieldType->value.' '.bin2hex(random_bytes(2)),
            'field_type' => $fieldType,
            'scope' => $scope,
            'applies_to' => $appliesTo,
            'sort_default' => 80,
            'reportable' => false,
        ], $admin);

        app(FieldDefinitionOptionsWriter::class)->replace($definition, [
            'lock_version' => $definition->lock_version,
            'options' => $options,
        ], $admin);
        $definition->refresh();

        $setWriter = app(FieldSetVersionAdminWriter::class);
        if ($calc) {
            $this->addToActiveSet($admin, $setWriter, AdminFieldSetCatalog::CALCULATION_CORE, $definition, $requiredOverride, $visibleOverride);
        }
        if ($dispo) {
            $this->addToActiveSet($admin, $setWriter, AdminFieldSetCatalog::DISPO_ORDER_CORE, $definition, $requiredOverride, $visibleOverride);
        }

        app(ConfigurationSnapshotMaterializer::class)
            ->materializeFromActiveSet(AdminFieldSetCatalog::CALCULATION_CORE);

        return $definition->fresh(['currentRevision']) ?? $definition;
    }

    private function addToActiveSet(
        User $admin,
        FieldSetVersionAdminWriter $setWriter,
        string $setKey,
        FieldDefinition $definition,
        ?bool $requiredOverride,
        ?bool $visibleOverride,
    ): void {
        $fieldSet = FieldSet::query()->where('key', $setKey)->firstOrFail();
        $source = FieldSetVersion::query()->whereKey($fieldSet->active_version_id)->firstOrFail();
        $draft = $setWriter->createDraftFromVersion($fieldSet, $source, $admin, $fieldSet->lock_version);
        $fieldSet->refresh();
        $setWriter->addCustomMembership($fieldSet, $draft, [
            'field_definition_id' => $definition->id,
            'field_definition_revision_id' => (int) $definition->current_revision_id,
            'sort' => 80,
            'required_override' => $requiredOverride,
            'visible_override' => $visibleOverride,
            'lock_version' => $fieldSet->lock_version,
        ], $admin);
        $fieldSet->refresh();
        $setWriter->activateDraft($fieldSet, $draft, $admin, $fieldSet->lock_version);
    }

    /**
     * @return array<string, mixed>
     */
    private function updatePayload(CalculationWriter $writer, Calculation $calculation): array
    {
        $fresh = $calculation->fresh([
            'positions.planRows',
            'positions.timeRanges',
            'positions.discounts',
            'orderDiscounts',
            'configurationSnapshot',
            'fieldValues.snapshotFieldDefinition',
        ]);
        $payload = $writer->payloadFromCalculation($fresh);
        $payload['lock_version'] = $fresh->lock_version;

        return $payload;
    }

    /**
     * @param  array<string, mixed>  $catalog
     * @return array<string, mixed>
     */
    private function positionPayload(array $catalog): array
    {
        return [
            'inventory_id' => $catalog['hamburg']->id,
            'advertising_medium_id' => $catalog['medium']->id,
            'spot_method' => 'average',
            'length_seconds' => 30,
            'total_spot_count' => 10,
            'position_discount_percent' => '0',
            'ae_percent' => '15',
            'plan_rows' => [['hour' => 8, 'day_group' => 'mo_fr']],
        ];
    }
}
