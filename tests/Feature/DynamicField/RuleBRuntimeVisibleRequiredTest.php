<?php

namespace Tests\Feature\DynamicField;

use App\Enums\FieldAppliesTo;
use App\Enums\FieldScope;
use App\Enums\FieldType;
use App\Enums\Role;
use App\Models\Calculation;
use App\Models\FieldDefinition;
use App\Models\FieldSet;
use App\Models\FieldSetVersion;
use App\Models\SnapshotFieldDefinition;
use App\Models\SnapshotFieldRule;
use App\Models\User;
use App\Services\Calculation\CalculationWriter;
use App\Services\DispoOrder\DispoOrderWriter;
use App\Services\DynamicField\Admin\AdminFieldSetCatalog;
use App\Services\DynamicField\Admin\FieldDefinitionCustomWriter;
use App\Services\DynamicField\Admin\FieldSetVersionAdminWriter;
use App\Services\DynamicField\CalculationDynamicFieldWriter;
use App\Services\DynamicField\ConfigurationSnapshotMaterializer;
use App\Services\DynamicField\DispoOrderDynamicFieldWriter;
use App\Services\DynamicField\SnapshotFieldRuleEvaluator;
use App\Support\DynamicField\FieldRuleContract;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesSavedCalculation;
use Tests\Concerns\CreatesSpotClassicCatalog;
use Tests\TestCase;

/**
 * DF-3-RULE-B: Keep-on-missing, Schema-Props inkl. basis_visible=false, DYN-005 Effective Visible.
 */
class RuleBRuntimeVisibleRequiredTest extends TestCase
{
    use CreatesSavedCalculation;
    use CreatesSpotClassicCatalog;
    use RefreshDatabase;

    public function test_calc_text_missing_key_keeps_stored_value(): void
    {
        $admin = User::factory()->role(Role::Admin)->create();
        $definition = $this->createAndActivateOnSets(
            $admin,
            'RULE-B Keep Text',
            FieldAppliesTo::Both,
            calc: true,
            dispo: true,
        );

        $calculation = $this->savedCalculationWithCustomValue($definition->key, 'Bleibt');
        $writer = app(CalculationDynamicFieldWriter::class);
        $writer->syncFromPayload($calculation, [
            'dynamic_field_values' => [
                'campaign_period' => null,
            ],
            'positions' => $calculation->positions->values()->map(fn ($position, int $index): array => [
                'id' => $position->id,
                'client_key' => $position->client_key,
                'dynamic_field_values' => [
                    'period_open' => true,
                ],
            ])->all(),
        ]);

        $calculation->refresh()->load('fieldValues.snapshotFieldDefinition');
        $row = $calculation->fieldValues->first(
            fn ($value) => $value->snapshotFieldDefinition?->key === $definition->key,
        );
        $this->assertNotNull($row);
        $this->assertSame('Bleibt', $row->value_string);
    }

    public function test_calc_text_explicit_null_clears_value(): void
    {
        $admin = User::factory()->role(Role::Admin)->create();
        $definition = $this->createAndActivateOnSets(
            $admin,
            'RULE-B Clear Text',
            FieldAppliesTo::Both,
            calc: true,
            dispo: true,
        );

        $calculation = $this->savedCalculationWithCustomValue($definition->key, 'Weg');
        $writer = app(CalculationDynamicFieldWriter::class);
        $writer->syncFromPayload($calculation, [
            'dynamic_field_values' => [
                $definition->key => null,
            ],
            'positions' => $calculation->positions->values()->map(fn ($position): array => [
                'id' => $position->id,
                'client_key' => $position->client_key,
                'dynamic_field_values' => [
                    'period_open' => true,
                ],
            ])->all(),
        ]);

        $calculation->refresh()->load('fieldValues.snapshotFieldDefinition');
        $row = $calculation->fieldValues->first(
            fn ($value) => $value->snapshotFieldDefinition?->key === $definition->key,
        );
        $this->assertNotNull($row);
        $this->assertNull($row->value_string);
    }

    public function test_dispo_schema_includes_basis_invisible_custom_and_action_target_flag(): void
    {
        $admin = User::factory()->role(Role::Admin)->create();
        $definition = $this->createAndActivateOnSets(
            $admin,
            'RULE-B Hidden Basis',
            FieldAppliesTo::DispoOrder,
            calc: false,
            dispo: true,
            requiredOverride: false,
            visibleOverride: false,
        );

        $calculation = $this->savedCalculation();
        $user = User::factory()->role(Role::Sales)->create();
        $order = app(DispoOrderWriter::class)
            ->createFromCalculation($calculation, $calculation->positions()->pluck('id')->all(), $user)
            ->order;

        $order->load('configurationSnapshot.fieldDefinitions');
        $snapKeys = $order->configurationSnapshot?->fieldDefinitions->pluck('key', 'visible')->all();
        $schema = app(DispoOrderDynamicFieldWriter::class)
            ->fieldSchemaProp($order);

        $allKeys = collect($schema['fields'] ?? [])->map(fn ($f) => [
            'key' => $f['key'] ?? null,
            'visible' => $f['visible'] ?? null,
            'editable' => $f['editable'] ?? null,
            'calc_origin' => $f['calc_origin'] ?? null,
        ])->all();

        $match = collect($schema['editable_custom_header_fields'] ?? [])
            ->firstWhere('key', $definition->key);
        $this->assertIsArray(
            $match,
            'Feld fehlt im editable Bucket. defKey='.$definition->key.' fields='.json_encode($allKeys),
        );
        $this->assertFalse((bool) ($match['visible'] ?? true));
        $this->assertTrue((bool) ($match['editable'] ?? false));
        $this->assertArrayHasKey('action_target_readonly', $match);
        $this->assertFalse((bool) $match['action_target_readonly']);
        $this->assertFalse((bool) ($match['calc_origin'] ?? true));
    }

    public function test_effective_visibility_hides_static_required_target(): void
    {
        $admin = User::factory()->role(Role::Admin)->create();
        $flag = $this->createAndActivateOnSets(
            $admin,
            'RULE-B Flag',
            FieldAppliesTo::Both,
            calc: true,
            dispo: true,
        );
        $target = $this->createAndActivateOnSets(
            $admin,
            'RULE-B Target',
            FieldAppliesTo::Both,
            calc: true,
            dispo: true,
            requiredOverride: true,
            visibleOverride: true,
        );

        $calculation = $this->savedCalculation();
        $snapshot = $calculation->configurationSnapshot;
        $this->assertNotNull($snapshot);
        $snapshot->load(['fieldDefinitions', 'rules', 'sources']);

        $targetDef = SnapshotFieldDefinition::query()
            ->where('configuration_snapshot_id', $snapshot->id)
            ->where('key', $target->key)
            ->firstOrFail();

        $condition = [
            'op' => FieldRuleContract::CONDITION_FIELD_EMPTY,
            'field_key' => $flag->key,
        ];
        $action = [
            'op' => FieldRuleContract::ACTION_SET_VISIBLE,
            'field_key' => $target->key,
            'value' => false,
        ];
        SnapshotFieldRule::query()->create([
            'configuration_snapshot_id' => $snapshot->id,
            'sort' => 50,
            'condition_json' => $condition,
            'action_json' => $action,
            'dedupe_key' => FieldRuleContract::dedupePayload($condition, $action),
        ]);

        $snapshot->unsetRelation('rules');
        $snapshot->load('rules');

        $visible = app(SnapshotFieldRuleEvaluator::class)->isEffectivelyVisible(
            $snapshot,
            $targetDef,
            [$flag->key => null, $target->key => null],
            [],
        );

        $this->assertFalse($visible);
    }

    private function savedCalculation(): Calculation
    {
        $catalog = $this->createSpotClassicCatalog();

        return $this->createSavedCalculation($catalog, [
            ['inventory_id' => $catalog['hamburg']->id],
        ]);
    }

    private function savedCalculationWithCustomValue(string $key, string $value): Calculation
    {
        $catalog = $this->createSpotClassicCatalog();
        $user = User::factory()->role(Role::Sales)->create();

        return app(CalculationWriter::class)->create($this->withLiveSchemaFingerprint([
            'planning_mode' => 'manual',
            'customer_name' => 'Testkunde GmbH',
            'agency_name' => 'Testagentur',
            'campaign' => 'Frühjahr 2026',
            'product_title' => 'Produkt A',
            'order_discount_percent' => '0',
            'ae_enabled' => false,
            'dynamic_field_values' => [
                $key => $value,
            ],
            'positions' => [[
                'inventory_id' => $catalog['hamburg']->id,
                'advertising_medium_id' => $catalog['medium']->id,
                'spot_method' => 'average',
                'length_seconds' => 30,
                'total_spot_count' => 10,
                'position_discount_percent' => '0',
                'ae_percent' => '15',
                'plan_rows' => [['hour' => 8, 'day_group' => 'mo_fr']],
            ]],
        ]), $user);
    }

    /**
     * @return array<string, mixed>
     */
    private function calculationUpdatePayload(Calculation $calculation): array
    {
        $calculation->load([
            'configurationSnapshot',
            'fieldValues.snapshotFieldDefinition',
            'positions.planRows',
            'positions.timeRanges',
            'positions.discounts',
            'positions.fieldValues.snapshotFieldDefinition',
            'orderDiscounts',
        ]);

        $headerValues = [];
        foreach ($calculation->fieldValues as $value) {
            $key = $value->snapshotFieldDefinition?->key;
            if ($key === null) {
                continue;
            }
            $headerValues[$key] = $value->value_string ?? $value->value_text ?? $value->value_boolean;
            if ($value->value_period_start !== null || $value->value_period_end !== null) {
                $headerValues[$key] = [
                    'start' => $value->value_period_start?->toDateString(),
                    'end' => $value->value_period_end?->toDateString(),
                ];
            }
        }

        return [
            'lock_version' => $calculation->lock_version,
            'planning_mode' => $calculation->planning_mode->value,
            'schema_fingerprint' => (string) $calculation->configurationSnapshot?->schema_fingerprint,
            'customer_name' => $calculation->customer_name,
            'agency_name' => $calculation->agency_name,
            'campaign' => $calculation->campaign,
            'product_title' => $calculation->product_title,
            'briefing' => $calculation->briefing,
            'order_discount_percent' => (string) $calculation->order_discount_percent,
            'ae_enabled' => (bool) $calculation->ae_enabled,
            'dynamic_field_values' => $headerValues,
            'order_discounts' => $calculation->orderDiscounts->map(fn ($discount): array => [
                'type' => $discount->type->value,
                'custom_label' => $discount->custom_label,
                'percent' => (string) $discount->percent,
            ])->all(),
            'positions' => $this->withPositionSchemaFingerprints(
                $calculation,
                $calculation->positions->values()->map(function ($position): array {
                    return [
                        'id' => $position->id,
                        'client_key' => $position->client_key,
                        'inventory_id' => $position->inventory_id,
                        'advertising_medium_id' => $position->advertising_medium_id,
                        'spot_method' => $position->spot_method->value,
                        'length_seconds' => $position->length_seconds,
                        'total_spot_count' => $position->total_spot_count,
                        'position_discount_percent' => (string) $position->position_discount_percent,
                        'ae_percent' => (string) $position->ae_percent,
                        'plan_rows' => $position->planRows->map(fn ($row): array => [
                            'hour' => $row->hour,
                            'day_group' => $row->day_group,
                        ])->all(),
                        'time_ranges' => $position->timeRanges->map(fn ($range): array => [
                            'start_hour' => $range->start_hour,
                            'end_hour_exclusive' => $range->end_hour_exclusive,
                            'day_group' => $range->day_group,
                            'spot_count' => $range->spot_count,
                        ])->all(),
                        'position_discounts' => $position->discounts->map(fn ($discount): array => [
                            'type' => $discount->type->value,
                            'custom_label' => $discount->custom_label,
                            'percent' => (string) $discount->percent,
                        ])->all(),
                        'dynamic_field_values' => [
                            'period_open' => true,
                        ],
                    ];
                })->all(),
            ),
        ];
    }

    private function createAndActivateOnSets(
        User $admin,
        string $label,
        FieldAppliesTo $appliesTo,
        bool $calc,
        bool $dispo,
        FieldType $fieldType = FieldType::ShortText,
        ?bool $requiredOverride = null,
        ?bool $visibleOverride = null,
    ): FieldDefinition {
        $definition = app(FieldDefinitionCustomWriter::class)->create([
            'label' => $label,
            'field_type' => $fieldType,
            'scope' => FieldScope::Header,
            'applies_to' => $appliesTo,
            'sort_default' => 70,
            'reportable' => false,
        ], $admin);

        $setWriter = app(FieldSetVersionAdminWriter::class);

        if ($calc) {
            $this->addToActiveSet(
                $admin,
                $setWriter,
                AdminFieldSetCatalog::CALCULATION_CORE,
                $definition,
                $requiredOverride,
                $visibleOverride,
            );
        }
        if ($dispo) {
            $this->addToActiveSet(
                $admin,
                $setWriter,
                AdminFieldSetCatalog::DISPO_ORDER_CORE,
                $definition,
                $requiredOverride,
                $visibleOverride,
            );
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
        ?bool $requiredOverride = null,
        ?bool $visibleOverride = null,
    ): void {
        $fieldSet = FieldSet::query()->where('key', $setKey)->firstOrFail();
        $source = FieldSetVersion::query()->whereKey($fieldSet->active_version_id)->firstOrFail();
        $draft = $setWriter->createDraftFromVersion($fieldSet, $source, $admin, $fieldSet->lock_version);
        $fieldSet->refresh();
        $setWriter->addCustomMembership($fieldSet, $draft, [
            'field_definition_id' => $definition->id,
            'field_definition_revision_id' => (int) $definition->current_revision_id,
            'sort' => 70,
            'required_override' => $requiredOverride,
            'visible_override' => $visibleOverride,
            'lock_version' => $fieldSet->lock_version,
        ], $admin);
        $fieldSet->refresh();
        $setWriter->activateDraft($fieldSet, $draft, $admin, $fieldSet->lock_version);
    }
}
