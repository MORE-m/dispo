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
use App\Services\DispoOrder\DispoOrderApprovalService;
use App\Services\DispoOrder\DispoOrderWriter;
use App\Services\DynamicField\Admin\AdminFieldSetCatalog;
use App\Services\DynamicField\Admin\FieldDefinitionCustomWriter;
use App\Services\DynamicField\Admin\FieldSetVersionAdminWriter;
use App\Services\DynamicField\ConfigurationSnapshotMaterializer;
use App\Services\DynamicField\DispoOrderDynamicFieldWriter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia;
use Tests\Concerns\CreatesSavedCalculation;
use Tests\Concerns\CreatesSpotClassicCatalog;
use Tests\TestCase;

/**
 * DF-3.2a Runtime: both-Semantik, Capture inkl. NULL, native Edit, max_length.
 */
class DynamicFieldCustomHeaderRuntimeTest extends TestCase
{
    use CreatesSavedCalculation;
    use CreatesSpotClassicCatalog;
    use RefreshDatabase;

    public function test_both_only_calc_does_not_appear_in_dispo_snapshot(): void
    {
        $admin = User::factory()->role(Role::Admin)->create();
        $definition = $this->createAndActivateOnSets($admin, 'Beide Nur Calc', FieldAppliesTo::Both, calc: true, dispo: false);

        $calculation = $this->savedCalculationWithCustomValue($definition->key, 'Wert Calc');
        $user = User::factory()->role(Role::Sales)->create();
        $order = app(DispoOrderWriter::class)
            ->createFromCalculation($calculation, $calculation->positions()->pluck('id')->all(), $user)
            ->order;

        $keys = SnapshotFieldDefinition::query()
            ->where('configuration_snapshot_id', $order->configuration_snapshot_id)
            ->pluck('key')
            ->all();
        $this->assertNotContains($definition->key, $keys);
    }

    public function test_both_only_dispo_is_native_editable(): void
    {
        $admin = User::factory()->role(Role::Admin)->create();
        $definition = $this->createAndActivateOnSets($admin, 'Beide Nur Dispo', FieldAppliesTo::Both, calc: false, dispo: true);

        $calculation = $this->savedCalculation();
        $user = User::factory()->role(Role::Sales)->create();
        $order = app(DispoOrderWriter::class)
            ->createFromCalculation($calculation, $calculation->positions()->pluck('id')->all(), $user)
            ->order;

        $snapDef = SnapshotFieldDefinition::query()
            ->where('configuration_snapshot_id', $order->configuration_snapshot_id)
            ->where('key', $definition->key)
            ->firstOrFail();

        $this->assertFalse(
            DispoOrderFieldValue::query()
                ->where('dispo_order_id', $order->id)
                ->where('snapshot_field_definition_id', $snapDef->id)
                ->exists(),
        );

        $this->actingAs($user)
            ->patch(route('dispo-orders.update', $order), [
                'lock_version' => $order->lock_version,
                'dynamic_field_values' => [
                    'billing_special_features' => null,
                    'disposition_notes' => null,
                    $definition->key => 'Nativ gesetzt',
                ],
            ])
            ->assertRedirect();

        $row = DispoOrderFieldValue::query()
            ->where('dispo_order_id', $order->id)
            ->where('snapshot_field_definition_id', $snapDef->id)
            ->firstOrFail();
        $this->assertSame('Nativ gesetzt', $row->value_string);
    }

    public function test_both_calc_and_dispo_captures_value_as_read_only(): void
    {
        $admin = User::factory()->role(Role::Admin)->create();
        $definition = $this->createAndActivateOnSets($admin, 'Beide Mit Wert', FieldAppliesTo::Both, calc: true, dispo: true);

        $calculation = $this->savedCalculationWithCustomValue($definition->key, 'Aus Calc');
        $user = User::factory()->role(Role::Sales)->create();
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
        $this->assertSame('Aus Calc', $row->value_string);

        $this->actingAs($user)
            ->patch(route('dispo-orders.update', $order), [
                'lock_version' => $order->lock_version,
                'dynamic_field_values' => [
                    'billing_special_features' => null,
                    'disposition_notes' => null,
                    $definition->key => 'Hack',
                ],
            ])
            ->assertSessionHasErrors('dynamic_field_values.'.$definition->key);
    }

    public function test_both_calc_and_dispo_captures_null_when_missing(): void
    {
        $admin = User::factory()->role(Role::Admin)->create();
        $definition = $this->createAndActivateOnSets($admin, 'Beide Null', FieldAppliesTo::Both, calc: true, dispo: true);

        $calculation = $this->savedCalculation();
        $calcSnapDef = SnapshotFieldDefinition::query()
            ->where('configuration_snapshot_id', $calculation->configuration_snapshot_id)
            ->where('key', $definition->key)
            ->first();
        $this->assertNotNull($calcSnapDef);

        $user = User::factory()->role(Role::Sales)->create();
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
        $this->assertNull($row->value_string);
        $this->assertNull($row->value_text);
    }

    public function test_calculation_only_rejected_in_dispo_set_compose_guard(): void
    {
        $admin = User::factory()->role(Role::Admin)->create();
        $definition = app(FieldDefinitionCustomWriter::class)->create([
            'label' => 'Calc Only Guard',
            'field_type' => FieldType::ShortText,
            'scope' => FieldScope::Header,
            'applies_to' => FieldAppliesTo::Calculation,
        ], $admin);

        $dispoSet = FieldSet::query()->where('key', AdminFieldSetCatalog::DISPO_ORDER_CORE)->firstOrFail();
        $writer = app(FieldSetVersionAdminWriter::class);
        $draft = $writer->createDraftFromVersion(
            $dispoSet,
            FieldSetVersion::query()->whereKey($dispoSet->active_version_id)->firstOrFail(),
            $admin,
            $dispoSet->lock_version,
        );
        $dispoSet->refresh();

        $this->expectException(ValidationException::class);
        $writer->addCustomMembership($dispoSet, $draft, [
            'field_definition_id' => $definition->id,
            'field_definition_revision_id' => (int) $definition->current_revision_id,
            'sort' => 90,
            'lock_version' => $dispoSet->lock_version,
        ], $admin);
    }

    public function test_long_text_utf8mb4_20000_on_mysql(): void
    {
        if (DB::connection()->getDriverName() !== 'mysql') {
            $this->markTestSkipped('utf8mb4 20000-Kapazitätstest erfordert MySQL.');
        }

        $admin = User::factory()->role(Role::Admin)->create();
        $definition = $this->createAndActivateOnSets(
            $admin,
            'Langer Text',
            FieldAppliesTo::Calculation,
            calc: true,
            dispo: false,
            fieldType: FieldType::LongText,
            maxLength: 20000,
        );

        $text = str_repeat('🙂', 20000);
        $this->assertSame(20000, mb_strlen($text));

        $catalog = $this->createSpotClassicCatalog();
        $user = User::factory()->role(Role::Sales)->create();
        $payload = [
            'planning_mode' => 'manual',
            'schema_fingerprint' => $this->liveSchemaFingerprint(),
            'customer_name' => 'Kunde',
            'campaign' => 'C',
            'product_title' => 'P',
            'order_discount_percent' => '0',
            'ae_enabled' => false,
            'dynamic_field_values' => [
                $definition->key => $text,
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
        ];

        $calculation = app(CalculationWriter::class)->create($payload, $user);
        $snapDef = SnapshotFieldDefinition::query()
            ->where('configuration_snapshot_id', $calculation->configuration_snapshot_id)
            ->where('key', $definition->key)
            ->firstOrFail();

        $stored = CalculationFieldValue::query()
            ->where('calculation_id', $calculation->id)
            ->where('snapshot_field_definition_id', $snapDef->id)
            ->value('value_text');

        $this->assertSame($text, $stored);
    }

    public function test_unknown_custom_key_rejected_on_calculation(): void
    {
        $catalog = $this->createSpotClassicCatalog();
        $user = User::factory()->role(Role::Sales)->create();

        $this->actingAs($user)
            ->post(route('calculations.store'), $this->withLiveSchemaFingerprint([
                'planning_mode' => 'manual',
                'customer_name' => 'Kunde',
                'campaign' => 'C',
                'product_title' => 'P',
                'order_discount_percent' => '0',
                'ae_enabled' => false,
                'dynamic_field_values' => [
                    'unknown_custom' => 'x',
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
            ]))
            ->assertSessionHasErrors('dynamic_field_values.unknown_custom');
    }

    public function test_predecessor_copies_all_native_header_texts_by_key(): void
    {
        $admin = User::factory()->role(Role::Admin)->create();
        $definition = $this->createAndActivateOnSets(
            $admin,
            'Extra Dispo Text',
            FieldAppliesTo::DispoOrder,
            calc: false,
            dispo: true,
            fieldType: FieldType::LongText,
        );

        $calculation = $this->savedCalculation();
        $creator = User::factory()->role(Role::Sales)->create();
        $approver = User::factory()->role(Role::Sales)->create();
        $writer = app(DispoOrderWriter::class);
        $approvals = app(DispoOrderApprovalService::class);

        $first = $writer->createFromCalculation(
            $calculation,
            $calculation->positions()->pluck('id')->all(),
            $creator,
        )->order;

        $this->actingAs($creator)
            ->patch(route('dispo-orders.update', $first), [
                'lock_version' => $first->lock_version,
                'dynamic_field_values' => [
                    'billing_special_features' => 'Alt Rechnung',
                    'disposition_notes' => 'Alt Dispo',
                    $definition->key => 'Alt Custom',
                ],
            ])
            ->assertRedirect();

        $first->refresh();
        $approvals->submit($first, $creator, $first->lock_version);
        $first->refresh();
        $approvals->reject($first, $approver, $first->lock_version, 'Bitte nachbessern');
        $first->refresh();

        $second = $writer->createRevision(
            $first,
            $calculation->fresh(['positions', 'configurationSnapshot']),
            $calculation->positions()->pluck('id')->all(),
            $creator,
        )->order;

        $texts = [];
        foreach ([$definition->key, 'billing_special_features', 'disposition_notes'] as $key) {
            $def = SnapshotFieldDefinition::query()
                ->where('configuration_snapshot_id', $second->configuration_snapshot_id)
                ->where('key', $key)
                ->firstOrFail();
            $row = DispoOrderFieldValue::query()
                ->where('dispo_order_id', $second->id)
                ->where('snapshot_field_definition_id', $def->id)
                ->first();
            $texts[$key] = $def->field_type === FieldType::ShortText
                ? $row?->value_string
                : $row?->value_text;
        }

        $this->assertSame('Alt Rechnung', $texts['billing_special_features']);
        $this->assertSame('Alt Dispo', $texts['disposition_notes']);
        $this->assertSame('Alt Custom', $texts[$definition->key]);
    }

    public function test_calc_field_schema_pins_required_visible_from_membership_not_live(): void
    {
        $admin = User::factory()->role(Role::Admin)->create();
        $definition = $this->createAndActivateOnSets(
            $admin,
            'Pflicht Schema',
            FieldAppliesTo::Both,
            calc: true,
            dispo: true,
            requiredOverride: true,
            visibleOverride: true,
        );

        $sales = User::factory()->role(Role::Sales)->create();
        $this->actingAs($sales)
            ->get(route('calculations.create'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('fieldSchema.fields', function ($fields) use ($definition): bool {
                    $match = collect($fields)->firstWhere('key', $definition->key);

                    return is_array($match)
                        && ($match['required'] ?? false) === true
                        && ($match['visible'] ?? false) === true
                        && ($match['applies_to'] ?? null) === 'both'
                        && array_key_exists('validation_json', $match)
                        && array_key_exists('help_text', $match)
                        && array_key_exists('max_length', $match)
                        && array_key_exists('sort', $match)
                        && array_key_exists('field_type', $match);
                })
            );

        $calculation = $this->savedCalculationWithCustomValue($definition->key, 'Gespeichert');
        $this->actingAs($sales)
            ->get(route('calculations.edit', $calculation))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('fieldSchema.fields', function ($fields) use ($definition): bool {
                    $match = collect($fields)->firstWhere('key', $definition->key);

                    return is_array($match)
                        && ($match['required'] ?? false) === true
                        && ($match['visible'] ?? false) === true;
                })
            );

        $snapDef = SnapshotFieldDefinition::query()
            ->where('configuration_snapshot_id', $calculation->configuration_snapshot_id)
            ->where('key', $definition->key)
            ->firstOrFail();
        $this->assertTrue($snapDef->required);
        $this->assertTrue($snapDef->visible);
    }

    public function test_calc_required_visible_validation_matrix(): void
    {
        $admin = User::factory()->role(Role::Admin)->create();
        $required = $this->createAndActivateOnSets(
            $admin,
            'Pflicht Sichtbar',
            FieldAppliesTo::Calculation,
            calc: true,
            dispo: false,
            requiredOverride: true,
            visibleOverride: true,
        );
        $invisibleRequired = $this->createAndActivateOnSets(
            $admin,
            'Pflicht Unsichtbar',
            FieldAppliesTo::Calculation,
            calc: true,
            dispo: false,
            requiredOverride: true,
            visibleOverride: false,
        );
        $optional = $this->createAndActivateOnSets(
            $admin,
            'Optional Sichtbar',
            FieldAppliesTo::Calculation,
            calc: true,
            dispo: false,
            requiredOverride: false,
            visibleOverride: true,
        );

        $catalog = $this->createSpotClassicCatalog();
        $sales = User::factory()->role(Role::Sales)->create();
        $base = $this->withLiveSchemaFingerprint([
            'planning_mode' => 'manual',
            'customer_name' => 'Kunde',
            'campaign' => 'C',
            'product_title' => 'P',
            'order_discount_percent' => '0',
            'ae_enabled' => false,
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
        ]);

        $this->actingAs($sales)
            ->post(route('calculations.store'), [
                ...$base,
                'dynamic_field_values' => [
                    $required->key => '',
                    $invisibleRequired->key => '',
                    $optional->key => '',
                ],
            ])
            ->assertSessionHasErrors("dynamic_field_values.{$required->key}")
            ->assertSessionDoesntHaveErrors("dynamic_field_values.{$invisibleRequired->key}")
            ->assertSessionDoesntHaveErrors("dynamic_field_values.{$optional->key}");

        $this->actingAs($sales)
            ->post(route('calculations.store'), [
                ...$base,
                'dynamic_field_values' => [
                    $required->key => 'OK',
                ],
            ])
            ->assertRedirect();
    }

    public function test_dispo_partial_saves_do_not_null_other_area_or_calc_origin(): void
    {
        $admin = User::factory()->role(Role::Admin)->create();
        $both = $this->createAndActivateOnSets(
            $admin,
            'Beide Capture',
            FieldAppliesTo::Both,
            calc: true,
            dispo: true,
        );
        $native = $this->createAndActivateOnSets(
            $admin,
            'Nur Dispo Edit',
            FieldAppliesTo::DispoOrder,
            calc: false,
            dispo: true,
        );

        $calculation = $this->savedCalculationWithCustomValue($both->key, 'Calc Herkunft');
        $user = User::factory()->role(Role::Sales)->create();
        $order = app(DispoOrderWriter::class)
            ->createFromCalculation($calculation, $calculation->positions()->pluck('id')->all(), $user)
            ->order;

        $this->actingAs($user)
            ->patch(route('dispo-orders.update', $order), [
                'lock_version' => $order->lock_version,
                'dynamic_field_values' => [
                    'billing_special_features' => 'Rechnung A',
                    'disposition_notes' => 'Dispo A',
                    $native->key => 'Custom A',
                ],
            ])
            ->assertRedirect();

        $order->refresh();
        $lock = $order->lock_version;

        $this->actingAs($user)
            ->patch(route('dispo-orders.update', $order), [
                'lock_version' => $lock,
                'dynamic_field_values' => [
                    'billing_special_features' => 'Rechnung B',
                    'disposition_notes' => 'Dispo B',
                ],
            ])
            ->assertRedirect();

        $order->refresh();
        $values = app(DispoOrderDynamicFieldWriter::class)->valuesProp($order);
        $this->assertSame('Rechnung B', $values['header']['billing_special_features']);
        $this->assertSame('Dispo B', $values['header']['disposition_notes']);
        $this->assertSame('Custom A', $values['header'][$native->key]);
        $this->assertSame('Calc Herkunft', $values['header'][$both->key]);

        $this->actingAs($user)
            ->patch(route('dispo-orders.update', $order), [
                'lock_version' => $order->lock_version,
                'dynamic_field_values' => [
                    $native->key => 'Custom B',
                ],
            ])
            ->assertRedirect();

        $order->refresh();
        $values = app(DispoOrderDynamicFieldWriter::class)->valuesProp($order);
        $this->assertSame('Rechnung B', $values['header']['billing_special_features']);
        $this->assertSame('Dispo B', $values['header']['disposition_notes']);
        $this->assertSame('Custom B', $values['header'][$native->key]);
        $this->assertSame('Calc Herkunft', $values['header'][$both->key]);

        $this->actingAs($user)
            ->patch(route('dispo-orders.update', $order), [
                'lock_version' => $order->lock_version,
                'dynamic_field_values' => [
                    $both->key => 'Darf nicht',
                ],
            ])
            ->assertSessionHasErrors("dynamic_field_values.{$both->key}");
    }

    public function test_dispo_submit_requires_visible_native_custom_header(): void
    {
        $admin = User::factory()->role(Role::Admin)->create();
        $definition = $this->createAndActivateOnSets(
            $admin,
            'Dispo Pflicht',
            FieldAppliesTo::DispoOrder,
            calc: false,
            dispo: true,
            requiredOverride: true,
            visibleOverride: true,
        );

        $calculation = $this->savedCalculation();
        $user = User::factory()->role(Role::Sales)->create();
        $order = app(DispoOrderWriter::class)
            ->createFromCalculation($calculation, $calculation->positions()->pluck('id')->all(), $user)
            ->order;

        $this->actingAs($user)
            ->post(route('dispo-orders.submit', $order), [
                'lock_version' => $order->lock_version,
            ])
            ->assertSessionHasErrors("dynamic_field_values.{$definition->key}");

        $this->actingAs($user)
            ->patch(route('dispo-orders.update', $order), [
                'lock_version' => $order->lock_version,
                'dynamic_field_values' => [
                    $definition->key => 'Erfüllt',
                ],
            ])
            ->assertRedirect();

        $order->refresh();
        $this->actingAs($user)
            ->post(route('dispo-orders.submit', $order), [
                'lock_version' => $order->lock_version,
            ])
            ->assertRedirect();
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

        return app(CalculationWriter::class)->create([
            'planning_mode' => 'manual',
            'schema_fingerprint' => $this->liveSchemaFingerprint(),
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
        ], $user);
    }

    private function createAndActivateOnSets(
        User $admin,
        string $label,
        FieldAppliesTo $appliesTo,
        bool $calc,
        bool $dispo,
        FieldType $fieldType = FieldType::ShortText,
        ?int $maxLength = null,
        ?bool $requiredOverride = null,
        ?bool $visibleOverride = null,
    ): FieldDefinition {
        $definition = app(FieldDefinitionCustomWriter::class)->create([
            'label' => $label,
            'field_type' => $fieldType,
            'scope' => FieldScope::Header,
            'applies_to' => $appliesTo,
            'max_length' => $maxLength,
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

        // Ensure next calculations pick up the active set.
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
