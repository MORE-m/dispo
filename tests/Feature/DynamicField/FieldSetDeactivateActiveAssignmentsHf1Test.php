<?php

namespace Tests\Feature\DynamicField;

use App\Enums\FieldAppliesTo;
use App\Enums\FieldScope;
use App\Enums\FieldType;
use App\Enums\Role;
use App\Models\AdvertisingCategory;
use App\Models\AdvertisingMedium;
use App\Models\AuditEvent;
use App\Models\Calculation;
use App\Models\FieldDefinition;
use App\Models\FieldSet;
use App\Models\FieldSetAssignment;
use App\Models\FieldSetVersion;
use App\Models\FieldSetVersionField;
use App\Models\SnapshotFieldDefinition;
use App\Models\User;
use App\Services\DynamicField\Admin\AdminFieldSetCatalog;
use App\Services\DynamicField\Admin\FieldDefinitionCustomWriter;
use App\Services\DynamicField\Admin\FieldSetVersionAdminWriter;
use App\Services\DynamicField\Assignment\AssignmentSourceLoader;
use App\Services\DynamicField\Assignment\FieldSetAssignmentAdminWriter;
use App\Support\Advertising\CanonicalAdvertisingCategories;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\CreatesSavedCalculation;
use Tests\Concerns\CreatesSpotClassicCatalog;
use Tests\TestCase;

/**
 * DF-3.3-fs-HF1: Feldset-Deaktivierung bei aktiven Assignments blockieren.
 */
class FieldSetDeactivateActiveAssignmentsHf1Test extends TestCase
{
    use CreatesSavedCalculation;
    use CreatesSpotClassicCatalog;
    use RefreshDatabase;

    public function test_active_global_assignment_blocks_fieldset_deactivate(): void
    {
        $admin = User::factory()->role(Role::Admin)->create();
        [$fieldSet, $assignment] = $this->createAssignableFieldSetWithActiveAssignment(
            $admin,
            'hf1_global_'.$this->suffix(),
            'global',
        );

        $this->assertDeactivateBlockedUnchanged($admin, $fieldSet, $assignment);
    }

    public function test_active_category_assignment_blocks_fieldset_deactivate(): void
    {
        $admin = User::factory()->role(Role::Admin)->create();
        $categoryId = (int) AdvertisingCategory::query()
            ->where('key', CanonicalAdvertisingCategories::SPOTS)
            ->value('id');

        [$fieldSet, $assignment] = $this->createAssignableFieldSetWithActiveAssignment(
            $admin,
            'hf1_cat_'.$this->suffix(),
            'advertising_category',
            $categoryId,
            null,
            FieldScope::Position,
        );

        $this->assertDeactivateBlockedUnchanged($admin, $fieldSet, $assignment);
    }

    public function test_active_medium_assignment_blocks_fieldset_deactivate(): void
    {
        $admin = User::factory()->role(Role::Admin)->create();
        $medium = AdvertisingMedium::query()->where('code', 'spot_classic')->first()
            ?? AdvertisingMedium::factory()->create([
                'code' => 'spot_classic_hf1_'.$this->suffix(),
                'name' => 'Spot HF1',
            ]);

        [$fieldSet, $assignment] = $this->createAssignableFieldSetWithActiveAssignment(
            $admin,
            'hf1_med_'.$this->suffix(),
            'advertising_medium',
            null,
            (int) $medium->id,
            FieldScope::Position,
        );

        $this->assertDeactivateBlockedUnchanged($admin, $fieldSet, $assignment);
    }

    public function test_only_inactive_assignments_allow_fieldset_deactivate(): void
    {
        $admin = User::factory()->role(Role::Admin)->create();
        $fieldSet = $this->createAssignableFreeFieldSet($admin, 'hf1_inactive_'.$this->suffix());
        $writer = app(FieldSetAssignmentAdminWriter::class);

        $inactive = $writer->create([
            'field_set_id' => $fieldSet->id,
            'target_layer' => 'global',
            'applies_to_process' => FieldAppliesTo::Calculation->value,
        ], $admin);
        $this->assertFalse($inactive->is_active);

        $this->actingAs($admin)
            ->postJson(route('administration.dynamic-fields.field-sets.deactivate', $fieldSet), [
                'lock_version' => $fieldSet->fresh()->lock_version,
            ])
            ->assertOk();

        $this->assertFalse($fieldSet->fresh()->is_assignable);
        $this->assertFalse($inactive->fresh()->is_active);
    }

    public function test_core_and_system_fieldsets_remain_protected(): void
    {
        $admin = User::factory()->role(Role::Admin)->create();
        $calc = FieldSet::query()->where('key', AdminFieldSetCatalog::CALCULATION_CORE)->firstOrFail();

        $this->actingAs($admin)
            ->postJson(route('administration.dynamic-fields.field-sets.deactivate', $calc), [
                'lock_version' => $calc->lock_version,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['field_set']);

        $this->assertFalse($calc->fresh()->is_assignable);
    }

    public function test_deactivated_fieldset_can_still_be_reactivated(): void
    {
        $admin = User::factory()->role(Role::Admin)->create();
        $fieldSet = $this->createAssignableFreeFieldSet($admin, 'hf1_react_'.$this->suffix());

        app(FieldSetVersionAdminWriter::class)->deactivate(
            $fieldSet->fresh(),
            $admin,
            (int) $fieldSet->fresh()->lock_version,
        );
        $this->assertFalse($fieldSet->fresh()->is_assignable);

        app(FieldSetVersionAdminWriter::class)->reactivate(
            $fieldSet->fresh(),
            $admin,
            (int) $fieldSet->fresh()->lock_version,
        );
        $this->assertTrue($fieldSet->fresh()->is_assignable);
    }

    public function test_inconsistent_active_assignment_still_fail_closes_runtime(): void
    {
        $admin = User::factory()->role(Role::Admin)->create();
        [$fieldSet, $assignment] = $this->createAssignableFieldSetWithActiveAssignment(
            $admin,
            'hf1_fc_'.$this->suffix(),
            'global',
        );

        $fieldSet->forceFill(['is_assignable' => false])->save();

        try {
            app(AssignmentSourceLoader::class)->loadGlobalSources(FieldAppliesTo::Calculation);
            $this->fail('Runtime muss fail-closed abbrechen.');
        } catch (ValidationException $exception) {
            $this->assertStringContainsString(
                "Aktives Assignment {$assignment->id} ist nicht mehr auflösbar",
                $exception->errors()['configuration'][0] ?? '',
            );
        }
    }

    public function test_blocked_deactivate_keeps_new_calculation_usable(): void
    {
        $admin = User::factory()->role(Role::Admin)->create();
        [$fieldSet, $assignment] = $this->createAssignableFieldSetWithActiveAssignment(
            $admin,
            'hf1_calc_block_'.$this->suffix(),
            'global',
        );
        $customKey = $this->customKeyForFieldSet($fieldSet);

        $this->assertDeactivateBlockedUnchanged($admin, $fieldSet, $assignment);

        $catalog = $this->createSpotClassicCatalog();
        $sales = User::factory()->role(Role::Sales)->create();
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
    }

    public function test_after_assignment_and_fieldset_deactivate_new_calculation_omits_fieldset(): void
    {
        $admin = User::factory()->role(Role::Admin)->create();
        [$fieldSet, $assignment] = $this->createAssignableFieldSetWithActiveAssignment(
            $admin,
            'hf1_calc_ok_'.$this->suffix(),
            'global',
        );
        $customKey = $this->customKeyForFieldSet($fieldSet);

        $assignmentWriter = app(FieldSetAssignmentAdminWriter::class);
        $assignmentWriter->deactivate($assignment->fresh(), [
            'lock_version' => (int) $assignment->fresh()->lock_version,
        ], $admin);

        app(FieldSetVersionAdminWriter::class)->deactivate(
            $fieldSet->fresh(),
            $admin,
            (int) $fieldSet->fresh()->lock_version,
        );
        $this->assertFalse($fieldSet->fresh()->is_assignable);

        $catalog = $this->createSpotClassicCatalog();
        $sales = User::factory()->role(Role::Sales)->create();
        $calculation = $this->createSavedCalculation($catalog, [
            ['inventory_id' => $catalog['hamburg']->id],
        ], $sales);

        $this->assertNotContains(
            $customKey,
            SnapshotFieldDefinition::query()
                ->where('configuration_snapshot_id', $calculation->configuration_snapshot_id)
                ->pluck('key')
                ->all(),
        );
    }

    public function test_frozen_calculation_remains_readable_after_later_fieldset_deactivate(): void
    {
        $admin = User::factory()->role(Role::Admin)->create();
        [$fieldSet, $assignment] = $this->createAssignableFieldSetWithActiveAssignment(
            $admin,
            'hf1_hist_'.$this->suffix(),
            'global',
        );
        $customKey = $this->customKeyForFieldSet($fieldSet);

        $catalog = $this->createSpotClassicCatalog();
        $sales = User::factory()->role(Role::Sales)->create();
        $calculation = $this->createSavedCalculation($catalog, [
            ['inventory_id' => $catalog['hamburg']->id],
        ], $sales);
        $snapshotId = (int) $calculation->configuration_snapshot_id;

        $this->assertContains(
            $customKey,
            SnapshotFieldDefinition::query()
                ->where('configuration_snapshot_id', $snapshotId)
                ->pluck('key')
                ->all(),
        );

        app(FieldSetAssignmentAdminWriter::class)->deactivate($assignment->fresh(), [
            'lock_version' => (int) $assignment->fresh()->lock_version,
        ], $admin);
        app(FieldSetVersionAdminWriter::class)->deactivate(
            $fieldSet->fresh(),
            $admin,
            (int) $fieldSet->fresh()->lock_version,
        );

        $this->actingAs($sales)
            ->get(route('calculations.edit', $calculation))
            ->assertOk();

        $this->actingAs($sales)
            ->postJson(route('calculations.field-schema'), ['calculation_id' => $calculation->id])
            ->assertOk()
            ->assertJsonPath('fieldSchema.schema_fingerprint', $calculation->fresh()->configurationSnapshot?->schema_fingerprint);

        $this->assertContains(
            $customKey,
            SnapshotFieldDefinition::query()
                ->where('configuration_snapshot_id', $snapshotId)
                ->pluck('key')
                ->all(),
        );
        $this->assertSame(1, Calculation::query()->whereKey($calculation->id)->count());
    }

    public function test_fieldset_show_lists_active_assignments_and_blocks_deactivate_ui(): void
    {
        $admin = User::factory()->role(Role::Admin)->create();
        [$fieldSet, $assignment] = $this->createAssignableFieldSetWithActiveAssignment(
            $admin,
            'hf1_ui_'.$this->suffix(),
            'global',
        );

        $this->actingAs($admin)
            ->get(route('administration.dynamic-fields.field-sets.show', $fieldSet))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('administration/dynamic-fields/field-sets/show')
                ->where('fieldSet.can_deactivate', false)
                ->where('fieldSet.active_assignment_count', 1)
                ->where('fieldSet.active_assignments.0.id', $assignment->id)
                ->where('fieldSet.active_assignments.0.href', route(
                    'administration.dynamic-fields.assignments.show',
                    $assignment,
                ))
                ->where('fieldSet.has_inconsistent_active_assignments', false));
    }

    public function test_fieldset_show_allows_deactivate_after_assignments_inactive(): void
    {
        $admin = User::factory()->role(Role::Admin)->create();
        [$fieldSet, $assignment] = $this->createAssignableFieldSetWithActiveAssignment(
            $admin,
            'hf1_ui_free_'.$this->suffix(),
            'global',
        );

        app(FieldSetAssignmentAdminWriter::class)->deactivate($assignment->fresh(), [
            'lock_version' => (int) $assignment->fresh()->lock_version,
        ], $admin);

        $this->actingAs($admin)
            ->get(route('administration.dynamic-fields.field-sets.show', $fieldSet->fresh()))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('fieldSet.can_deactivate', true)
                ->where('fieldSet.active_assignment_count', 0));
    }

    public function test_server_side_conflict_is_returned_as_validation_error(): void
    {
        $admin = User::factory()->role(Role::Admin)->create();
        [$fieldSet] = $this->createAssignableFieldSetWithActiveAssignment(
            $admin,
            'hf1_ui_conflict_'.$this->suffix(),
            'global',
        );

        $this->actingAs($admin)
            ->postJson(route('administration.dynamic-fields.field-sets.deactivate', $fieldSet), [
                'lock_version' => $fieldSet->fresh()->lock_version,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['field_set'])
            ->assertJsonFragment([
                'field_set' => [
                    'Das Feldset kann nicht deaktiviert werden, solange aktive Assignments darauf verweisen. Deaktiviere zuerst die aufgeführten Assignments (#'.FieldSetAssignment::query()
                        ->where('field_set_id', $fieldSet->id)
                        ->where('is_active', true)
                        ->orderBy('id')
                        ->value('id').').',
                ],
            ]);
    }

    public function test_inconsistent_state_shows_warning_and_keeps_reactivate(): void
    {
        $admin = User::factory()->role(Role::Admin)->create();
        [$fieldSet] = $this->createAssignableFieldSetWithActiveAssignment(
            $admin,
            'hf1_warn_'.$this->suffix(),
            'global',
        );

        $fieldSet->forceFill(['is_assignable' => false])->save();

        $this->actingAs($admin)
            ->get(route('administration.dynamic-fields.field-sets.show', $fieldSet->fresh()))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('fieldSet.has_inconsistent_active_assignments', true)
                ->where('fieldSet.can_reactivate', true)
                ->where('fieldSet.can_deactivate', false)
                ->where('fieldSet.active_assignment_count', 1));
    }

    /**
     * @return array{0: FieldSet, 1: FieldSetAssignment}
     */
    private function createAssignableFieldSetWithActiveAssignment(
        User $admin,
        string $key,
        string $targetLayer,
        ?int $categoryId = null,
        ?int $mediumId = null,
        FieldScope $scope = FieldScope::Header,
    ): array {
        $fieldSet = $this->createAssignableFreeFieldSet($admin, $key, $scope);
        $writer = app(FieldSetAssignmentAdminWriter::class);

        $payload = [
            'field_set_id' => $fieldSet->id,
            'target_layer' => $targetLayer,
            'applies_to_process' => FieldAppliesTo::Calculation->value,
        ];
        if ($categoryId !== null) {
            $payload['advertising_category_id'] = $categoryId;
        }
        if ($mediumId !== null) {
            $payload['advertising_medium_id'] = $mediumId;
        }

        $assignment = $writer->create($payload, $admin);
        $writer->activate($assignment, [
            'lock_version' => (int) $assignment->lock_version,
            'fingerprint' => $writer->canonicalActivationFingerprint(
                $writer->previewAffectedContexts($assignment, asCandidate: true),
            ),
        ], $admin);

        return [$fieldSet->fresh() ?? $fieldSet, $assignment->fresh() ?? $assignment];
    }

    private function assertDeactivateBlockedUnchanged(
        User $admin,
        FieldSet $fieldSet,
        FieldSetAssignment $assignment,
    ): void {
        $lockBefore = (int) $fieldSet->fresh()->lock_version;
        $auditBefore = AuditEvent::query()
            ->where('auditable_type', FieldSet::class)
            ->where('auditable_id', $fieldSet->id)
            ->where('action', 'field_set.deactivated')
            ->count();

        $this->actingAs($admin)
            ->postJson(route('administration.dynamic-fields.field-sets.deactivate', $fieldSet), [
                'lock_version' => $lockBefore,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['field_set']);

        $freshSet = $fieldSet->fresh();
        $freshAssignment = $assignment->fresh();
        $this->assertNotNull($freshSet);
        $this->assertNotNull($freshAssignment);
        $this->assertTrue($freshSet->is_assignable);
        $this->assertSame($lockBefore, (int) $freshSet->lock_version);
        $this->assertTrue($freshAssignment->is_active);
        $this->assertSame(
            $auditBefore,
            AuditEvent::query()
                ->where('auditable_type', FieldSet::class)
                ->where('auditable_id', $fieldSet->id)
                ->where('action', 'field_set.deactivated')
                ->count(),
        );
    }

    private function createAssignableFreeFieldSet(
        User $admin,
        string $key,
        FieldScope $scope = FieldScope::Header,
    ): FieldSet {
        $this->actingAs($admin)
            ->post(route('administration.dynamic-fields.field-sets.store'), [
                'name' => 'Free '.$key,
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
            'label' => 'Feld '.$key,
            'field_type' => FieldType::ShortText,
            'scope' => $scope,
            'applies_to' => FieldAppliesTo::Both,
            'max_length' => 100,
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

        return $fieldSet->fresh(['activeVersion']) ?? $fieldSet;
    }

    private function customKeyForFieldSet(FieldSet $fieldSet): string
    {
        $versionId = (int) ($fieldSet->active_version_id ?? 0);
        $this->assertGreaterThan(0, $versionId);

        $definitionId = FieldSetVersionField::query()
            ->where('field_set_version_id', $versionId)
            ->orderBy('id')
            ->value('field_definition_id');
        $this->assertNotNull($definitionId);

        $key = FieldDefinition::query()->whereKey((int) $definitionId)->value('key');
        $this->assertNotNull($key);

        return (string) $key;
    }

    private function suffix(): string
    {
        return bin2hex(random_bytes(3));
    }
}
