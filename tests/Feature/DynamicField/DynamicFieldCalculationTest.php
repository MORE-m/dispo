<?php

namespace Tests\Feature\DynamicField;

use App\Enums\Role;
use App\Models\AuditEvent;
use App\Models\Calculation;
use App\Models\ConfigurationSnapshot;
use App\Models\FieldDefinition;
use App\Models\FieldDefinitionRevision;
use App\Models\FieldRule;
use App\Models\FieldSet;
use App\Models\FieldSetVersion;
use App\Models\FieldSetVersionField;
use App\Models\SnapshotFieldDefinition;
use App\Models\User;
use App\Services\DynamicField\ConfigurationSnapshotMaterializer;
use App\Services\DynamicField\SnapshotFieldRuleEvaluator;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\Concerns\CreatesSavedCalculation;
use Tests\Concerns\CreatesSpotClassicCatalog;
use Tests\TestCase;

/**
 * DF-1: DYN-001, DYN-004, DYN-006, VER-002, VER-007, PRI-003, AUD-001.
 */
class DynamicFieldCalculationTest extends TestCase
{
    use CreatesSavedCalculation;
    use CreatesSpotClassicCatalog;
    use RefreshDatabase;

    public function test_legacy_migration_backfills_snapshot_and_period_open_for_pre_df1_rows(): void
    {
        /** @var object{up: callable, down: callable} $migration */
        $migration = require database_path('migrations/2026_09_04_100000_create_dynamic_field_calculation_tables.php');

        // DF-3.3a2β: calculation_positions und dispo_order_positions tragen seit
        // Generation 3 Fremdschlüssel auf configuration_snapshots. Vor dem
        // DF-1-Rollback muss diese Migration zurückgedreht werden, sonst zeigen die
        // Fremdschlüssel auf eine entfernte Tabelle. Der Testfall prüft nur den
        // DF-1-Backfill und braucht Generation 3 danach nicht wieder.
        /** @var object{up: callable, down: callable} $contextualFreeze */
        $contextualFreeze = require database_path('migrations/2026_09_09_100000_add_configuration_snapshot_v3_contextual_freeze.php');

        [$calculationId, $positionId] = (function () use ($migration, $contextualFreeze): array {
            $contextualFreeze->down();
            $migration->down();

            $this->assertFalse(Schema::hasTable('field_definitions'));
            $this->assertFalse(Schema::hasColumn('calculations', 'configuration_snapshot_id'));

            $user = User::factory()->role(Role::Sales)->create();
            $catalog = $this->createSpotClassicCatalog();
            $now = now();

            $calculationId = DB::table('calculations')->insertGetId([
                'number' => 'K-2026-00999',
                'number_year' => 2026,
                'number_seq' => 999,
                'status' => 'draft',
                'planning_mode' => 'manual',
                'advisor_id' => $user->id,
                'customer_name' => 'Legacy Kunde',
                'agency_name' => null,
                'campaign' => 'Legacy',
                'product_title' => 'Titel',
                'briefing' => null,
                'order_discount_percent' => 0,
                'ae_enabled' => false,
                'target_budget_nn' => null,
                'budget_strategy' => null,
                'budget_proposal_status' => null,
                'media_gross' => 0,
                'position_discount_total' => 0,
                'order_discount_total' => 0,
                'ae_total' => 0,
                'nn_invest' => 0,
                'requires_special_approval' => false,
                'special_approval_reasons' => null,
                'personal_discount_limit_percent' => null,
                'lock_version' => 1,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            $positionId = DB::table('calculation_positions')->insertGetId([
                'calculation_id' => $calculationId,
                'client_key' => (string) Str::uuid(),
                'inventory_id' => $catalog['hamburg']->id,
                'advertising_medium_id' => $catalog['medium']->id,
                'inventory_medium_rule_id' => null,
                'price_list_id' => null,
                'kind' => 'spot_classic',
                'spot_method' => 'average',
                'length_seconds' => 30,
                'total_spot_count' => 10,
                'needs_spot_redistribution' => false,
                'average_second_price' => null,
                'length_index' => null,
                'surcharge_percent' => 0,
                'position_discount_percent' => 0,
                'ae_percent' => 15,
                'is_discountable' => true,
                'is_ae_eligible' => true,
                'price_list_version' => null,
                'media_gross' => 0,
                'position_discount_amount' => 0,
                'order_discount_amount' => 0,
                'ae_amount' => 0,
                'nn_invest' => 0,
                'sort' => 0,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            $this->assertSame(1, DB::table('calculation_positions')->where('id', $positionId)->count(), 'pre-up');

            $migration->up();

            return [$calculationId, $positionId];
        })();

        $calculation = DB::table('calculations')->where('id', $calculationId)->first();
        $this->assertNotNull($calculation->configuration_snapshot_id);

        $snapshot = DB::table('configuration_snapshots')
            ->where('id', $calculation->configuration_snapshot_id)
            ->first();
        $this->assertSame('legacy_backfill', $snapshot->source);

        $this->assertSame(
            3,
            DB::table('snapshot_field_definitions')
                ->where('configuration_snapshot_id', $snapshot->id)
                ->count(),
        );
        $this->assertSame(
            1,
            DB::table('snapshot_field_rules')
                ->where('configuration_snapshot_id', $snapshot->id)
                ->count(),
        );

        $periodOpenDefId = DB::table('snapshot_field_definitions')
            ->where('configuration_snapshot_id', $snapshot->id)
            ->where('key', 'period_open')
            ->value('id');
        $this->assertNotNull($periodOpenDefId);

        $this->assertTrue(
            DB::table('calculation_position_field_values')
                ->where('calculation_position_id', $positionId)
                ->where('snapshot_field_definition_id', $periodOpenDefId)
                ->where('value_boolean', true)
                ->exists(),
        );

        $this->assertSame(
            0,
            DB::table('calculations')->whereNull('configuration_snapshot_id')->count(),
        );
        $this->assertSame(3, DB::table('field_definitions')->count());
        $this->assertSame(3, DB::table('field_definition_revisions')->count());
        $this->assertSame(3, DB::table('field_set_version_fields')->count());
        $this->assertSame(1, DB::table('field_rules')->count());
        $this->assertSame(
            1,
            DB::table('calculation_position_field_values')
                ->where('calculation_position_id', $positionId)
                ->where('snapshot_field_definition_id', $periodOpenDefId)
                ->count(),
        );

        // Idempotenter Teil-Backfill: keine zweiten period_open-Werte.
        $ref = new \ReflectionObject($migration);
        $method = $ref->getMethod('backfillExistingCalculations');
        $method->setAccessible(true);
        $method->invoke($migration);

        $this->assertSame(
            1,
            DB::table('calculation_position_field_values')
                ->where('calculation_position_id', $positionId)
                ->where('snapshot_field_definition_id', $periodOpenDefId)
                ->count(),
        );
        // Zweiter Backfill erzeugt einen weiteren Snapshot, weist ihn aber keiner
        // bestehenden Kalkulation erneut zu (whereNull).
        $this->assertSame(
            1,
            DB::table('calculations')
                ->where('id', $calculationId)
                ->where('configuration_snapshot_id', $calculation->configuration_snapshot_id)
                ->count(),
        );
    }

    public function test_pr_i_003_calculation_can_be_saved_without_campaign_period(): void
    {
        $catalog = $this->createSpotClassicCatalog();
        $user = User::factory()->role(Role::Sales)->create();

        $payload = $this->basePayload($catalog);
        $payload['dynamic_field_values'] = ['campaign_period' => null];

        $this->actingAs($user)
            ->post(route('calculations.store'), $payload)
            ->assertRedirect();

        $calculation = Calculation::query()->latest('id')->firstOrFail();
        $this->assertNull(
            $calculation->fieldValues()
                ->whereHas('snapshotFieldDefinition', fn ($q) => $q->where('key', 'campaign_period'))
                ->first()
                ?->value_period_start,
        );
    }

    public function test_partial_campaign_period_is_rejected(): void
    {
        $catalog = $this->createSpotClassicCatalog();
        $user = User::factory()->role(Role::Sales)->create();
        $payload = $this->basePayload($catalog);
        $payload['dynamic_field_values'] = [
            'campaign_period' => ['start' => '2026-03-01', 'end' => null],
        ];

        $this->actingAs($user)
            ->post(route('calculations.store'), $payload)
            ->assertSessionHasErrors(['dynamic_field_values.campaign_period']);
    }

    public function test_campaign_period_start_after_end_is_rejected(): void
    {
        $catalog = $this->createSpotClassicCatalog();
        $user = User::factory()->role(Role::Sales)->create();
        $payload = $this->basePayload($catalog);
        $payload['dynamic_field_values'] = [
            'campaign_period' => ['start' => '2026-04-01', 'end' => '2026-03-01'],
        ];

        $this->actingAs($user)
            ->post(route('calculations.store'), $payload)
            ->assertSessionHasErrors(['dynamic_field_values.campaign_period']);
    }

    public function test_partial_flight_period_is_rejected_even_when_period_open(): void
    {
        $catalog = $this->createSpotClassicCatalog();
        $user = User::factory()->role(Role::Sales)->create();
        $payload = $this->basePayload($catalog);
        $payload['positions'][0]['dynamic_field_values'] = [
            'period_open' => true,
            'position_flight_period' => ['start' => '2026-03-01', 'end' => null],
        ];

        $this->actingAs($user)
            ->post(route('calculations.store'), $payload)
            ->assertSessionHasErrors([
                'positions.0.dynamic_field_values.position_flight_period',
            ]);
    }

    public function test_dy_n_006_period_open_false_requires_flight_period_from_snapshot_rule(): void
    {
        $catalog = $this->createSpotClassicCatalog();
        $user = User::factory()->role(Role::Sales)->create();
        $payload = $this->basePayload($catalog);
        $payload['positions'][0]['dynamic_field_values'] = [
            'period_open' => false,
            'position_flight_period' => null,
        ];

        $this->actingAs($user)
            ->post(route('calculations.store'), $payload)
            ->assertSessionHasErrors([
                'positions.0.dynamic_field_values.position_flight_period',
            ]);
    }

    public function test_dy_n_006_period_open_false_with_flight_period_succeeds(): void
    {
        $catalog = $this->createSpotClassicCatalog();
        $user = User::factory()->role(Role::Sales)->create();
        $payload = $this->basePayload($catalog);
        $payload['positions'][0]['dynamic_field_values'] = [
            'period_open' => false,
            'position_flight_period' => [
                'start' => '2026-03-01',
                'end' => '2026-03-31',
            ],
        ];

        $this->actingAs($user)
            ->post(route('calculations.store'), $payload)
            ->assertRedirect();

        $calculation = Calculation::query()->latest('id')->firstOrFail();
        $position = $calculation->positions()->firstOrFail();
        $values = $position->fieldValues()->with('snapshotFieldDefinition')->get()->keyBy(
            fn ($row) => $row->snapshotFieldDefinition->key,
        );

        $this->assertFalse((bool) $values['period_open']->value_boolean);
        $this->assertSame('2026-03-01', $values['position_flight_period']->value_period_start->toDateString());
        $this->assertSame('2026-03-31', $values['position_flight_period']->value_period_end->toDateString());
    }

    public function test_unknown_dynamic_field_key_is_rejected(): void
    {
        $catalog = $this->createSpotClassicCatalog();
        $user = User::factory()->role(Role::Sales)->create();
        $payload = $this->basePayload($catalog);
        $payload['dynamic_field_values']['unknown_field'] = 'x';

        $this->actingAs($user)
            ->post(route('calculations.store'), $payload)
            ->assertSessionHasErrors(['dynamic_field_values.unknown_field']);
    }

    public function test_header_field_in_position_payload_is_rejected(): void
    {
        $catalog = $this->createSpotClassicCatalog();
        $user = User::factory()->role(Role::Sales)->create();
        $payload = $this->basePayload($catalog);
        $payload['positions'][0]['dynamic_field_values']['campaign_period'] = [
            'start' => '2026-01-01',
            'end' => '2026-01-31',
        ];

        $this->actingAs($user)
            ->post(route('calculations.store'), $payload)
            ->assertSessionHasErrors([
                'positions.0.dynamic_field_values.campaign_period',
            ]);
    }

    public function test_unknown_rule_operator_blocks_snapshot_materialization(): void
    {
        $version = FieldSet::query()
            ->where('key', 'system_calculation_core')
            ->firstOrFail()
            ->activeVersion;

        FieldRule::query()->create([
            'field_set_version_id' => $version->id,
            'sort' => 99,
            'condition_json' => ['op' => 'unknown_op', 'field_key' => 'period_open', 'value' => false],
            'action_json' => ['op' => 'require_field', 'field_key' => 'position_flight_period'],
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Unbekannter Regel-Bedingungsoperator');

        app(ConfigurationSnapshotMaterializer::class)->materializeFromActiveSet();
    }

    public function test_field_set_version_rejects_two_revisions_of_same_definition(): void
    {
        $definition = FieldDefinition::query()->where('key', 'campaign_period')->firstOrFail();
        $set = FieldSet::query()->where('key', 'system_calculation_core')->firstOrFail();
        $version = FieldSetVersion::query()->create([
            'field_set_id' => $set->id,
            'version' => 99,
            'status' => 'draft',
            'created_at' => now(),
        ]);

        $rev1 = $definition->current_revision_id;
        $rev2 = FieldDefinitionRevision::query()->create([
            'field_definition_id' => $definition->id,
            'revision' => 99,
            'label' => 'Duplikat',
            'help_text' => null,
            'validation_json' => null,
            'group_key' => 'header',
            'sort_default' => 10,
            'reportable' => true,
            'created_at' => now(),
        ])->id;

        FieldSetVersionField::query()->create([
            'field_set_version_id' => $version->id,
            'field_definition_id' => $definition->id,
            'field_definition_revision_id' => $rev1,
            'sort' => 0,
        ]);

        $this->expectException(QueryException::class);

        FieldSetVersionField::query()->create([
            'field_set_version_id' => $version->id,
            'field_definition_id' => $definition->id,
            'field_definition_revision_id' => $rev2,
            'sort' => 1,
        ]);
    }

    public function test_ve_r_007_existing_calculation_keeps_old_snapshot_label_after_new_revision(): void
    {
        $catalog = $this->createSpotClassicCatalog();
        $user = User::factory()->role(Role::Sales)->create();
        $payload = $this->basePayload($catalog);
        $payload['dynamic_field_values'] = [
            'campaign_period' => ['start' => '2026-01-01', 'end' => '2026-02-01'],
        ];

        $this->actingAs($user)->post(route('calculations.store'), $payload)->assertRedirect();
        $old = Calculation::query()->latest('id')->firstOrFail();
        $oldSnapshotId = $old->configuration_snapshot_id;
        $oldLabel = SnapshotFieldDefinition::query()
            ->where('configuration_snapshot_id', $oldSnapshotId)
            ->where('key', 'campaign_period')
            ->value('label');

        $definition = FieldDefinition::query()->where('key', 'campaign_period')->firstOrFail();
        $newRevision = FieldDefinitionRevision::query()->create([
            'field_definition_id' => $definition->id,
            'revision' => 2,
            'label' => 'Kampagnenzeitraum (neu)',
            'help_text' => 'geändert',
            'validation_json' => null,
            'group_key' => 'header',
            'sort_default' => 10,
            'reportable' => true,
            'created_at' => now(),
        ]);
        $definition->current_revision_id = $newRevision->id;
        $definition->save();

        $set = FieldSet::query()->where('key', 'system_calculation_core')->firstOrFail();
        $newVersion = FieldSetVersion::query()->create([
            'field_set_id' => $set->id,
            'version' => 2,
            'status' => 'active',
            'created_at' => now(),
        ]);
        FieldSetVersion::query()->whereKey($set->active_version_id)->update(['status' => 'archived']);

        foreach (FieldDefinition::query()->with('currentRevision')->get() as $def) {
            FieldSetVersionField::query()->create([
                'field_set_version_id' => $newVersion->id,
                'field_definition_id' => $def->id,
                'field_definition_revision_id' => $def->current_revision_id,
                'sort' => $def->currentRevision?->sort_default ?? 0,
                'required_override' => null,
                'visible_override' => null,
            ]);
        }

        FieldRule::query()->create([
            'field_set_version_id' => $newVersion->id,
            'sort' => 0,
            'condition_json' => [
                'op' => SnapshotFieldRuleEvaluator::CONDITION_FIELD_EQUALS,
                'field_key' => 'period_open',
                'value' => false,
            ],
            'action_json' => [
                'op' => SnapshotFieldRuleEvaluator::ACTION_REQUIRE_FIELD,
                'field_key' => 'position_flight_period',
            ],
        ]);

        $set->active_version_id = $newVersion->id;
        $set->save();

        $this->actingAs($user)->post(route('calculations.store'), $this->basePayload($catalog))->assertRedirect();
        $newer = Calculation::query()->latest('id')->firstOrFail();

        $this->assertSame(
            $oldLabel,
            SnapshotFieldDefinition::query()
                ->where('configuration_snapshot_id', $old->fresh()->configuration_snapshot_id)
                ->where('key', 'campaign_period')
                ->value('label'),
        );
        $this->assertSame(
            'Kampagnenzeitraum (neu)',
            SnapshotFieldDefinition::query()
                ->where('configuration_snapshot_id', $newer->configuration_snapshot_id)
                ->where('key', 'campaign_period')
                ->value('label'),
        );
        $this->assertNotSame($oldSnapshotId, $newer->configuration_snapshot_id);
    }

    public function test_new_calculations_get_seed_active_snapshot_source(): void
    {
        $catalog = $this->createSpotClassicCatalog();
        $calculation = $this->createSavedCalculation($catalog, [
            ['inventory_id' => $catalog['hamburg']->id],
        ]);

        $snapshot = ConfigurationSnapshot::query()->findOrFail($calculation->configuration_snapshot_id);
        $this->assertSame('seed_active', $snapshot->source->value);
    }

    public function test_au_d_001_update_records_before_after_dynamic_values_per_position(): void
    {
        $catalog = $this->createSpotClassicCatalog();
        $user = User::factory()->role(Role::Sales)->create();
        $payload = $this->basePayload($catalog);
        $payload['positions'][] = [
            'inventory_id' => $catalog['rock']->id,
            'advertising_medium_id' => $catalog['medium']->id,
            'spot_method' => 'average',
            'length_seconds' => 30,
            'total_spot_count' => 5,
            'position_discount_percent' => '0',
            'ae_percent' => '15',
            'plan_rows' => [['hour' => 9, 'day_group' => 'mo_fr']],
            'dynamic_field_values' => [
                'period_open' => true,
                'position_flight_period' => null,
            ],
        ];
        $payload = $this->withLiveSchemaFingerprint($payload);

        $this->actingAs($user)->post(route('calculations.store'), $payload)->assertRedirect();
        $calculation = Calculation::query()->latest('id')->firstOrFail();
        $positions = $calculation->positions()->orderBy('sort')->get();
        $this->assertCount(2, $positions);

        $beforeCount = AuditEvent::query()
            ->where('auditable_type', Calculation::class)
            ->where('auditable_id', $calculation->id)
            ->where('action', 'calculation.updated')
            ->count();

        $update = $this->basePayload($catalog);
        $update['lock_version'] = $calculation->lock_version;
        $update['schema_fingerprint'] = (string) $calculation->configurationSnapshot->schema_fingerprint;
        $update['dynamic_field_values'] = [
            'campaign_period' => ['start' => '2026-04-01', 'end' => '2026-04-30'],
        ];
        $update['positions'] = [
            [
                'id' => $positions[0]->id,
                'client_key' => $positions[0]->client_key,
                'inventory_id' => $catalog['hamburg']->id,
                'advertising_medium_id' => $catalog['medium']->id,
                'spot_method' => 'average',
                'length_seconds' => 30,
                'total_spot_count' => 10,
                'position_discount_percent' => '0',
                'ae_percent' => '15',
                'plan_rows' => [['hour' => 8, 'day_group' => 'mo_fr']],
                'dynamic_field_values' => [
                    'period_open' => false,
                    'position_flight_period' => [
                        'start' => '2026-05-01',
                        'end' => '2026-05-15',
                    ],
                ],
            ],
            [
                'id' => $positions[1]->id,
                'client_key' => $positions[1]->client_key,
                'inventory_id' => $catalog['rock']->id,
                'advertising_medium_id' => $catalog['medium']->id,
                'spot_method' => 'average',
                'length_seconds' => 30,
                'total_spot_count' => 5,
                'position_discount_percent' => '0',
                'ae_percent' => '15',
                'plan_rows' => [['hour' => 9, 'day_group' => 'mo_fr']],
                'dynamic_field_values' => [
                    'period_open' => true,
                    'position_flight_period' => null,
                ],
            ],
        ];
        $update['positions'] = $this->withPositionSchemaFingerprints($calculation, $update['positions']);

        $this->actingAs($user)
            ->from(route('calculations.edit', $calculation))
            ->put(route('calculations.update', $calculation), $update)
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        $event = AuditEvent::query()
            ->where('auditable_type', Calculation::class)
            ->where('auditable_id', $calculation->id)
            ->where('action', 'calculation.updated')
            ->latest('id')
            ->firstOrFail();

        $this->assertSame($beforeCount + 1, AuditEvent::query()
            ->where('auditable_type', Calculation::class)
            ->where('auditable_id', $calculation->id)
            ->where('action', 'calculation.updated')
            ->count());

        $this->assertNull($event->old_values['dynamic_field_values']['campaign_period'] ?? null);
        $this->assertSame('2026-04-01', $event->new_values['dynamic_field_values']['campaign_period']['start']);
        $this->assertSame('2026-04-30', $event->new_values['dynamic_field_values']['campaign_period']['end']);

        $this->assertTrue($event->old_values['positions'][0]['dynamic_field_values']['period_open']);
        $this->assertFalse($event->new_values['positions'][0]['dynamic_field_values']['period_open']);
        $this->assertNull($event->old_values['positions'][0]['dynamic_field_values']['position_flight_period']);
        $this->assertSame(
            '2026-05-01',
            $event->new_values['positions'][0]['dynamic_field_values']['position_flight_period']['start'],
        );
        $this->assertTrue($event->new_values['positions'][1]['dynamic_field_values']['period_open']);
        $this->assertNull($event->new_values['positions'][1]['dynamic_field_values']['position_flight_period']);
    }

    public function test_failed_validation_does_not_write_update_audit(): void
    {
        $catalog = $this->createSpotClassicCatalog();
        $user = User::factory()->role(Role::Sales)->create();
        $calculation = $this->createSavedCalculation($catalog, [
            ['inventory_id' => $catalog['hamburg']->id],
        ], $user);

        $before = AuditEvent::query()
            ->where('auditable_id', $calculation->id)
            ->where('action', 'calculation.updated')
            ->count();

        $payload = $this->basePayload($catalog);
        $payload['lock_version'] = $calculation->lock_version;
        $payload['schema_fingerprint'] = (string) $calculation->configurationSnapshot->schema_fingerprint;
        $payload['positions'][0]['id'] = $calculation->positions()->first()->id;
        $payload['positions'][0]['client_key'] = $calculation->positions()->first()->client_key;
        $payload['positions'][0]['dynamic_field_values'] = [
            'period_open' => false,
            'position_flight_period' => null,
        ];
        $payload['positions'] = $this->withPositionSchemaFingerprints($calculation, $payload['positions']);

        $this->actingAs($user)
            ->put(route('calculations.update', $calculation), $payload)
            ->assertSessionHasErrors();

        $this->assertSame(
            $before,
            AuditEvent::query()
                ->where('auditable_id', $calculation->id)
                ->where('action', 'calculation.updated')
                ->count(),
        );
    }

    public function test_materializer_creates_snapshot_from_active_set(): void
    {
        $snapshot = app(ConfigurationSnapshotMaterializer::class)->materializeFromActiveSet();
        $this->assertGreaterThanOrEqual(3, $snapshot->fieldDefinitions()->count());
        $this->assertGreaterThanOrEqual(1, $snapshot->rules()->count());
    }

    /**
     * @param  array{hamburg: mixed, rock: mixed, medium: mixed}  $catalog
     * @return array<string, mixed>
     */
    private function basePayload(array $catalog): array
    {
        return $this->withLiveSchemaFingerprint([
            'planning_mode' => 'manual',
            'customer_name' => 'Dyn Kunde',
            'agency_name' => null,
            'campaign' => 'Dyn',
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
