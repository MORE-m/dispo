<?php

namespace Tests\Feature\DynamicField;

use App\Enums\FieldAppliesTo;
use App\Enums\FieldScope;
use App\Enums\FieldSetVersionStatus;
use App\Enums\FieldType;
use App\Enums\Role;
use App\Models\AuditEvent;
use App\Models\FieldDefinition;
use App\Models\FieldSet;
use App\Models\FieldSetVersion;
use App\Models\FieldSetVersionField;
use App\Models\User;
use App\Services\DynamicField\Admin\AdminFieldSetCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * DF-3.2a Admin: Custom Header-Textfelder, Membership, Deactivate, 409.
 */
class DynamicFieldAdminDf32aTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_creates_custom_header_field_and_rejects_system_prefix(): void
    {
        $admin = User::factory()->role(Role::Admin)->create();

        $this->actingAs($admin)
            ->post(route('administration.dynamic-fields.definitions.store'), [
                'label' => 'Kampagnen Hinweis',
                'field_type' => FieldType::ShortText->value,
                'applies_to' => FieldAppliesTo::Both->value,
                'max_length' => 100,
                'reportable' => true,
                'sort_default' => 50,
            ])
            ->assertRedirect();

        $definition = FieldDefinition::query()->where('key', 'kampagnen_hinweis')->firstOrFail();
        $this->assertFalse($definition->is_system);
        $this->assertTrue($definition->is_active);
        $this->assertSame(1, $definition->lock_version);
        $this->assertSame(FieldScope::Header, $definition->scope);
        $this->assertSame(100, $definition->currentRevision?->validation_json['max_length'] ?? null);

        $this->assertDatabaseHas('audit_events', [
            'action' => 'field_definition.created',
            'auditable_id' => $definition->id,
        ]);

        $this->actingAs($admin)
            ->post(route('administration.dynamic-fields.definitions.store'), [
                'label' => 'System Foo',
                'field_type' => FieldType::LongText->value,
                'applies_to' => FieldAppliesTo::Calculation->value,
            ])
            ->assertSessionHasErrors('label');
    }

    public function test_update_before_used_and_revision_keeps_key(): void
    {
        $admin = User::factory()->role(Role::Admin)->create();
        $definition = $this->createCustomDefinition($admin, [
            'label' => 'Notiz A',
            'applies_to' => FieldAppliesTo::DispoOrder->value,
        ]);
        $originalKey = $definition->key;

        $this->actingAs($admin)
            ->put(route('administration.dynamic-fields.definitions.update', $definition), [
                'lock_version' => $definition->lock_version,
                'label' => 'Notiz B',
                'field_type' => FieldType::LongText->value,
                'applies_to' => FieldAppliesTo::Both->value,
                'max_length' => 500,
            ])
            ->assertRedirect();

        $definition->refresh();
        $this->assertSame($originalKey, $definition->key);
        $this->assertSame('Notiz B', $definition->currentRevision?->label);
        $this->assertSame(FieldType::LongText, $definition->field_type);
        $this->assertSame(2, $definition->lock_version);

        $this->actingAs($admin)
            ->post(route('administration.dynamic-fields.definitions.revisions.store', $definition), [
                'lock_version' => $definition->lock_version,
                'label' => 'Notiz C',
                'sort_default' => 10,
                'reportable' => false,
                'max_length' => 800,
            ])
            ->assertRedirect();

        $definition->refresh();
        $this->assertSame($originalKey, $definition->key);
        $this->assertSame('Notiz C', $definition->currentRevision?->label);
        $this->assertSame(2, $definition->currentRevision?->revision);
        $this->assertSame(800, $definition->currentRevision?->validation_json['max_length'] ?? null);
        $this->assertSame(3, $definition->lock_version);
    }

    public function test_stale_lock_on_definition_returns_409(): void
    {
        $admin = User::factory()->role(Role::Admin)->create();
        $definition = $this->createCustomDefinition($admin);
        $staleLock = $definition->lock_version;

        $this->actingAs($admin)
            ->put(route('administration.dynamic-fields.definitions.update', $definition), [
                'lock_version' => $definition->lock_version,
                'label' => 'Zwischenstand',
            ])
            ->assertRedirect();

        $response = $this->actingAs($admin)
            ->putJson(route('administration.dynamic-fields.definitions.update', $definition), [
                'lock_version' => $staleLock,
                'label' => 'Konflikt',
            ]);

        $response->assertStatus(409);
        $this->assertStringContainsString('parallel', (string) $response->json('message'));
    }

    public function test_membership_add_remove_guards_and_applies_to(): void
    {
        $admin = User::factory()->role(Role::Admin)->create();
        $calcOnly = $this->createCustomDefinition($admin, [
            'label' => 'Nur Calc',
            'applies_to' => FieldAppliesTo::Calculation->value,
        ]);
        $dispoOnly = $this->createCustomDefinition($admin, [
            'label' => 'Nur Dispo',
            'applies_to' => FieldAppliesTo::DispoOrder->value,
        ]);
        $both = $this->createCustomDefinition($admin, [
            'label' => 'Beide',
            'applies_to' => FieldAppliesTo::Both->value,
        ]);

        $dispoSet = FieldSet::query()->where('key', AdminFieldSetCatalog::DISPO_ORDER_CORE)->firstOrFail();
        $calcSet = FieldSet::query()->where('key', AdminFieldSetCatalog::CALCULATION_CORE)->firstOrFail();
        $dispoDraft = $this->createDraft($admin, $dispoSet);
        $calcDraft = $this->createDraft($admin, $calcSet);

        $dispoSet->refresh();
        $this->actingAs($admin)
            ->post(route('administration.dynamic-fields.field-sets.versions.memberships.store', [$dispoSet, $dispoDraft]), [
                'lock_version' => $dispoSet->lock_version,
                'field_definition_id' => $calcOnly->id,
                'field_definition_revision_id' => $calcOnly->current_revision_id,
                'sort' => 40,
            ])
            ->assertSessionHasErrors('field_definition_id');

        $calcSet->refresh();
        $this->actingAs($admin)
            ->post(route('administration.dynamic-fields.field-sets.versions.memberships.store', [$calcSet, $calcDraft]), [
                'lock_version' => $calcSet->lock_version,
                'field_definition_id' => $dispoOnly->id,
                'field_definition_revision_id' => $dispoOnly->current_revision_id,
                'sort' => 40,
            ])
            ->assertSessionHasErrors('field_definition_id');

        $dispoSet->refresh();
        $this->actingAs($admin)
            ->post(route('administration.dynamic-fields.field-sets.versions.memberships.store', [$dispoSet, $dispoDraft]), [
                'lock_version' => $dispoSet->lock_version,
                'field_definition_id' => $both->id,
                'field_definition_revision_id' => $both->current_revision_id,
                'sort' => 40,
                'required_override' => true,
                'visible_override' => null,
            ])
            ->assertRedirect();

        $membership = FieldSetVersionField::query()
            ->where('field_set_version_id', $dispoDraft->id)
            ->where('field_definition_id', $both->id)
            ->firstOrFail();

        $systemMembership = FieldSetVersionField::query()
            ->where('field_set_version_id', $dispoDraft->id)
            ->whereHas('definition', fn ($q) => $q->where('is_system', true))
            ->firstOrFail();

        $dispoSet->refresh();
        $this->actingAs($admin)
            ->delete(route('administration.dynamic-fields.field-sets.versions.memberships.destroy', [
                $dispoSet,
                $dispoDraft,
                $systemMembership,
            ]), [
                'lock_version' => $dispoSet->lock_version,
            ])
            ->assertSessionHasErrors('membership');

        $dispoSet->refresh();
        $this->actingAs($admin)
            ->delete(route('administration.dynamic-fields.field-sets.versions.memberships.destroy', [
                $dispoSet,
                $dispoDraft,
                $membership,
            ]), [
                'lock_version' => $dispoSet->lock_version,
            ])
            ->assertRedirect();

        $this->assertDatabaseMissing('field_set_version_fields', ['id' => $membership->id]);
    }

    public function test_deactivate_blocked_while_active_or_draft_membership_then_hard_delete(): void
    {
        $admin = User::factory()->role(Role::Admin)->create();
        $definition = $this->createCustomDefinition($admin, [
            'label' => 'Temp Feld',
            'applies_to' => FieldAppliesTo::DispoOrder->value,
        ]);

        $dispoSet = FieldSet::query()->where('key', AdminFieldSetCatalog::DISPO_ORDER_CORE)->firstOrFail();
        $draft = $this->createDraft($admin, $dispoSet);
        $dispoSet->refresh();
        $this->actingAs($admin)
            ->post(route('administration.dynamic-fields.field-sets.versions.memberships.store', [$dispoSet, $draft]), [
                'lock_version' => $dispoSet->lock_version,
                'field_definition_id' => $definition->id,
                'field_definition_revision_id' => $definition->current_revision_id,
                'sort' => 55,
            ])
            ->assertRedirect();

        $definition->refresh();
        $this->actingAs($admin)
            ->post(route('administration.dynamic-fields.definitions.deactivate', $definition), [
                'lock_version' => $definition->lock_version,
            ])
            ->assertSessionHasErrors('definition');

        $dispoSet->refresh();
        $membership = FieldSetVersionField::query()
            ->where('field_set_version_id', $draft->id)
            ->where('field_definition_id', $definition->id)
            ->firstOrFail();
        $this->actingAs($admin)
            ->delete(route('administration.dynamic-fields.field-sets.versions.memberships.destroy', [
                $dispoSet,
                $draft,
                $membership,
            ]), [
                'lock_version' => $dispoSet->lock_version,
            ])
            ->assertRedirect();

        $definition->refresh();
        $lockBeforeDelete = $definition->lock_version;
        $this->actingAs($admin)
            ->post(route('administration.dynamic-fields.definitions.deactivate', $definition), [
                'lock_version' => $definition->lock_version,
            ])
            ->assertRedirect();

        $definition->refresh();
        $this->assertFalse($definition->is_active);
        $this->assertSame($lockBeforeDelete + 1, $definition->lock_version);

        $this->actingAs($admin)
            ->post(route('administration.dynamic-fields.definitions.reactivate', $definition), [
                'lock_version' => $definition->lock_version,
            ])
            ->assertRedirect();
        $definition->refresh();
        $this->assertTrue($definition->is_active);

        $lockBeforeHardDelete = $definition->lock_version;
        $this->actingAs($admin)
            ->delete(route('administration.dynamic-fields.definitions.destroy', $definition), [
                'lock_version' => $definition->lock_version,
            ])
            ->assertRedirect(route('administration.dynamic-fields.definitions.index'));

        $this->assertDatabaseMissing('field_definitions', ['id' => $definition->id]);
        $deletedEvent = AuditEvent::query()
            ->where('action', 'field_definition.deleted')
            ->where('auditable_id', $definition->id)
            ->firstOrFail();
        $this->assertSame($lockBeforeHardDelete, $deletedEvent->old_values['lock_version'] ?? null);
    }

    public function test_index_lists_system_and_custom_separately(): void
    {
        $admin = User::factory()->role(Role::Admin)->create();
        $this->createCustomDefinition($admin, ['label' => 'Extra Feld']);

        $this->actingAs($admin)
            ->get(route('administration.dynamic-fields.definitions.index', ['type' => 'custom']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('administration/dynamic-fields/definitions/index')
                ->has('customDefinitions', 1)
                ->has('systemDefinitions')
                ->where('filter.type', 'custom'));
    }

    public function test_sales_cannot_mutate_custom_definitions(): void
    {
        $admin = User::factory()->role(Role::Admin)->create();
        $sales = User::factory()->role(Role::Sales)->create();
        $definition = $this->createCustomDefinition($admin);

        $this->actingAs($sales)
            ->post(route('administration.dynamic-fields.definitions.store'), [
                'label' => 'X',
                'field_type' => FieldType::ShortText->value,
                'applies_to' => FieldAppliesTo::Both->value,
            ])
            ->assertForbidden();

        $this->actingAs($sales)
            ->post(route('administration.dynamic-fields.definitions.deactivate', $definition), [
                'lock_version' => $definition->lock_version,
            ])
            ->assertForbidden();
    }

    public function test_draft_membership_locks_structural_update_and_activate_rechecks_applies_to(): void
    {
        $admin = User::factory()->role(Role::Admin)->create();
        $definition = $this->createCustomDefinition($admin, [
            'label' => 'Drift Guard',
            'applies_to' => FieldAppliesTo::Both->value,
        ]);

        $calcSet = FieldSet::query()->where('key', AdminFieldSetCatalog::CALCULATION_CORE)->firstOrFail();
        $calcDraft = $this->createDraft($admin, $calcSet);
        $calcSet->refresh();

        $this->actingAs($admin)
            ->post(route('administration.dynamic-fields.field-sets.versions.memberships.store', [$calcSet, $calcDraft]), [
                'lock_version' => $calcSet->lock_version,
                'field_definition_id' => $definition->id,
                'field_definition_revision_id' => $definition->current_revision_id,
                'sort' => 55,
            ])
            ->assertRedirect();

        $definition->refresh();
        $this->actingAs($admin)
            ->put(route('administration.dynamic-fields.definitions.update', $definition), [
                'lock_version' => $definition->lock_version,
                'label' => 'Drift Guard',
                'field_type' => FieldType::ShortText->value,
                'applies_to' => FieldAppliesTo::DispoOrder->value,
                'max_length' => 100,
            ])
            ->assertSessionHasErrors('definition');

        // Defense-in-depth: even if applies_to were wrong, activate must reject.
        $definition->forceFill(['applies_to' => FieldAppliesTo::DispoOrder])->save();
        $calcSet->refresh();
        $this->actingAs($admin)
            ->post(route('administration.dynamic-fields.field-sets.versions.activate', [$calcSet, $calcDraft]), [
                'lock_version' => $calcSet->lock_version,
            ])
            ->assertSessionHasErrors('field_definition_id');
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function createCustomDefinition(User $admin, array $overrides = []): FieldDefinition
    {
        $this->actingAs($admin)
            ->post(route('administration.dynamic-fields.definitions.store'), array_merge([
                'label' => 'Custom Hinweis',
                'field_type' => FieldType::ShortText->value,
                'applies_to' => FieldAppliesTo::Both->value,
                'sort_default' => 80,
                'reportable' => false,
            ], $overrides))
            ->assertRedirect();

        $label = (string) ($overrides['label'] ?? 'Custom Hinweis');
        $key = str($label)->ascii()->lower()->replaceMatches('/[^a-z0-9]+/', '_')->trim('_')->toString();

        return FieldDefinition::query()->where('key', $key)->firstOrFail();
    }

    private function createDraft(User $admin, FieldSet $fieldSet): FieldSetVersion
    {
        $this->actingAs($admin)
            ->post(route('administration.dynamic-fields.field-sets.drafts.store', $fieldSet), [
                'lock_version' => $fieldSet->lock_version,
                'source_version_id' => $fieldSet->active_version_id,
            ])
            ->assertRedirect();

        return FieldSetVersion::query()
            ->where('field_set_id', $fieldSet->id)
            ->where('status', FieldSetVersionStatus::Draft)
            ->firstOrFail();
    }
}
