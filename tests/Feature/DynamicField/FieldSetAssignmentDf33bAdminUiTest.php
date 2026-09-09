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
use App\Support\Advertising\CanonicalAdvertisingCategories;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * DF-3.3b / DYN-002 / ADM-002 / AUTH-001 / AUD-001 / PO-33b-1 / PO-33b-2
 */
class FieldSetAssignmentDf33bAdminUiTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_open_assignment_index_and_create_pages(): void
    {
        $admin = User::factory()->role(Role::Admin)->create();

        $this->actingAs($admin)
            ->get(route('administration.dynamic-fields.assignments.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('administration/dynamic-fields/assignments/index')
                ->has('assignments'));

        $this->actingAs($admin)
            ->get(route('administration.dynamic-fields.assignments.create'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('administration/dynamic-fields/assignments/create')
                ->has('formOptions.fieldSets')
                ->has('formOptions.categories')
                ->has('formOptions.media')
                ->where('catalogNote', fn (string $note): bool => str_contains($note, 'PO-33b-1')));
    }

    public function test_non_admin_receives_403_on_assignment_pages(): void
    {
        $sales = User::factory()->role(Role::Sales)->create();

        $this->actingAs($sales)
            ->get(route('administration.dynamic-fields.assignments.index'))
            ->assertForbidden();

        $this->actingAs($sales)
            ->get(route('administration.dynamic-fields.assignments.create'))
            ->assertForbidden();
    }

    public function test_hub_links_include_assignments(): void
    {
        $admin = User::factory()->role(Role::Admin)->create();

        $this->actingAs($admin)
            ->get(route('administration.dynamic-fields.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('administration/dynamic-fields/index')
                ->where('links', fn ($links): bool => collect($links)->contains(
                    fn (array $link): bool => str_contains((string) $link['href'], '/assignments'),
                )));
    }

    public function test_create_global_category_and_medium_assignments(): void
    {
        $admin = User::factory()->role(Role::Admin)->create();
        $fieldSet = $this->createAssignableFreeFieldSet($admin, 'ui_global_'.$this->suffix());
        $category = AdvertisingCategory::query()
            ->where('key', CanonicalAdvertisingCategories::SPOTS)
            ->firstOrFail();
        $medium = AdvertisingMedium::factory()->create([
            'category_id' => $category->id,
            'is_active' => true,
        ]);

        $globalResponse = $this->actingAs($admin)
            ->postJson(route('administration.dynamic-fields.assignments.store'), [
                'field_set_id' => $fieldSet->id,
                'target_layer' => 'global',
                'applies_to_process' => 'calculation',
                'sort' => 5,
            ])
            ->assertCreated();

        $global = $globalResponse->json('assignment');

        $this->assertFalse($global['is_active']);
        $this->assertSame('Global', $global['target_label']);

        $this->actingAs($admin)
            ->postJson(route('administration.dynamic-fields.assignments.store'), [
                'field_set_id' => $fieldSet->id,
                'target_layer' => 'global',
                'applies_to_process' => 'dispo_order',
                'sort' => 1,
            ])
            ->assertCreated()
            ->assertJsonPath('redirect', fn ($redirect): bool => str_contains((string) $redirect, '/assignments/'));

        $categoryAssignment = $this->actingAs($admin)
            ->postJson(route('administration.dynamic-fields.assignments.store'), [
                'field_set_id' => $fieldSet->id,
                'target_layer' => 'advertising_category',
                'advertising_category_id' => $category->id,
                'applies_to_process' => 'calculation',
                'sort' => 2,
            ])
            ->assertCreated()
            ->json('assignment');

        $this->assertStringContainsString($category->name, (string) $categoryAssignment['target_label']);

        $mediumAssignment = $this->actingAs($admin)
            ->postJson(route('administration.dynamic-fields.assignments.store'), [
                'field_set_id' => $fieldSet->id,
                'target_layer' => 'advertising_medium',
                'advertising_medium_id' => $medium->id,
                'applies_to_process' => 'calculation',
                'sort' => 3,
            ])
            ->assertCreated()
            ->json('assignment');

        $this->assertStringContainsString($medium->name, (string) $mediumAssignment['target_label']);

        $this->actingAs($admin)
            ->get(route('administration.dynamic-fields.assignments.show', $global['id']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('administration/dynamic-fields/assignments/show')
                ->where('assignment.id', $global['id'])
                ->where('assignment.is_active', false));
    }

    public function test_core_fieldset_and_invalid_process_are_rejected(): void
    {
        $admin = User::factory()->role(Role::Admin)->create();
        $core = FieldSet::query()->where('key', AdminFieldSetCatalog::CALCULATION_CORE)->firstOrFail();
        $calcOnly = $this->createAssignableFreeFieldSet(
            $admin,
            'ui_calc_only_'.$this->suffix(),
            FieldAppliesTo::Calculation,
        );

        $this->actingAs($admin)
            ->postJson(route('administration.dynamic-fields.assignments.store'), [
                'field_set_id' => $core->id,
                'target_layer' => 'global',
                'applies_to_process' => 'calculation',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['field_set_id']);

        $this->actingAs($admin)
            ->postJson(route('administration.dynamic-fields.assignments.store'), [
                'field_set_id' => $calcOnly->id,
                'target_layer' => 'global',
                'applies_to_process' => 'dispo_order',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['applies_to_process']);
    }

    public function test_duplicate_target_returns_readable_conflict(): void
    {
        $admin = User::factory()->role(Role::Admin)->create();
        $fieldSet = $this->createAssignableFreeFieldSet($admin, 'ui_dup_'.$this->suffix());

        $this->actingAs($admin)
            ->postJson(route('administration.dynamic-fields.assignments.store'), [
                'field_set_id' => $fieldSet->id,
                'target_layer' => 'global',
                'applies_to_process' => 'calculation',
            ])
            ->assertCreated();

        $this->actingAs($admin)
            ->postJson(route('administration.dynamic-fields.assignments.store'), [
                'field_set_id' => $fieldSet->id,
                'target_layer' => 'global',
                'applies_to_process' => 'calculation',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['assignment']);
    }

    public function test_inactive_assignment_editable_active_structurally_locked_and_stale_lock_is_409(): void
    {
        $admin = User::factory()->role(Role::Admin)->create();
        $fieldSet = $this->createAssignableFreeFieldSet($admin, 'ui_edit_'.$this->suffix());
        $writer = app(FieldSetAssignmentAdminWriter::class);

        $assignment = $writer->create([
            'field_set_id' => $fieldSet->id,
            'target_layer' => 'global',
            'applies_to_process' => 'calculation',
            'sort' => 1,
        ], $admin);

        $this->actingAs($admin)
            ->putJson(route('administration.dynamic-fields.assignments.update', $assignment), [
                'lock_version' => $assignment->lock_version,
                'sort' => 9,
            ])
            ->assertOk()
            ->assertJsonPath('assignment.sort', 9);

        $previews = $writer->previewAffectedContexts($assignment->fresh(), asCandidate: true);
        $fingerprint = $writer->canonicalActivationFingerprint($previews);
        $activated = $writer->activate($assignment->fresh(), [
            'lock_version' => (int) $assignment->fresh()->lock_version,
            'fingerprint' => $fingerprint,
        ], $admin);

        $this->actingAs($admin)
            ->putJson(route('administration.dynamic-fields.assignments.update', $activated), [
                'lock_version' => $activated->lock_version,
                'sort' => 11,
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['assignment']);

        $this->actingAs($admin)
            ->putJson(route('administration.dynamic-fields.assignments.update', $activated), [
                'lock_version' => $activated->lock_version - 1,
                'sort' => 12,
            ])
            ->assertStatus(409);
    }

    public function test_context_preview_exposes_provenance_and_blocking_conflicts_block_activation(): void
    {
        $admin = User::factory()->role(Role::Admin)->create();
        $definition = $this->createCustomDefinition($admin, [
            'label' => 'Konfliktfeld '.$this->suffix(),
            'scope' => FieldScope::Header->value,
            'applies_to' => FieldAppliesTo::Both->value,
        ]);

        $setA = $this->createAssignableFreeFieldSetWithDefinition(
            $admin,
            'ui_conf_a_'.$this->suffix(),
            $definition,
            requiredOverride: true,
        );
        $setB = $this->createAssignableFreeFieldSetWithDefinition(
            $admin,
            'ui_conf_b_'.$this->suffix(),
            $definition,
            requiredOverride: false,
        );

        $writer = app(FieldSetAssignmentAdminWriter::class);
        $first = $writer->create([
            'field_set_id' => $setA->id,
            'target_layer' => 'global',
            'applies_to_process' => 'calculation',
            'sort' => 1,
        ], $admin);
        $firstPreviews = $writer->previewAffectedContexts($first, asCandidate: true);
        $writer->activate($first, [
            'lock_version' => $first->lock_version,
            'fingerprint' => $writer->canonicalActivationFingerprint($firstPreviews),
        ], $admin);

        $second = $writer->create([
            'field_set_id' => $setB->id,
            'target_layer' => 'global',
            'applies_to_process' => 'calculation',
            'sort' => 2,
        ], $admin);

        $preview = $this->actingAs($admin)
            ->postJson(route('administration.dynamic-fields.assignments.context-preview'), [
                'process' => 'calculation',
                'scope' => 'header',
                'candidate_assignment_id' => $second->id,
            ])
            ->assertOk()
            ->json('preview');

        $this->assertNotEmpty($preview['fields']);
        $this->assertArrayHasKey('winning_layer', $preview['fields'][0]);
        $this->assertTrue($preview['has_blocking_conflicts']);

        $activation = $this->actingAs($admin)
            ->postJson(route('administration.dynamic-fields.assignments.activation-preview', $second))
            ->assertOk()
            ->assertJsonPath('lock_version', $second->lock_version)
            ->json();

        $this->assertTrue($activation['has_blocking_conflicts']);

        $this->actingAs($admin)
            ->postJson(route('administration.dynamic-fields.assignments.activate', $second), [
                'lock_version' => $second->lock_version,
                'fingerprint' => $activation['fingerprint'],
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['assignment']);
    }

    public function test_activation_stale_fingerprint_is_409_and_activate_deactivate_audited(): void
    {
        $admin = User::factory()->role(Role::Admin)->create();
        $fieldSet = $this->createAssignableFreeFieldSet($admin, 'ui_act_'.$this->suffix());
        $writer = app(FieldSetAssignmentAdminWriter::class);
        $assignment = $writer->create([
            'field_set_id' => $fieldSet->id,
            'target_layer' => 'global',
            'applies_to_process' => 'calculation',
            'sort' => 1,
        ], $admin);

        $previews = $writer->previewAffectedContexts($assignment, asCandidate: true);
        $fingerprint = $writer->canonicalActivationFingerprint($previews);

        $this->actingAs($admin)
            ->postJson(route('administration.dynamic-fields.assignments.activate', $assignment), [
                'lock_version' => $assignment->lock_version,
                'fingerprint' => str_repeat('a', 64),
            ])
            ->assertStatus(409);

        $this->actingAs($admin)
            ->postJson(route('administration.dynamic-fields.assignments.activate', $assignment), [
                'lock_version' => $assignment->lock_version,
                'fingerprint' => $fingerprint,
            ])
            ->assertOk()
            ->assertJsonPath('assignment.is_active', true);

        $activated = $assignment->fresh();
        $this->actingAs($admin)
            ->postJson(route('administration.dynamic-fields.assignments.deactivate', $activated), [
                'lock_version' => $activated->lock_version,
            ])
            ->assertOk()
            ->assertJsonPath('assignment.is_active', false);

        $actions = AuditEvent::query()
            ->where('auditable_type', FieldSetAssignment::class)
            ->where('auditable_id', $assignment->id)
            ->pluck('action')
            ->all();

        $this->assertContains('field_set_assignment.activated', $actions);
        $this->assertContains('field_set_assignment.deactivated', $actions);
    }

    public function test_deactivated_catalog_target_not_offered_for_new_but_historical_target_remains_readable(): void
    {
        $admin = User::factory()->role(Role::Admin)->create();
        $fieldSet = $this->createAssignableFreeFieldSet($admin, 'ui_hist_'.$this->suffix());
        $category = AdvertisingCategory::query()->where('is_active', true)->orderBy('id')->firstOrFail();
        $writer = app(FieldSetAssignmentAdminWriter::class);

        $assignment = $writer->create([
            'field_set_id' => $fieldSet->id,
            'target_layer' => 'advertising_category',
            'advertising_category_id' => $category->id,
            'applies_to_process' => 'calculation',
            'sort' => 1,
        ], $admin);

        $category->forceFill(['is_active' => false])->save();

        $this->actingAs($admin)
            ->get(route('administration.dynamic-fields.assignments.create'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('formOptions.categories', fn ($categories): bool => collect($categories)->every(
                    fn (array $row): bool => $row['is_active'] === true,
                )));

        $this->actingAs($admin)
            ->postJson(route('administration.dynamic-fields.assignments.store'), [
                'field_set_id' => $fieldSet->id,
                'target_layer' => 'advertising_category',
                'advertising_category_id' => $category->id,
                'applies_to_process' => 'dispo_order',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['advertising_category_id']);

        $this->actingAs($admin)
            ->get(route('administration.dynamic-fields.assignments.show', $assignment))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('assignment.target_is_selectable', false)
                ->where('assignment.target_label', fn (string $label): bool => str_contains($label, 'deaktiviert'))
                ->where('formOptions.categories', fn ($categories): bool => collect($categories)->contains(
                    fn (array $row): bool => (int) $row['id'] === (int) $category->id && $row['is_active'] === false,
                )));

        $this->actingAs($admin)
            ->get(route('administration.dynamic-fields.assignments.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('assignments', fn ($rows): bool => collect($rows)->contains(
                    fn (array $row): bool => (int) $row['id'] === (int) $assignment->id
                        && $row['target_is_selectable'] === false,
                )));
    }

    public function test_non_admin_cannot_mutate_assignments(): void
    {
        $admin = User::factory()->role(Role::Admin)->create();
        $sales = User::factory()->role(Role::Sales)->create();
        $fieldSet = $this->createAssignableFreeFieldSet($admin, 'ui_auth_'.$this->suffix());
        $assignment = app(FieldSetAssignmentAdminWriter::class)->create([
            'field_set_id' => $fieldSet->id,
            'target_layer' => 'global',
            'applies_to_process' => 'calculation',
        ], $admin);

        $this->actingAs($sales)
            ->postJson(route('administration.dynamic-fields.assignments.store'), [
                'field_set_id' => $fieldSet->id,
                'target_layer' => 'global',
                'applies_to_process' => 'dispo_order',
            ])
            ->assertForbidden();

        $this->actingAs($sales)
            ->get(route('administration.dynamic-fields.assignments.show', $assignment))
            ->assertForbidden();
    }

    private function createAssignableFreeFieldSet(
        User $admin,
        string $key,
        FieldAppliesTo $appliesTo = FieldAppliesTo::Both,
        FieldScope $scope = FieldScope::Header,
    ): FieldSet {
        $definition = $this->createCustomDefinition($admin, [
            'label' => 'Feld '.$key,
            'scope' => $scope->value,
            'applies_to' => $appliesTo === FieldAppliesTo::Both
                ? FieldAppliesTo::Both->value
                : $appliesTo->value,
        ]);

        return $this->createAssignableFreeFieldSetWithDefinition(
            $admin,
            $key,
            $definition,
            appliesTo: $appliesTo,
        );
    }

    private function createAssignableFreeFieldSetWithDefinition(
        User $admin,
        string $key,
        FieldDefinition $definition,
        FieldAppliesTo $appliesTo = FieldAppliesTo::Both,
        ?bool $requiredOverride = null,
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

        $this->actingAs($admin)
            ->post(route('administration.dynamic-fields.field-sets.versions.memberships.store', [$fieldSet, $draft]), array_filter([
                'lock_version' => $fieldSet->fresh()->lock_version,
                'field_definition_id' => $definition->id,
                'field_definition_revision_id' => $definition->current_revision_id,
                'sort' => 20,
                'required_override' => $requiredOverride,
            ], static fn ($value): bool => $value !== null))
            ->assertRedirect();

        $this->actingAs($admin)
            ->post(route('administration.dynamic-fields.field-sets.versions.activate', [$fieldSet, $draft]), [
                'lock_version' => $fieldSet->fresh()->lock_version,
            ])
            ->assertRedirect();

        $fresh = $fieldSet->fresh(['activeVersion']);
        $this->assertTrue((bool) $fresh?->is_assignable);

        return $fresh ?? $fieldSet;
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function createCustomDefinition(User $admin, array $overrides = []): FieldDefinition
    {
        return app(FieldDefinitionCustomWriter::class)->create([
            'label' => $overrides['label'] ?? 'Custom '.$this->suffix(),
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
