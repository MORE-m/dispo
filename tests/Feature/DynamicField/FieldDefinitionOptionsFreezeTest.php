<?php

namespace Tests\Feature\DynamicField;

use App\Enums\FieldAppliesTo;
use App\Enums\FieldScope;
use App\Enums\FieldType;
use App\Enums\Role;
use App\Models\ConfigurationSnapshot;
use App\Models\ConfigurationSnapshotSourceField;
use App\Models\FieldDefinition;
use App\Models\FieldDefinitionRevision;
use App\Models\SnapshotFieldDefinition;
use App\Models\User;
use App\Services\DynamicField\Admin\FieldDefinitionOptionsWriter;
use App\Services\DynamicField\Admin\FieldSetVersionAdminWriter;
use App\Services\DynamicField\Assignment\FieldSetAssignmentAdminWriter;
use App\Services\DynamicField\ConfigurationSnapshotFreezeService;
use App\Services\DynamicField\ConfigurationSnapshotIntegrity;
use App\Support\DynamicField\FieldDefinitionOptionContract;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FieldDefinitionOptionsFreezeTest extends TestCase
{
    use RefreshDatabase;

    public function test_gen3_freeze_persists_options_and_passes_integrity(): void
    {
        $admin = User::factory()->role(Role::Admin)->create();
        $definition = $this->activateSelectGlobalAssignment($admin);

        $freeze = app(ConfigurationSnapshotFreezeService::class);
        $live = $freeze->resolveLiveSchemaForCalculationV3();
        $result = $freeze->freezeCalculationV3($live['schema_fingerprint'], []);

        /** @var ConfigurationSnapshot $snapshot */
        $snapshot = $result['base'];
        $this->assertSame(ConfigurationSnapshot::FORMAT_VERSION_CONTEXTUAL_FREEZE, (int) $snapshot->format_version);

        $sourceField = ConfigurationSnapshotSourceField::query()
            ->where('field_definition_id', $definition->id)
            ->firstOrFail();
        $effective = SnapshotFieldDefinition::query()
            ->where('configuration_snapshot_id', $snapshot->id)
            ->where('field_definition_id', $definition->id)
            ->firstOrFail();

        $expected = FieldDefinitionOptionContract::canonicalize([
            ['key' => 'opt_a', 'label' => 'Alpha', 'sort' => 10, 'is_active' => true],
            ['key' => 'opt_b', 'label' => 'Beta', 'sort' => 20, 'is_active' => false],
        ]);

        // MySQL-JSON kann Objekt-Key-Reihenfolge umschreiben; Vertrag über Canonical vergleichen.
        $this->assertSame(
            $expected,
            FieldDefinitionOptionContract::canonicalize($sourceField->options_json ?? []),
        );
        $this->assertSame(
            $expected,
            FieldDefinitionOptionContract::canonicalize($effective->options_json ?? []),
        );

        app(ConfigurationSnapshotIntegrity::class)->assertReadable($snapshot);
    }

    public function test_legacy_snapshots_without_choice_fields_remain_valid_with_null_options(): void
    {
        $freeze = app(ConfigurationSnapshotFreezeService::class);
        $live = $freeze->resolveLiveSchemaForCalculationV3();
        $result = $freeze->freezeCalculationV3($live['schema_fingerprint'], []);

        /** @var ConfigurationSnapshot $snapshot */
        $snapshot = $result['base'];

        foreach ($snapshot->fieldDefinitions as $definition) {
            $this->assertFalse($definition->field_type->isChoice());
            $this->assertNull($definition->options_json);
        }

        app(ConfigurationSnapshotIntegrity::class)->assertReadable($snapshot);
    }

    public function test_integrity_fails_closed_when_select_options_missing(): void
    {
        $admin = User::factory()->role(Role::Admin)->create();
        $definition = $this->activateSelectGlobalAssignment($admin);

        $freeze = app(ConfigurationSnapshotFreezeService::class);
        $live = $freeze->resolveLiveSchemaForCalculationV3();
        $result = $freeze->freezeCalculationV3($live['schema_fingerprint'], []);
        /** @var ConfigurationSnapshot $snapshot */
        $snapshot = $result['base'];

        SnapshotFieldDefinition::query()
            ->where('configuration_snapshot_id', $snapshot->id)
            ->where('field_definition_id', $definition->id)
            ->update(['options_json' => null]);

        $this->expectException(\RuntimeException::class);
        app(ConfigurationSnapshotIntegrity::class)->assertReadable($snapshot->fresh(['fieldDefinitions', 'sources.fields', 'sources.rules', 'rules']));
    }

    private function activateSelectGlobalAssignment(User $admin): FieldDefinition
    {
        $definition = new FieldDefinition;
        $definition->key = 'select_freeze_'.bin2hex(random_bytes(3));
        $definition->field_type = FieldType::Select;
        $definition->is_system = false;
        $definition->is_key_protected = false;
        $definition->scope = FieldScope::Header;
        $definition->applies_to = FieldAppliesTo::Both;
        $definition->is_active = true;
        $definition->lock_version = 1;
        $definition->save();

        $revision = new FieldDefinitionRevision;
        $revision->field_definition_id = $definition->id;
        $revision->revision = 1;
        $revision->label = 'Freeze Select';
        $revision->help_text = null;
        $revision->validation_json = null;
        $revision->group_key = null;
        $revision->sort_default = 0;
        $revision->reportable = true;
        $revision->created_at = now();
        $revision->save();
        $definition->current_revision_id = $revision->id;
        $definition->save();

        $optionsWriter = app(FieldDefinitionOptionsWriter::class);
        $optionsWriter->replace($definition, [
            'lock_version' => $definition->lock_version,
            'options' => [
                ['key' => 'opt_a', 'label' => 'Alpha', 'sort' => 10],
                ['key' => 'opt_b', 'label' => 'Beta', 'sort' => 20],
            ],
        ], $admin);
        $definition->refresh();
        $optionsWriter->replace($definition, [
            'lock_version' => $definition->lock_version,
            'options' => [
                ['key' => 'opt_a', 'label' => 'Alpha', 'sort' => 10],
            ],
        ], $admin);
        $definition->refresh();

        $fieldSets = app(FieldSetVersionAdminWriter::class);
        $fieldSet = $fieldSets->createFreeFieldSet([
            'name' => 'Select Freeze Set',
            'key' => 'select_freeze_set_'.bin2hex(random_bytes(3)),
            'applies_to' => FieldAppliesTo::Both->value,
        ], $admin);
        $draft = $fieldSet->versions()->where('status', 'draft')->firstOrFail();
        $fieldSet->refresh();
        $fieldSets->addCustomMembership(
            $fieldSet,
            $draft,
            [
                'field_definition_id' => $definition->id,
                'field_definition_revision_id' => (int) $definition->current_revision_id,
                'sort' => 20,
                'lock_version' => $fieldSet->lock_version,
            ],
            $admin,
        );
        $fieldSet->refresh();
        $fieldSets->activateDraft($fieldSet, $draft, $admin, $fieldSet->lock_version);

        $assignmentWriter = app(FieldSetAssignmentAdminWriter::class);
        $assignment = $assignmentWriter->create([
            'field_set_id' => $fieldSet->id,
            'target_layer' => 'global',
            'applies_to_process' => FieldAppliesTo::Calculation->value,
        ], $admin);
        $assignmentWriter->activate($assignment, [
            'lock_version' => $assignment->lock_version,
            'fingerprint' => $assignmentWriter->canonicalActivationFingerprint(
                $assignmentWriter->previewAffectedContexts($assignment, asCandidate: true),
            ),
        ], $admin);

        return $definition->fresh() ?? $definition;
    }
}
