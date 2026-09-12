<?php

namespace Tests\Feature\DynamicField;

use App\Enums\FieldAppliesTo;
use App\Enums\FieldScope;
use App\Enums\FieldType;
use App\Enums\Role;
use App\Models\Calculation;
use App\Models\CalculationFieldValue;
use App\Models\CalculationPositionFieldValue;
use App\Models\FieldDefinition;
use App\Models\FieldSet;
use App\Models\FieldSetVersion;
use App\Models\SnapshotFieldDefinition;
use App\Models\User;
use App\Services\Calculation\CalculationWriter;
use App\Services\DynamicField\Admin\AdminFieldSetCatalog;
use App\Services\DynamicField\Admin\FieldDefinitionCustomWriter;
use App\Services\DynamicField\Admin\FieldDefinitionOptionsWriter;
use App\Services\DynamicField\Admin\FieldSetVersionAdminWriter;
use App\Services\DynamicField\ConfigurationSnapshotMaterializer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesSavedCalculation;
use Tests\Concerns\CreatesSpotClassicCatalog;
use Tests\TestCase;

/**
 * DF-3-REST-C2: Calc-UI-Vertragsflächen (Props / Partial-Save), ohne C1-Serverlogik zu ändern.
 */
class ChoiceValueCalcUiC2Test extends TestCase
{
    use CreatesSavedCalculation;
    use CreatesSpotClassicCatalog;
    use RefreshDatabase;

    public function test_calc_edit_props_include_header_select_multi_and_saved_values(): void
    {
        $admin = User::factory()->role(Role::Admin)->create();
        $select = $this->activateChoiceOnCore(
            $admin,
            FieldType::Select,
            FieldScope::Header,
            FieldAppliesTo::Calculation,
            [
                ['key' => 'opt_a', 'label' => 'A', 'sort' => 1],
                ['key' => 'opt_b', 'label' => 'B', 'sort' => 2],
            ],
            calc: true,
            dispo: false,
        );
        $multi = $this->activateChoiceOnCore(
            $admin,
            FieldType::MultiSelect,
            FieldScope::Header,
            FieldAppliesTo::Calculation,
            [
                ['key' => 'opt_b', 'label' => 'B', 'sort' => 2],
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
            'dynamic_field_values' => [
                $select->key => 'opt_a',
                $multi->key => ['opt_b', 'opt_a'],
            ],
            'positions' => [$this->positionPayload($catalog)],
        ]), $user);

        $this->actingAs($user)
            ->get(route('calculations.edit', $calculation))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('calculation.dynamic_field_values.'.$select->key, 'opt_a')
                ->where('calculation.dynamic_field_values.'.$multi->key, ['opt_a', 'opt_b'])
                ->where('fieldSchema.fields', function ($fields) use ($select, $multi) {
                    $selectRow = collect($fields)->firstWhere('key', $select->key);
                    $multiRow = collect($fields)->firstWhere('key', $multi->key);

                    return is_array($selectRow)
                        && $selectRow['field_type'] === 'select'
                        && is_array($selectRow['options_json'])
                        && ($selectRow['options_json'][0]['key'] ?? null) === 'opt_a'
                        && is_array($multiRow)
                        && $multiRow['field_type'] === 'multi_select'
                        && is_array($multiRow['options_json']);
                }));
    }

    public function test_position_choice_props_use_effective_snapshot_not_live_options(): void
    {
        $admin = User::factory()->role(Role::Admin)->create();
        $definition = $this->activateChoiceOnCore(
            $admin,
            FieldType::Select,
            FieldScope::Position,
            FieldAppliesTo::Calculation,
            [
                ['key' => 'opt_a', 'label' => 'Label Freeze', 'sort' => 1],
            ],
            calc: true,
            dispo: false,
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
            'dynamic_field_values' => [],
            'positions' => [[
                ...$this->positionPayload($catalog),
                'dynamic_field_values' => [
                    $definition->key => 'opt_a',
                ],
            ]],
        ]), $user);

        app(FieldDefinitionOptionsWriter::class)->replace($definition->fresh(), [
            'lock_version' => $definition->fresh()->lock_version,
            'options' => [
                ['key' => 'opt_a', 'label' => 'Label Live', 'sort' => 1],
                ['key' => 'opt_live', 'label' => 'Nur Live', 'sort' => 2],
            ],
        ], $admin);

        $this->actingAs($user)
            ->get(route('calculations.edit', $calculation))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('calculation.positions.0.dynamic_field_values.'.$definition->key, 'opt_a')
                ->where('calculation.positions.0.field_schema.fields', function ($fields) use ($definition) {
                    $match = collect($fields)->firstWhere('key', $definition->key);
                    if (! is_array($match) || $match['field_type'] !== 'select') {
                        return false;
                    }
                    $options = $match['options_json'] ?? null;
                    if (! is_array($options)) {
                        return false;
                    }
                    $keys = array_column($options, 'key');
                    $label = $options[0]['label'] ?? null;

                    return $keys === ['opt_a']
                        && $label === 'Label Freeze'
                        && ! in_array('opt_live', $keys, true);
                }));
    }

    public function test_historical_inactive_option_remains_in_schema_props(): void
    {
        $admin = User::factory()->role(Role::Admin)->create();
        $definition = $this->activateChoiceOnCore(
            $admin,
            FieldType::Select,
            FieldScope::Header,
            FieldAppliesTo::Calculation,
            [
                ['key' => 'opt_a', 'label' => 'A', 'sort' => 1],
                ['key' => 'opt_old', 'label' => 'Alt', 'sort' => 2],
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
            'dynamic_field_values' => [
                $definition->key => 'opt_old',
            ],
            'positions' => [$this->positionPayload($catalog)],
        ]), $user);

        SnapshotFieldDefinition::query()
            ->where('configuration_snapshot_id', $calculation->configuration_snapshot_id)
            ->where('key', $definition->key)
            ->update([
                'options_json' => json_encode([
                    ['key' => 'opt_a', 'label' => 'A', 'sort' => 1, 'is_active' => true],
                    ['key' => 'opt_old', 'label' => 'Alt', 'sort' => 2, 'is_active' => false],
                ], JSON_THROW_ON_ERROR),
            ]);

        $this->actingAs($user)
            ->get(route('calculations.edit', $calculation))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('calculation.dynamic_field_values.'.$definition->key, 'opt_old')
                ->where('fieldSchema.fields', function ($fields) use ($definition) {
                    $match = collect($fields)->firstWhere('key', $definition->key);
                    $options = collect($match['options_json'] ?? []);
                    $old = $options->firstWhere('key', 'opt_old');

                    return is_array($old) && ($old['is_active'] ?? true) === false;
                }));
    }

    public function test_ui_partial_save_explicit_clear_vs_missing_key(): void
    {
        $admin = User::factory()->role(Role::Admin)->create();
        $select = $this->activateChoiceOnCore(
            $admin,
            FieldType::Select,
            FieldScope::Header,
            FieldAppliesTo::Calculation,
            [
                ['key' => 'opt_a', 'label' => 'A', 'sort' => 1],
                ['key' => 'opt_b', 'label' => 'B', 'sort' => 2],
            ],
            calc: true,
            dispo: false,
        );
        $multi = $this->activateChoiceOnCore(
            $admin,
            FieldType::MultiSelect,
            FieldScope::Header,
            FieldAppliesTo::Calculation,
            [
                ['key' => 'opt_a', 'label' => 'A', 'sort' => 1],
                ['key' => 'opt_b', 'label' => 'B', 'sort' => 2],
            ],
            calc: true,
            dispo: false,
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
                $select->key => 'opt_a',
                $multi->key => ['opt_a', 'opt_b'],
            ],
            'positions' => [$this->positionPayload($catalog)],
        ]), $user);

        $payload = $this->updatePayload($writer, $calculation);
        $payload['dynamic_field_values'][$select->key] = null;
        $payload['dynamic_field_values'][$multi->key] = [];
        $writer->update($calculation->fresh(), $payload, $user);

        $selectSnap = SnapshotFieldDefinition::query()
            ->where('configuration_snapshot_id', $calculation->configuration_snapshot_id)
            ->where('key', $select->key)
            ->firstOrFail();
        $multiSnap = SnapshotFieldDefinition::query()
            ->where('configuration_snapshot_id', $calculation->configuration_snapshot_id)
            ->where('key', $multi->key)
            ->firstOrFail();
        $selectRow = CalculationFieldValue::query()
            ->where('calculation_id', $calculation->id)
            ->where('snapshot_field_definition_id', $selectSnap->id)
            ->firstOrFail();
        $multiRow = CalculationFieldValue::query()
            ->where('calculation_id', $calculation->id)
            ->where('snapshot_field_definition_id', $multiSnap->id)
            ->firstOrFail();
        $this->assertNull($selectRow->value_json);
        $this->assertSame([], $multiRow->value_json);

        $payload = $this->updatePayload($writer, $calculation->fresh());
        $payload['dynamic_field_values'][$select->key] = 'opt_b';
        $payload['dynamic_field_values'][$multi->key] = ['opt_a'];
        $writer->update($calculation->fresh(), $payload, $user);

        $payload = $this->updatePayload($writer, $calculation->fresh());
        unset($payload['dynamic_field_values'][$select->key], $payload['dynamic_field_values'][$multi->key]);
        $payload['customer_name'] = 'Kunde ohne Choice-Keys';
        $writer->update($calculation->fresh(), $payload, $user);

        $selectRow->refresh();
        $multiRow->refresh();
        $this->assertSame('opt_b', $selectRow->value_json);
        $this->assertSame(['opt_a'], $multiRow->value_json);
    }

    public function test_invalid_choice_payload_maps_422_to_field_key(): void
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

        $payload = $this->updatePayload($writer, $calculation);
        $payload['dynamic_field_values'][$definition->key] = 'nope';

        $this->actingAs($user)
            ->put(route('calculations.update', $calculation), $payload)
            ->assertSessionHasErrors('dynamic_field_values.'.$definition->key);
    }

    public function test_text_field_props_remain_available_alongside_choice(): void
    {
        $admin = User::factory()->role(Role::Admin)->create();
        $text = app(FieldDefinitionCustomWriter::class)->create([
            'label' => 'Text C2 '.bin2hex(random_bytes(2)),
            'field_type' => FieldType::ShortText,
            'scope' => FieldScope::Header,
            'applies_to' => FieldAppliesTo::Calculation,
            'sort_default' => 70,
            'reportable' => false,
            'max_length' => 100,
        ], $admin);
        $this->addDefinitionToCalcCore($admin, $text);

        $choice = $this->activateChoiceOnCore(
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
            'dynamic_field_values' => [
                $text->key => 'Hallo',
                $choice->key => 'opt_a',
            ],
            'positions' => [$this->positionPayload($catalog)],
        ]), $user);

        $this->actingAs($user)
            ->get(route('calculations.edit', $calculation))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('calculation.dynamic_field_values.'.$text->key, 'Hallo')
                ->where('fieldSchema.fields', function ($fields) use ($text, $choice) {
                    $textRow = collect($fields)->firstWhere('key', $text->key);
                    $choiceRow = collect($fields)->firstWhere('key', $choice->key);

                    return is_array($textRow)
                        && $textRow['field_type'] === 'short_text'
                        && ($textRow['options_json'] ?? null) === null
                        && is_array($choiceRow)
                        && $choiceRow['field_type'] === 'select';
                }));
    }

    public function test_position_multi_persist_via_ui_shaped_payload(): void
    {
        $admin = User::factory()->role(Role::Admin)->create();
        $definition = $this->activateChoiceOnCore(
            $admin,
            FieldType::MultiSelect,
            FieldScope::Position,
            FieldAppliesTo::Calculation,
            [
                ['key' => 'opt_b', 'label' => 'B', 'sort' => 2],
                ['key' => 'opt_a', 'label' => 'A', 'sort' => 1],
            ],
            calc: true,
            dispo: false,
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
            'dynamic_field_values' => [],
            'positions' => [[
                ...$this->positionPayload($catalog),
                'dynamic_field_values' => [
                    $definition->key => ['opt_b', 'opt_a'],
                ],
            ]],
        ]), $user);

        $this->actingAs($user)
            ->get(route('calculations.edit', $calculation))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where(
                    'calculation.positions.0.dynamic_field_values.'.$definition->key,
                    ['opt_a', 'opt_b'],
                )
                ->where('calculation.positions.0.field_schema.fields', function ($fields) use ($definition) {
                    $match = collect($fields)->firstWhere('key', $definition->key);

                    return is_array($match)
                        && $match['field_type'] === 'multi_select'
                        && is_array($match['options_json']);
                }));

        $row = CalculationPositionFieldValue::query()
            ->whereHas('snapshotFieldDefinition', fn ($q) => $q->where('key', $definition->key))
            ->where('calculation_position_id', $calculation->positions()->firstOrFail()->id)
            ->firstOrFail();
        $this->assertSame(['opt_a', 'opt_b'], $row->value_json);
    }

    /**
     * @param  list<array{key: string, label: string, sort: int, is_active?: bool}>  $options
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
            'label' => 'Choice C2 '.$fieldType->value.' '.bin2hex(random_bytes(2)),
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

    private function addDefinitionToCalcCore(User $admin, FieldDefinition $definition): void
    {
        $this->addToActiveSet(
            $admin,
            app(FieldSetVersionAdminWriter::class),
            AdminFieldSetCatalog::CALCULATION_CORE,
            $definition,
            null,
            null,
        );
        app(ConfigurationSnapshotMaterializer::class)
            ->materializeFromActiveSet(AdminFieldSetCatalog::CALCULATION_CORE);
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
