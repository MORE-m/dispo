<?php

namespace Tests\Feature\DynamicField;

use App\Enums\FieldAppliesTo;
use App\Enums\FieldScope;
use App\Enums\FieldType;
use App\Enums\PlanningMode;
use App\Enums\Role;
use App\Models\Calculation;
use App\Models\CalculationPositionFieldValue;
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
use App\Services\DynamicField\Admin\FieldSetVersionAdminWriter;
use App\Services\DynamicField\ConfigurationSnapshotMaterializer;
use App\Services\DynamicField\DispoOrderDynamicFieldWriter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia;
use Tests\Concerns\CreatesSavedCalculation;
use Tests\Concerns\CreatesSpotClassicCatalog;
use Tests\TestCase;

/**
 * DF-3.2b Runtime: Position-Custom-Text, Identity, Capture, Submit.
 */
class DynamicFieldCustomPositionRuntimeTest extends TestCase
{
    use CreatesSavedCalculation;
    use CreatesSpotClassicCatalog;
    use RefreshDatabase;

    public function test_reorder_positions_keeps_values_by_client_key(): void
    {
        $admin = User::factory()->role(Role::Admin)->create();
        $definition = $this->createAndActivateOnSets(
            $admin,
            'Pos Hinweis',
            FieldAppliesTo::Calculation,
            calc: true,
            dispo: false,
            scope: FieldScope::Position,
            requiredOverride: false,
        );

        $catalog = $this->createSpotClassicCatalog();
        $user = User::factory()->role(Role::Sales)->create();
        $keyA = (string) Str::uuid();
        $keyB = (string) Str::uuid();

        $calculation = app(CalculationWriter::class)->create([
            'planning_mode' => 'manual',
            'customer_name' => 'Kunde',
            'campaign' => 'C',
            'product_title' => 'P',
            'order_discount_percent' => '0',
            'ae_enabled' => false,
            'positions' => [
                $this->positionPayload($catalog, $catalog['hamburg']->id, $keyA, [
                    $definition->key => 'Wert A',
                ]),
                $this->positionPayload($catalog, $catalog['rock']->id, $keyB, [
                    $definition->key => 'Wert B',
                ]),
            ],
        ], $user);

        $positions = $calculation->positions()->orderBy('sort')->get();
        $this->assertCount(2, $positions);
        $storedKeyA = (string) $positions[0]->client_key;
        $storedKeyB = (string) $positions[1]->client_key;
        $idA = (int) $positions[0]->id;
        $idB = (int) $positions[1]->id;

        $snapDef = SnapshotFieldDefinition::query()
            ->where('configuration_snapshot_id', $calculation->configuration_snapshot_id)
            ->where('key', $definition->key)
            ->firstOrFail();

        $valueFor = function ($position) use ($snapDef): ?string {
            return CalculationPositionFieldValue::query()
                ->where('calculation_position_id', $position->id)
                ->where('snapshot_field_definition_id', $snapDef->id)
                ->value('value_string');
        };

        $this->assertSame('Wert A', $valueFor($positions[0]));
        $this->assertSame('Wert B', $valueFor($positions[1]));

        $update = [
            'planning_mode' => 'manual',
            'customer_name' => 'Kunde',
            'campaign' => 'C',
            'product_title' => 'P',
            'order_discount_percent' => '0',
            'ae_enabled' => false,
            'lock_version' => $calculation->lock_version,
            'positions' => [
                $this->positionPayload($catalog, $catalog['rock']->id, $storedKeyB, [
                    $definition->key => 'Wert B',
                ], $idB),
                $this->positionPayload($catalog, $catalog['hamburg']->id, $storedKeyA, [
                    $definition->key => 'Wert A',
                ], $idA),
            ],
        ];

        $fresh = app(CalculationWriter::class)->update($calculation, $update, $user);
        $reordered = $fresh->positions()->orderBy('sort')->get();
        $this->assertSame($storedKeyB, $reordered[0]->client_key);
        $this->assertSame($storedKeyA, $reordered[1]->client_key);
        $this->assertSame('Wert B', $valueFor($reordered[0]));
        $this->assertSame('Wert A', $valueFor($reordered[1]));
    }

    public function test_budget_apply_with_empty_required_position_custom_succeeds(): void
    {
        $admin = User::factory()->role(Role::Admin)->create();
        $this->createAndActivateOnSets(
            $admin,
            'Budget Pos Pflicht',
            FieldAppliesTo::Calculation,
            calc: true,
            dispo: false,
            scope: FieldScope::Position,
            requiredOverride: true,
            visibleOverride: true,
        );

        $catalog = $this->createSpotClassicCatalog();
        $user = User::factory()->role(Role::Sales)->create();

        $this->actingAs($user)->post(route('calculations.store'), [
            'planning_mode' => PlanningMode::Budget->value,
            'target_budget_nn' => '500',
            'order_discount_percent' => '0',
            'order_discounts' => [],
            'ae_enabled' => false,
            'positions' => [],
        ])->assertRedirect();

        $calculation = Calculation::query()->firstOrFail();

        $propose = $this->actingAs($user)->postJson(route('calculations.budget-propose'), [
            'planning_mode' => PlanningMode::Budget->value,
            'target_budget_nn' => '500',
            'budget_wish_inventory_ids' => [
                $catalog['hamburg']->id,
            ],
            'budget_spot_length_seconds' => 30,
            'budget_distribution_ranges' => [
                [
                    'start_hour' => 8,
                    'end_hour_exclusive' => 12,
                    'day_group' => 'mo_fr',
                ],
            ],
            'order_discount_percent' => '0',
            'order_discounts' => [],
            'ae_enabled' => false,
            'positions' => [],
            'calculation_id' => $calculation->id,
        ]);
        $propose->assertOk();
        $proposalId = $propose->json('proposal.id');
        $this->assertNotNull($proposalId);

        $this->actingAs($user)
            ->post(route('calculations.budget-apply', [
                'calculation' => $calculation,
                'proposal' => $proposalId,
            ]))
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $calculation->refresh();
        $this->assertGreaterThan(0, $calculation->positions()->count());
    }

    public function test_dispo_create_requires_position_custom_from_calc(): void
    {
        $admin = User::factory()->role(Role::Admin)->create();
        $definition = $this->createAndActivateOnSets(
            $admin,
            'Pflicht Pos',
            FieldAppliesTo::Both,
            calc: true,
            dispo: true,
            scope: FieldScope::Position,
            requiredOverride: true,
            visibleOverride: true,
        );

        $catalog = $this->createSpotClassicCatalog();
        $user = User::factory()->role(Role::Sales)->create();

        $empty = app(CalculationWriter::class)->create([
            'planning_mode' => 'manual',
            'customer_name' => 'Kunde',
            'campaign' => 'C',
            'product_title' => 'P',
            'order_discount_percent' => '0',
            'ae_enabled' => false,
            'positions' => [
                $this->positionPayload($catalog, $catalog['hamburg']->id, (string) Str::uuid(), [
                    $definition->key => null,
                ]),
            ],
        ], $user);

        try {
            app(DispoOrderWriter::class)->createFromCalculation(
                $empty,
                $empty->positions()->pluck('id')->all(),
                $user,
            );
            $this->fail('Expected ValidationException for empty required position custom.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey(
                'positions.0.dynamic_field_values.'.$definition->key,
                $exception->errors(),
            );
        }

        $filled = app(CalculationWriter::class)->create([
            'planning_mode' => 'manual',
            'customer_name' => 'Kunde',
            'campaign' => 'C',
            'product_title' => 'P',
            'order_discount_percent' => '0',
            'ae_enabled' => false,
            'positions' => [
                $this->positionPayload($catalog, $catalog['hamburg']->id, (string) Str::uuid(), [
                    $definition->key => 'Erfüllt',
                ]),
            ],
        ], $user);

        $order = app(DispoOrderWriter::class)
            ->createFromCalculation($filled, $filled->positions()->pluck('id')->all(), $user)
            ->order;

        $snapDef = SnapshotFieldDefinition::query()
            ->where('configuration_snapshot_id', $order->configuration_snapshot_id)
            ->where('key', $definition->key)
            ->firstOrFail();
        $row = DispoOrderPositionFieldValue::query()
            ->where('dispo_order_position_id', $order->positions()->firstOrFail()->id)
            ->where('snapshot_field_definition_id', $snapDef->id)
            ->firstOrFail();
        $this->assertSame('Erfüllt', $row->value_string);
    }

    public function test_both_capture_with_null_creates_row(): void
    {
        $admin = User::factory()->role(Role::Admin)->create();
        $definition = $this->createAndActivateOnSets(
            $admin,
            'Beide Pos Null',
            FieldAppliesTo::Both,
            calc: true,
            dispo: true,
            scope: FieldScope::Position,
        );

        $calculation = $this->savedCalculation();
        $user = User::factory()->role(Role::Sales)->create();
        $order = app(DispoOrderWriter::class)
            ->createFromCalculation($calculation, $calculation->positions()->pluck('id')->all(), $user)
            ->order;

        $snapDef = SnapshotFieldDefinition::query()
            ->where('configuration_snapshot_id', $order->configuration_snapshot_id)
            ->where('key', $definition->key)
            ->firstOrFail();

        $row = DispoOrderPositionFieldValue::query()
            ->where('dispo_order_position_id', $order->positions()->firstOrFail()->id)
            ->where('snapshot_field_definition_id', $snapDef->id)
            ->firstOrFail();
        $this->assertNull($row->value_string);
        $this->assertNull($row->value_text);
    }

    public function test_after_deleting_calc_position_schema_and_native_save(): void
    {
        $admin = User::factory()->role(Role::Admin)->create();
        $calcOrigin = $this->createAndActivateOnSets(
            $admin,
            'Calc Origin Pos',
            FieldAppliesTo::Both,
            calc: true,
            dispo: true,
            scope: FieldScope::Position,
        );
        $native = $this->createAndActivateOnSets(
            $admin,
            'Native Pos',
            FieldAppliesTo::DispoOrder,
            calc: false,
            dispo: true,
            scope: FieldScope::Position,
        );

        $catalog = $this->createSpotClassicCatalog();
        $user = User::factory()->role(Role::Sales)->create();
        $keyKeep = (string) Str::uuid();
        $keyDrop = (string) Str::uuid();

        $calculation = app(CalculationWriter::class)->create([
            'planning_mode' => 'manual',
            'customer_name' => 'Kunde',
            'campaign' => 'C',
            'product_title' => 'P',
            'order_discount_percent' => '0',
            'ae_enabled' => false,
            'positions' => [
                $this->positionPayload($catalog, $catalog['hamburg']->id, $keyKeep, [
                    $calcOrigin->key => 'Keep',
                ]),
                $this->positionPayload($catalog, $catalog['rock']->id, $keyDrop, [
                    $calcOrigin->key => 'Drop',
                ]),
            ],
        ], $user);

        $positions = $calculation->positions()->orderBy('sort')->get();
        $keepId = $positions[0]->id;

        $calculation = app(CalculationWriter::class)->update($calculation, [
            'planning_mode' => 'manual',
            'customer_name' => 'Kunde',
            'campaign' => 'C',
            'product_title' => 'P',
            'order_discount_percent' => '0',
            'ae_enabled' => false,
            'lock_version' => $calculation->lock_version,
            'positions' => [
                $this->positionPayload($catalog, $catalog['hamburg']->id, $keyKeep, [
                    $calcOrigin->key => 'Keep',
                ], $keepId),
            ],
        ], $user);

        $order = app(DispoOrderWriter::class)
            ->createFromCalculation($calculation, $calculation->positions()->pluck('id')->all(), $user)
            ->order;

        $this->actingAs($user)
            ->get(route('dispo-orders.show', $order))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('fieldSchema.calc_origin_custom_position_fields', function ($fields) use ($calcOrigin): bool {
                    return collect($fields)->contains(fn ($f) => ($f['key'] ?? null) === $calcOrigin->key);
                })
                ->where('fieldSchema.editable_custom_position_fields', function ($fields) use ($native): bool {
                    return collect($fields)->contains(fn ($f) => ($f['key'] ?? null) === $native->key);
                })
            );

        $dispoPositionId = (int) $order->positions()->firstOrFail()->id;
        $this->actingAs($user)
            ->patch(route('dispo-orders.update-position-customs', $order), [
                'lock_version' => $order->lock_version,
                'position_dynamic_field_values' => [
                    $dispoPositionId => [
                        $native->key => 'Nativ OK',
                    ],
                ],
            ])
            ->assertRedirect();

        $order->refresh();
        $nativeSnap = SnapshotFieldDefinition::query()
            ->where('configuration_snapshot_id', $order->configuration_snapshot_id)
            ->where('key', $native->key)
            ->firstOrFail();
        $this->assertSame(
            1,
            DispoOrderPositionFieldValue::query()
                ->where('dispo_order_position_id', $dispoPositionId)
                ->where('snapshot_field_definition_id', $nativeSnap->id)
                ->count(),
        );
        $this->assertSame(
            'Nativ OK',
            DispoOrderPositionFieldValue::query()
                ->where('dispo_order_position_id', $dispoPositionId)
                ->where('snapshot_field_definition_id', $nativeSnap->id)
                ->value('value_string'),
        );

        $this->actingAs($user)
            ->patch(route('dispo-orders.update-position-customs', $order), [
                'lock_version' => $order->lock_version,
                'position_dynamic_field_values' => [
                    $dispoPositionId => [
                        $calcOrigin->key => 'Hack',
                    ],
                ],
            ])
            ->assertSessionHasErrors('position_dynamic_field_values.'.$dispoPositionId.'.'.$calcOrigin->key);
    }

    public function test_submit_requires_native_position_custom(): void
    {
        $admin = User::factory()->role(Role::Admin)->create();
        $definition = $this->createAndActivateOnSets(
            $admin,
            'Submit Pos Pflicht',
            FieldAppliesTo::DispoOrder,
            calc: false,
            dispo: true,
            scope: FieldScope::Position,
            requiredOverride: true,
            visibleOverride: true,
        );

        $calculation = $this->savedCalculation();
        $user = User::factory()->role(Role::Sales)->create();
        $order = app(DispoOrderWriter::class)
            ->createFromCalculation($calculation, $calculation->positions()->pluck('id')->all(), $user)
            ->order;

        try {
            app(DispoOrderDynamicFieldWriter::class)->assertReadyForSubmit($order);
            $this->fail('Expected ValidationException for missing native position custom.');
        } catch (ValidationException $exception) {
            $positionId = (int) $order->positions()->firstOrFail()->id;
            $this->assertArrayHasKey(
                "position_dynamic_field_values.{$positionId}.{$definition->key}",
                $exception->errors(),
            );
        }

        $positionId = (int) $order->positions()->firstOrFail()->id;
        $this->actingAs($user)
            ->patch(route('dispo-orders.update-position-customs', $order), [
                'lock_version' => $order->lock_version,
                'position_dynamic_field_values' => [
                    $positionId => [
                        $definition->key => 'Bereit',
                    ],
                ],
            ])
            ->assertRedirect();

        $order->refresh();
        app(DispoOrderDynamicFieldWriter::class)->assertReadyForSubmit($order);
        $this->assertTrue(true);
    }

    public function test_long_text_utf8mb4_20000_on_mysql_position(): void
    {
        if (DB::connection()->getDriverName() !== 'mysql') {
            $this->markTestSkipped('utf8mb4 20000-Kapazitätstest erfordert MySQL.');
        }

        $admin = User::factory()->role(Role::Admin)->create();
        $definition = $this->createAndActivateOnSets(
            $admin,
            'Langer Pos Text',
            FieldAppliesTo::Calculation,
            calc: true,
            dispo: false,
            scope: FieldScope::Position,
            fieldType: FieldType::LongText,
            maxLength: 20000,
        );

        $text = str_repeat('🙂', 20000);
        $catalog = $this->createSpotClassicCatalog();
        $user = User::factory()->role(Role::Sales)->create();
        $calculation = app(CalculationWriter::class)->create([
            'planning_mode' => 'manual',
            'customer_name' => 'Kunde',
            'campaign' => 'C',
            'product_title' => 'P',
            'order_discount_percent' => '0',
            'ae_enabled' => false,
            'positions' => [
                $this->positionPayload($catalog, $catalog['hamburg']->id, (string) Str::uuid(), [
                    $definition->key => $text,
                ]),
            ],
        ], $user);

        $snapDef = SnapshotFieldDefinition::query()
            ->where('configuration_snapshot_id', $calculation->configuration_snapshot_id)
            ->where('key', $definition->key)
            ->firstOrFail();

        $stored = CalculationPositionFieldValue::query()
            ->where('calculation_position_id', $calculation->positions()->firstOrFail()->id)
            ->where('snapshot_field_definition_id', $snapDef->id)
            ->value('value_text');

        $this->assertSame($text, $stored);
    }

    /**
     * @param  array<string, mixed>  $dynamic
     * @return array<string, mixed>
     */
    private function positionPayload(
        array $catalog,
        int $inventoryId,
        string $clientKey,
        array $dynamic,
        ?int $id = null,
    ): array {
        $payload = [
            'client_key' => $clientKey,
            'inventory_id' => $inventoryId,
            'advertising_medium_id' => $catalog['medium']->id,
            'spot_method' => 'average',
            'length_seconds' => 30,
            'total_spot_count' => 10,
            'position_discount_percent' => '0',
            'ae_percent' => '15',
            'plan_rows' => [['hour' => 8, 'day_group' => 'mo_fr']],
            'dynamic_field_values' => array_merge([
                'period_open' => true,
            ], $dynamic),
        ];
        if ($id !== null) {
            $payload['id'] = $id;
        }

        return $payload;
    }

    private function savedCalculation(): Calculation
    {
        $catalog = $this->createSpotClassicCatalog();

        return $this->createSavedCalculation($catalog, [
            ['inventory_id' => $catalog['hamburg']->id],
        ]);
    }

    private function createAndActivateOnSets(
        User $admin,
        string $label,
        FieldAppliesTo $appliesTo,
        bool $calc,
        bool $dispo,
        FieldScope $scope = FieldScope::Position,
        FieldType $fieldType = FieldType::ShortText,
        ?int $maxLength = null,
        ?bool $requiredOverride = null,
        ?bool $visibleOverride = null,
    ): FieldDefinition {
        $definition = app(FieldDefinitionCustomWriter::class)->create([
            'label' => $label,
            'field_type' => $fieldType,
            'scope' => $scope,
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
            'sort' => 90,
            'required_override' => $requiredOverride,
            'visible_override' => $visibleOverride,
            'lock_version' => $fieldSet->lock_version,
        ], $admin);
        $fieldSet->refresh();
        $setWriter->activateDraft($fieldSet, $draft, $admin, $fieldSet->lock_version);
    }
}
