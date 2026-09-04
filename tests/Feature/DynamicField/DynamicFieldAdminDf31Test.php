<?php

namespace Tests\Feature\DynamicField;

use App\Enums\FieldSetVersionStatus;
use App\Enums\Role;
use App\Models\AuditEvent;
use App\Models\Calculation;
use App\Models\FieldDefinition;
use App\Models\FieldSet;
use App\Models\FieldSetVersion;
use App\Models\User;
use App\Services\DynamicField\Admin\AdminFieldSetCatalog;
use App\Services\DynamicField\ConfigurationSnapshotMaterializer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * DF-3.1 / DYN-001 / DYN-003 / VER-005 / VER-007 / ADM-001 / ADM-002 / AUTH-001 / AT-14
 */
class DynamicFieldAdminDf31Test extends TestCase
{
    use RefreshDatabase;

    public function test_sales_and_product_management_are_denied_dynamic_field_admin(): void
    {
        foreach ([Role::Sales, Role::ProductManagement, Role::Disposition] as $role) {
            $user = User::factory()->role($role)->create();

            $this->actingAs($user)
                ->get(route('administration.dynamic-fields.index'))
                ->assertForbidden();
        }
    }

    public function test_admin_can_open_administration_hub_and_definitions(): void
    {
        $admin = User::factory()->role(Role::Admin)->create();

        $this->actingAs($admin)
            ->get(route('administration.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('administration/index')
                ->where('modules.0.key', 'dynamic-fields')
                ->where('modules.0.available', true));

        $this->actingAs($admin)
            ->get(route('administration.dynamic-fields.definitions.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('administration/dynamic-fields/definitions/index')
                ->has('definitions'));
    }

    public function test_admin_creates_revision_and_activation_does_not_mutate_old_snapshots(): void
    {
        $admin = User::factory()->role(Role::Admin)->create();
        $sales = User::factory()->role(Role::Sales)->create();

        $definition = FieldDefinition::query()->where('key', 'disposition_notes')->firstOrFail();
        $oldLabel = $definition->currentRevision?->label;
        $this->assertNotNull($oldLabel);

        $fieldSet = FieldSet::query()->where('key', AdminFieldSetCatalog::DISPO_ORDER_CORE)->firstOrFail();
        $activeVersionId = $fieldSet->active_version_id;
        $this->assertNotNull($activeVersionId);

        $oldSnapshot = app(ConfigurationSnapshotMaterializer::class)
            ->materializeFromActiveSet(AdminFieldSetCatalog::DISPO_ORDER_CORE);
        $oldSnapLabel = $oldSnapshot->fieldDefinitions()->where('key', 'disposition_notes')->value('label');
        $this->assertSame($oldLabel, $oldSnapLabel);

        $this->actingAs($admin)
            ->post(route('administration.dynamic-fields.definitions.revisions.store', $definition), [
                'label' => 'Rechnungsbesonderheiten (neu)',
                'help_text' => 'Aktualisierter Hilfetext',
                'group_key' => 'dispo',
                'sort_default' => 10,
                'reportable' => true,
            ])
            ->assertRedirect(route('administration.dynamic-fields.definitions.show', $definition));

        $definition->refresh();
        $this->assertSame('Rechnungsbesonderheiten (neu)', $definition->currentRevision?->label);
        $this->assertSame(2, $definition->currentRevision?->revision);

        $this->assertDatabaseHas('audit_events', [
            'action' => 'field_definition.revision_created',
            'auditable_type' => FieldDefinition::class,
            'auditable_id' => $definition->id,
        ]);

        $fieldSet->refresh();
        $this->actingAs($admin)
            ->post(route('administration.dynamic-fields.field-sets.drafts.store', $fieldSet), [
                'lock_version' => $fieldSet->lock_version,
                'source_version_id' => $activeVersionId,
            ])
            ->assertRedirect();

        $draft = FieldSetVersion::query()
            ->where('field_set_id', $fieldSet->id)
            ->where('status', FieldSetVersionStatus::Draft)
            ->firstOrFail();

        $fieldSet->refresh();
        $this->actingAs($admin)
            ->post(route('administration.dynamic-fields.field-sets.versions.pin-current', [$fieldSet, $draft]), [
                'lock_version' => $fieldSet->lock_version,
            ])
            ->assertRedirect();

        $this->actingAs($admin)
            ->get(route('administration.dynamic-fields.field-sets.versions.preview', [$fieldSet, $draft]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('administration/dynamic-fields/field-sets/preview')
                ->where('preview.status', 'draft')
                ->has('preview.fields')
                ->has('preview.rules'));

        $fieldSet->refresh();
        $this->actingAs($admin)
            ->post(route('administration.dynamic-fields.field-sets.versions.activate', [$fieldSet, $draft]), [
                'lock_version' => $fieldSet->lock_version,
            ])
            ->assertRedirect(route('administration.dynamic-fields.field-sets.show', $fieldSet));

        $oldSnapshot->refresh();
        $this->assertSame(
            $oldSnapLabel,
            $oldSnapshot->fieldDefinitions()->where('key', 'disposition_notes')->value('label'),
        );

        $newSnapshot = app(ConfigurationSnapshotMaterializer::class)
            ->materializeFromActiveSet(AdminFieldSetCatalog::DISPO_ORDER_CORE);
        $this->assertSame(
            'Rechnungsbesonderheiten (neu)',
            $newSnapshot->fieldDefinitions()->where('key', 'disposition_notes')->value('label'),
        );

        $this->assertSame(
            FieldSetVersionStatus::Active,
            FieldSetVersion::query()->whereKey($draft->id)->firstOrFail()->status,
        );
        $this->assertSame(
            FieldSetVersionStatus::Archived,
            FieldSetVersion::query()->whereKey($activeVersionId)->firstOrFail()->status,
        );

        $this->actingAs($sales)
            ->post(route('administration.dynamic-fields.definitions.revisions.store', $definition), [
                'label' => 'Unerlaubt',
                'sort_default' => 1,
                'reportable' => true,
            ])
            ->assertForbidden();
    }

    public function test_stale_lock_version_returns_conflict_on_activate(): void
    {
        $admin = User::factory()->role(Role::Admin)->create();
        $fieldSet = FieldSet::query()->where('key', AdminFieldSetCatalog::CALCULATION_CORE)->firstOrFail();
        $activeId = $fieldSet->active_version_id;
        $this->assertNotNull($activeId);

        $this->actingAs($admin)
            ->post(route('administration.dynamic-fields.field-sets.drafts.store', $fieldSet), [
                'lock_version' => $fieldSet->lock_version,
                'source_version_id' => $activeId,
            ])
            ->assertRedirect();

        $draft = FieldSetVersion::query()
            ->where('field_set_id', $fieldSet->id)
            ->where('status', FieldSetVersionStatus::Draft)
            ->firstOrFail();

        $fieldSet->refresh();
        $this->actingAs($admin)
            ->post(route('administration.dynamic-fields.field-sets.versions.activate', [$fieldSet, $draft]), [
                'lock_version' => $fieldSet->lock_version - 1,
            ])
            ->assertStatus(409);
    }

    public function test_preview_has_no_side_effects_on_calculations(): void
    {
        $admin = User::factory()->role(Role::Admin)->create();
        $fieldSet = FieldSet::query()->where('key', AdminFieldSetCatalog::CALCULATION_CORE)->firstOrFail();
        $version = FieldSetVersion::query()->whereKey($fieldSet->active_version_id)->firstOrFail();
        $calcCount = Calculation::query()->count();
        $auditCount = AuditEvent::query()->count();

        $this->actingAs($admin)
            ->get(route('administration.dynamic-fields.field-sets.versions.preview', [$fieldSet, $version]))
            ->assertOk();

        $this->assertSame($calcCount, Calculation::query()->count());
        $this->assertSame($auditCount, AuditEvent::query()->count());
    }

    public function test_draft_rules_cannot_be_mutated_via_membership_update_payload(): void
    {
        $admin = User::factory()->role(Role::Admin)->create();
        $fieldSet = FieldSet::query()->where('key', AdminFieldSetCatalog::CALCULATION_CORE)->firstOrFail();
        $activeId = $fieldSet->active_version_id;

        $this->actingAs($admin)
            ->post(route('administration.dynamic-fields.field-sets.drafts.store', $fieldSet), [
                'lock_version' => $fieldSet->lock_version,
                'source_version_id' => $activeId,
            ])
            ->assertRedirect();

        $draft = FieldSetVersion::query()
            ->where('field_set_id', $fieldSet->id)
            ->where('status', FieldSetVersionStatus::Draft)
            ->with('fields', 'rules')
            ->firstOrFail();

        $rulesBefore = $draft->rules->map(fn ($rule) => [
            'condition' => $rule->condition_json,
            'action' => $rule->action_json,
        ])->all();

        $fieldSet->refresh();
        $this->actingAs($admin)
            ->put(route('administration.dynamic-fields.field-sets.versions.update', [$fieldSet, $draft]), [
                'lock_version' => $fieldSet->lock_version,
                'fields' => $draft->fields->map(fn ($field) => [
                    'id' => $field->id,
                    'field_definition_revision_id' => $field->field_definition_revision_id,
                    'sort' => $field->sort + 1,
                    'required_override' => $field->required_override,
                    'visible_override' => $field->visible_override,
                ])->all(),
                'rules' => [
                    ['condition' => ['op' => 'hack'], 'action' => ['op' => 'hack']],
                ],
            ])
            ->assertRedirect();

        $draft->refresh()->load('rules');
        $this->assertSame(
            $rulesBefore,
            $draft->rules->map(fn ($rule) => [
                'condition' => $rule->condition_json,
                'action' => $rule->action_json,
            ])->all(),
        );
    }

    public function test_management_can_access_and_denied_roles_cannot_hit_mutating_routes(): void
    {
        $management = User::factory()->role(Role::Management)->create();
        $sales = User::factory()->role(Role::Sales)->create();
        $definition = FieldDefinition::query()->where('key', 'campaign_period')->firstOrFail();
        $fieldSet = FieldSet::query()->where('key', AdminFieldSetCatalog::CALCULATION_CORE)->firstOrFail();
        $version = FieldSetVersion::query()->whereKey($fieldSet->active_version_id)->firstOrFail();

        $this->actingAs($management)
            ->get(route('administration.dynamic-fields.field-sets.index'))
            ->assertOk();

        foreach ([
            ['get', route('administration.index')],
            ['get', route('administration.dynamic-fields.definitions.show', $definition)],
            ['post', route('administration.dynamic-fields.definitions.revisions.store', $definition)],
            ['post', route('administration.dynamic-fields.field-sets.drafts.store', $fieldSet)],
            ['put', route('administration.dynamic-fields.field-sets.versions.update', [$fieldSet, $version])],
            ['post', route('administration.dynamic-fields.field-sets.versions.activate', [$fieldSet, $version])],
        ] as [$method, $uri]) {
            $this->actingAs($sales)
                ->{$method}($uri, [
                    'label' => 'x',
                    'sort_default' => 1,
                    'reportable' => true,
                    'lock_version' => 1,
                    'source_version_id' => $version->id,
                    'fields' => [],
                ])
                ->assertForbidden();
        }
    }

    public function test_active_and_archived_versions_cannot_be_updated_in_place(): void
    {
        $admin = User::factory()->role(Role::Admin)->create();
        $fieldSet = FieldSet::query()->where('key', AdminFieldSetCatalog::CALCULATION_CORE)->firstOrFail();
        $active = FieldSetVersion::query()->whereKey($fieldSet->active_version_id)->with('fields')->firstOrFail();

        $this->actingAs($admin)
            ->put(route('administration.dynamic-fields.field-sets.versions.update', [$fieldSet, $active]), [
                'lock_version' => $fieldSet->lock_version,
                'fields' => $active->fields->map(fn ($field) => [
                    'id' => $field->id,
                    'field_definition_revision_id' => $field->field_definition_revision_id,
                    'sort' => $field->sort + 1,
                    'required_override' => $field->required_override,
                    'visible_override' => $field->visible_override,
                ])->all(),
            ])
            ->assertSessionHasErrors('version');

        $this->actingAs($admin)
            ->post(route('administration.dynamic-fields.field-sets.drafts.store', $fieldSet), [
                'lock_version' => $fieldSet->lock_version,
                'source_version_id' => $active->id,
            ])
            ->assertRedirect();

        $draft = FieldSetVersion::query()
            ->where('field_set_id', $fieldSet->id)
            ->where('status', FieldSetVersionStatus::Draft)
            ->firstOrFail();

        $fieldSet->refresh();
        $this->actingAs($admin)
            ->post(route('administration.dynamic-fields.field-sets.versions.activate', [$fieldSet, $draft]), [
                'lock_version' => $fieldSet->lock_version,
            ])
            ->assertRedirect();

        $active->refresh();
        $this->assertSame(FieldSetVersionStatus::Archived, $active->status);

        $fieldSet->refresh();
        $this->actingAs($admin)
            ->put(route('administration.dynamic-fields.field-sets.versions.update', [$fieldSet, $active]), [
                'lock_version' => $fieldSet->lock_version,
                'fields' => $active->fields()->get()->map(fn ($field) => [
                    'id' => $field->id,
                    'field_definition_revision_id' => $field->field_definition_revision_id,
                    'sort' => $field->sort,
                    'required_override' => $field->required_override,
                    'visible_override' => $field->visible_override,
                ])->all(),
            ])
            ->assertSessionHasErrors('version');
    }

    public function test_copy_as_template_from_archived_creates_new_draft(): void
    {
        $admin = User::factory()->role(Role::Admin)->create();
        $fieldSet = FieldSet::query()->where('key', AdminFieldSetCatalog::CALCULATION_CORE)->firstOrFail();
        $originalActiveId = $fieldSet->active_version_id;

        $this->actingAs($admin)
            ->post(route('administration.dynamic-fields.field-sets.drafts.store', $fieldSet), [
                'lock_version' => $fieldSet->lock_version,
                'source_version_id' => $originalActiveId,
            ])
            ->assertRedirect();

        $firstDraft = FieldSetVersion::query()
            ->where('field_set_id', $fieldSet->id)
            ->where('status', FieldSetVersionStatus::Draft)
            ->firstOrFail();

        $fieldSet->refresh();
        $this->actingAs($admin)
            ->post(route('administration.dynamic-fields.field-sets.versions.activate', [$fieldSet, $firstDraft]), [
                'lock_version' => $fieldSet->lock_version,
            ])
            ->assertRedirect();

        $archived = FieldSetVersion::query()->whereKey($originalActiveId)->firstOrFail();
        $this->assertSame(FieldSetVersionStatus::Archived, $archived->status);

        $fieldSet->refresh();
        $this->actingAs($admin)
            ->post(route('administration.dynamic-fields.field-sets.drafts.store', $fieldSet), [
                'lock_version' => $fieldSet->lock_version,
                'source_version_id' => $archived->id,
            ])
            ->assertRedirect();

        $copyDraft = FieldSetVersion::query()
            ->where('field_set_id', $fieldSet->id)
            ->where('status', FieldSetVersionStatus::Draft)
            ->firstOrFail();

        $this->assertNotSame($archived->id, $copyDraft->id);
        $this->assertSame(FieldSetVersionStatus::Draft, $copyDraft->status);
        $this->assertSame($archived->fields()->count(), $copyDraft->fields()->count());
    }

    public function test_conflict_response_uses_german_message(): void
    {
        $admin = User::factory()->role(Role::Admin)->create();
        $fieldSet = FieldSet::query()->where('key', AdminFieldSetCatalog::CALCULATION_CORE)->firstOrFail();
        $activeId = $fieldSet->active_version_id;

        $this->actingAs($admin)
            ->post(route('administration.dynamic-fields.field-sets.drafts.store', $fieldSet), [
                'lock_version' => $fieldSet->lock_version,
                'source_version_id' => $activeId,
            ])
            ->assertRedirect();

        $draft = FieldSetVersion::query()
            ->where('field_set_id', $fieldSet->id)
            ->where('status', FieldSetVersionStatus::Draft)
            ->firstOrFail();

        $fieldSet->refresh();
        $response = $this->actingAs($admin)
            ->postJson(route('administration.dynamic-fields.field-sets.versions.activate', [$fieldSet, $draft]), [
                'lock_version' => $fieldSet->lock_version - 1,
            ]);

        $response->assertStatus(409);
        $this->assertStringContainsString(
            'parallel geändert',
            (string) $response->json('message'),
        );
    }

    public function test_conflict_on_draft_update_and_pin_returns_german_message(): void
    {
        $admin = User::factory()->role(Role::Admin)->create();
        $fieldSet = FieldSet::query()->where('key', AdminFieldSetCatalog::DISPO_ORDER_CORE)->firstOrFail();
        $activeId = $fieldSet->active_version_id;

        $this->actingAs($admin)
            ->post(route('administration.dynamic-fields.field-sets.drafts.store', $fieldSet), [
                'lock_version' => $fieldSet->lock_version,
                'source_version_id' => $activeId,
            ])
            ->assertRedirect();

        $draft = FieldSetVersion::query()
            ->where('field_set_id', $fieldSet->id)
            ->where('status', FieldSetVersionStatus::Draft)
            ->firstOrFail();

        $fieldSet->refresh();
        $staleLock = $fieldSet->lock_version - 1;
        $membership = $draft->fields()->firstOrFail();

        $update = $this->actingAs($admin)
            ->putJson(route('administration.dynamic-fields.field-sets.versions.update', [$fieldSet, $draft]), [
                'lock_version' => $staleLock,
                'fields' => [[
                    'id' => $membership->id,
                    'field_definition_revision_id' => $membership->field_definition_revision_id,
                    'sort' => $membership->sort,
                    'required_override' => $membership->required_override,
                    'visible_override' => $membership->visible_override,
                ]],
            ]);

        $update->assertStatus(409);
        $this->assertStringContainsString('parallel geändert', (string) $update->json('message'));

        $pin = $this->actingAs($admin)
            ->postJson(route('administration.dynamic-fields.field-sets.versions.pin-current', [$fieldSet, $draft]), [
                'lock_version' => $staleLock,
            ]);

        $pin->assertStatus(409);
        $this->assertStringContainsString('parallel geändert', (string) $pin->json('message'));
    }
}
