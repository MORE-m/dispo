<?php

namespace Tests\Feature\DynamicField;

use App\Enums\ConfigurationSnapshotSource;
use App\Enums\FieldAppliesTo;
use App\Enums\FieldScope;
use App\Enums\FieldType;
use App\Enums\Role;
use App\Models\AdvertisingCategory;
use App\Models\AdvertisingMedium;
use App\Models\Calculation;
use App\Models\ConfigurationSnapshot;
use App\Models\FieldSet;
use App\Models\FieldSetVersion;
use App\Models\User;
use App\Services\Calculation\CalculationWriter;
use App\Services\DispoOrder\DispoOrderWriter;
use App\Services\DynamicField\Admin\FieldDefinitionCustomWriter;
use App\Services\DynamicField\Admin\FieldSetVersionAdminWriter;
use App\Services\DynamicField\Assignment\FieldSetAssignmentAdminWriter;
use App\Services\DynamicField\ConfigurationSnapshotFreezeService;
use App\Services\DynamicField\ConfigurationSnapshotIntegrity;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia;
use Tests\Concerns\CreatesSavedCalculation;
use Tests\Concerns\CreatesSpotClassicCatalog;
use Tests\TestCase;

/**
 * DF-3.3a2β / VER-003: Ownership, Unique, historischer Kontext, Calc→Dispo.
 */
class ConfigurationSnapshotDf33a2bFeatureTest extends TestCase
{
    use CreatesSavedCalculation;
    use CreatesSpotClassicCatalog;
    use RefreshDatabase;

    public function test_two_positions_get_distinct_effectives_with_unique_constraint(): void
    {
        $catalog = $this->createSpotClassicCatalog();
        $calculation = $this->createSavedCalculation($catalog, [
            ['inventory_id' => $catalog['hamburg']->id],
            ['inventory_id' => $catalog['rock']->id],
        ]);

        $positions = $calculation->positions()->orderBy('id')->get();
        $this->assertCount(2, $positions);

        $firstEffective = (int) $positions[0]->effective_configuration_snapshot_id;
        $secondEffective = (int) $positions[1]->effective_configuration_snapshot_id;
        $this->assertNotSame(0, $firstEffective);
        $this->assertNotSame(0, $secondEffective);
        $this->assertNotSame($firstEffective, $secondEffective);

        $this->expectException(QueryException::class);
        DB::table('calculation_positions')->where('id', $positions[1]->id)->update([
            'effective_configuration_snapshot_id' => $firstEffective,
        ]);
    }

    public function test_cross_table_ownership_blocks_calc_effective_on_dispo_position(): void
    {
        $catalog = $this->createSpotClassicCatalog();
        $user = User::factory()->role(Role::Sales)->create();
        $calculation = $this->createSavedCalculation($catalog, [
            ['inventory_id' => $catalog['hamburg']->id],
        ], $user);
        $calcPosition = $calculation->positions()->firstOrFail();
        $calcEffective = ConfigurationSnapshot::query()
            ->findOrFail($calcPosition->effective_configuration_snapshot_id);

        $order = app(DispoOrderWriter::class)
            ->createFromCalculation($calculation, [$calcPosition->id], $user)
            ->order;
        $dispoPosition = $order->positions()->firstOrFail();

        $this->expectException(\RuntimeException::class);
        $dispoPosition->forceFill([
            'effective_configuration_snapshot_id' => $calcEffective->id,
        ])->save();
        app(ConfigurationSnapshotIntegrity::class)->assertOwnership($calcEffective->fresh());
    }

    public function test_live_recategorization_keeps_effective_readable(): void
    {
        $catalog = $this->createSpotClassicCatalog();
        $calculation = $this->createSavedCalculation($catalog, [
            ['inventory_id' => $catalog['hamburg']->id],
        ]);
        $position = $calculation->positions()->firstOrFail();
        $effective = ConfigurationSnapshot::query()
            ->findOrFail($position->effective_configuration_snapshot_id);

        $frozenCategoryId = (int) $effective->context_advertising_category_id;
        $otherCategory = AdvertisingCategory::query()
            ->where('id', '!=', $frozenCategoryId)
            ->firstOrFail();

        AdvertisingMedium::query()->whereKey($catalog['medium']->id)->update([
            'category_id' => $otherCategory->id,
            'name' => 'Umbenannt live',
        ]);

        $effective->refresh()->assertReadable();
        $this->assertSame($frozenCategoryId, (int) $effective->context_advertising_category_id);
        $this->assertNotSame('Umbenannt live', $effective->context_advertising_medium_name);
    }

    public function test_dispo_inherits_historical_calc_context_not_live_category(): void
    {
        $catalog = $this->createSpotClassicCatalog();
        $user = User::factory()->role(Role::Sales)->create();
        $calculation = $this->createSavedCalculation($catalog, [
            ['inventory_id' => $catalog['hamburg']->id],
        ], $user);
        $calcPosition = $calculation->positions()->firstOrFail();
        $calcEffective = ConfigurationSnapshot::query()
            ->findOrFail($calcPosition->effective_configuration_snapshot_id);
        $frozenCategoryId = (int) $calcEffective->context_advertising_category_id;
        $frozenMediumName = (string) $calcEffective->context_advertising_medium_name;

        $otherCategory = AdvertisingCategory::query()
            ->where('id', '!=', $frozenCategoryId)
            ->firstOrFail();
        AdvertisingMedium::query()->whereKey($catalog['medium']->id)->update([
            'category_id' => $otherCategory->id,
            'name' => 'Live anders',
        ]);

        $order = app(DispoOrderWriter::class)
            ->createFromCalculation($calculation->fresh(), [$calcPosition->id], $user)
            ->order;
        $dispoPosition = $order->positions()->firstOrFail();
        $dispoEffective = ConfigurationSnapshot::query()
            ->findOrFail($dispoPosition->effective_configuration_snapshot_id);

        $this->assertSame($frozenCategoryId, (int) $dispoEffective->context_advertising_category_id);
        $this->assertSame($frozenMediumName, (string) $dispoEffective->context_advertising_medium_name);
        $this->assertSame(
            ConfigurationSnapshotSource::DispoOrderPositionEffective,
            $dispoEffective->source,
        );
    }

    public function test_empty_required_custom_blocks_dispo_create_but_not_calc(): void
    {
        $admin = User::factory()->role(Role::Admin)->create();
        $definition = app(FieldDefinitionCustomWriter::class)->create([
            'label' => 'Pflicht Pos β',
            'field_type' => FieldType::ShortText,
            'scope' => FieldScope::Position,
            'applies_to' => FieldAppliesTo::Both,
            'max_length' => 80,
        ], $admin);

        $fieldSet = FieldSet::query()->where('key', 'system_calculation_core')->firstOrFail();
        $draft = app(FieldSetVersionAdminWriter::class)
            ->createDraftFromVersion(
                $fieldSet,
                FieldSetVersion::query()->whereKey($fieldSet->active_version_id)->firstOrFail(),
                $admin,
                $fieldSet->lock_version,
            );
        $fieldSet->refresh();
        app(FieldSetVersionAdminWriter::class)->addCustomMembership(
            $fieldSet,
            $draft,
            [
                'field_definition_id' => $definition->id,
                'field_definition_revision_id' => (int) $definition->current_revision_id,
                'sort' => 90,
                'required_override' => true,
                'visible_override' => true,
                'lock_version' => $fieldSet->lock_version,
            ],
            $admin,
        );
        $fieldSet->refresh();
        app(FieldSetVersionAdminWriter::class)->activateDraft(
            $fieldSet,
            $draft,
            $admin,
            $fieldSet->lock_version,
        );

        $catalog = $this->createSpotClassicCatalog();
        $user = User::factory()->role(Role::Sales)->create();
        $payload = $this->withLiveSchemaFingerprint([
            'planning_mode' => 'manual',
            'customer_name' => 'PO-32b-1',
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
                'dynamic_field_values' => [
                    'period_open' => true,
                    $definition->key => null,
                ],
            ]],
        ]);

        $calculation = app(CalculationWriter::class)->create($payload, $user);
        $this->assertNotNull($calculation->id);

        try {
            app(DispoOrderWriter::class)->createFromCalculation(
                $calculation,
                $calculation->positions()->pluck('id')->all(),
                $user,
            );
            $this->fail('Dispo-Create muss leeres Pflichtfeld blockieren.');
        } catch (ValidationException $exception) {
            $this->assertNotEmpty($exception->errors());
        }
    }

    public function test_ownerless_effective_is_not_readable(): void
    {
        $freeze = app(ConfigurationSnapshotFreezeService::class);
        $live = $freeze->resolveLiveSchemaForCalculationV3();
        $positionSchema = $freeze->resolveLivePositionSchema(
            (int) $this->createSpotClassicCatalog()['medium']->id,
        );
        $result = $freeze->freezeCalculationV3($live['schema_fingerprint'], [[
            'client_key' => 'orphan',
            'advertising_medium_id' => (int) $positionSchema['context']['context_advertising_medium_id']
                ?? (int) $this->createSpotClassicCatalog()['medium']->id,
            'schema_fingerprint' => $positionSchema['schema_fingerprint'],
        ]]);

        $effective = $result['effectives_by_client_key']['orphan'];
        $this->expectException(\RuntimeException::class);
        $effective->assertReadable();
    }

    public function test_http_create_accepts_category_and_medium_position_customs(): void
    {
        $catalog = $this->createSpotClassicCatalog();
        $admin = User::factory()->role(Role::Admin)->create();
        $medium = $catalog['medium'];
        $otherCategory = AdvertisingCategory::query()
            ->where('id', '!=', $medium->category_id)
            ->firstOrFail();
        $otherMedium = AdvertisingMedium::factory()->create([
            'category_id' => $otherCategory->id,
            'code' => 'df33a2b_http_other',
            'name' => 'HTTP Other Medium',
        ]);
        $catalog['hamburg']->mediumRules()->create([
            'advertising_medium_id' => $otherMedium->id,
        ]);
        $catalog['rock']->mediumRules()->create([
            'advertising_medium_id' => $otherMedium->id,
        ]);

        $categoryKey = $this->activateScopedPositionField(
            $admin,
            'advertising_category',
            ['advertising_category_id' => $medium->category_id],
        );
        $mediumKey = $this->activateScopedPositionField(
            $admin,
            'advertising_medium',
            ['advertising_medium_id' => $otherMedium->id],
        );

        $user = User::factory()->role(Role::Sales)->create();
        $payload = $this->withLiveSchemaFingerprint([
            'planning_mode' => 'manual',
            'customer_name' => 'HTTP Gen3 Kontext',
            'campaign' => 'C',
            'product_title' => 'P',
            'order_discount_percent' => '0',
            'ae_enabled' => false,
            'dynamic_field_values' => ['campaign_period' => null],
            'positions' => [
                [
                    'inventory_id' => $catalog['hamburg']->id,
                    'advertising_medium_id' => $medium->id,
                    'spot_method' => 'average',
                    'length_seconds' => 30,
                    'total_spot_count' => 10,
                    'position_discount_percent' => '0',
                    'ae_percent' => '15',
                    'plan_rows' => [['hour' => 8, 'day_group' => 'mo_fr']],
                    'dynamic_field_values' => [
                        'period_open' => true,
                        'position_flight_period' => null,
                        $categoryKey => 'Kat-Wert',
                    ],
                ],
                [
                    'inventory_id' => $catalog['rock']->id,
                    'advertising_medium_id' => $otherMedium->id,
                    'spot_method' => 'average',
                    'length_seconds' => 20,
                    'total_spot_count' => 5,
                    'position_discount_percent' => '0',
                    'ae_percent' => '15',
                    'plan_rows' => [['hour' => 10, 'day_group' => 'mo_fr']],
                    'dynamic_field_values' => [
                        'period_open' => true,
                        'position_flight_period' => null,
                        $mediumKey => 'Medium-Wert',
                    ],
                ],
            ],
        ]);

        $this->actingAs($user)
            ->postJson(route('calculations.store'), $payload)
            ->assertRedirect();

        $calculation = Calculation::query()
            ->where('customer_name', 'HTTP Gen3 Kontext')
            ->latest('id')
            ->firstOrFail();
        $this->assertSame(
            ConfigurationSnapshot::FORMAT_VERSION_CONTEXTUAL_FREEZE,
            (int) $calculation->configurationSnapshot->format_version,
        );

        $positions = $calculation->positions()->orderBy('id')->get();
        $this->assertCount(2, $positions);
        $this->assertNotSame(
            (int) $positions[0]->effective_configuration_snapshot_id,
            (int) $positions[1]->effective_configuration_snapshot_id,
        );
        $this->assertTrue(
            $positions[0]->effectiveConfigurationSnapshot
                ->fieldDefinitions
                ->contains('key', $categoryKey),
        );
        $this->assertTrue(
            $positions[1]->effectiveConfigurationSnapshot
                ->fieldDefinitions
                ->contains('key', $mediumKey),
        );

        $this->actingAs($user)
            ->get(route('calculations.edit', $calculation))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('calculation.positions', 2)
                ->where(
                    'calculation.positions.0.field_schema.fields',
                    fn ($fields) => collect($fields)->contains('key', $categoryKey),
                )
                ->where(
                    'calculation.positions.1.field_schema.fields',
                    fn ($fields) => collect($fields)->contains('key', $mediumKey),
                )
            );
    }

    /**
     * @param  array<string, mixed>  $target
     */
    private function activateScopedPositionField(User $admin, string $targetLayer, array $target): string
    {
        $key = 'df33a2b_http_'.bin2hex(random_bytes(3));

        $this->actingAs($admin)
            ->post(route('administration.dynamic-fields.field-sets.store'), [
                'name' => 'HTTP Set '.$key,
                'key' => $key,
                'applies_to' => FieldAppliesTo::Both->value,
            ])
            ->assertRedirect();

        $fieldSet = FieldSet::query()->where('key', $key)->firstOrFail();
        $draft = FieldSetVersion::query()
            ->where('field_set_id', $fieldSet->id)
            ->where('status', 'draft')
            ->firstOrFail();

        $definition = app(FieldDefinitionCustomWriter::class)->create([
            'label' => 'HTTP Feld '.$key,
            'field_type' => FieldType::ShortText,
            'scope' => FieldScope::Position,
            'applies_to' => FieldAppliesTo::Both,
            'max_length' => 120,
        ], $admin);

        $this->actingAs($admin)
            ->post(route('administration.dynamic-fields.field-sets.versions.memberships.store', [$fieldSet, $draft]), [
                'lock_version' => $fieldSet->fresh()->lock_version,
                'field_definition_id' => $definition->id,
                'field_definition_revision_id' => $definition->current_revision_id,
                'sort' => 20,
            ])
            ->assertRedirect();

        $this->actingAs($admin)
            ->post(route('administration.dynamic-fields.field-sets.versions.activate', [$fieldSet, $draft]), [
                'lock_version' => $fieldSet->fresh()->lock_version,
            ])
            ->assertRedirect();

        $writer = app(FieldSetAssignmentAdminWriter::class);
        $assignment = $writer->create(array_merge([
            'field_set_id' => $fieldSet->id,
            'target_layer' => $targetLayer,
            'applies_to_process' => FieldAppliesTo::Calculation->value,
        ], $target), $admin);

        $writer->activate($assignment, [
            'lock_version' => $assignment->lock_version,
            'fingerprint' => $writer->canonicalActivationFingerprint(
                $writer->previewAffectedContexts($assignment, asCandidate: true),
            ),
        ], $admin);

        return $definition->key;
    }
}
