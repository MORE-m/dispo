<?php

namespace Tests\Feature\DynamicField;

use App\Enums\Role;
use App\Models\Calculation;
use App\Models\ConfigurationSnapshot;
use App\Models\FieldDefinition;
use App\Models\FieldDefinitionRevision;
use App\Models\FieldSet;
use App\Models\FieldSetVersion;
use App\Models\FieldSetVersionField;
use App\Models\SnapshotFieldDefinition;
use App\Models\User;
use App\Services\DynamicField\ConfigurationSnapshotMaterializer;
use Illuminate\Foundation\Testing\RefreshDatabase;
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

    public function test_ve_r_002_migration_backfills_configuration_snapshot_on_existing_calculations(): void
    {
        $catalog = $this->createSpotClassicCatalog();
        $calculation = $this->createSavedCalculation($catalog, [
            ['inventory_id' => $catalog['hamburg']->id],
        ]);

        $this->assertNotNull($calculation->fresh()->configuration_snapshot_id);
        $this->assertDatabaseHas('calculation_position_field_values', [
            'calculation_position_id' => $calculation->positions()->first()->id,
            'value_boolean' => 1,
        ]);
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
                'field_definition_revision_id' => $def->current_revision_id,
                'sort' => $def->currentRevision?->sort_default ?? 0,
                'required_override' => null,
                'visible_override' => null,
            ]);
        }

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

    public function test_au_d_001_calculation_update_records_dynamic_values_in_audit_snapshot(): void
    {
        $catalog = $this->createSpotClassicCatalog();
        $user = User::factory()->role(Role::Sales)->create();
        $calculation = $this->createSavedCalculation($catalog, [
            ['inventory_id' => $catalog['hamburg']->id],
        ], $user);

        $payload = $this->basePayload($catalog);
        $payload['lock_version'] = $calculation->lock_version;
        $payload['dynamic_field_values'] = [
            'campaign_period' => ['start' => '2026-04-01', 'end' => '2026-04-30'],
        ];
        $payload['positions'][0]['id'] = $calculation->positions()->first()->id;
        $payload['positions'][0]['client_key'] = $calculation->positions()->first()->client_key;

        $this->actingAs($user)
            ->put(route('calculations.update', $calculation), $payload)
            ->assertRedirect();

        $this->assertDatabaseHas('audit_events', [
            'auditable_type' => Calculation::class,
            'auditable_id' => $calculation->id,
            'action' => 'calculation.updated',
        ]);
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
        return [
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
        ];
    }
}
