<?php

namespace Tests\Feature\DynamicField;

use App\Enums\FieldAppliesTo;
use App\Enums\FieldScope;
use App\Enums\FieldType;
use App\Enums\Role;
use App\Models\ConfigurationSnapshot;
use App\Models\DispoOrder;
use App\Models\DispoOrderFieldValue;
use App\Models\DispoOrderPosition;
use App\Models\DispoOrderPositionFieldValue;
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
use App\Services\DynamicField\ConfigurationSnapshotMaterializer;
use App\Services\DynamicField\DispoOrderDynamicFieldWriter;
use App\Support\DynamicField\FieldDefinitionOptionContract;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\Concerns\CreatesSavedCalculation;
use Tests\Concerns\CreatesSpotClassicCatalog;
use Tests\TestCase;

/**
 * DF-3-REST-C3: Dispo-Choice-UI-Vertragsflächen (Props / Partial-Save / Submit).
 */
class ChoiceValueDispoUiC3Test extends TestCase
{
    use CreatesSavedCalculation;
    use CreatesSpotClassicCatalog;
    use RefreshDatabase;

    public function test_position_field_schemas_include_native_choice_options(): void
    {
        $admin = User::factory()->role(Role::Admin)->create();
        $definition = $this->activateChoiceOnCore(
            $admin,
            FieldType::Select,
            FieldScope::Position,
            FieldAppliesTo::DispoOrder,
            [
                ['key' => 'opt_a', 'label' => 'A', 'sort' => 1],
                ['key' => 'opt_b', 'label' => 'B', 'sort' => 2],
            ],
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

        $positionId = (int) $order->positions()->firstOrFail()->id;

        $this->actingAs($user)
            ->get(route('dispo-orders.show', $order))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where(
                    "fieldSchema.position_field_schemas.{$positionId}.editable_custom_fields",
                    function ($fields) use ($definition): bool {
                        $match = collect($fields)->firstWhere('key', $definition->key);

                        return is_array($match)
                            && $match['field_type'] === 'select'
                            && is_array($match['options_json'] ?? null)
                            && ($match['options_json'][0]['key'] ?? null) === 'opt_a';
                    },
                ));
    }

    public function test_per_position_options_json_can_diverge_in_field_schema(): void
    {
        $admin = User::factory()->role(Role::Admin)->create();
        $definition = $this->activateChoiceOnCore(
            $admin,
            FieldType::Select,
            FieldScope::Position,
            FieldAppliesTo::DispoOrder,
            [
                ['key' => 'opt_a', 'label' => 'Shared A', 'sort' => 1],
                ['key' => 'opt_b', 'label' => 'Shared B', 'sort' => 2],
            ],
            calc: false,
            dispo: true,
        );

        $catalog = $this->createSpotClassicCatalog();
        $calculation = $this->createSavedCalculation($catalog, [
            ['inventory_id' => $catalog['hamburg']->id],
            ['inventory_id' => $catalog['rock']->id, 'total_spot_count' => 5, 'hour' => 10],
        ]);
        $user = User::factory()->role(Role::Sales)->create();
        $order = app(DispoOrderWriter::class)
            ->createFromCalculation($calculation, $calculation->positions()->pluck('id')->all(), $user)
            ->order;

        $positions = $order->positions()->orderBy('id')->get();
        $this->assertCount(2, $positions);
        /** @var DispoOrderPosition $posA */
        $posA = $positions[0];
        /** @var DispoOrderPosition $posB */
        $posB = $positions[1];

        $this->ensureDistinctEffectiveSnapshot($posB);

        SnapshotFieldDefinition::query()
            ->where('configuration_snapshot_id', $posB->fresh()->effective_configuration_snapshot_id)
            ->where('key', $definition->key)
            ->update([
                'options_json' => json_encode([
                    ['key' => 'opt_x', 'label' => 'Nur B', 'sort' => 1, 'is_active' => true],
                    ['key' => 'opt_y', 'label' => 'Auch B', 'sort' => 2, 'is_active' => true],
                ], JSON_THROW_ON_ERROR),
            ]);

        $schema = app(DispoOrderDynamicFieldWriter::class)->fieldSchemaProp($order->fresh([
            'positions.effectiveConfigurationSnapshot.fieldDefinitions',
            'configurationSnapshot.fieldDefinitions',
        ]));

        $fieldsA = $schema['position_field_schemas'][(int) $posA->id]['editable_custom_fields'] ?? [];
        $fieldsB = $schema['position_field_schemas'][(int) $posB->id]['editable_custom_fields'] ?? [];
        $matchA = collect($fieldsA)->firstWhere('key', $definition->key);
        $matchB = collect($fieldsB)->firstWhere('key', $definition->key);

        $this->assertIsArray($matchA);
        $this->assertIsArray($matchB);
        $this->assertSame(['opt_a', 'opt_b'], array_column($matchA['options_json'] ?? [], 'key'));
        $this->assertSame(['opt_x', 'opt_y'], array_column($matchB['options_json'] ?? [], 'key'));
        $this->assertSame('Shared A', $matchA['options_json'][0]['label'] ?? null);
        $this->assertSame('Nur B', $matchB['options_json'][0]['label'] ?? null);

        $this->actingAs($user)
            ->get(route('dispo-orders.show', $order))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where(
                    "fieldSchema.position_field_schemas.{$posA->id}.editable_custom_fields",
                    function ($fields) use ($definition): bool {
                        $match = collect($fields)->firstWhere('key', $definition->key);

                        return is_array($match)
                            && ($match['options_json'][0]['key'] ?? null) === 'opt_a';
                    },
                )
                ->where(
                    "fieldSchema.position_field_schemas.{$posB->id}.editable_custom_fields",
                    function ($fields) use ($definition): bool {
                        $match = collect($fields)->firstWhere('key', $definition->key);

                        return is_array($match)
                            && ($match['options_json'][0]['key'] ?? null) === 'opt_x';
                    },
                ));
    }

    public function test_header_native_select_and_multi_save_reload(): void
    {
        $admin = User::factory()->role(Role::Admin)->create();
        $select = $this->activateChoiceOnCore(
            $admin,
            FieldType::Select,
            FieldScope::Header,
            FieldAppliesTo::DispoOrder,
            [
                ['key' => 'opt_a', 'label' => 'A', 'sort' => 1],
                ['key' => 'opt_b', 'label' => 'B', 'sort' => 2],
            ],
            calc: false,
            dispo: true,
        );
        $multi = $this->activateChoiceOnCore(
            $admin,
            FieldType::MultiSelect,
            FieldScope::Header,
            FieldAppliesTo::DispoOrder,
            [
                ['key' => 'opt_b', 'label' => 'B', 'sort' => 2],
                ['key' => 'opt_a', 'label' => 'A', 'sort' => 1],
            ],
            calc: false,
            dispo: true,
        );

        $order = $this->createDispoOrder();
        $user = User::factory()->role(Role::Sales)->create();

        $this->actingAs($user)
            ->patch(route('dispo-orders.update', $order), [
                'lock_version' => $order->lock_version,
                'dynamic_field_values' => [
                    $select->key => 'opt_a',
                    $multi->key => ['opt_b', 'opt_a'],
                ],
            ])
            ->assertRedirect();

        $this->actingAs($user)
            ->get(route('dispo-orders.show', $order->fresh()))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('order.dynamic_field_values.'.$select->key, 'opt_a')
                ->where('order.dynamic_field_values.'.$multi->key, ['opt_a', 'opt_b'])
                ->where('fieldSchema.editable_custom_header_fields', function ($fields) use ($select, $multi): bool {
                    $selectRow = collect($fields)->firstWhere('key', $select->key);
                    $multiRow = collect($fields)->firstWhere('key', $multi->key);

                    return is_array($selectRow)
                        && $selectRow['field_type'] === 'select'
                        && is_array($selectRow['options_json'] ?? null)
                        && is_array($multiRow)
                        && $multiRow['field_type'] === 'multi_select';
                }));
    }

    public function test_position_native_choice_save_via_update_position_customs(): void
    {
        $admin = User::factory()->role(Role::Admin)->create();
        $definition = $this->activateChoiceOnCore(
            $admin,
            FieldType::MultiSelect,
            FieldScope::Position,
            FieldAppliesTo::DispoOrder,
            [
                ['key' => 'opt_b', 'label' => 'B', 'sort' => 2],
                ['key' => 'opt_a', 'label' => 'A', 'sort' => 1],
            ],
            calc: false,
            dispo: true,
        );

        $order = $this->createDispoOrder();
        $user = User::factory()->role(Role::Sales)->create();
        $positionId = (int) $order->positions()->firstOrFail()->id;

        $this->actingAs($user)
            ->patch(route('dispo-orders.update-position-customs', $order), [
                'lock_version' => $order->lock_version,
                'position_dynamic_field_values' => [
                    $positionId => [
                        $definition->key => ['opt_b', 'opt_a'],
                    ],
                ],
            ])
            ->assertRedirect();

        $snap = $this->dispoPositionSnapDef($order->positions()->firstOrFail(), $definition->key);
        $row = DispoOrderPositionFieldValue::query()
            ->where('dispo_order_position_id', $positionId)
            ->where('snapshot_field_definition_id', $snap->id)
            ->firstOrFail();
        $this->assertSame(['opt_a', 'opt_b'], $row->value_json);

        $this->actingAs($user)
            ->get(route('dispo-orders.show', $order->fresh()))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where(
                    "order.positions.0.dynamic_field_values.{$definition->key}",
                    ['opt_a', 'opt_b'],
                ));
    }

    public function test_partial_save_missing_key_keeps_value_and_touched_only(): void
    {
        $admin = User::factory()->role(Role::Admin)->create();
        $select = $this->activateChoiceOnCore(
            $admin,
            FieldType::Select,
            FieldScope::Header,
            FieldAppliesTo::DispoOrder,
            [
                ['key' => 'opt_a', 'label' => 'A', 'sort' => 1],
                ['key' => 'opt_b', 'label' => 'B', 'sort' => 2],
            ],
            calc: false,
            dispo: true,
        );
        $multi = $this->activateChoiceOnCore(
            $admin,
            FieldType::MultiSelect,
            FieldScope::Header,
            FieldAppliesTo::DispoOrder,
            [
                ['key' => 'opt_a', 'label' => 'A', 'sort' => 1],
                ['key' => 'opt_b', 'label' => 'B', 'sort' => 2],
            ],
            calc: false,
            dispo: true,
        );

        $order = $this->createDispoOrder();
        $user = User::factory()->role(Role::Sales)->create();

        $this->actingAs($user)
            ->patch(route('dispo-orders.update', $order), [
                'lock_version' => $order->lock_version,
                'dynamic_field_values' => [
                    $select->key => 'opt_a',
                    $multi->key => ['opt_a', 'opt_b'],
                ],
            ])
            ->assertRedirect();

        $order->refresh();
        $this->actingAs($user)
            ->patch(route('dispo-orders.update', $order), [
                'lock_version' => $order->lock_version,
                'dynamic_field_values' => [
                    $select->key => 'opt_b',
                ],
            ])
            ->assertRedirect();

        $selectSnap = $this->dispoHeaderSnapDef($order, $select->key);
        $multiSnap = $this->dispoHeaderSnapDef($order, $multi->key);
        $selectRow = DispoOrderFieldValue::query()
            ->where('dispo_order_id', $order->id)
            ->where('snapshot_field_definition_id', $selectSnap->id)
            ->firstOrFail();
        $multiRow = DispoOrderFieldValue::query()
            ->where('dispo_order_id', $order->id)
            ->where('snapshot_field_definition_id', $multiSnap->id)
            ->firstOrFail();
        $this->assertSame('opt_b', $selectRow->value_json);
        $this->assertSame(['opt_a', 'opt_b'], $multiRow->value_json);
    }

    public function test_explicit_clear_select_null_and_multi_empty(): void
    {
        $admin = User::factory()->role(Role::Admin)->create();
        $select = $this->activateChoiceOnCore(
            $admin,
            FieldType::Select,
            FieldScope::Header,
            FieldAppliesTo::DispoOrder,
            [
                ['key' => 'opt_a', 'label' => 'A', 'sort' => 1],
            ],
            calc: false,
            dispo: true,
        );
        $multi = $this->activateChoiceOnCore(
            $admin,
            FieldType::MultiSelect,
            FieldScope::Header,
            FieldAppliesTo::DispoOrder,
            [
                ['key' => 'opt_a', 'label' => 'A', 'sort' => 1],
            ],
            calc: false,
            dispo: true,
        );

        $order = $this->createDispoOrder();
        $user = User::factory()->role(Role::Sales)->create();

        $this->actingAs($user)
            ->patch(route('dispo-orders.update', $order), [
                'lock_version' => $order->lock_version,
                'dynamic_field_values' => [
                    $select->key => 'opt_a',
                    $multi->key => ['opt_a'],
                ],
            ])
            ->assertRedirect();

        $order->refresh();
        $this->actingAs($user)
            ->patch(route('dispo-orders.update', $order), [
                'lock_version' => $order->lock_version,
                'dynamic_field_values' => [
                    $select->key => null,
                    $multi->key => [],
                ],
            ])
            ->assertRedirect();

        $selectSnap = $this->dispoHeaderSnapDef($order, $select->key);
        $multiSnap = $this->dispoHeaderSnapDef($order, $multi->key);
        $selectRow = DispoOrderFieldValue::query()
            ->where('dispo_order_id', $order->id)
            ->where('snapshot_field_definition_id', $selectSnap->id)
            ->firstOrFail();
        $multiRow = DispoOrderFieldValue::query()
            ->where('dispo_order_id', $order->id)
            ->where('snapshot_field_definition_id', $multiSnap->id)
            ->firstOrFail();
        $this->assertNull($selectRow->value_json);
        $this->assertSame([], $multiRow->value_json);
    }

    public function test_unknown_option_key_and_invalid_payload_form_422(): void
    {
        $admin = User::factory()->role(Role::Admin)->create();
        $select = $this->activateChoiceOnCore(
            $admin,
            FieldType::Select,
            FieldScope::Header,
            FieldAppliesTo::DispoOrder,
            [
                ['key' => 'opt_a', 'label' => 'A', 'sort' => 1],
            ],
            calc: false,
            dispo: true,
        );
        $multi = $this->activateChoiceOnCore(
            $admin,
            FieldType::MultiSelect,
            FieldScope::Header,
            FieldAppliesTo::DispoOrder,
            [
                ['key' => 'opt_a', 'label' => 'A', 'sort' => 1],
            ],
            calc: false,
            dispo: true,
        );

        $order = $this->createDispoOrder();
        $user = User::factory()->role(Role::Sales)->create();

        $this->actingAs($user)
            ->patch(route('dispo-orders.update', $order), [
                'lock_version' => $order->lock_version,
                'dynamic_field_values' => [
                    $select->key => 'nope',
                ],
            ])
            ->assertSessionHasErrors('dynamic_field_values.'.$select->key);

        $this->actingAs($user)
            ->patch(route('dispo-orders.update', $order), [
                'lock_version' => $order->lock_version,
                'dynamic_field_values' => [
                    $select->key => ['opt_a'],
                ],
            ])
            ->assertSessionHasErrors('dynamic_field_values.'.$select->key);

        $this->actingAs($user)
            ->patch(route('dispo-orders.update', $order), [
                'lock_version' => $order->lock_version,
                'dynamic_field_values' => [
                    $multi->key => 'opt_a',
                ],
            ])
            ->assertSessionHasErrors('dynamic_field_values.'.$multi->key);
    }

    public function test_multi_over_fifty_selected_422(): void
    {
        $admin = User::factory()->role(Role::Admin)->create();
        $options = [];
        $keys = [];
        $limit = FieldDefinitionOptionContract::MAX_MULTI_SELECT_SELECTED;
        for ($i = 0; $i <= $limit; $i++) {
            $key = sprintf('k%02d', $i);
            $keys[] = $key;
            $options[] = ['key' => $key, 'label' => "L{$i}", 'sort' => $i + 1];
        }
        $definition = $this->activateChoiceOnCore(
            $admin,
            FieldType::MultiSelect,
            FieldScope::Header,
            FieldAppliesTo::DispoOrder,
            $options,
            calc: false,
            dispo: true,
        );

        $order = $this->createDispoOrder();
        $user = User::factory()->role(Role::Sales)->create();

        $this->actingAs($user)
            ->patch(route('dispo-orders.update', $order), [
                'lock_version' => $order->lock_version,
                'dynamic_field_values' => [
                    $definition->key => $keys,
                ],
            ])
            ->assertSessionHasErrors('dynamic_field_values.'.$definition->key);
    }

    public function test_inactive_keep_remove_and_readd_rejected_on_dispo_header(): void
    {
        $admin = User::factory()->role(Role::Admin)->create();
        $select = $this->activateChoiceOnCore(
            $admin,
            FieldType::Select,
            FieldScope::Header,
            FieldAppliesTo::DispoOrder,
            [
                ['key' => 'opt_a', 'label' => 'A', 'sort' => 1],
                ['key' => 'opt_b', 'label' => 'B', 'sort' => 2],
            ],
            calc: false,
            dispo: true,
        );
        $multi = $this->activateChoiceOnCore(
            $admin,
            FieldType::MultiSelect,
            FieldScope::Header,
            FieldAppliesTo::DispoOrder,
            [
                ['key' => 'opt_a', 'label' => 'A', 'sort' => 1],
                ['key' => 'opt_b', 'label' => 'B', 'sort' => 2],
            ],
            calc: false,
            dispo: true,
        );

        $order = $this->createDispoOrder();
        $user = User::factory()->role(Role::Sales)->create();

        $this->actingAs($user)
            ->patch(route('dispo-orders.update', $order), [
                'lock_version' => $order->lock_version,
                'dynamic_field_values' => [
                    $select->key => 'opt_b',
                    $multi->key => ['opt_b', 'opt_a'],
                ],
            ])
            ->assertRedirect();

        $inactiveOptions = json_encode([
            ['key' => 'opt_a', 'label' => 'A', 'sort' => 1, 'is_active' => true],
            ['key' => 'opt_b', 'label' => 'B', 'sort' => 2, 'is_active' => false],
        ], JSON_THROW_ON_ERROR);

        SnapshotFieldDefinition::query()
            ->where('configuration_snapshot_id', $order->configuration_snapshot_id)
            ->whereIn('key', [$select->key, $multi->key])
            ->update(['options_json' => $inactiveOptions]);

        $order->refresh();
        $this->actingAs($user)
            ->patch(route('dispo-orders.update', $order), [
                'lock_version' => $order->lock_version,
                'dynamic_field_values' => [
                    $select->key => 'opt_b',
                    $multi->key => ['opt_b', 'opt_a'],
                ],
            ])
            ->assertRedirect();

        $order->refresh();
        $this->actingAs($user)
            ->patch(route('dispo-orders.update', $order), [
                'lock_version' => $order->lock_version,
                'dynamic_field_values' => [
                    $select->key => 'opt_a',
                    $multi->key => ['opt_a'],
                ],
            ])
            ->assertRedirect();

        $order->refresh();
        $this->actingAs($user)
            ->patch(route('dispo-orders.update', $order), [
                'lock_version' => $order->lock_version,
                'dynamic_field_values' => [
                    $select->key => 'opt_b',
                ],
            ])
            ->assertSessionHasErrors('dynamic_field_values.'.$select->key);

        $this->actingAs($user)
            ->patch(route('dispo-orders.update', $order), [
                'lock_version' => $order->lock_version,
                'dynamic_field_values' => [
                    $multi->key => ['opt_a', 'opt_b'],
                ],
            ])
            ->assertSessionHasErrors('dynamic_field_values.'.$multi->key);
    }

    public function test_calc_origin_choice_manipulation_rejected_and_props_include_options(): void
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
        $calculation = app(CalculationWriter::class)->create($this->withLiveSchemaFingerprint([
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

        $this->actingAs($user)
            ->get(route('dispo-orders.show', $order))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('fieldSchema.calc_origin_custom_header_fields', function ($fields) use ($definition): bool {
                    $match = collect($fields)->firstWhere('key', $definition->key);

                    return is_array($match)
                        && $match['field_type'] === 'select'
                        && is_array($match['options_json'] ?? null)
                        && ($match['options_json'][0]['key'] ?? null) === 'opt_a';
                })
                ->where('order.dynamic_field_values.'.$definition->key, 'opt_a'));

        $this->actingAs($user)
            ->patch(route('dispo-orders.update', $order), [
                'lock_version' => $order->lock_version,
                'dynamic_field_values' => [
                    $definition->key => 'opt_b',
                ],
            ])
            ->assertSessionHasErrors('dynamic_field_values.'.$definition->key);
    }

    public function test_historically_missing_calc_origin_custom_does_not_block_submit(): void
    {
        $admin = User::factory()->role(Role::Admin)->create();
        $calcOrigin = $this->activateChoiceOnCore(
            $admin,
            FieldType::Select,
            FieldScope::Header,
            FieldAppliesTo::Both,
            [
                ['key' => 'opt_a', 'label' => 'A', 'sort' => 1],
            ],
        );
        $native = $this->activateChoiceOnCore(
            $admin,
            FieldType::Select,
            FieldScope::Header,
            FieldAppliesTo::DispoOrder,
            [
                ['key' => 'opt_n', 'label' => 'N', 'sort' => 1],
            ],
            requiredOverride: true,
            visibleOverride: true,
            calc: false,
            dispo: true,
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
                $calcOrigin->key => 'opt_a',
            ],
            'positions' => [$this->positionPayload($catalog)],
        ]), $user);

        $order = app(DispoOrderWriter::class)
            ->createFromCalculation($calculation, $calculation->positions()->pluck('id')->all(), $user)
            ->order;

        $originSnap = $this->dispoHeaderSnapDef($order, $calcOrigin->key);
        DispoOrderFieldValue::query()
            ->where('dispo_order_id', $order->id)
            ->where('snapshot_field_definition_id', $originSnap->id)
            ->delete();

        $this->actingAs($user)
            ->patch(route('dispo-orders.update', $order), [
                'lock_version' => $order->lock_version,
                'dynamic_field_values' => [
                    $native->key => 'opt_n',
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

    public function test_disposition_cannot_patch_choice_403(): void
    {
        $admin = User::factory()->role(Role::Admin)->create();
        $definition = $this->activateChoiceOnCore(
            $admin,
            FieldType::Select,
            FieldScope::Header,
            FieldAppliesTo::DispoOrder,
            [
                ['key' => 'opt_a', 'label' => 'A', 'sort' => 1],
            ],
            calc: false,
            dispo: true,
        );

        $sales = User::factory()->role(Role::Sales)->create();
        $disposition = User::factory()->role(Role::Disposition)->create();
        $catalog = $this->createSpotClassicCatalog();
        $calculation = $this->createSavedCalculation($catalog, [
            ['inventory_id' => $catalog['hamburg']->id],
        ]);
        $order = app(DispoOrderWriter::class)
            ->createFromCalculation($calculation, $calculation->positions()->pluck('id')->all(), $sales)
            ->order;

        $this->actingAs($disposition)
            ->patch(route('dispo-orders.update', $order), [
                'lock_version' => $order->lock_version,
                'dynamic_field_values' => [
                    $definition->key => 'opt_a',
                ],
            ])
            ->assertForbidden();
    }

    public function test_stale_lock_version_on_choice_update_409(): void
    {
        $admin = User::factory()->role(Role::Admin)->create();
        $definition = $this->activateChoiceOnCore(
            $admin,
            FieldType::Select,
            FieldScope::Header,
            FieldAppliesTo::DispoOrder,
            [
                ['key' => 'opt_a', 'label' => 'A', 'sort' => 1],
                ['key' => 'opt_b', 'label' => 'B', 'sort' => 2],
            ],
            calc: false,
            dispo: true,
        );

        $order = $this->createDispoOrder();
        $user = User::factory()->role(Role::Sales)->create();

        $this->actingAs($user)
            ->patch(route('dispo-orders.update', $order), [
                'lock_version' => $order->lock_version,
                'dynamic_field_values' => [
                    $definition->key => 'opt_a',
                ],
            ])
            ->assertRedirect();

        $stale = $order->lock_version;
        $order->refresh();

        $this->actingAs($user)
            ->patch(route('dispo-orders.update', $order), [
                'lock_version' => $stale,
                'dynamic_field_values' => [
                    $definition->key => 'opt_b',
                ],
            ])
            ->assertStatus(409);

        $snap = $this->dispoHeaderSnapDef($order, $definition->key);
        $row = DispoOrderFieldValue::query()
            ->where('dispo_order_id', $order->id)
            ->where('snapshot_field_definition_id', $snap->id)
            ->firstOrFail();
        $this->assertSame('opt_a', $row->value_json);
    }

    public function test_damaged_options_json_fail_closed_on_patch(): void
    {
        $admin = User::factory()->role(Role::Admin)->create();
        $definition = $this->activateChoiceOnCore(
            $admin,
            FieldType::Select,
            FieldScope::Header,
            FieldAppliesTo::DispoOrder,
            [
                ['key' => 'opt_a', 'label' => 'A', 'sort' => 1],
            ],
            calc: false,
            dispo: true,
        );

        $order = $this->createDispoOrder();
        $user = User::factory()->role(Role::Sales)->create();

        SnapshotFieldDefinition::query()
            ->where('configuration_snapshot_id', $order->configuration_snapshot_id)
            ->where('key', $definition->key)
            ->update([
                'options_json' => json_encode(['broken' => true], JSON_THROW_ON_ERROR),
            ]);

        // Bestehender Integrity-Vertrag: beschädigtes options_json macht den
        // Snapshot unlesbar (assertReadable) – fail-closed, keine Mutation.
        $this->actingAs($user)
            ->patch(route('dispo-orders.update', $order), [
                'lock_version' => $order->lock_version,
                'dynamic_field_values' => [
                    $definition->key => 'opt_a',
                ],
            ])
            ->assertStatus(500);

        $this->assertNull(
            DispoOrderFieldValue::query()
                ->where('dispo_order_id', $order->id)
                ->whereHas('snapshotFieldDefinition', fn ($q) => $q->where('key', $definition->key))
                ->first(),
        );
        $this->assertSame(
            (int) $order->lock_version,
            (int) $order->fresh()->lock_version,
        );
    }

    public function test_required_native_empty_choice_blocks_submit(): void
    {
        $admin = User::factory()->role(Role::Admin)->create();
        $definition = $this->activateChoiceOnCore(
            $admin,
            FieldType::Select,
            FieldScope::Header,
            FieldAppliesTo::DispoOrder,
            [
                ['key' => 'opt_a', 'label' => 'A', 'sort' => 1],
            ],
            requiredOverride: true,
            visibleOverride: true,
            calc: false,
            dispo: true,
        );

        $order = $this->createDispoOrder();
        $user = User::factory()->role(Role::Sales)->create();

        $this->actingAs($user)
            ->post(route('dispo-orders.submit', $order), [
                'lock_version' => $order->lock_version,
            ])
            ->assertSessionHasErrors('dynamic_field_values.'.$definition->key);

        $this->actingAs($user)
            ->patch(route('dispo-orders.update', $order), [
                'lock_version' => $order->lock_version,
                'dynamic_field_values' => [
                    $definition->key => 'opt_a',
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
            'label' => 'Choice C3 '.$fieldType->value.' '.bin2hex(random_bytes(2)),
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

    private function createDispoOrder(): DispoOrder
    {
        $catalog = $this->createSpotClassicCatalog();
        $calculation = $this->createSavedCalculation($catalog, [
            ['inventory_id' => $catalog['hamburg']->id],
        ]);
        $user = User::factory()->role(Role::Sales)->create();

        return app(DispoOrderWriter::class)
            ->createFromCalculation($calculation, $calculation->positions()->pluck('id')->all(), $user)
            ->order;
    }

    private function dispoHeaderSnapDef(DispoOrder $order, string $key): SnapshotFieldDefinition
    {
        return SnapshotFieldDefinition::query()
            ->where('configuration_snapshot_id', $order->configuration_snapshot_id)
            ->where('key', $key)
            ->firstOrFail();
    }

    private function dispoPositionSnapDef(DispoOrderPosition $position, string $key): SnapshotFieldDefinition
    {
        $snapshotId = $position->effective_configuration_snapshot_id
            ?? $position->dispoOrder?->configuration_snapshot_id;

        return SnapshotFieldDefinition::query()
            ->where('configuration_snapshot_id', $snapshotId)
            ->where('key', $key)
            ->firstOrFail();
    }

    private function ensureDistinctEffectiveSnapshot(DispoOrderPosition $position): void
    {
        $sourceId = (int) $position->effective_configuration_snapshot_id;
        $siblings = DispoOrderPosition::query()
            ->where('dispo_order_id', $position->dispo_order_id)
            ->where('effective_configuration_snapshot_id', $sourceId)
            ->count();
        if ($siblings <= 1) {
            return;
        }

        $source = ConfigurationSnapshot::query()->findOrFail($sourceId);
        $clone = $source->replicate();
        $clone->created_at = now();
        $clone->save();

        foreach (SnapshotFieldDefinition::query()->where('configuration_snapshot_id', $sourceId)->get() as $def) {
            $copy = $def->replicate();
            $copy->configuration_snapshot_id = $clone->id;
            $copy->save();
        }

        $position->forceFill(['effective_configuration_snapshot_id' => $clone->id])->save();
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
