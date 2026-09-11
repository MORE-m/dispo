<?php

namespace Tests\Feature\DynamicField;

use App\Enums\FieldAppliesTo;
use App\Enums\FieldScope;
use App\Enums\FieldSetVersionStatus;
use App\Enums\FieldType;
use App\Enums\Role;
use App\Models\FieldDefinition;
use App\Models\FieldDefinitionRevision;
use App\Models\FieldDefinitionRevisionOption;
use App\Models\FieldSet;
use App\Models\FieldSetVersion;
use App\Models\FieldSetVersionField;
use App\Models\User;
use App\Services\DynamicField\Admin\FieldDefinitionOptionsWriter;
use App\Services\DynamicField\Admin\FieldSetVersionAdminWriter;
use App\Services\DynamicField\Assignment\FieldSetAssignmentAdminWriter;
use App\Services\DynamicField\ConfigurationSnapshotFreezeService;
use App\Support\DynamicField\FieldDefinitionOptionContract;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * DF-3-REST-A Pre-PR: fehlende Vertragsfälle (No-op-Reihenfolge, Label/Sort,
 * Reaktivierung, Active-Option-Pflicht bei Activate/Freeze).
 */
class FieldDefinitionOptionsContractCoverageTest extends TestCase
{
    use RefreshDatabase;

    public function test_payload_order_alone_is_noop(): void
    {
        $admin = User::factory()->role(Role::Admin)->create();
        $definition = $this->createSelectDefinition('choice_order');
        $writer = app(FieldDefinitionOptionsWriter::class);

        $writer->replace($definition, [
            'lock_version' => $definition->lock_version,
            'options' => [
                ['key' => 'a', 'label' => 'A', 'sort' => 1],
                ['key' => 'b', 'label' => 'B', 'sort' => 2],
            ],
        ], $admin);
        $definition->refresh();
        $revisionId = (int) $definition->current_revision_id;
        $lock = (int) $definition->lock_version;

        $result = $writer->replace($definition, [
            'lock_version' => $lock,
            'options' => [
                ['key' => 'b', 'label' => 'B', 'sort' => 2],
                ['key' => 'a', 'label' => 'A', 'sort' => 1],
            ],
        ], $admin);

        $definition->refresh();
        $this->assertFalse($result['has_changes']);
        $this->assertSame($revisionId, (int) $definition->current_revision_id);
        $this->assertSame($lock, (int) $definition->lock_version);
    }

    public function test_label_change_creates_new_revision(): void
    {
        $admin = User::factory()->role(Role::Admin)->create();
        $definition = $this->createSelectDefinition('choice_label');
        $writer = app(FieldDefinitionOptionsWriter::class);
        $writer->replace($definition, [
            'lock_version' => $definition->lock_version,
            'options' => [
                ['key' => 'a', 'label' => 'Alt', 'sort' => 1],
            ],
        ], $admin);
        $definition->refresh();
        $before = (int) $definition->current_revision_id;

        $result = $writer->replace($definition, [
            'lock_version' => $definition->lock_version,
            'options' => [
                ['key' => 'a', 'label' => 'Neu', 'sort' => 1],
            ],
        ], $admin);

        $definition->refresh();
        $this->assertTrue($result['has_changes']);
        $this->assertNotSame($before, (int) $definition->current_revision_id);
        $this->assertDatabaseHas('field_definition_revision_options', [
            'field_definition_revision_id' => $before,
            'key' => 'a',
            'label' => 'Alt',
        ]);
        $this->assertDatabaseHas('field_definition_revision_options', [
            'field_definition_revision_id' => $definition->current_revision_id,
            'key' => 'a',
            'label' => 'Neu',
        ]);
    }

    public function test_sort_change_creates_new_revision(): void
    {
        $admin = User::factory()->role(Role::Admin)->create();
        $definition = $this->createSelectDefinition('choice_sort');
        $writer = app(FieldDefinitionOptionsWriter::class);
        $writer->replace($definition, [
            'lock_version' => $definition->lock_version,
            'options' => [
                ['key' => 'a', 'label' => 'A', 'sort' => 1],
            ],
        ], $admin);
        $definition->refresh();
        $before = (int) $definition->current_revision_id;

        $result = $writer->replace($definition, [
            'lock_version' => $definition->lock_version,
            'options' => [
                ['key' => 'a', 'label' => 'A', 'sort' => 99],
            ],
        ], $admin);

        $this->assertTrue($result['has_changes']);
        $definition->refresh();
        $this->assertNotSame($before, (int) $definition->current_revision_id);
    }

    public function test_reactivation_of_same_key_keeps_identity(): void
    {
        $admin = User::factory()->role(Role::Admin)->create();
        $definition = $this->createSelectDefinition('choice_reactivate');
        $writer = app(FieldDefinitionOptionsWriter::class);

        $writer->replace($definition, [
            'lock_version' => $definition->lock_version,
            'options' => [
                ['key' => 'keep', 'label' => 'Keep', 'sort' => 1],
                ['key' => 'temp', 'label' => 'Temp', 'sort' => 2],
            ],
        ], $admin);
        $definition->refresh();

        $writer->replace($definition, [
            'lock_version' => $definition->lock_version,
            'options' => [
                ['key' => 'keep', 'label' => 'Keep', 'sort' => 1],
            ],
        ], $admin);
        $definition->refresh();

        $result = $writer->replace($definition, [
            'lock_version' => $definition->lock_version,
            'options' => [
                ['key' => 'keep', 'label' => 'Keep', 'sort' => 1],
                ['key' => 'temp', 'label' => 'Temp again', 'sort' => 2],
            ],
        ], $admin);

        $this->assertTrue($result['has_changes']);
        $definition->refresh();
        $this->assertDatabaseHas('field_definition_revision_options', [
            'field_definition_revision_id' => $definition->current_revision_id,
            'key' => 'temp',
            'label' => 'Temp again',
            'is_active' => 1,
        ]);
        $this->assertGreaterThanOrEqual(
            6,
            FieldDefinitionRevisionOption::query()->count(),
        );
    }

    public function test_active_fieldset_version_stays_on_old_revision(): void
    {
        $admin = User::factory()->role(Role::Admin)->create();
        $definition = $this->createSelectDefinition('choice_active_pin');
        $writer = app(FieldDefinitionOptionsWriter::class);
        $writer->replace($definition, [
            'lock_version' => $definition->lock_version,
            'options' => [
                ['key' => 'a', 'label' => 'A', 'sort' => 1],
            ],
        ], $admin);
        $definition->refresh();
        $pinned = (int) $definition->current_revision_id;

        $fieldSets = app(FieldSetVersionAdminWriter::class);
        $fieldSet = $fieldSets->createFreeFieldSet([
            'name' => 'Active Pin Set',
            'key' => 'active_pin_'.bin2hex(random_bytes(3)),
            'applies_to' => FieldAppliesTo::Calculation->value,
        ], $admin);
        $draft = $fieldSet->versions()->where('status', 'draft')->firstOrFail();
        $fieldSet->refresh();
        $fieldSets->addCustomMembership($fieldSet, $draft, [
            'field_definition_id' => $definition->id,
            'field_definition_revision_id' => $pinned,
            'sort' => 10,
            'lock_version' => $fieldSet->lock_version,
        ], $admin);
        $fieldSet->refresh();
        $fieldSets->activateDraft($fieldSet, $draft, $admin, $fieldSet->lock_version);
        $fieldSet->refresh();

        $activeVersionId = (int) $fieldSet->active_version_id;
        $writer->replace($definition, [
            'lock_version' => $definition->lock_version,
            'options' => [
                ['key' => 'a', 'label' => 'A2', 'sort' => 1],
            ],
        ], $admin);
        $definition->refresh();

        $membership = FieldSetVersionField::query()
            ->where('field_set_version_id', $activeVersionId)
            ->firstOrFail();
        $this->assertSame($pinned, (int) $membership->field_definition_revision_id);
        $this->assertNotSame($pinned, (int) $definition->current_revision_id);
        $this->assertSame(FieldSetVersionStatus::Active, $fieldSet->fresh()->activeVersion?->status);
    }

    public function test_activation_rejects_choice_field_without_active_option(): void
    {
        $admin = User::factory()->role(Role::Admin)->create();
        $definition = $this->createSelectDefinition('choice_inactive_only');
        $writer = app(FieldDefinitionOptionsWriter::class);
        $writer->replace($definition, [
            'lock_version' => $definition->lock_version,
            'options' => [
                ['key' => 'a', 'label' => 'A', 'sort' => 1],
            ],
        ], $admin);
        $definition->refresh();
        $writer->replace($definition, [
            'lock_version' => $definition->lock_version,
            'options' => [
                ['key' => 'a', 'label' => 'A', 'sort' => 1, 'is_active' => false],
            ],
        ], $admin);
        $definition->refresh();

        $fieldSets = app(FieldSetVersionAdminWriter::class);
        $fieldSet = $fieldSets->createFreeFieldSet([
            'name' => 'Inactive Only',
            'key' => 'inactive_only_'.bin2hex(random_bytes(3)),
            'applies_to' => FieldAppliesTo::Calculation->value,
        ], $admin);
        $draft = $fieldSet->versions()->where('status', 'draft')->firstOrFail();
        $fieldSet->refresh();
        $fieldSets->addCustomMembership($fieldSet, $draft, [
            'field_definition_id' => $definition->id,
            'field_definition_revision_id' => (int) $definition->current_revision_id,
            'sort' => 10,
            'lock_version' => $fieldSet->lock_version,
        ], $admin);
        $fieldSet->refresh();

        try {
            $fieldSets->activateDraft($fieldSet, $draft, $admin, $fieldSet->lock_version);
            $this->fail('Aktivierung ohne aktive Option muss 422 erzeugen.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('fields', $exception->errors());
        }
    }

    public function test_freeze_loader_rejects_choice_field_without_active_option(): void
    {
        $admin = User::factory()->role(Role::Admin)->create();
        $definition = $this->createSelectDefinition('choice_freeze_inactive');
        $writer = app(FieldDefinitionOptionsWriter::class);
        $writer->replace($definition, [
            'lock_version' => $definition->lock_version,
            'options' => [
                ['key' => 'a', 'label' => 'A', 'sort' => 1],
            ],
        ], $admin);
        $definition->refresh();
        $writer->replace($definition, [
            'lock_version' => $definition->lock_version,
            'options' => [
                ['key' => 'a', 'label' => 'A', 'sort' => 1, 'is_active' => false],
            ],
        ], $admin);
        $definition->refresh();

        // Aktive Version bewusst unter Umgehung der Activate-Guards anlegen,
        // um den Freeze-/Loader-Pfad fail-closed zu prüfen.
        $fieldSet = new FieldSet;
        $fieldSet->key = 'freeze_inactive_'.bin2hex(random_bytes(3));
        $fieldSet->name = 'Freeze Inactive';
        $fieldSet->is_system = false;
        $fieldSet->applies_to = FieldAppliesTo::Both;
        $fieldSet->is_assignable = true;
        $fieldSet->lock_version = 1;
        $fieldSet->save();

        $version = new FieldSetVersion;
        $version->field_set_id = $fieldSet->id;
        $version->version = 1;
        $version->status = FieldSetVersionStatus::Active;
        $version->created_at = now();
        $version->save();
        $fieldSet->active_version_id = $version->id;
        $fieldSet->save();

        FieldSetVersionField::query()->create([
            'field_set_version_id' => $version->id,
            'field_definition_id' => $definition->id,
            'field_definition_revision_id' => (int) $definition->current_revision_id,
            'sort' => 10,
            'required_override' => null,
            'visible_override' => null,
        ]);

        $assignmentWriter = app(FieldSetAssignmentAdminWriter::class);
        $assignment = $assignmentWriter->create([
            'field_set_id' => $fieldSet->id,
            'target_layer' => 'global',
            'applies_to_process' => FieldAppliesTo::Calculation->value,
        ], $admin);
        $assignment->is_active = true;
        $assignment->save();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('mindestens eine aktive Option');
        app(ConfigurationSnapshotFreezeService::class)->resolveLiveSchemaForCalculationV3();
    }

    public function test_fingerprint_stable_across_payload_order(): void
    {
        $left = FieldDefinitionOptionContract::fingerprint([
            ['key' => 'b', 'label' => 'B', 'sort' => 2, 'is_active' => true],
            ['key' => 'a', 'label' => 'A', 'sort' => 1, 'is_active' => true],
        ]);
        $right = FieldDefinitionOptionContract::fingerprint([
            ['key' => 'a', 'label' => 'A', 'sort' => 1, 'is_active' => true],
            ['key' => 'b', 'label' => 'B', 'sort' => 2, 'is_active' => true],
        ]);
        $this->assertSame($left, $right);
    }

    public function test_assert_frozen_options_requires_active_option(): void
    {
        $this->expectException(\RuntimeException::class);
        FieldDefinitionOptionContract::assertFrozenOptions([
            ['key' => 'a', 'label' => 'A', 'sort' => 1, 'is_active' => false],
        ], FieldType::Select, 'choice');
    }

    public function test_empty_revision_options_allowed_on_draft_definition(): void
    {
        $definition = $this->createSelectDefinition('choice_empty_draft');
        $this->assertSame(0, FieldDefinitionRevisionOption::query()
            ->where('field_definition_revision_id', $definition->current_revision_id)
            ->count());
        $this->assertSame(FieldType::Select, $definition->field_type);
    }

    private function createSelectDefinition(string $key): FieldDefinition
    {
        $definition = new FieldDefinition;
        $definition->key = $key;
        $definition->field_type = FieldType::Select;
        $definition->is_system = false;
        $definition->is_key_protected = false;
        $definition->scope = FieldScope::Header;
        $definition->applies_to = FieldAppliesTo::Calculation;
        $definition->is_active = true;
        $definition->lock_version = 1;
        $definition->save();

        $revision = new FieldDefinitionRevision;
        $revision->field_definition_id = $definition->id;
        $revision->revision = 1;
        $revision->label = 'Auswahl '.$key;
        $revision->help_text = null;
        $revision->validation_json = null;
        $revision->group_key = null;
        $revision->sort_default = 0;
        $revision->reportable = true;
        $revision->created_at = now();
        $revision->save();

        $definition->current_revision_id = $revision->id;
        $definition->save();

        return $definition->fresh() ?? $definition;
    }
}
