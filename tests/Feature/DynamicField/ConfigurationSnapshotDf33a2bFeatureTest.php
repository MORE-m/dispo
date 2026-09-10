<?php

namespace Tests\Feature\DynamicField;

use App\Enums\CalculationKind;
use App\Enums\ConfigurationSnapshotSource;
use App\Enums\FieldAppliesTo;
use App\Enums\FieldScope;
use App\Enums\FieldType;
use App\Enums\Role;
use App\Models\AdvertisingCategory;
use App\Models\AdvertisingMedium;
use App\Models\Calculation;
use App\Models\CalculationPosition;
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
        // Zweites Spot-Medium in derselben Spots-Kategorie: unterschiedliche Effektiv-Schemas
        // über Kategorie- vs. Medium-Feldset, ohne reale Nicht-Spot-Kategorien zu verfälschen.
        $otherMedium = AdvertisingMedium::factory()->create([
            'category_id' => $medium->category_id,
            'code' => 'df33a2b_http_other',
            'name' => 'HTTP Other Spot Medium',
            'kind' => CalculationKind::SpotClassic,
            'sort' => 1,
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

    public function test_partial_dispo_selection_creates_only_one_effective(): void
    {
        $catalog = $this->createSpotClassicCatalog();
        $user = User::factory()->role(Role::Sales)->create();
        $calculation = $this->createSavedCalculation($catalog, [
            ['inventory_id' => $catalog['hamburg']->id],
            ['inventory_id' => $catalog['rock']->id],
        ], $user);
        $positions = $calculation->positions()->orderBy('id')->get();
        $this->assertCount(2, $positions);

        $selectedId = (int) $positions[0]->id;
        $skippedId = (int) $positions[1]->id;

        $order = app(DispoOrderWriter::class)
            ->createFromCalculation($calculation, [$selectedId], $user)
            ->order;

        $dispoEffectives = ConfigurationSnapshot::query()
            ->where('parent_configuration_snapshot_id', $order->configuration_snapshot_id)
            ->where('source', ConfigurationSnapshotSource::DispoOrderPositionEffective->value)
            ->get();
        $this->assertCount(1, $dispoEffectives);

        $bound = $order->positions()->firstOrFail();
        $this->assertSame($selectedId, (int) $bound->calculation_position_id);
        $this->assertSame(
            (int) $dispoEffectives->first()->id,
            (int) $bound->effective_configuration_snapshot_id,
        );
        $dispoEffectives->first()->assertReadable();

        $this->assertNull(
            ConfigurationSnapshot::query()
                ->where('parent_configuration_snapshot_id', $order->configuration_snapshot_id)
                ->where('source_configuration_snapshot_id', $positions[1]->effective_configuration_snapshot_id)
                ->first(),
            'Nicht ausgewählte Calc-Position darf kein Dispo-Effektiv erzeugen',
        );
        $this->assertSame($skippedId, (int) $positions[1]->id);
    }

    public function test_delete_effective_fails_closed_on_unexpected_position_reference(): void
    {
        $catalog = $this->createSpotClassicCatalog();
        $calculation = $this->createSavedCalculation($catalog, [
            ['inventory_id' => $catalog['hamburg']->id],
        ]);
        $position = $calculation->positions()->firstOrFail();
        $effective = ConfigurationSnapshot::query()
            ->findOrFail($position->effective_configuration_snapshot_id);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('unerwartete Restreferenzen');
        app(ConfigurationSnapshotFreezeService::class)
            ->deleteEffectiveIfUnreferenced($effective);
    }

    public function test_effective_rejects_empty_context_and_mismatched_frozen_sources(): void
    {
        $catalog = $this->createSpotClassicCatalog();
        $admin = User::factory()->role(Role::Admin)->create();
        $medium = $catalog['medium'];

        $categoryKey = $this->activateScopedPositionField(
            $admin,
            'advertising_category',
            ['advertising_category_id' => $medium->category_id],
        );
        $mediumKey = $this->activateScopedPositionField(
            $admin,
            'advertising_medium',
            ['advertising_medium_id' => $medium->id],
        );
        $this->assertNotSame('', $categoryKey);
        $this->assertNotSame('', $mediumKey);

        $calculation = $this->createSavedCalculation($catalog, [
            ['inventory_id' => $catalog['hamburg']->id],
        ]);
        $position = $calculation->positions()->firstOrFail();
        $effective = ConfigurationSnapshot::query()
            ->findOrFail($position->effective_configuration_snapshot_id);

        $categorySource = $effective->sources()->where('layer', 'advertising_category')->first();
        $mediumSource = $effective->sources()->where('layer', 'advertising_medium')->first();
        $this->assertNotNull($categorySource, 'Kategorie-Assignment-Source muss vorhanden sein');
        $this->assertNotNull($mediumSource, 'Werbemittel-Assignment-Source muss vorhanden sein');

        $originalMediumName = (string) $effective->context_advertising_medium_name;
        $effective->forceFill(['context_advertising_medium_name' => ''])->save();
        try {
            $effective->fresh()->assertReadable();
            $this->fail('Leerer Kontextname muss fail-closed sein.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('vollständigen eingefrorenen Kontext', $exception->getMessage());
        }
        $effective->forceFill(['context_advertising_medium_name' => $originalMediumName])->save();

        $mediumSource->forceFill(['target_id' => ((int) $mediumSource->target_id) + 999])->save();
        try {
            $effective->fresh()->assertReadable();
            $this->fail('Abweichende Medium-Source-ID muss fail-closed sein.');
        } catch (\RuntimeException $exception) {
            $this->assertTrue(
                str_contains($exception->getMessage(), 'weicht vom eingefrorenen Kontext')
                || str_contains($exception->getMessage(), 'target_identity')
                || str_contains($exception->getMessage(), 'passt nicht zum eingefrorenen Kontext'),
                $exception->getMessage(),
            );
        }
        $mediumSource->forceFill([
            'target_id' => (int) $effective->context_advertising_medium_id,
        ])->save();

        $originalMediumId = (int) $effective->context_advertising_medium_id;
        $otherMedium = AdvertisingMedium::factory()->create([
            'code' => 'df33a2b_ctx_mismatch',
            'category_id' => $medium->category_id,
            'is_active' => true,
            'default_length_seconds' => 30,
        ]);
        $effective->forceFill(['context_advertising_medium_id' => $otherMedium->id])->save();
        try {
            $effective->fresh()->assertReadable();
            $this->fail('Abweichende Kontext-Medium-ID muss fail-closed sein.');
        } catch (\RuntimeException $exception) {
            $this->assertTrue(
                str_contains($exception->getMessage(), 'weicht vom eingefrorenen Kontext')
                || str_contains($exception->getMessage(), 'passt nicht zum eingefrorenen Kontext'),
                $exception->getMessage(),
            );
        }
        $effective->forceFill(['context_advertising_medium_id' => $originalMediumId])->save();

        $mediumSource->forceFill(['target_key' => 'wrong_code'])->save();
        try {
            $effective->fresh()->assertReadable();
            $this->fail('Abweichender Medium-Code muss fail-closed sein.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('weicht vom eingefrorenen Kontext', $exception->getMessage());
        }
        $mediumSource->forceFill(['target_key' => (string) $effective->context_advertising_medium_code])->save();

        $mediumSource->forceFill(['target_name' => 'Abweichender Name'])->save();
        try {
            $effective->fresh()->assertReadable();
            $this->fail('Abweichender Medium-Name muss fail-closed sein.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('weicht vom eingefrorenen Kontext', $exception->getMessage());
        }
        $mediumSource->forceFill(['target_name' => (string) $effective->context_advertising_medium_name])->save();

        $categorySource->forceFill(['target_key' => 'wrong_key'])->save();
        try {
            $effective->fresh()->assertReadable();
            $this->fail('Abweichender Kategorie-Key muss fail-closed sein.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('weicht vom eingefrorenen Kontext', $exception->getMessage());
        }
        $categorySource->forceFill(['target_key' => (string) $effective->context_advertising_category_key])->save();

        $categorySource->forceFill(['target_name' => 'Abweichende Kategorie'])->save();
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('weicht vom eingefrorenen Kontext');
        $effective->fresh()->assertReadable();
    }

    public function test_gen3_update_requires_base_and_position_fingerprints(): void
    {
        $catalog = $this->createSpotClassicCatalog();
        $user = User::factory()->role(Role::Sales)->create();
        $create = $this->withLiveSchemaFingerprint([
            'planning_mode' => 'manual',
            'customer_name' => 'FP Update',
            'campaign' => 'C',
            'product_title' => 'P',
            'order_discount_percent' => '0',
            'ae_enabled' => false,
            'positions' => [[
                'inventory_id' => $catalog['hamburg']->id,
                'advertising_medium_id' => $catalog['medium']->id,
                'spot_method' => 'average',
                'length_seconds' => 30,
                'total_spot_count' => 5,
                'position_discount_percent' => '0',
                'ae_percent' => '0',
                'plan_rows' => [['hour' => 8, 'day_group' => 'mo_fr']],
            ]],
        ]);
        $this->actingAs($user)->post(route('calculations.store'), $create)->assertRedirect();
        $calculation = Calculation::query()->latest('id')->firstOrFail();
        $baseFingerprint = (string) $calculation->configurationSnapshot->schema_fingerprint;
        $positions = $this->withPositionSchemaFingerprints(
            $calculation,
            $calculation->positions->map(fn ($position): array => [
                'id' => $position->id,
                'inventory_id' => $position->inventory_id,
                'advertising_medium_id' => $position->advertising_medium_id,
                'spot_method' => $position->spot_method->value,
                'length_seconds' => $position->length_seconds,
                'total_spot_count' => $position->total_spot_count,
                'position_discount_percent' => (string) $position->position_discount_percent,
                'ae_percent' => (string) $position->ae_percent,
                'plan_rows' => [['hour' => 8, 'day_group' => 'mo_fr']],
            ])->all(),
        );

        $this->actingAs($user)->putJson(route('calculations.update', $calculation), [
            'planning_mode' => 'manual',
            'order_discount_percent' => '0',
            'lock_version' => $calculation->lock_version,
            'positions' => $positions,
        ])->assertUnprocessable()->assertJsonValidationErrors(['schema_fingerprint']);

        $this->actingAs($user)->putJson(route('calculations.update', $calculation), [
            'planning_mode' => 'manual',
            'order_discount_percent' => '0',
            'lock_version' => $calculation->lock_version,
            'schema_fingerprint' => 'not-a-sha256',
            'positions' => $positions,
        ])->assertUnprocessable()->assertJsonValidationErrors(['schema_fingerprint']);

        $positionsWithoutFp = $positions;
        unset($positionsWithoutFp[0]['schema_fingerprint']);
        $this->actingAs($user)->putJson(route('calculations.update', $calculation), [
            'planning_mode' => 'manual',
            'order_discount_percent' => '0',
            'lock_version' => $calculation->lock_version,
            'schema_fingerprint' => $baseFingerprint,
            'positions' => $positionsWithoutFp,
        ])->assertUnprocessable()->assertJsonValidationErrors(['positions.0.schema_fingerprint']);

        $this->actingAs($user)->putJson(route('calculations.update', $calculation), [
            'planning_mode' => 'manual',
            'order_discount_percent' => '0',
            'lock_version' => $calculation->lock_version,
            'schema_fingerprint' => str_repeat('b', 64),
            'positions' => $positions,
            'dynamic_field_values' => ['unknown_custom_field' => 'x'],
        ])->assertStatus(409);

        $stalePositions = $positions;
        $stalePositions[0]['schema_fingerprint'] = str_repeat('c', 64);
        $stalePositions[0]['dynamic_field_values'] = ['unknown_custom_field' => 'x'];
        $this->actingAs($user)->putJson(route('calculations.update', $calculation), [
            'planning_mode' => 'manual',
            'order_discount_percent' => '0',
            'lock_version' => $calculation->lock_version,
            'schema_fingerprint' => $baseFingerprint,
            'positions' => $stalePositions,
        ])->assertStatus(409);

        $this->actingAs($user)->put(route('calculations.update', $calculation), [
            'planning_mode' => 'manual',
            'customer_name' => 'FP Update OK',
            'campaign' => 'C',
            'product_title' => 'P',
            'order_discount_percent' => '0',
            'ae_enabled' => false,
            'lock_version' => $calculation->lock_version,
            'schema_fingerprint' => $baseFingerprint,
            'positions' => $positions,
        ])->assertRedirect();

        $calculation->refresh();
        $this->actingAs($user)->put(route('calculations.update', $calculation), [
            'planning_mode' => 'manual',
            'customer_name' => 'Header Only',
            'campaign' => 'C',
            'product_title' => 'P',
            'order_discount_percent' => '0',
            'ae_enabled' => false,
            'lock_version' => $calculation->lock_version,
            'schema_fingerprint' => $baseFingerprint,
            'briefing' => 'Nur Kopf',
        ])->assertRedirect();
        $this->assertSame('Header Only', $calculation->fresh()->customer_name);
    }

    public function test_gen3_source_and_origin_combinations_are_fail_closed(): void
    {
        $catalog = $this->createSpotClassicCatalog();
        $calculation = $this->createSavedCalculation($catalog, [
            ['inventory_id' => $catalog['hamburg']->id],
        ]);
        $calcBase = $calculation->configurationSnapshot()->firstOrFail();
        $calcEffective = ConfigurationSnapshot::query()
            ->findOrFail($calculation->positions()->firstOrFail()->effective_configuration_snapshot_id);

        $user = User::factory()->role(Role::Sales)->create();
        $order = app(DispoOrderWriter::class)
            ->createFromCalculation($calculation, $calculation->positions()->pluck('id')->all(), $user)
            ->order;
        $dispoBase = $order->configurationSnapshot()->firstOrFail();
        $dispoEffective = ConfigurationSnapshot::query()
            ->findOrFail($order->positions()->firstOrFail()->effective_configuration_snapshot_id);

        $calcBase->forceFill(['source' => ConfigurationSnapshotSource::LegacyBackfill])->save();
        try {
            $calcBase->fresh()->assertReadable();
            $this->fail('legacy_backfill als Gen-3-Calc-Basis muss fail-closed sein.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('seed_active', $exception->getMessage());
        }
        $calcBase->forceFill(['source' => ConfigurationSnapshotSource::SeedActive])->save();

        $calcBase->forceFill(['source_configuration_snapshot_id' => $calcBase->id])->save();
        try {
            $calcBase->fresh()->assertReadable();
            $this->fail('Selbstreferenz der Calc-Basis muss fail-closed sein.');
        } catch (\RuntimeException $exception) {
            $this->assertTrue(
                str_contains($exception->getMessage(), 'sich selbst')
                || str_contains($exception->getMessage(), 'keine Herkunft'),
            );
        }
        $calcBase->forceFill(['source_configuration_snapshot_id' => null])->save();

        $dispoBase->forceFill(['source' => ConfigurationSnapshotSource::DispoOrderLegacyBackfill])->save();
        try {
            $dispoBase->fresh()->assertReadable();
            $this->fail('dispo_order_legacy_backfill als Gen-3-Dispo-Basis muss fail-closed sein.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('dispo_order_create', $exception->getMessage());
        }
        $dispoBase->forceFill(['source' => ConfigurationSnapshotSource::DispoOrderCreate])->save();

        $dispoBase->forceFill(['source_configuration_snapshot_id' => $calcEffective->id])->save();
        try {
            $dispoBase->fresh()->assertReadable();
            $this->fail('Dispo-Basis mit Effektiv-Herkunft muss fail-closed sein.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('Calc-Basis', $exception->getMessage());
        }
        $dispoBase->forceFill(['source_configuration_snapshot_id' => $calcBase->id])->save();

        $calcEffective->forceFill(['parent_configuration_snapshot_id' => $dispoBase->id])->save();
        try {
            $calcEffective->fresh()->assertReadable();
            $this->fail('Calc-Effektiv mit Dispo-Parent muss fail-closed sein.');
        } catch (\RuntimeException $exception) {
            $this->assertTrue(
                str_contains($exception->getMessage(), 'Calc-Basis')
                || str_contains($exception->getMessage(), 'Prozessfamilie'),
            );
        }
        $calcEffective->forceFill(['parent_configuration_snapshot_id' => $calcBase->id])->save();

        $calcEffective->forceFill(['source_configuration_snapshot_id' => $calcBase->id])->save();
        try {
            $calcEffective->fresh()->assertReadable();
            $this->fail('Calc-Effektiv mit Herkunft muss fail-closed sein.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('keine Herkunft', $exception->getMessage());
        }
        $calcEffective->forceFill(['source_configuration_snapshot_id' => null])->save();

        $dispoEffective->forceFill(['source_configuration_snapshot_id' => $calcBase->id])->save();
        try {
            $dispoEffective->fresh()->assertReadable();
            $this->fail('Dispo-Effektiv mit Basis-Herkunft muss fail-closed sein.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('Calc-Effektiv', $exception->getMessage());
        }
        $dispoEffective->forceFill(['source_configuration_snapshot_id' => $calcEffective->id])->save();

        $dispoEffective->forceFill([
            'context_advertising_medium_name' => 'Abweichend vom Calc-Effektiv',
        ])->save();
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('weicht von der Calc-Herkunft ab');
        $dispoEffective->fresh()->assertReadable();
    }

    public function test_delete_effective_fails_closed_for_each_residual_reference_kind(): void
    {
        $catalog = $this->createSpotClassicCatalog();
        $calculation = $this->createSavedCalculation($catalog, [
            ['inventory_id' => $catalog['hamburg']->id],
        ]);
        $position = $calculation->positions()->firstOrFail();
        $effective = ConfigurationSnapshot::query()
            ->findOrFail($position->effective_configuration_snapshot_id);
        $freeze = app(ConfigurationSnapshotFreezeService::class);

        try {
            $freeze->deleteEffectiveIfUnreferenced($effective);
            $this->fail('Calc-Positionsreferenz muss fail-closed sein.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('unerwartete Restreferenzen', $exception->getMessage());
            $this->assertStringContainsString('calc=[', $exception->getMessage());
        }

        $user = User::factory()->role(Role::Sales)->create();
        $order = app(DispoOrderWriter::class)
            ->createFromCalculation($calculation, [$position->id], $user)
            ->order;
        $dispoEffective = ConfigurationSnapshot::query()
            ->findOrFail($order->positions()->firstOrFail()->effective_configuration_snapshot_id);

        try {
            $freeze->deleteEffectiveIfUnreferenced($dispoEffective);
            $this->fail('Dispo-Positionsreferenz muss fail-closed sein.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('dispo=[', $exception->getMessage());
        }

        $position->forceFill(['effective_configuration_snapshot_id' => null])->save();
        $child = ConfigurationSnapshot::query()->create([
            'field_set_id' => $effective->field_set_id,
            'field_set_version_id' => $effective->field_set_version_id,
            'source' => ConfigurationSnapshotSource::SeedActive,
            'format_version' => ConfigurationSnapshot::FORMAT_VERSION_LEGACY,
            'schema_fingerprint' => str_repeat('d', 64),
            'parent_configuration_snapshot_id' => $effective->id,
            'created_at' => now(),
        ]);
        try {
            $freeze->deleteEffectiveIfUnreferenced($effective->fresh());
            $this->fail('Parent-Restreferenz muss fail-closed sein.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('parent=[', $exception->getMessage());
        }
        $child->forceFill(['parent_configuration_snapshot_id' => null])->save();

        $child->forceFill(['source_configuration_snapshot_id' => $effective->id])->save();
        try {
            $freeze->deleteEffectiveIfUnreferenced($effective->fresh());
            $this->fail('Herkunfts-Restreferenz muss fail-closed sein.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('origin=[', $exception->getMessage());
        }
    }

    public function test_replace_rolls_back_when_old_effective_has_residual_reference(): void
    {
        $catalog = $this->createSpotClassicCatalog();
        $mediumB = AdvertisingMedium::factory()->create([
            'code' => 'df33a2b_replace_rollback',
            'is_active' => true,
            'default_length_seconds' => 30,
            'category_id' => $catalog['medium']->category_id,
        ]);
        $catalog['hamburg']->mediumRules()->create([
            'advertising_medium_id' => $mediumB->id,
        ]);

        $calculation = $this->createSavedCalculation($catalog, [
            ['inventory_id' => $catalog['hamburg']->id],
        ]);
        $position = $calculation->positions()->firstOrFail();
        $oldEffectiveId = (int) $position->effective_configuration_snapshot_id;
        $oldContextName = (string) $position->advertising_medium_name;
        $oldValueCount = DB::table('calculation_position_field_values')
            ->where('calculation_position_id', $position->id)
            ->count();

        ConfigurationSnapshot::query()->create([
            'field_set_id' => $calculation->configurationSnapshot->field_set_id,
            'field_set_version_id' => $calculation->configurationSnapshot->field_set_version_id,
            'source' => ConfigurationSnapshotSource::SeedActive,
            'format_version' => ConfigurationSnapshot::FORMAT_VERSION_LEGACY,
            'schema_fingerprint' => str_repeat('e', 64),
            'parent_configuration_snapshot_id' => $oldEffectiveId,
            'created_at' => now(),
        ]);

        $base = $calculation->configurationSnapshot()->firstOrFail();
        $fingerprint = (string) app(ConfigurationSnapshotFreezeService::class)
            ->resolvePositionSchemaFromBase($base, (int) $mediumB->id)['schema_fingerprint'];

        try {
            app(ConfigurationSnapshotFreezeService::class)->replacePositionEffective(
                $position,
                $base,
                (int) $mediumB->id,
                $fingerprint,
            );
            $this->fail('Replace mit Restreferenz muss rollbacken.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('unerwartete Restreferenzen', $exception->getMessage());
        }

        $position->refresh();
        $this->assertSame($oldEffectiveId, (int) $position->effective_configuration_snapshot_id);
        $this->assertSame($oldContextName, (string) $position->advertising_medium_name);
        $this->assertSame(
            $oldValueCount,
            DB::table('calculation_position_field_values')
                ->where('calculation_position_id', $position->id)
                ->count(),
        );
        $this->assertTrue(
            ConfigurationSnapshot::query()->whereKey($oldEffectiveId)->exists(),
            'Alter Effektiv-Snapshot muss erhalten bleiben',
        );

        $candidateIds = ConfigurationSnapshot::query()
            ->where('parent_configuration_snapshot_id', $base->id)
            ->where('source', ConfigurationSnapshotSource::CalculationPositionEffective)
            ->where('id', '!=', $oldEffectiveId)
            ->pluck('id');
        foreach ($candidateIds as $candidateId) {
            $this->assertTrue(
                CalculationPosition::query()
                    ->where('effective_configuration_snapshot_id', $candidateId)
                    ->exists(),
                'Kein ownerloser neuer Effektiv-Snapshot nach Rollback',
            );
        }
    }

    public function test_effective_rejects_wrong_owner_base(): void
    {
        $catalog = $this->createSpotClassicCatalog();
        $first = $this->createSavedCalculation($catalog, [
            ['inventory_id' => $catalog['hamburg']->id],
        ]);
        $second = $this->createSavedCalculation($catalog, [
            ['inventory_id' => $catalog['rock']->id],
        ]);
        $position = $first->positions()->firstOrFail();
        $foreignBaseId = (int) $second->configuration_snapshot_id;
        $effective = ConfigurationSnapshot::query()
            ->findOrFail($position->effective_configuration_snapshot_id);

        $effective->forceFill(['parent_configuration_snapshot_id' => $foreignBaseId])->save();

        try {
            $effective->fresh()->assertReadable();
            $this->fail('Falsche Parent-Basis muss fail-closed sein.');
        } catch (\RuntimeException $exception) {
            $this->assertTrue(
                str_contains($exception->getMessage(), 'Parent')
                || str_contains($exception->getMessage(), 'Calc-Position gehört nicht')
                || str_contains($exception->getMessage(), 'Prozessfamilie')
                || str_contains($exception->getMessage(), 'Calc-Basis'),
            );
        }

        $effective->forceFill([
            'parent_configuration_snapshot_id' => $first->configuration_snapshot_id,
        ])->save();
        $first->forceFill(['configuration_snapshot_id' => $foreignBaseId])->save();

        $this->expectException(\RuntimeException::class);
        $effective->fresh()->assertReadable();
    }

    public function test_stale_position_fingerprint_returns_409_before_field_422(): void
    {
        $catalog = $this->createSpotClassicCatalog();
        $user = User::factory()->role(Role::Sales)->create();
        $payload = $this->withLiveSchemaFingerprint([
            'planning_mode' => 'manual',
            'customer_name' => 'Fingerprint Drift',
            'campaign' => 'C',
            'product_title' => 'P',
            'order_discount_percent' => '0',
            'ae_enabled' => false,
            'dynamic_field_values' => ['campaign_period' => null],
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
                    'position_flight_period' => null,
                    'unknown_custom_field' => 'x',
                ],
            ]],
        ]);
        $payload['positions'][0]['schema_fingerprint'] = str_repeat('a', 64);

        $this->actingAs($user)
            ->postJson(route('calculations.store'), $payload)
            ->assertStatus(409)
            ->assertJsonFragment(['message' => 'Die Feldkonfiguration hat sich geändert. Bitte neu laden und erneut speichern.']);
    }

    public function test_missing_position_fingerprint_on_create_returns_422(): void
    {
        $catalog = $this->createSpotClassicCatalog();
        $user = User::factory()->role(Role::Sales)->create();
        $payload = $this->withLiveSchemaFingerprint([
            'planning_mode' => 'manual',
            'customer_name' => 'Missing FP',
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
        unset($payload['positions'][0]['schema_fingerprint']);

        $this->actingAs($user)
            ->postJson(route('calculations.store'), $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['positions.0.schema_fingerprint']);
    }

    public function test_empty_required_custom_header_does_not_block_calc_but_blocks_dispo(): void
    {
        $admin = User::factory()->role(Role::Admin)->create();
        $definition = app(FieldDefinitionCustomWriter::class)->create([
            'label' => 'Pflicht Kopf β',
            'field_type' => FieldType::ShortText,
            'scope' => FieldScope::Header,
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
            'customer_name' => 'PO-32b-1 Header',
            'campaign' => 'C',
            'product_title' => 'P',
            'order_discount_percent' => '0',
            'ae_enabled' => false,
            'dynamic_field_values' => [
                'campaign_period' => null,
                $definition->key => null,
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
                'dynamic_field_values' => [
                    'period_open' => true,
                    'position_flight_period' => null,
                ],
            ]],
        ]);

        $this->actingAs($user)
            ->postJson(route('calculations.store'), $payload)
            ->assertRedirect();

        $calculation = Calculation::query()
            ->where('customer_name', 'PO-32b-1 Header')
            ->latest('id')
            ->firstOrFail();

        try {
            app(DispoOrderWriter::class)->createFromCalculation(
                $calculation,
                $calculation->positions()->pluck('id')->all(),
                $user,
            );
            $this->fail('Dispo-Create muss leeres Header-Pflichtfeld blockieren.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey(
                'dynamic_field_values.'.$definition->key,
                $exception->errors(),
            );
        }
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
