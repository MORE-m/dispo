<?php

namespace Tests\Feature\DynamicField;

use App\Enums\FieldAppliesTo;
use App\Enums\FieldScope;
use App\Enums\FieldType;
use App\Enums\Role;
use App\Models\FieldDefinition;
use App\Models\FieldSet;
use App\Models\FieldSetVersion;
use App\Models\User;
use App\Services\DynamicField\Admin\AdminFieldSetCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * DF-3.2b Admin: Position-Custom-Felder, Membership-Scope, Activate.
 */
class DynamicFieldAdminDf32bTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_creates_custom_position_field(): void
    {
        $admin = User::factory()->role(Role::Admin)->create();

        $this->actingAs($admin)
            ->post(route('administration.dynamic-fields.definitions.store'), [
                'label' => 'Positionsnotiz',
                'field_type' => FieldType::ShortText->value,
                'scope' => FieldScope::Position->value,
                'applies_to' => FieldAppliesTo::Both->value,
                'max_length' => 120,
                'sort_default' => 40,
            ])
            ->assertRedirect();

        $definition = FieldDefinition::query()->where('key', 'positionsnotiz')->firstOrFail();
        $this->assertSame(FieldScope::Position, $definition->scope);
        $this->assertFalse($definition->is_system);
        $this->assertSame(120, $definition->currentRevision?->validation_json['max_length'] ?? null);
    }

    public function test_membership_scope_mismatch_rejected_and_matching_activates(): void
    {
        $admin = User::factory()->role(Role::Admin)->create();

        $header = $this->createCustomDefinition($admin, [
            'label' => 'Header Match',
            'scope' => FieldScope::Header->value,
            'applies_to' => FieldAppliesTo::Both->value,
        ]);
        $position = $this->createCustomDefinition($admin, [
            'label' => 'Position Match',
            'scope' => FieldScope::Position->value,
            'applies_to' => FieldAppliesTo::Both->value,
        ]);

        $calcSet = FieldSet::query()->where('key', AdminFieldSetCatalog::CALCULATION_CORE)->firstOrFail();
        $calcDraft = $this->createDraft($admin, $calcSet);

        $calcSet->refresh();
        $this->actingAs($admin)
            ->post(route('administration.dynamic-fields.field-sets.versions.memberships.store', [$calcSet, $calcDraft]), [
                'lock_version' => $calcSet->lock_version,
                'field_definition_id' => $position->id,
                'field_definition_revision_id' => $position->current_revision_id,
                'sort' => 60,
            ])
            ->assertRedirect();

        $calcSet->refresh();
        $this->actingAs($admin)
            ->post(route('administration.dynamic-fields.field-sets.versions.memberships.store', [$calcSet, $calcDraft]), [
                'lock_version' => $calcSet->lock_version,
                'field_definition_id' => $header->id,
                'field_definition_revision_id' => $header->current_revision_id,
                'sort' => 61,
            ])
            ->assertRedirect();

        $calcSet->refresh();
        $this->actingAs($admin)
            ->post(route('administration.dynamic-fields.field-sets.versions.activate', [$calcSet, $calcDraft]), [
                'lock_version' => $calcSet->lock_version,
            ])
            ->assertRedirect();

        $calcSet->refresh();
        $this->assertSame($calcDraft->id, $calcSet->active_version_id);
    }

    public function test_applies_to_mismatch_still_rejected_for_position(): void
    {
        $admin = User::factory()->role(Role::Admin)->create();
        $dispoOnlyPosition = $this->createCustomDefinition($admin, [
            'label' => 'Nur Dispo Pos',
            'scope' => FieldScope::Position->value,
            'applies_to' => FieldAppliesTo::DispoOrder->value,
        ]);

        $calcSet = FieldSet::query()->where('key', AdminFieldSetCatalog::CALCULATION_CORE)->firstOrFail();
        $calcDraft = $this->createDraft($admin, $calcSet);
        $calcSet->refresh();

        $this->actingAs($admin)
            ->post(route('administration.dynamic-fields.field-sets.versions.memberships.store', [$calcSet, $calcDraft]), [
                'lock_version' => $calcSet->lock_version,
                'field_definition_id' => $dispoOnlyPosition->id,
                'field_definition_revision_id' => $dispoOnlyPosition->current_revision_id,
                'sort' => 70,
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
                'label' => 'Custom Pos',
                'field_type' => FieldType::ShortText->value,
                'scope' => FieldScope::Position->value,
                'applies_to' => FieldAppliesTo::Both->value,
                'sort_default' => 80,
                'reportable' => false,
            ], $overrides))
            ->assertRedirect();

        $label = (string) ($overrides['label'] ?? 'Custom Pos');
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
            ->where('status', 'draft')
            ->firstOrFail();
    }
}
