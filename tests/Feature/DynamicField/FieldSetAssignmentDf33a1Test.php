<?php

namespace Tests\Feature\DynamicField;

use App\Enums\FieldAppliesTo;
use App\Enums\FieldScope;
use App\Enums\FieldType;
use App\Enums\Role;
use App\Models\AdvertisingCategory;
use App\Models\AdvertisingMedium;
use App\Models\AuditEvent;
use App\Models\FieldDefinition;
use App\Models\FieldSet;
use App\Models\FieldSetAssignment;
use App\Models\FieldSetVersion;
use App\Models\User;
use App\Services\DynamicField\Admin\AdminFieldSetCatalog;
use App\Services\DynamicField\Admin\FieldDefinitionCustomWriter;
use App\Services\DynamicField\Assignment\FieldSetAssignmentAdminWriter;
use App\Services\DynamicField\Assignment\FieldSetAssignmentPreviewService;
use App\Services\DynamicField\ConfigurationSnapshotMaterializer;
use App\Support\Advertising\CanonicalAdvertisingCategories;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * DF-3.3a1 / DYN-002 / ADM-002 / AUD-001 / AUTH-001
 */
class FieldSetAssignmentDf33a1Test extends TestCase
{
    use RefreshDatabase;

    public function test_migration_creates_table_with_unique_and_xor_guards(): void
    {
        $this->assertTrue(Schema::hasTable('field_set_assignments'));
        $this->assertTrue(Schema::hasColumns('field_set_assignments', [
            'id',
            'field_set_id',
            'target_layer',
            'advertising_category_id',
            'advertising_medium_id',
            'target_identity',
            'applies_to_process',
            'is_active',
            'sort',
            'lock_version',
        ]));

        $admin = User::factory()->role(Role::Admin)->create();
        $fieldSet = $this->createAssignableFreeFieldSet($admin, 'assign_unique_'.$this->suffix());

        DB::table('field_set_assignments')->insert([
            'field_set_id' => $fieldSet->id,
            'target_layer' => 'global',
            'advertising_category_id' => null,
            'advertising_medium_id' => null,
            'target_identity' => 'g',
            'applies_to_process' => 'calculation',
            'is_active' => 0,
            'sort' => 0,
            'lock_version' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        try {
            DB::table('field_set_assignments')->insert([
                'field_set_id' => $fieldSet->id,
                'target_layer' => 'global',
                'advertising_category_id' => null,
                'advertising_medium_id' => null,
                'target_identity' => 'g',
                'applies_to_process' => 'calculation',
                'is_active' => 0,
                'sort' => 1,
                'lock_version' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $this->fail('Expected unique violation for duplicate global assignment.');
        } catch (\Throwable $exception) {
            $this->assertTrue(
                $exception instanceof UniqueConstraintViolationException
                || $exception instanceof QueryException,
            );
        }

        $categoryId = (int) AdvertisingCategory::query()
            ->where('key', CanonicalAdvertisingCategories::SPOTS)
            ->value('id');

        DB::table('field_set_assignments')->insert([
            'field_set_id' => $fieldSet->id,
            'target_layer' => 'advertising_category',
            'advertising_category_id' => $categoryId,
            'advertising_medium_id' => null,
            'target_identity' => 'c:'.$categoryId,
            'applies_to_process' => 'calculation',
            'is_active' => 0,
            'sort' => 0,
            'lock_version' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        try {
            DB::table('field_set_assignments')->insert([
                'field_set_id' => $fieldSet->id,
                'target_layer' => 'advertising_category',
                'advertising_category_id' => $categoryId,
                'advertising_medium_id' => null,
                'target_identity' => 'c:'.$categoryId,
                'applies_to_process' => 'calculation',
                'is_active' => 0,
                'sort' => 2,
                'lock_version' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $this->fail('Expected unique violation for duplicate category assignment.');
        } catch (\Throwable $exception) {
            $this->assertTrue(
                $exception instanceof UniqueConstraintViolationException
                || $exception instanceof QueryException,
            );
        }

        $medium = AdvertisingMedium::query()->where('code', 'spot_classic')->first()
            ?? AdvertisingMedium::factory()->create([
                'code' => 'spot_classic_'.$this->suffix(),
                'name' => 'Spot',
            ]);

        DB::table('field_set_assignments')->insert([
            'field_set_id' => $fieldSet->id,
            'target_layer' => 'advertising_medium',
            'advertising_category_id' => null,
            'advertising_medium_id' => $medium->id,
            'target_identity' => 'm:'.$medium->id,
            'applies_to_process' => 'calculation',
            'is_active' => 0,
            'sort' => 0,
            'lock_version' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        try {
            DB::table('field_set_assignments')->insert([
                'field_set_id' => $fieldSet->id,
                'target_layer' => 'advertising_medium',
                'advertising_category_id' => null,
                'advertising_medium_id' => $medium->id,
                'target_identity' => 'm:'.$medium->id,
                'applies_to_process' => 'calculation',
                'is_active' => 0,
                'sort' => 3,
                'lock_version' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $this->fail('Expected unique violation for duplicate medium assignment.');
        } catch (\Throwable $exception) {
            $this->assertTrue(
                $exception instanceof UniqueConstraintViolationException
                || $exception instanceof QueryException,
            );
        }

        try {
            DB::table('field_set_assignments')->insert([
                'field_set_id' => $fieldSet->id,
                'target_layer' => 'global',
                'advertising_category_id' => $categoryId,
                'advertising_medium_id' => null,
                'target_identity' => 'g',
                'applies_to_process' => 'dispo_order',
                'is_active' => 0,
                'sort' => 0,
                'lock_version' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $this->fail('Expected XOR violation for global with category_id.');
        } catch (\Throwable $exception) {
            $this->assertTrue(
                $exception instanceof QueryException
                || $exception instanceof UniqueConstraintViolationException,
            );
        }
    }

    public function test_restrict_delete_on_fieldset_and_targets(): void
    {
        $admin = User::factory()->role(Role::Admin)->create();
        $fieldSet = $this->createAssignableFreeFieldSet($admin, 'assign_restrict_'.$this->suffix());
        $writer = app(FieldSetAssignmentAdminWriter::class);
        $assignment = $writer->create([
            'field_set_id' => $fieldSet->id,
            'target_layer' => 'global',
            'applies_to_process' => 'calculation',
            'sort' => 0,
        ], $admin);

        try {
            DB::table('field_sets')->where('id', $fieldSet->id)->delete();
            $this->fail('Expected restrict on field_set delete.');
        } catch (QueryException) {
            $this->assertTrue(true);
        }

        $this->assertDatabaseHas('field_set_assignments', ['id' => $assignment->id]);
    }

    public function test_create_update_activate_deactivate_preview_and_audit_flow(): void
    {
        $admin = User::factory()->role(Role::Admin)->create();
        $sales = User::factory()->role(Role::Sales)->create();
        $fieldSet = $this->createAssignableFreeFieldSet($admin, 'assign_flow_'.$this->suffix());

        $this->actingAs($sales)
            ->postJson(route('administration.dynamic-fields.assignments.store'), [
                'field_set_id' => $fieldSet->id,
                'target_layer' => 'global',
                'applies_to_process' => 'calculation',
            ])
            ->assertForbidden();

        $create = $this->actingAs($admin)
            ->postJson(route('administration.dynamic-fields.assignments.store'), [
                'field_set_id' => $fieldSet->id,
                'target_layer' => 'global',
                'applies_to_process' => 'calculation',
                'sort' => 10,
            ])
            ->assertCreated()
            ->json('assignment');

        $this->assertFalse($create['is_active']);
        $this->assertSame(1, $create['lock_version']);
        $this->assertSame('g', $create['target_identity']);

        $this->assertDatabaseHas('audit_events', [
            'action' => 'field_set_assignment.created',
            'auditable_id' => $create['id'],
        ]);

        $assignment = FieldSetAssignment::query()->findOrFail($create['id']);

        $this->actingAs($admin)
            ->putJson(route('administration.dynamic-fields.assignments.update', $assignment), [
                'lock_version' => 1,
                'sort' => 20,
            ])
            ->assertOk()
            ->assertJsonPath('assignment.sort', 20)
            ->assertJsonPath('assignment.lock_version', 2);

        $preview = $this->actingAs($admin)
            ->postJson(route('administration.dynamic-fields.assignments.context-preview'), [
                'process' => 'calculation',
                'scope' => 'header',
                'candidate_assignment_id' => $assignment->id,
            ])
            ->assertOk()
            ->json('preview');

        $this->assertFalse($preview['has_blocking_conflicts']);
        $this->assertNotEmpty($preview['fingerprint']);
        $this->assertTrue(collect($preview['included_assignments'])->contains(
            fn (array $row): bool => (int) $row['id'] === (int) $assignment->id && $row['is_candidate'] === true,
        ));

        $activationPreview = $this->actingAs($admin)
            ->postJson(route('administration.dynamic-fields.assignments.activation-preview', $assignment))
            ->assertOk()
            ->json();

        $this->assertFalse($activationPreview['has_blocking_conflicts']);
        $fingerprint = $activationPreview['fingerprint'];

        $this->actingAs($admin)
            ->postJson(route('administration.dynamic-fields.assignments.activate', $assignment), [
                'lock_version' => 2,
                'fingerprint' => $fingerprint,
            ])
            ->assertOk()
            ->assertJsonPath('assignment.is_active', true);

        $assignment->refresh();
        $this->assertTrue($assignment->is_active);
        $this->assertSame(3, $assignment->lock_version);

        $this->actingAs($admin)
            ->putJson(route('administration.dynamic-fields.assignments.update', $assignment), [
                'lock_version' => 3,
                'sort' => 99,
            ])
            ->assertStatus(422);

        $this->actingAs($admin)
            ->postJson(route('administration.dynamic-fields.assignments.deactivate', $assignment), [
                'lock_version' => 3,
            ])
            ->assertOk()
            ->assertJsonPath('assignment.is_active', false);

        $actions = AuditEvent::query()
            ->where('auditable_type', FieldSetAssignment::class)
            ->where('auditable_id', $assignment->id)
            ->pluck('action')
            ->all();
        $this->assertContains('field_set_assignment.created', $actions);
        $this->assertContains('field_set_assignment.updated', $actions);
        $this->assertContains('field_set_assignment.activated', $actions);
        $this->assertContains('field_set_assignment.deactivated', $actions);
    }

    public function test_activate_rejects_stale_fingerprint_with_409(): void
    {
        $admin = User::factory()->role(Role::Admin)->create();
        $fieldSet = $this->createAssignableFreeFieldSet($admin, 'assign_fp_'.$this->suffix());
        $writer = app(FieldSetAssignmentAdminWriter::class);
        $assignment = $writer->create([
            'field_set_id' => $fieldSet->id,
            'target_layer' => 'global',
            'applies_to_process' => 'calculation',
        ], $admin);

        $this->actingAs($admin)
            ->postJson(route('administration.dynamic-fields.assignments.activate', $assignment), [
                'lock_version' => 1,
                'fingerprint' => str_repeat('a', 64),
            ])
            ->assertStatus(409);
    }

    public function test_duplicate_create_is_readable_conflict_not_500(): void
    {
        $admin = User::factory()->role(Role::Admin)->create();
        $fieldSet = $this->createAssignableFreeFieldSet($admin, 'assign_dup_'.$this->suffix());
        $writer = app(FieldSetAssignmentAdminWriter::class);
        $writer->create([
            'field_set_id' => $fieldSet->id,
            'target_layer' => 'global',
            'applies_to_process' => 'calculation',
        ], $admin);

        try {
            $writer->create([
                'field_set_id' => $fieldSet->id,
                'target_layer' => 'global',
                'applies_to_process' => 'calculation',
            ], $admin);
            $this->fail('Expected ValidationException for duplicate.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('assignment', $exception->errors());
        }
    }

    public function test_core_fieldset_cannot_be_assigned(): void
    {
        $admin = User::factory()->role(Role::Admin)->create();
        $core = FieldSet::query()->where('key', AdminFieldSetCatalog::CALCULATION_CORE)->firstOrFail();
        $writer = app(FieldSetAssignmentAdminWriter::class);

        $this->expectException(ValidationException::class);
        $writer->create([
            'field_set_id' => $core->id,
            'target_layer' => 'global',
            'applies_to_process' => 'calculation',
        ], $admin);
    }

    public function test_process_subset_guard(): void
    {
        $admin = User::factory()->role(Role::Admin)->create();
        $fieldSet = $this->createAssignableFreeFieldSet(
            $admin,
            'assign_proc_'.$this->suffix(),
            FieldAppliesTo::Calculation,
        );
        $writer = app(FieldSetAssignmentAdminWriter::class);

        $this->expectException(ValidationException::class);
        $writer->create([
            'field_set_id' => $fieldSet->id,
            'target_layer' => 'global',
            'applies_to_process' => 'both',
        ], $admin);
    }

    public function test_lock_version_conflict_on_update(): void
    {
        $admin = User::factory()->role(Role::Admin)->create();
        $fieldSet = $this->createAssignableFreeFieldSet($admin, 'assign_lock_'.$this->suffix());
        $writer = app(FieldSetAssignmentAdminWriter::class);
        $assignment = $writer->create([
            'field_set_id' => $fieldSet->id,
            'target_layer' => 'global',
            'applies_to_process' => 'calculation',
        ], $admin);

        $this->actingAs($admin)
            ->putJson(route('administration.dynamic-fields.assignments.update', $assignment), [
                'lock_version' => 99,
                'sort' => 5,
            ])
            ->assertStatus(409);
    }

    public function test_active_assignments_do_not_affect_calculation_materializer(): void
    {
        $admin = User::factory()->role(Role::Admin)->create();
        $fieldSet = $this->createAssignableFreeFieldSet($admin, 'assign_runtime_'.$this->suffix());
        $writer = app(FieldSetAssignmentAdminWriter::class);
        $assignment = $writer->create([
            'field_set_id' => $fieldSet->id,
            'target_layer' => 'global',
            'applies_to_process' => 'calculation',
        ], $admin);

        $previews = $writer->previewAffectedContexts($assignment, asCandidate: true);
        $fingerprint = $writer->canonicalActivationFingerprint($previews);
        $writer->activate($assignment, [
            'lock_version' => 1,
            'fingerprint' => $fingerprint,
        ], $admin);

        $snapshot = app(ConfigurationSnapshotMaterializer::class)->materializeFromActiveSet();
        $keys = $snapshot->fieldDefinitions()->pluck('key')->all();
        $this->assertContains('campaign_period', $keys);
        $customKeys = FieldDefinition::query()
            ->where('is_system', false)
            ->pluck('key')
            ->all();
        foreach ($customKeys as $customKey) {
            $this->assertNotContains($customKey, $keys);
        }
    }

    public function test_preview_fingerprint_changes_when_fieldset_version_changes(): void
    {
        $admin = User::factory()->role(Role::Admin)->create();
        $fieldSet = $this->createAssignableFreeFieldSet($admin, 'assign_ver_'.$this->suffix());
        $writer = app(FieldSetAssignmentAdminWriter::class);
        $assignment = $writer->create([
            'field_set_id' => $fieldSet->id,
            'target_layer' => 'global',
            'applies_to_process' => 'calculation',
        ], $admin);

        $before = app(FieldSetAssignmentPreviewService::class)->preview([
            'process' => 'calculation',
            'scope' => 'header',
            'candidate_assignment_id' => $assignment->id,
        ]);

        $fieldSet->refresh();
        $activeVersionId = (int) $fieldSet->active_version_id;
        $this->assertGreaterThan(0, $activeVersionId);

        $this->actingAs($admin)
            ->post(route('administration.dynamic-fields.field-sets.drafts.store', $fieldSet), [
                'lock_version' => (int) $fieldSet->lock_version,
                'source_version_id' => $activeVersionId,
            ])
            ->assertRedirect();

        $draft = FieldSetVersion::query()
            ->where('field_set_id', $fieldSet->id)
            ->where('status', 'draft')
            ->orderByDesc('id')
            ->firstOrFail();

        $definition = $this->createCustomDefinition($admin, [
            'label' => 'Extra '.$this->suffix(),
            'scope' => FieldScope::Header->value,
        ]);

        $fieldSet->refresh();
        $this->actingAs($admin)
            ->post(route('administration.dynamic-fields.field-sets.versions.memberships.store', [
                'fieldSet' => $fieldSet->id,
                'version' => $draft->id,
            ]), [
                'lock_version' => (int) $fieldSet->fresh()->lock_version,
                'field_definition_id' => $definition->id,
                'field_definition_revision_id' => $definition->current_revision_id,
                'sort' => 30,
            ])
            ->assertRedirect();

        $fieldSet->refresh();
        $this->actingAs($admin)
            ->post(route('administration.dynamic-fields.field-sets.versions.activate', [
                'fieldSet' => $fieldSet->id,
                'version' => $draft->id,
            ]), [
                'lock_version' => (int) $fieldSet->fresh()->lock_version,
            ])
            ->assertRedirect();

        $after = app(FieldSetAssignmentPreviewService::class)->preview([
            'process' => 'calculation',
            'scope' => 'header',
            'candidate_assignment_id' => $assignment->id,
        ]);

        $this->assertNotSame($before['fingerprint'], $after['fingerprint']);
    }

    public function test_category_and_medium_assignment_contexts(): void
    {
        $admin = User::factory()->role(Role::Admin)->create();
        $fieldSet = $this->createAssignableFreeFieldSet(
            $admin,
            'assign_ctx_'.$this->suffix(),
            FieldAppliesTo::Both,
            FieldScope::Position,
        );
        $categoryId = (int) AdvertisingCategory::query()
            ->where('key', CanonicalAdvertisingCategories::SPOTS)
            ->value('id');
        $medium = AdvertisingMedium::factory()->create([
            'code' => 'ctx_medium_'.$this->suffix(),
            'name' => 'Ctx Medium',
            'category_id' => $categoryId,
        ]);

        $writer = app(FieldSetAssignmentAdminWriter::class);
        $categoryAssignment = $writer->create([
            'field_set_id' => $fieldSet->id,
            'target_layer' => 'advertising_category',
            'advertising_category_id' => $categoryId,
            'applies_to_process' => 'calculation',
            'sort' => 1,
        ], $admin);

        $previews = $writer->previewAffectedContexts($categoryAssignment, asCandidate: true);
        $this->assertNotEmpty($previews);
        $scopes = collect($previews)->pluck('scope')->unique()->all();
        $this->assertSame(['position'], $scopes);
        $this->assertTrue(collect($previews)->contains(
            fn (array $p): bool => (int) ($p['advertising_medium_id'] ?? 0) === (int) $medium->id,
        ));

        $mediumAssignment = $writer->create([
            'field_set_id' => $fieldSet->id,
            'target_layer' => 'advertising_medium',
            'advertising_medium_id' => $medium->id,
            'applies_to_process' => 'calculation',
            'sort' => 2,
        ], $admin);
        $mediumPreviews = $writer->previewAffectedContexts($mediumAssignment, asCandidate: true);
        $this->assertCount(1, array_filter(
            $mediumPreviews,
            static fn (array $p): bool => $p['process'] === 'calculation',
        ));
    }

    public function test_not_null_columns_reject_null(): void
    {
        $admin = User::factory()->role(Role::Admin)->create();
        $fieldSet = $this->createAssignableFreeFieldSet($admin, 'assign_nn_'.$this->suffix());

        $this->expectException(QueryException::class);
        DB::table('field_set_assignments')->insert([
            'field_set_id' => $fieldSet->id,
            'target_layer' => 'global',
            'advertising_category_id' => null,
            'advertising_medium_id' => null,
            'target_identity' => null,
            'applies_to_process' => 'calculation',
            'is_active' => 0,
            'sort' => 0,
            'lock_version' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function createAssignableFreeFieldSet(
        User $admin,
        string $key,
        FieldAppliesTo $appliesTo = FieldAppliesTo::Both,
        FieldScope $scope = FieldScope::Header,
    ): FieldSet {
        $this->actingAs($admin)
            ->post(route('administration.dynamic-fields.field-sets.store'), [
                'name' => 'Free '.$key,
                'key' => $key,
                'applies_to' => $appliesTo->value,
            ])
            ->assertRedirect();

        $fieldSet = FieldSet::query()->where('key', $key)->firstOrFail();
        $draft = FieldSetVersion::query()
            ->where('field_set_id', $fieldSet->id)
            ->where('status', 'draft')
            ->firstOrFail();

        $definition = $this->createCustomDefinition($admin, [
            'label' => 'Feld '.$key,
            'scope' => $scope->value,
            'applies_to' => $appliesTo === FieldAppliesTo::Both
                ? FieldAppliesTo::Both->value
                : $appliesTo->value,
        ]);

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

        return $fieldSet->fresh(['activeVersion']) ?? $fieldSet;
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function createCustomDefinition(User $admin, array $overrides = []): FieldDefinition
    {
        return app(FieldDefinitionCustomWriter::class)->create([
            'label' => $overrides['label'] ?? 'Custom',
            'field_type' => FieldType::ShortText,
            'scope' => FieldScope::tryFrom($overrides['scope'] ?? FieldScope::Header->value) ?? FieldScope::Header,
            'applies_to' => FieldAppliesTo::tryFrom($overrides['applies_to'] ?? FieldAppliesTo::Both->value)
                ?? FieldAppliesTo::Both,
            'max_length' => 100,
        ], $admin);
    }

    private function suffix(): string
    {
        return bin2hex(random_bytes(3));
    }
}
