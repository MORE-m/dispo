<?php

namespace Tests\Feature\DynamicField;

use App\Enums\FieldAppliesTo;
use App\Enums\FieldScope;
use App\Enums\FieldType;
use App\Enums\Role;
use App\Models\Calculation;
use App\Models\ConfigurationSnapshot;
use App\Models\ConfigurationSnapshotSource;
use App\Models\FieldDefinition;
use App\Models\FieldSet;
use App\Models\FieldSetVersion;
use App\Models\SnapshotFieldDefinition;
use App\Models\SnapshotFieldRule;
use App\Models\User;
use App\Services\DispoOrder\DispoOrderApprovalService;
use App\Services\DispoOrder\DispoOrderWriter;
use App\Services\DynamicField\Admin\AdminFieldSetCatalog;
use App\Services\DynamicField\Admin\FieldDefinitionCustomWriter;
use App\Services\DynamicField\Assignment\FieldSetAssignmentAdminWriter;
use App\Services\DynamicField\ConfigurationSnapshotFreezeService;
use App\Services\DynamicField\ConfigurationSnapshotMaterializer;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Inertia\Testing\AssertableInertia;
use Tests\Concerns\CreatesSavedCalculation;
use Tests\Concerns\CreatesSpotClassicCatalog;
use Tests\TestCase;

/**
 * DF-3.3a2α / VER-002: Generation-2-Freeze aus Core + globalen Assignments,
 * Quellengraph, Property-Provenance und Fingerprint-Drift.
 */
class ConfigurationSnapshotDf33a2aTest extends TestCase
{
    use CreatesSavedCalculation;
    use CreatesSpotClassicCatalog;
    use RefreshDatabase;

    public function test_migration_adds_not_null_format_version_and_keeps_legacy_snapshots_on_generation_one(): void
    {
        $this->assertTrue(Schema::hasColumn('configuration_snapshots', 'format_version'));
        $this->assertTrue(Schema::hasColumn('configuration_snapshots', 'schema_fingerprint'));
        $this->assertTrue(Schema::hasTable('configuration_snapshot_sources'));
        $this->assertTrue(Schema::hasTable('configuration_snapshot_source_fields'));
        $this->assertTrue(Schema::hasTable('configuration_snapshot_source_rules'));

        $legacy = app(ConfigurationSnapshotMaterializer::class)->materializeFromActiveSet();
        $this->assertSame(
            ConfigurationSnapshot::FORMAT_VERSION_LEGACY,
            (int) $legacy->format_version,
        );

        $set = FieldSet::query()->where('key', AdminFieldSetCatalog::CALCULATION_CORE)->firstOrFail();

        $this->expectException(QueryException::class);

        DB::table('configuration_snapshots')->insert([
            'field_set_id' => $set->id,
            'field_set_version_id' => $set->active_version_id,
            'source' => 'seed_active',
            'format_version' => null,
            'created_at' => now(),
        ]);
    }

    public function test_new_calculation_freezes_generation_two_with_sources_and_provenance(): void
    {
        $catalog = $this->createSpotClassicCatalog();
        $calculation = $this->createSavedCalculation($catalog, [
            ['inventory_id' => $catalog['hamburg']->id],
        ]);

        $snapshot = ConfigurationSnapshot::query()->findOrFail($calculation->configuration_snapshot_id);

        $this->assertSame(
            ConfigurationSnapshot::FORMAT_VERSION_GLOBAL_FREEZE,
            (int) $snapshot->format_version,
        );
        $this->assertNotNull($snapshot->schema_fingerprint);
        $this->assertGreaterThanOrEqual(1, $snapshot->sources()->count());
        $this->assertTrue(
            ConfigurationSnapshotSource::query()
                ->where('configuration_snapshot_id', $snapshot->id)
                ->where('role', ConfigurationSnapshotSource::ROLE_CORE)
                ->exists(),
        );

        $definitions = SnapshotFieldDefinition::query()
            ->where('configuration_snapshot_id', $snapshot->id)
            ->get();
        $this->assertGreaterThanOrEqual(3, $definitions->count());

        foreach ($definitions as $definition) {
            foreach (SnapshotFieldDefinition::PROVENANCE_COLUMNS as $column) {
                $this->assertNotNull(
                    $definition->{$column},
                    "Feld „{$definition->key}“ ohne {$column}.",
                );
            }
        }

        $rules = SnapshotFieldRule::query()
            ->where('configuration_snapshot_id', $snapshot->id)
            ->get();
        $this->assertGreaterThanOrEqual(1, $rules->count());

        foreach ($rules as $rule) {
            $this->assertNotNull($rule->provenance_source_id);
        }
    }

    public function test_active_global_assignment_field_is_offered_and_frozen_for_new_calculations(): void
    {
        $admin = User::factory()->role(Role::Admin)->create();
        $customKey = $this->activateGlobalCalculationField($admin)->key;
        $sales = User::factory()->role(Role::Sales)->create();

        $this->actingAs($sales)
            ->get(route('calculations.create'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('fieldSchema.format_version', ConfigurationSnapshot::FORMAT_VERSION_GLOBAL_FREEZE)
                ->where('fieldSchema.fields', fn ($fields): bool => collect($fields)
                    ->pluck('key')
                    ->contains($customKey))
            );

        $created = $this->actingAs($sales)
            ->postJson(route('calculations.field-schema'))
            ->assertOk();

        $this->assertContains($customKey, array_column($created->json('fieldSchema.fields'), 'key'));
        $this->assertSame(
            ConfigurationSnapshot::FORMAT_VERSION_GLOBAL_FREEZE,
            $created->json('fieldSchema.format_version'),
        );
        $this->assertSame(
            ConfigurationSnapshot::FORMAT_VERSION_GLOBAL_FREEZE,
            $created->json('target_format_version'),
        );
        $this->assertNotEmpty($created->json('fieldSchema.schema_fingerprint'));

        $catalog = $this->createSpotClassicCatalog();
        $calculation = $this->createSavedCalculation($catalog, [
            ['inventory_id' => $catalog['hamburg']->id],
        ], $sales);

        $this->assertContains(
            $customKey,
            SnapshotFieldDefinition::query()
                ->where('configuration_snapshot_id', $calculation->configuration_snapshot_id)
                ->pluck('key')
                ->all(),
        );

        $existing = $this->actingAs($sales)
            ->postJson(route('calculations.field-schema'), ['calculation_id' => $calculation->id])
            ->assertOk();

        $snapshot = ConfigurationSnapshot::query()->findOrFail($calculation->configuration_snapshot_id);
        $this->assertSame(
            (int) $snapshot->format_version,
            $existing->json('fieldSchema.format_version'),
        );
        $this->assertSame(
            $snapshot->schema_fingerprint,
            $existing->json('fieldSchema.schema_fingerprint'),
        );
    }

    public function test_stale_schema_fingerprint_on_create_is_rejected_with_409_before_field_validation(): void
    {
        $catalog = $this->createSpotClassicCatalog();
        $user = User::factory()->role(Role::Sales)->create();

        $drifted = $this->basePayload($catalog);
        $drifted['schema_fingerprint'] = str_repeat('a', 64);
        $drifted['dynamic_field_values']['unknown_field'] = 'x';

        $this->actingAs($user)
            ->post(route('calculations.store'), $drifted)
            ->assertStatus(409);

        $this->assertSame(0, Calculation::query()->count());

        $fresh = $this->basePayload($catalog);
        $fresh['schema_fingerprint'] = app(ConfigurationSnapshotFreezeService::class)
            ->resolveLiveSchemaForCalculation()['schema_fingerprint'];

        $this->actingAs($user)
            ->post(route('calculations.store'), $fresh)
            ->assertRedirect();

        $this->assertSame(1, Calculation::query()->count());
    }

    public function test_update_of_generation_one_calculation_keeps_snapshot_and_format_version(): void
    {
        $catalog = $this->createSpotClassicCatalog();
        $user = User::factory()->role(Role::Sales)->create();
        $calculation = $this->createSavedCalculation($catalog, [
            ['inventory_id' => $catalog['hamburg']->id],
        ], $user);
        $snapshotId = (int) $calculation->configuration_snapshot_id;

        DB::table('configuration_snapshots')
            ->where('id', $snapshotId)
            ->update(['format_version' => ConfigurationSnapshot::FORMAT_VERSION_LEGACY]);

        $position = $calculation->positions()->firstOrFail();
        $payload = $this->basePayload($catalog);
        $payload['customer_name'] = 'Generation 1';
        $payload['lock_version'] = $calculation->lock_version;
        $payload['positions'][0]['id'] = $position->id;
        $payload['positions'][0]['client_key'] = $position->client_key;

        $this->actingAs($user)
            ->put(route('calculations.update', $calculation), $payload)
            ->assertRedirect();

        $calculation->refresh();
        $this->assertSame('Generation 1', $calculation->customer_name);
        $this->assertSame($snapshotId, (int) $calculation->configuration_snapshot_id);
        $this->assertSame(
            ConfigurationSnapshot::FORMAT_VERSION_LEGACY,
            (int) DB::table('configuration_snapshots')->where('id', $snapshotId)->value('format_version'),
        );
    }

    public function test_dispo_order_create_freezes_generation_two_from_calculation_snapshot(): void
    {
        $calculation = $this->savedCalculation();
        $user = User::factory()->role(Role::Sales)->create();

        $order = app(DispoOrderWriter::class)
            ->createFromCalculation($calculation, $calculation->positions()->pluck('id')->all(), $user)
            ->order
            ->fresh();

        $snapshot = ConfigurationSnapshot::query()->findOrFail($order->configuration_snapshot_id);

        $this->assertSame(
            ConfigurationSnapshot::FORMAT_VERSION_GLOBAL_FREEZE,
            (int) $snapshot->format_version,
        );
        $this->assertSame(
            (int) $calculation->configuration_snapshot_id,
            (int) $snapshot->source_configuration_snapshot_id,
        );
        $this->assertNotNull($snapshot->schema_fingerprint);
        $this->assertTrue(
            ConfigurationSnapshotSource::query()
                ->where('configuration_snapshot_id', $snapshot->id)
                ->where('target_identity', ConfigurationSnapshotSource::TARGET_IDENTITY_CALC_ORIGIN)
                ->exists(),
        );
    }

    public function test_dispo_revision_clones_format_version_and_keys_into_a_new_snapshot(): void
    {
        $calculation = $this->savedCalculation();
        $creator = User::factory()->role(Role::Sales)->create();
        $approver = User::factory()->role(Role::Sales)->create();
        $writer = app(DispoOrderWriter::class);
        $approvals = app(DispoOrderApprovalService::class);

        $predecessor = $writer->createFromCalculation(
            $calculation,
            $calculation->positions()->pluck('id')->all(),
            $creator,
        )->order;

        $approvals->submit($predecessor, $creator, $predecessor->lock_version);
        $predecessor->refresh();
        $approvals->reject($predecessor, $approver, $predecessor->lock_version, 'Bitte nachbessern');
        $predecessor->refresh();

        $successor = $writer->createRevision(
            $predecessor,
            $calculation->fresh(['positions', 'configurationSnapshot']),
            $calculation->positions()->pluck('id')->all(),
            $creator,
        )->order->fresh();

        $predecessorSnapshot = ConfigurationSnapshot::query()
            ->findOrFail($predecessor->configuration_snapshot_id);
        $successorSnapshot = ConfigurationSnapshot::query()
            ->findOrFail($successor->configuration_snapshot_id);

        $this->assertNotSame((int) $predecessorSnapshot->id, (int) $successorSnapshot->id);
        $this->assertSame(
            (int) $predecessorSnapshot->format_version,
            (int) $successorSnapshot->format_version,
        );
        $this->assertSame(
            $predecessorSnapshot->schema_fingerprint,
            $successorSnapshot->schema_fingerprint,
        );
        $this->assertSame(
            $this->snapshotKeys($predecessorSnapshot),
            $this->snapshotKeys($successorSnapshot),
        );

        $clonedSourceIds = ConfigurationSnapshotSource::query()
            ->where('configuration_snapshot_id', $successorSnapshot->id)
            ->pluck('id')
            ->map(static fn ($id): int => (int) $id)
            ->all();
        $this->assertNotEmpty($clonedSourceIds);

        foreach (SnapshotFieldDefinition::query()
            ->where('configuration_snapshot_id', $successorSnapshot->id)
            ->get() as $definition) {
            foreach (SnapshotFieldDefinition::PROVENANCE_COLUMNS as $column) {
                $this->assertContains((int) $definition->{$column}, $clonedSourceIds);
            }
        }
    }

    public function test_provenance_cannot_point_to_a_source_of_another_snapshot(): void
    {
        $catalog = $this->createSpotClassicCatalog();
        $first = $this->createSavedCalculation($catalog, [
            ['inventory_id' => $catalog['hamburg']->id],
        ]);
        $second = $this->createSavedCalculation($catalog, [
            ['inventory_id' => $catalog['rock']->id],
        ]);

        $foreignSourceId = (int) ConfigurationSnapshotSource::query()
            ->where('configuration_snapshot_id', $second->configuration_snapshot_id)
            ->value('id');
        $this->assertGreaterThan(0, $foreignSourceId);

        $definitionId = (int) SnapshotFieldDefinition::query()
            ->where('configuration_snapshot_id', $first->configuration_snapshot_id)
            ->value('id');

        $this->expectException(QueryException::class);

        DB::table('snapshot_field_definitions')
            ->where('id', $definitionId)
            ->update(['provenance_definition_source_id' => $foreignSourceId]);
    }

    private function savedCalculation(): Calculation
    {
        $catalog = $this->createSpotClassicCatalog();

        return $this->createSavedCalculation($catalog, [
            ['inventory_id' => $catalog['hamburg']->id],
        ]);
    }

    /**
     * @return list<string>
     */
    private function snapshotKeys(ConfigurationSnapshot $snapshot): array
    {
        return SnapshotFieldDefinition::query()
            ->where('configuration_snapshot_id', $snapshot->id)
            ->orderBy('key')
            ->pluck('key')
            ->all();
    }

    /**
     * Freies Feldset mit einem Header-Textfeld, global für Kalkulationen aktiv.
     */
    private function activateGlobalCalculationField(User $admin): FieldDefinition
    {
        $key = 'df33a2a_'.bin2hex(random_bytes(3));

        $this->actingAs($admin)
            ->post(route('administration.dynamic-fields.field-sets.store'), [
                'name' => 'Freies Set '.$key,
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
            'label' => 'Globales Feld '.$key,
            'field_type' => FieldType::ShortText,
            'scope' => FieldScope::Header,
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
        $assignment = $writer->create([
            'field_set_id' => $fieldSet->id,
            'target_layer' => 'global',
            'applies_to_process' => FieldAppliesTo::Calculation->value,
        ], $admin);

        $writer->activate($assignment, [
            'lock_version' => $assignment->lock_version,
            'fingerprint' => $writer->canonicalActivationFingerprint(
                $writer->previewAffectedContexts($assignment, asCandidate: true),
            ),
        ], $admin);

        return $definition;
    }

    /**
     * @param  array{hamburg: mixed, rock: mixed, medium: mixed}  $catalog
     * @return array<string, mixed>
     */
    private function basePayload(array $catalog): array
    {
        return [
            'planning_mode' => 'manual',
            'customer_name' => 'Freeze Kunde',
            'agency_name' => null,
            'campaign' => 'Freeze',
            'product_title' => 'Titel',
            'order_discount_percent' => '0',
            'ae_enabled' => false,
            'dynamic_field_values' => [
                'campaign_period' => null,
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
        ];
    }
}
