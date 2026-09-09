<?php

namespace Tests\Feature\DynamicField;

use App\Enums\Role;
use App\Models\Calculation;
use App\Models\ConfigurationSnapshot;
use App\Models\ConfigurationSnapshotSourceField;
use App\Models\SnapshotFieldDefinition;
use App\Models\SnapshotFieldRule;
use App\Models\User;
use App\Services\DispoOrder\DispoOrderWriter;
use App\Services\DynamicField\ConfigurationSnapshotCloneService;
use App\Services\DynamicField\ConfigurationSnapshotFreezeService;
use App\Services\DynamicField\ConfigurationSnapshotMaterializer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Tests\Concerns\CreatesSavedCalculation;
use Tests\Concerns\CreatesSpotClassicCatalog;
use Tests\TestCase;

/**
 * DF-3.3a2α-Härtung: verpflichtender Create-Fingerprint, v2-Integrität, Schema-API.
 */
class ConfigurationSnapshotDf33a2aHardeningTest extends TestCase
{
    use CreatesSavedCalculation;
    use CreatesSpotClassicCatalog;
    use RefreshDatabase;

    public function test_create_without_schema_fingerprint_is_rejected_with_422(): void
    {
        $catalog = $this->createSpotClassicCatalog();
        $user = User::factory()->role(Role::Sales)->create();
        $payload = $this->basePayload($catalog);
        unset($payload['schema_fingerprint']);

        $this->actingAs($user)
            ->postJson(route('calculations.store'), $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['schema_fingerprint']);

        $this->assertSame(0, Calculation::query()->count());
    }

    public function test_create_with_null_schema_fingerprint_is_rejected_with_422(): void
    {
        $catalog = $this->createSpotClassicCatalog();
        $user = User::factory()->role(Role::Sales)->create();
        $payload = $this->basePayload($catalog);
        $payload['schema_fingerprint'] = null;

        $this->actingAs($user)
            ->postJson(route('calculations.store'), $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['schema_fingerprint']);
    }

    public function test_create_with_empty_schema_fingerprint_is_rejected_with_422(): void
    {
        $catalog = $this->createSpotClassicCatalog();
        $user = User::factory()->role(Role::Sales)->create();
        $payload = $this->basePayload($catalog);
        $payload['schema_fingerprint'] = '';

        $this->actingAs($user)
            ->postJson(route('calculations.store'), $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['schema_fingerprint']);
    }

    public function test_create_with_wrong_length_schema_fingerprint_is_rejected_with_422(): void
    {
        $catalog = $this->createSpotClassicCatalog();
        $user = User::factory()->role(Role::Sales)->create();
        $payload = $this->basePayload($catalog);
        $payload['schema_fingerprint'] = str_repeat('a', 63);

        $this->actingAs($user)
            ->postJson(route('calculations.store'), $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['schema_fingerprint']);
    }

    public function test_create_with_non_hex_schema_fingerprint_is_rejected_with_422(): void
    {
        $catalog = $this->createSpotClassicCatalog();
        $user = User::factory()->role(Role::Sales)->create();
        $payload = $this->basePayload($catalog);
        $payload['schema_fingerprint'] = str_repeat('g', 64);

        $this->actingAs($user)
            ->postJson(route('calculations.store'), $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['schema_fingerprint']);
    }

    public function test_create_with_current_schema_fingerprint_succeeds(): void
    {
        $catalog = $this->createSpotClassicCatalog();
        $user = User::factory()->role(Role::Sales)->create();

        $this->actingAs($user)
            ->post(route('calculations.store'), $this->basePayload($catalog))
            ->assertRedirect();

        $this->assertSame(1, Calculation::query()->count());
    }

    public function test_create_with_stale_but_valid_fingerprint_is_409_before_dynamic_field_422(): void
    {
        $catalog = $this->createSpotClassicCatalog();
        $user = User::factory()->role(Role::Sales)->create();
        $payload = $this->basePayload($catalog);
        $payload['schema_fingerprint'] = str_repeat('a', 64);
        $payload['dynamic_field_values']['unknown_field'] = 'x';

        $this->actingAs($user)
            ->post(route('calculations.store'), $payload)
            ->assertStatus(409);

        $this->assertSame(0, Calculation::query()->count());
    }

    public function test_preview_update_and_budget_do_not_require_create_fingerprint(): void
    {
        $catalog = $this->createSpotClassicCatalog();
        $user = User::factory()->role(Role::Sales)->create();
        $payload = $this->basePayload($catalog);
        unset($payload['schema_fingerprint']);

        $this->actingAs($user)
            ->postJson(route('calculations.preview'), $payload)
            ->assertOk();

        $calculation = $this->createSavedCalculation($catalog, [
            ['inventory_id' => $catalog['hamburg']->id],
        ], $user);
        $position = $calculation->positions()->firstOrFail();
        $update = $this->basePayload($catalog);
        $update['schema_fingerprint'] = (string) $calculation->configurationSnapshot->schema_fingerprint;
        $update['lock_version'] = $calculation->lock_version;
        $update['positions'][0]['id'] = $position->id;
        $update['positions'][0]['client_key'] = $position->client_key;
        $update['positions'] = $this->withPositionSchemaFingerprints($calculation, $update['positions']);

        $this->actingAs($user)
            ->put(route('calculations.update', $calculation), $update)
            ->assertRedirect();

        // Preview und Budget-Propose bleiben ohne Client-Fingerprint zulässig.
        $preview = $this->basePayload($catalog);
        unset($preview['schema_fingerprint']);
        $this->actingAs($user)
            ->postJson(route('calculations.preview'), $preview)
            ->assertOk();

        $this->actingAs($user)
            ->postJson(route('calculations.budget-propose'), [
                'planning_mode' => 'budget',
                'target_budget_nn' => '500',
                'budget_wish_inventory_ids' => [$catalog['hamburg']->id],
                'budget_spot_length_seconds' => 30,
                'budget_distribution_ranges' => [[
                    'start_hour' => 8,
                    'end_hour_exclusive' => 12,
                    'day_group' => 'mo_fr',
                ]],
                'order_discount_percent' => '0',
                'ae_enabled' => false,
            ])
            ->assertOk();
    }

    public function test_field_schema_api_returns_generation_three_for_new_calculation(): void
    {
        $user = User::factory()->role(Role::Sales)->create();

        $response = $this->actingAs($user)
            ->postJson(route('calculations.field-schema'))
            ->assertOk();

        $this->assertSame(
            ConfigurationSnapshot::FORMAT_VERSION_CONTEXTUAL_FREEZE,
            $response->json('fieldSchema.format_version'),
        );
        $this->assertSame(
            ConfigurationSnapshot::FORMAT_VERSION_CONTEXTUAL_FREEZE,
            $response->json('target_format_version'),
        );
        $this->assertSame(
            $response->json('fieldSchema.format_version'),
            $response->json('format_version'),
        );
    }

    public function test_field_schema_api_returns_generation_one_for_existing_v1_calculation(): void
    {
        $catalog = $this->createSpotClassicCatalog();
        $user = User::factory()->role(Role::Sales)->create();
        $calculation = $this->createSavedCalculation($catalog, [
            ['inventory_id' => $catalog['hamburg']->id],
        ], $user);

        DB::table('configuration_snapshots')
            ->where('id', $calculation->configuration_snapshot_id)
            ->update([
                'format_version' => ConfigurationSnapshot::FORMAT_VERSION_LEGACY,
                'schema_fingerprint' => null,
            ]);

        $response = $this->actingAs($user)
            ->postJson(route('calculations.field-schema'), ['calculation_id' => $calculation->id])
            ->assertOk();

        $this->assertSame(
            ConfigurationSnapshot::FORMAT_VERSION_LEGACY,
            $response->json('fieldSchema.format_version'),
        );
        $this->assertSame(
            ConfigurationSnapshot::FORMAT_VERSION_LEGACY,
            $response->json('target_format_version'),
        );
        $this->assertSame(
            ConfigurationSnapshot::FORMAT_VERSION_LEGACY,
            $response->json('format_version'),
        );
    }

    public function test_field_schema_api_returns_generation_three_for_existing_v3_calculation(): void
    {
        $catalog = $this->createSpotClassicCatalog();
        $user = User::factory()->role(Role::Sales)->create();
        $calculation = $this->createSavedCalculation($catalog, [
            ['inventory_id' => $catalog['hamburg']->id],
        ], $user);

        $response = $this->actingAs($user)
            ->postJson(route('calculations.field-schema'), ['calculation_id' => $calculation->id])
            ->assertOk();

        $this->assertSame(
            ConfigurationSnapshot::FORMAT_VERSION_CONTEXTUAL_FREEZE,
            $response->json('fieldSchema.format_version'),
        );
        $this->assertSame(
            ConfigurationSnapshot::FORMAT_VERSION_CONTEXTUAL_FREEZE,
            $response->json('target_format_version'),
        );
    }

    public function test_field_schema_rejects_invalid_calculation_id(): void
    {
        $user = User::factory()->role(Role::Sales)->create();

        $this->actingAs($user)
            ->postJson(route('calculations.field-schema'), ['calculation_id' => 'abc'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['calculation_id']);
    }

    public function test_field_schema_rejects_unauthorized_calculation(): void
    {
        $catalog = $this->createSpotClassicCatalog();
        $owner = User::factory()->role(Role::Sales)->create();
        $other = User::factory()->role(Role::ProductManagement)->create();
        $calculation = $this->createSavedCalculation($catalog, [
            ['inventory_id' => $catalog['hamburg']->id],
        ], $owner);

        $this->actingAs($other)
            ->postJson(route('calculations.field-schema'), ['calculation_id' => $calculation->id])
            ->assertForbidden();
    }

    public function test_v2_without_sources_is_not_readable(): void
    {
        $snapshot = $this->freshV2CalcSnapshot();
        $this->corruptV2ByRemovingSources((int) $snapshot->id);

        Log::spy();
        $this->expectException(RuntimeException::class);
        $snapshot->fresh()->assertReadable();
    }

    public function test_v2_without_schema_fingerprint_is_not_readable(): void
    {
        $snapshot = $this->freshV2CalcSnapshot();
        DB::table('configuration_snapshots')
            ->where('id', $snapshot->id)
            ->update(['schema_fingerprint' => null]);

        $this->expectException(RuntimeException::class);
        $snapshot->fresh()->assertReadable();
    }

    public function test_v2_definition_without_property_provenance_is_not_readable(): void
    {
        $snapshot = $this->freshV2CalcSnapshot();
        $definition = SnapshotFieldDefinition::query()
            ->where('configuration_snapshot_id', $snapshot->id)
            ->firstOrFail();

        DB::table('snapshot_field_definitions')
            ->where('id', $definition->id)
            ->update(['provenance_definition_source_id' => null]);

        $this->expectException(RuntimeException::class);
        $snapshot->fresh(['fieldDefinitions', 'rules', 'sources'])->assertReadable();
    }

    public function test_provenance_source_without_matching_source_field_is_not_readable(): void
    {
        $snapshot = $this->freshV2CalcSnapshot();
        $definition = SnapshotFieldDefinition::query()
            ->where('configuration_snapshot_id', $snapshot->id)
            ->firstOrFail();

        ConfigurationSnapshotSourceField::query()
            ->where('configuration_snapshot_source_id', $definition->provenance_definition_source_id)
            ->where('field_definition_id', $definition->field_definition_id)
            ->delete();

        $this->expectException(RuntimeException::class);
        $snapshot->fresh(['fieldDefinitions', 'rules', 'sources.fields', 'sources.rules'])->assertReadable();
    }

    public function test_v2_rule_without_provenance_or_dedupe_key_is_not_readable(): void
    {
        $snapshot = $this->freshV2CalcSnapshot();
        $rule = SnapshotFieldRule::query()
            ->where('configuration_snapshot_id', $snapshot->id)
            ->firstOrFail();

        DB::table('snapshot_field_rules')
            ->where('id', $rule->id)
            ->update([
                'provenance_source_id' => null,
                'dedupe_key' => null,
            ]);

        $this->expectException(RuntimeException::class);
        $snapshot->fresh(['fieldDefinitions', 'rules', 'sources'])->assertReadable();
    }

    public function test_damaged_predecessor_cannot_be_cloned(): void
    {
        $catalog = $this->createSpotClassicCatalog();
        $calculation = $this->createSavedCalculation($catalog, [
            ['inventory_id' => $catalog['hamburg']->id],
        ]);
        $user = User::factory()->role(Role::Sales)->create();
        $order = app(DispoOrderWriter::class)
            ->createFromCalculation($calculation, $calculation->positions()->pluck('id')->all(), $user)
            ->order;

        $snapshot = ConfigurationSnapshot::query()->findOrFail($order->configuration_snapshot_id);
        $this->corruptV2ByRemovingSources((int) $snapshot->id);

        $this->expectException(RuntimeException::class);
        app(ConfigurationSnapshotCloneService::class)->cloneForDispoRevision($snapshot->fresh());
    }

    public function test_damaged_calc_v2_snapshot_cannot_be_composed_to_dispo(): void
    {
        $catalog = $this->createSpotClassicCatalog();
        $calculation = $this->createSavedCalculation($catalog, [
            ['inventory_id' => $catalog['hamburg']->id],
        ]);
        $user = User::factory()->role(Role::Sales)->create();

        $this->corruptV2ByRemovingSources((int) $calculation->configuration_snapshot_id);

        $this->expectException(RuntimeException::class);
        app(DispoOrderWriter::class)
            ->createFromCalculation($calculation->fresh(), $calculation->positions()->pluck('id')->all(), $user);
    }

    public function test_generation_one_snapshots_remain_readable(): void
    {
        $legacy = app(ConfigurationSnapshotMaterializer::class)->materializeFromActiveSet();
        $this->assertSame(ConfigurationSnapshot::FORMAT_VERSION_LEGACY, (int) $legacy->format_version);
        $legacy->assertReadable();
    }

    private function freshV2CalcSnapshot(): ConfigurationSnapshot
    {
        $fingerprint = app(ConfigurationSnapshotFreezeService::class)
            ->resolveLiveSchemaForCalculation()['schema_fingerprint'];

        return app(ConfigurationSnapshotFreezeService::class)
            ->freezeCalculationV2($fingerprint)
            ->load(['fieldDefinitions', 'rules', 'sources.fields', 'sources.rules']);
    }

    private function corruptV2ByRemovingSources(int $snapshotId): void
    {
        $sourceIds = DB::table('configuration_snapshot_sources')
            ->where('configuration_snapshot_id', $snapshotId)
            ->pluck('id')
            ->all();

        DB::connection()->getSchemaBuilder()->disableForeignKeyConstraints();
        try {
            foreach (SnapshotFieldDefinition::PROVENANCE_COLUMNS as $column) {
                DB::table('snapshot_field_definitions')
                    ->where('configuration_snapshot_id', $snapshotId)
                    ->update([$column => null]);
            }
            DB::table('snapshot_field_rules')
                ->where('configuration_snapshot_id', $snapshotId)
                ->update([
                    'provenance_source_id' => null,
                    'dedupe_key' => null,
                ]);
            if ($sourceIds !== []) {
                DB::table('configuration_snapshot_source_rules')
                    ->whereIn('configuration_snapshot_source_id', $sourceIds)
                    ->delete();
                DB::table('configuration_snapshot_source_fields')
                    ->whereIn('configuration_snapshot_source_id', $sourceIds)
                    ->delete();
                DB::table('configuration_snapshot_sources')
                    ->where('configuration_snapshot_id', $snapshotId)
                    ->delete();
            }
        } finally {
            DB::connection()->getSchemaBuilder()->enableForeignKeyConstraints();
        }
    }

    /**
     * @param  array{hamburg: mixed, rock: mixed, medium: mixed}  $catalog
     * @return array<string, mixed>
     */
    private function basePayload(array $catalog): array
    {
        return $this->withLiveSchemaFingerprint([
            'planning_mode' => 'manual',
            'customer_name' => 'Härtung Kunde',
            'agency_name' => null,
            'campaign' => 'Härtung',
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
        ]);
    }
}
