<?php

namespace Tests\Feature\DynamicField;

use App\Enums\FieldAppliesTo;
use App\Enums\FieldScope;
use App\Enums\FieldSetVersionStatus;
use App\Enums\FieldType;
use App\Enums\Role;
use App\Exceptions\FieldDefinitionConflictException;
use App\Models\AuditEvent;
use App\Models\FieldDefinition;
use App\Models\FieldDefinitionRevision;
use App\Models\FieldDefinitionRevisionOption;
use App\Models\FieldSet;
use App\Models\FieldSetVersion;
use App\Models\FieldSetVersionField;
use App\Models\User;
use App\Services\DynamicField\Admin\FieldDefinitionOptionsWriter;
use App\Support\DynamicField\FieldDefinitionOptionContract;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class FieldDefinitionOptionsFoundationTest extends TestCase
{
    use RefreshDatabase;

    public function test_migration_adds_options_tables_and_columns(): void
    {
        $this->assertTrue(Schema::hasTable('field_definition_revision_options'));
        $this->assertTrue(Schema::hasColumn('configuration_snapshot_source_fields', 'options_json'));
        $this->assertTrue(Schema::hasColumn('snapshot_field_definitions', 'options_json'));
    }

    public function test_desired_state_creates_new_revision_and_audit(): void
    {
        $admin = User::factory()->role(Role::Admin)->create();
        $definition = $this->createSelectDefinition('choice_a');

        $result = app(FieldDefinitionOptionsWriter::class)->replace($definition, [
            'lock_version' => $definition->lock_version,
            'options' => [
                ['key' => 'opt_a', 'label' => 'Option A', 'sort' => 10],
                ['key' => 'opt_b', 'label' => 'Option B', 'sort' => 20],
            ],
        ], $admin);

        $this->assertTrue($result['has_changes']);
        $definition->refresh();
        $this->assertNotNull($result['revision']);
        $this->assertSame(2, (int) $result['revision']->revision);
        $this->assertSame(2, (int) $definition->lock_version);
        $this->assertDatabaseCount('field_definition_revision_options', 2);
        $this->assertDatabaseHas('audit_events', [
            'action' => 'field_definition.options_replaced',
        ]);
    }

    public function test_identical_desired_state_is_noop_without_audit_or_lock_bump(): void
    {
        $admin = User::factory()->role(Role::Admin)->create();
        $definition = $this->createSelectDefinition('choice_noop');
        $writer = app(FieldDefinitionOptionsWriter::class);

        $first = $writer->replace($definition, [
            'lock_version' => $definition->lock_version,
            'options' => [
                ['key' => 'only', 'label' => 'Only', 'sort' => 1],
            ],
        ], $admin);
        $definition->refresh();
        $auditsBefore = AuditEvent::query()->where('action', 'field_definition.options_replaced')->count();
        $revisionId = (int) $definition->current_revision_id;
        $lock = (int) $definition->lock_version;

        $second = $writer->replace($definition, [
            'lock_version' => $lock,
            'options' => [
                ['key' => 'only', 'label' => 'Only', 'sort' => 1],
            ],
        ], $admin);

        $definition->refresh();
        $this->assertFalse($second['has_changes']);
        $this->assertNull($second['revision']);
        $this->assertSame($revisionId, (int) $definition->current_revision_id);
        $this->assertSame($lock, (int) $definition->lock_version);
        $this->assertSame(
            $auditsBefore,
            AuditEvent::query()->where('action', 'field_definition.options_replaced')->count(),
        );
        $this->assertTrue($first['has_changes']);
    }

    public function test_missing_option_is_deactivated_not_deleted(): void
    {
        $admin = User::factory()->role(Role::Admin)->create();
        $definition = $this->createSelectDefinition('choice_deact');
        $writer = app(FieldDefinitionOptionsWriter::class);

        $writer->replace($definition, [
            'lock_version' => $definition->lock_version,
            'options' => [
                ['key' => 'keep', 'label' => 'Keep', 'sort' => 1],
                ['key' => 'gone', 'label' => 'Gone', 'sort' => 2],
            ],
        ], $admin);
        $definition->refresh();
        $oldRevisionId = (int) $definition->current_revision_id;

        $writer->replace($definition, [
            'lock_version' => $definition->lock_version,
            'options' => [
                ['key' => 'keep', 'label' => 'Keep', 'sort' => 1],
            ],
        ], $admin);
        $definition->refresh();

        $this->assertDatabaseHas('field_definition_revision_options', [
            'field_definition_revision_id' => $oldRevisionId,
            'key' => 'gone',
            'is_active' => 1,
        ]);
        $this->assertDatabaseHas('field_definition_revision_options', [
            'field_definition_revision_id' => $definition->current_revision_id,
            'key' => 'gone',
            'is_active' => 0,
        ]);
        $this->assertSame(
            4,
            FieldDefinitionRevisionOption::query()->count(),
        );
    }

    public function test_fieldset_draft_keeps_pinned_revision_after_options_change(): void
    {
        $admin = User::factory()->role(Role::Admin)->create();
        $definition = $this->createSelectDefinition('choice_pin');
        $writer = app(FieldDefinitionOptionsWriter::class);
        $writer->replace($definition, [
            'lock_version' => $definition->lock_version,
            'options' => [
                ['key' => 'one', 'label' => 'One', 'sort' => 1],
            ],
        ], $admin);
        $definition->refresh();
        $pinnedRevisionId = (int) $definition->current_revision_id;

        $fieldSet = new FieldSet;
        $fieldSet->key = 'free_options_pin_'.uniqid();
        $fieldSet->name = 'Options Pin';
        $fieldSet->is_system = false;
        $fieldSet->applies_to = FieldAppliesTo::Calculation;
        $fieldSet->is_assignable = false;
        $fieldSet->lock_version = 1;
        $fieldSet->save();

        $draft = new FieldSetVersion;
        $draft->field_set_id = $fieldSet->id;
        $draft->version = 1;
        $draft->status = FieldSetVersionStatus::Draft;
        $draft->created_at = now();
        $draft->save();

        FieldSetVersionField::query()->create([
            'field_set_version_id' => $draft->id,
            'field_definition_id' => $definition->id,
            'field_definition_revision_id' => $pinnedRevisionId,
            'sort' => 10,
            'required_override' => null,
            'visible_override' => null,
        ]);

        $writer->replace($definition, [
            'lock_version' => $definition->lock_version,
            'options' => [
                ['key' => 'one', 'label' => 'One renamed', 'sort' => 1],
            ],
        ], $admin);
        $definition->refresh();

        $membership = FieldSetVersionField::query()
            ->where('field_set_version_id', $draft->id)
            ->firstOrFail();
        $this->assertSame($pinnedRevisionId, (int) $membership->field_definition_revision_id);
        $this->assertNotSame($pinnedRevisionId, (int) $definition->current_revision_id);
    }

    public function test_stale_lock_version_yields_409(): void
    {
        $admin = User::factory()->role(Role::Admin)->create();
        $definition = $this->createSelectDefinition('choice_lock');

        $this->expectException(FieldDefinitionConflictException::class);
        app(FieldDefinitionOptionsWriter::class)->replace($definition, [
            'lock_version' => $definition->lock_version + 1,
            'options' => [
                ['key' => 'a', 'label' => 'A', 'sort' => 1],
            ],
        ], $admin);
    }

    public function test_stale_fingerprint_yields_409(): void
    {
        $admin = User::factory()->role(Role::Admin)->create();
        $definition = $this->createSelectDefinition('choice_fp');

        $this->expectException(FieldDefinitionConflictException::class);
        app(FieldDefinitionOptionsWriter::class)->replace($definition, [
            'lock_version' => $definition->lock_version,
            'fingerprint' => str_repeat('0', 64),
            'options' => [
                ['key' => 'a', 'label' => 'A', 'sort' => 1],
            ],
        ], $admin);
    }

    public function test_non_choice_definition_rejected(): void
    {
        $admin = User::factory()->role(Role::Admin)->create();
        $definition = FieldDefinition::query()->where('key', 'campaign_period')->firstOrFail();

        try {
            app(FieldDefinitionOptionsWriter::class)->replace($definition, [
                'lock_version' => $definition->lock_version,
                'options' => [
                    ['key' => 'a', 'label' => 'A', 'sort' => 1],
                ],
            ], $admin);
            $this->fail('Nicht-Auswahlfeld muss 422 erzeugen.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('definition', $exception->errors());
        }
    }

    public function test_preview_fingerprint_matches_writer(): void
    {
        $admin = User::factory()->role(Role::Admin)->create();
        $definition = $this->createSelectDefinition('choice_fp_ok');
        $writer = app(FieldDefinitionOptionsWriter::class);
        $options = [
            ['key' => 'a', 'label' => 'A', 'sort' => 1],
        ];
        $fingerprint = $writer->previewFingerprint($definition, $options);

        $result = $writer->replace($definition, [
            'lock_version' => $definition->lock_version,
            'fingerprint' => $fingerprint,
            'options' => $options,
        ], $admin);

        $this->assertTrue($result['has_changes']);
        $this->assertSame($fingerprint, $result['fingerprint']);
        $this->assertSame(
            FieldDefinitionOptionContract::fingerprint($result['options']),
            $fingerprint,
        );
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
