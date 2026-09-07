<?php

namespace Tests\Feature\DynamicField;

use App\Enums\FieldAppliesTo;
use App\Enums\FieldScope;
use App\Enums\FieldSetVersionStatus;
use App\Enums\FieldType;
use App\Enums\Role;
use App\Models\FieldDefinition;
use App\Models\FieldSet;
use App\Models\FieldSetVersion;
use App\Models\FieldSetVersionField;
use App\Models\User;
use App\Services\DynamicField\Admin\AdminFieldSetCatalog;
use App\Services\DynamicField\Admin\FieldDefinitionCustomWriter;
use App\Services\DynamicField\ConfigurationSnapshotMaterializer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * DF-3.3-fs / DYN-002 / DYN-003 / VER-005 / VER-006 / ADM-001 / ADM-002 / AUTH-001
 */
class DynamicFieldAdminDf33FsTest extends TestCase
{
    use RefreshDatabase;

    public function test_migration_backfills_core_metadata_and_preserves_ids(): void
    {
        $calc = FieldSet::query()->where('key', AdminFieldSetCatalog::CALCULATION_CORE)->firstOrFail();
        $dispo = FieldSet::query()->where('key', AdminFieldSetCatalog::DISPO_ORDER_CORE)->firstOrFail();

        $before = [
            'calc_id' => $calc->id,
            'dispo_id' => $dispo->id,
            'calc_active' => $calc->active_version_id,
            'dispo_active' => $dispo->active_version_id,
            'calc_versions' => FieldSetVersion::query()->where('field_set_id', $calc->id)->count(),
            'memberships' => FieldSetVersionField::query()->count(),
            'snapshot_defs' => DB::table('snapshot_field_definitions')->count(),
        ];

        $this->assertTrue($calc->is_system);
        $this->assertSame(FieldAppliesTo::Calculation, $calc->applies_to);
        $this->assertFalse($calc->is_assignable);
        $this->assertTrue($dispo->is_system);
        $this->assertSame(FieldAppliesTo::DispoOrder, $dispo->applies_to);
        $this->assertFalse($dispo->is_assignable);

        /** @var object{up: callable, down: callable} $migration */
        $migration = require database_path('migrations/2026_09_07_200000_add_fieldset_container_metadata.php');
        $migration->down();
        $this->assertFalse(Schema::hasColumn('field_sets', 'is_system'));

        $migration->up();

        $calc->refresh();
        $dispo->refresh();
        $this->assertSame($before['calc_id'], $calc->id);
        $this->assertSame($before['dispo_id'], $dispo->id);
        $this->assertSame($before['calc_active'], $calc->active_version_id);
        $this->assertSame($before['dispo_active'], $dispo->active_version_id);
        $this->assertSame(
            $before['calc_versions'],
            FieldSetVersion::query()->where('field_set_id', $calc->id)->count(),
        );
        $this->assertSame($before['memberships'], FieldSetVersionField::query()->count());
        $this->assertSame($before['snapshot_defs'], DB::table('snapshot_field_definitions')->count());
        $this->assertTrue($calc->is_system);
        $this->assertSame(FieldAppliesTo::Calculation, $calc->applies_to);
        $this->assertFalse($calc->is_assignable);
    }

    public function test_migration_fails_closed_on_unknown_fieldset(): void
    {
        /** @var object{up: callable, down: callable} $migration */
        $migration = require database_path('migrations/2026_09_07_200000_add_fieldset_container_metadata.php');
        $migration->down();

        $now = now();
        $unknownId = DB::table('field_sets')->insertGetId([
            'key' => 'legacy_unknown_set',
            'name' => 'Unbekannt',
            'active_version_id' => null,
            'lock_version' => 1,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        try {
            $migration->up();
            $this->fail('Expected RuntimeException for unknown fieldset.');
        } catch (\Throwable $exception) {
            $this->assertInstanceOf(\RuntimeException::class, $exception);
            $this->assertStringContainsString((string) $unknownId, $exception->getMessage());
            $this->assertStringContainsString('legacy_unknown_set', $exception->getMessage());
        }

        DB::table('field_sets')->where('id', $unknownId)->delete();
        $migration->up();
        $this->assertTrue(Schema::hasColumn('field_sets', 'is_system'));
    }

    public function test_admin_creates_free_fieldset_atomically_with_empty_draft(): void
    {
        $admin = User::factory()->role(Role::Admin)->create();

        $this->actingAs($admin)
            ->post(route('administration.dynamic-fields.field-sets.store'), [
                'name' => 'Targeting Paket',
                'key' => 'targeting_paket',
                'applies_to' => FieldAppliesTo::Both->value,
            ])
            ->assertRedirect();

        $fieldSet = FieldSet::query()->where('key', 'targeting_paket')->firstOrFail();
        $this->assertFalse($fieldSet->is_system);
        $this->assertFalse($fieldSet->is_assignable);
        $this->assertNull($fieldSet->active_version_id);
        $this->assertSame(1, $fieldSet->lock_version);
        $this->assertSame(FieldAppliesTo::Both, $fieldSet->applies_to);

        $draft = FieldSetVersion::query()->where('field_set_id', $fieldSet->id)->sole();
        $this->assertSame(1, $draft->version);
        $this->assertSame(FieldSetVersionStatus::Draft, $draft->status);
        $this->assertSame(0, $draft->fields()->count());

        $this->assertDatabaseHas('audit_events', [
            'action' => 'field_set.created',
            'auditable_id' => $fieldSet->id,
        ]);
    }

    public function test_key_validation_uniqueness_system_prefix_and_immutability(): void
    {
        $admin = User::factory()->role(Role::Admin)->create();
        $fieldSet = $this->createFreeFieldSet($admin, ['key' => 'reporting_set']);

        $this->actingAs($admin)
            ->post(route('administration.dynamic-fields.field-sets.store'), [
                'name' => 'Duplikat',
                'key' => 'reporting_set',
                'applies_to' => FieldAppliesTo::Calculation->value,
            ])
            ->assertSessionHasErrors('key');

        $this->actingAs($admin)
            ->post(route('administration.dynamic-fields.field-sets.store'), [
                'name' => 'System Fake',
                'key' => 'system_fake',
                'applies_to' => FieldAppliesTo::Calculation->value,
            ])
            ->assertSessionHasErrors('key');

        $this->actingAs($admin)
            ->put(route('administration.dynamic-fields.field-sets.update', $fieldSet), [
                'lock_version' => $fieldSet->lock_version,
                'name' => 'Reporting Set Neu',
                'key' => 'hacked_key',
            ])
            ->assertRedirect();

        $fieldSet->refresh();
        $this->assertSame('reporting_set', $fieldSet->key);
        $this->assertSame('Reporting Set Neu', $fieldSet->name);
    }

    public function test_applies_to_mutable_before_first_activation_and_immutable_after(): void
    {
        $admin = User::factory()->role(Role::Admin)->create();
        $fieldSet = $this->createFreeFieldSet($admin, [
            'key' => 'event_data',
            'applies_to' => FieldAppliesTo::Calculation->value,
        ]);
        $definition = $this->createCustomDefinition($admin, [
            'label' => 'Event Hinweis',
            'applies_to' => FieldAppliesTo::Both->value,
        ]);
        $draft = FieldSetVersion::query()->where('field_set_id', $fieldSet->id)->firstOrFail();
        $this->addMembership($admin, $fieldSet, $draft, $definition);

        $this->actingAs($admin)
            ->put(route('administration.dynamic-fields.field-sets.update', $fieldSet), [
                'lock_version' => $fieldSet->fresh()->lock_version,
                'applies_to' => FieldAppliesTo::Both->value,
            ])
            ->assertRedirect();
        $this->assertSame(FieldAppliesTo::Both, $fieldSet->fresh()->applies_to);

        $this->activateDraft($admin, $fieldSet, $draft);

        $this->actingAs($admin)
            ->put(route('administration.dynamic-fields.field-sets.update', $fieldSet->fresh()), [
                'lock_version' => $fieldSet->fresh()->lock_version,
                'applies_to' => FieldAppliesTo::DispoOrder->value,
            ])
            ->assertSessionHasErrors('applies_to');
    }

    public function test_applies_to_change_rejects_incompatible_draft_memberships(): void
    {
        $admin = User::factory()->role(Role::Admin)->create();
        $fieldSet = $this->createFreeFieldSet($admin, [
            'key' => 'mixed_guard',
            'applies_to' => FieldAppliesTo::Both->value,
        ]);
        $calcOnly = $this->createCustomDefinition($admin, [
            'label' => 'Nur Calc',
            'applies_to' => FieldAppliesTo::Calculation->value,
        ]);
        $draft = FieldSetVersion::query()->where('field_set_id', $fieldSet->id)->firstOrFail();
        $this->addMembership($admin, $fieldSet, $draft, $calcOnly);

        $this->actingAs($admin)
            ->put(route('administration.dynamic-fields.field-sets.update', $fieldSet->fresh()), [
                'lock_version' => $fieldSet->fresh()->lock_version,
                'applies_to' => FieldAppliesTo::DispoOrder->value,
            ])
            ->assertSessionHasErrors('field_definition_id');
    }

    public function test_membership_applies_to_matrix_and_system_defs_blocked_on_free_sets(): void
    {
        $admin = User::factory()->role(Role::Admin)->create();
        $fieldSet = $this->createFreeFieldSet($admin, [
            'key' => 'dispo_only_set',
            'applies_to' => FieldAppliesTo::DispoOrder->value,
        ]);
        $draft = FieldSetVersion::query()->where('field_set_id', $fieldSet->id)->firstOrFail();

        $calcDef = $this->createCustomDefinition($admin, [
            'label' => 'Calc Only',
            'applies_to' => FieldAppliesTo::Calculation->value,
        ]);
        $dispoDef = $this->createCustomDefinition($admin, [
            'label' => 'Dispo Only',
            'applies_to' => FieldAppliesTo::DispoOrder->value,
        ]);
        $system = FieldDefinition::query()->where('key', 'campaign_period')->firstOrFail();

        $this->actingAs($admin)
            ->post(route('administration.dynamic-fields.field-sets.versions.memberships.store', [$fieldSet, $draft]), [
                'lock_version' => $fieldSet->fresh()->lock_version,
                'field_definition_id' => $calcDef->id,
                'field_definition_revision_id' => $calcDef->current_revision_id,
                'sort' => 10,
            ])
            ->assertSessionHasErrors('field_definition_id');

        $this->actingAs($admin)
            ->post(route('administration.dynamic-fields.field-sets.versions.memberships.store', [$fieldSet, $draft]), [
                'lock_version' => $fieldSet->fresh()->lock_version,
                'field_definition_id' => $system->id,
                'field_definition_revision_id' => $system->current_revision_id,
                'sort' => 10,
            ])
            ->assertSessionHasErrors('field_definition_id');

        $this->addMembership($admin, $fieldSet->fresh(), $draft, $dispoDef);
        $this->assertSame(1, $draft->fields()->count());
    }

    public function test_empty_activation_rejected_first_activation_sets_assignable(): void
    {
        $admin = User::factory()->role(Role::Admin)->create();
        $fieldSet = $this->createFreeFieldSet($admin, ['key' => 'material_set']);
        $draft = FieldSetVersion::query()->where('field_set_id', $fieldSet->id)->firstOrFail();

        $this->actingAs($admin)
            ->post(route('administration.dynamic-fields.field-sets.versions.activate', [$fieldSet, $draft]), [
                'lock_version' => $fieldSet->lock_version,
            ])
            ->assertSessionHasErrors('fields');

        $definition = $this->createCustomDefinition($admin, [
            'label' => 'Material Hinweis',
            'applies_to' => FieldAppliesTo::Both->value,
        ]);
        $this->addMembership($admin, $fieldSet->fresh(), $draft, $definition);
        $this->activateDraft($admin, $fieldSet->fresh(), $draft);

        $fieldSet->refresh();
        $this->assertTrue($fieldSet->is_assignable);
        $this->assertNotNull($fieldSet->active_version_id);
        $this->assertSame(FieldSetVersionStatus::Active, $draft->fresh()->status);
    }

    public function test_later_activation_respects_manual_deactivation_and_reactivate_requires_active(): void
    {
        $admin = User::factory()->role(Role::Admin)->create();
        $fieldSet = $this->createFreeFieldSet($admin, ['key' => 'social_set']);
        $definition = $this->createCustomDefinition($admin, [
            'label' => 'Social Text',
            'applies_to' => FieldAppliesTo::Both->value,
        ]);
        $draft = FieldSetVersion::query()->where('field_set_id', $fieldSet->id)->firstOrFail();
        $this->addMembership($admin, $fieldSet, $draft, $definition);
        $this->activateDraft($admin, $fieldSet->fresh(), $draft);

        $this->actingAs($admin)
            ->post(route('administration.dynamic-fields.field-sets.deactivate', $fieldSet->fresh()), [
                'lock_version' => $fieldSet->fresh()->lock_version,
            ])
            ->assertRedirect();
        $this->assertFalse($fieldSet->fresh()->is_assignable);

        $this->actingAs($admin)
            ->post(route('administration.dynamic-fields.field-sets.drafts.store', $fieldSet->fresh()), [
                'lock_version' => $fieldSet->fresh()->lock_version,
                'source_version_id' => $fieldSet->fresh()->active_version_id,
            ])
            ->assertRedirect();

        $newDraft = FieldSetVersion::query()
            ->where('field_set_id', $fieldSet->id)
            ->where('status', FieldSetVersionStatus::Draft)
            ->firstOrFail();
        $this->activateDraft($admin, $fieldSet->fresh(), $newDraft);
        $this->assertFalse($fieldSet->fresh()->is_assignable);

        $this->actingAs($admin)
            ->post(route('administration.dynamic-fields.field-sets.reactivate', $fieldSet->fresh()), [
                'lock_version' => $fieldSet->fresh()->lock_version,
            ])
            ->assertRedirect();
        $this->assertTrue($fieldSet->fresh()->is_assignable);

        $this->assertDatabaseHas('audit_events', [
            'action' => 'field_set.deactivated',
            'auditable_id' => $fieldSet->id,
        ]);
        $this->assertDatabaseHas('audit_events', [
            'action' => 'field_set.reactivated',
            'auditable_id' => $fieldSet->id,
        ]);
    }

    public function test_reactivate_without_active_version_is_rejected(): void
    {
        $admin = User::factory()->role(Role::Admin)->create();
        $fieldSet = $this->createFreeFieldSet($admin, ['key' => 'never_active']);

        $this->actingAs($admin)
            ->post(route('administration.dynamic-fields.field-sets.reactivate', $fieldSet), [
                'lock_version' => $fieldSet->lock_version,
            ])
            ->assertSessionHasErrors('field_set');
    }

    public function test_core_fieldsets_remain_protected_and_not_assignable(): void
    {
        $admin = User::factory()->role(Role::Admin)->create();
        $calc = FieldSet::query()->where('key', AdminFieldSetCatalog::CALCULATION_CORE)->firstOrFail();

        $this->actingAs($admin)
            ->put(route('administration.dynamic-fields.field-sets.update', $calc), [
                'lock_version' => $calc->lock_version,
                'name' => 'Hack',
                'applies_to' => FieldAppliesTo::Both->value,
            ])
            ->assertSessionHasErrors('field_set');

        $this->actingAs($admin)
            ->post(route('administration.dynamic-fields.field-sets.deactivate', $calc), [
                'lock_version' => $calc->lock_version,
            ])
            ->assertSessionHasErrors('field_set');

        $this->assertFalse($calc->fresh()->is_assignable);
        $this->assertTrue($calc->fresh()->is_system);
    }

    public function test_unknown_system_fieldset_is_not_administrable(): void
    {
        $rogue = new FieldSet;
        $rogue->key = 'system_rogue_core';
        $rogue->name = 'Rogue';
        $rogue->is_system = true;
        $rogue->applies_to = FieldAppliesTo::Both;
        $rogue->is_assignable = false;
        $rogue->lock_version = 1;
        $rogue->save();

        $this->assertFalse(AdminFieldSetCatalog::isAdministrable($rogue));

        $admin = User::factory()->role(Role::Admin)->create();
        $this->actingAs($admin)
            ->from(route('administration.dynamic-fields.field-sets.index'))
            ->get(route('administration.dynamic-fields.field-sets.show', $rogue))
            ->assertRedirect(route('administration.dynamic-fields.field-sets.index'))
            ->assertSessionHasErrors('field_set');
    }

    public function test_cross_fieldset_draft_copy_is_rejected(): void
    {
        $admin = User::factory()->role(Role::Admin)->create();
        $a = $this->createFreeFieldSet($admin, ['key' => 'copy_a']);
        $b = $this->createFreeFieldSet($admin, ['key' => 'copy_b']);
        $source = FieldSetVersion::query()->where('field_set_id', $a->id)->firstOrFail();

        $this->actingAs($admin)
            ->post(route('administration.dynamic-fields.field-sets.drafts.store', $b), [
                'lock_version' => $b->lock_version,
                'source_version_id' => $source->id,
            ])
            ->assertNotFound();
    }

    public function test_stale_lock_version_returns_conflict_and_permissions(): void
    {
        $admin = User::factory()->role(Role::Admin)->create();
        $sales = User::factory()->role(Role::Sales)->create();
        $management = User::factory()->role(Role::Management)->create();
        $fieldSet = $this->createFreeFieldSet($admin, ['key' => 'lock_set']);

        $this->actingAs($sales)
            ->post(route('administration.dynamic-fields.field-sets.store'), [
                'name' => 'Nope',
                'applies_to' => FieldAppliesTo::Both->value,
            ])
            ->assertForbidden();

        $this->actingAs($management)
            ->get(route('administration.dynamic-fields.field-sets.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('administration/dynamic-fields/field-sets/index')
                ->has('coreFieldSets', 2)
                ->has('freeFieldSets'));

        $this->actingAs($admin)
            ->put(route('administration.dynamic-fields.field-sets.update', $fieldSet), [
                'lock_version' => $fieldSet->lock_version + 5,
                'name' => 'Stale',
            ])
            ->assertStatus(409);
    }

    public function test_inactive_definition_rejected_and_core_runtime_unchanged(): void
    {
        $admin = User::factory()->role(Role::Admin)->create();
        $fieldSet = $this->createFreeFieldSet($admin, ['key' => 'inactive_guard']);
        $draft = FieldSetVersion::query()->where('field_set_id', $fieldSet->id)->firstOrFail();
        $definition = $this->createCustomDefinition($admin, [
            'label' => 'Temp Field',
            'applies_to' => FieldAppliesTo::Both->value,
        ]);

        $this->actingAs($admin)
            ->post(route('administration.dynamic-fields.definitions.deactivate', $definition), [
                'lock_version' => $definition->lock_version,
            ])
            ->assertRedirect();

        $this->actingAs($admin)
            ->post(route('administration.dynamic-fields.field-sets.versions.memberships.store', [$fieldSet, $draft]), [
                'lock_version' => $fieldSet->fresh()->lock_version,
                'field_definition_id' => $definition->id,
                'field_definition_revision_id' => $definition->current_revision_id,
                'sort' => 10,
            ])
            ->assertSessionHasErrors('field_definition_id');

        $beforeCount = DB::table('snapshot_field_definitions')->count();
        app(ConfigurationSnapshotMaterializer::class)
            ->materializeFromActiveSet(AdminFieldSetCatalog::CALCULATION_CORE);
        $this->assertGreaterThanOrEqual($beforeCount, DB::table('snapshot_field_definitions')->count());

        $this->assertSame(
            2,
            FieldSet::query()->where('is_system', true)->count(),
        );
        $this->assertTrue(
            FieldSet::query()->where('key', 'inactive_guard')->where('is_assignable', false)->exists(),
        );
    }

    /**
     * @param  array{name?: string, key?: string, applies_to?: string}  $overrides
     */
    private function createFreeFieldSet(User $admin, array $overrides = []): FieldSet
    {
        $this->actingAs($admin)
            ->post(route('administration.dynamic-fields.field-sets.store'), [
                'name' => $overrides['name'] ?? 'Freies Set',
                'key' => $overrides['key'] ?? null,
                'applies_to' => $overrides['applies_to'] ?? FieldAppliesTo::Both->value,
            ])
            ->assertRedirect();

        $query = FieldSet::query()->where('is_system', false);
        if (isset($overrides['key'])) {
            $query->where('key', $overrides['key']);
        }

        return $query->latest('id')->firstOrFail();
    }

    /**
     * @param  array{label?: string, applies_to?: string, scope?: string}  $overrides
     */
    private function createCustomDefinition(User $admin, array $overrides = []): FieldDefinition
    {
        return app(FieldDefinitionCustomWriter::class)->create([
            'label' => $overrides['label'] ?? 'Custom Text',
            'field_type' => FieldType::ShortText,
            'scope' => FieldScope::tryFrom($overrides['scope'] ?? FieldScope::Header->value) ?? FieldScope::Header,
            'applies_to' => FieldAppliesTo::tryFrom($overrides['applies_to'] ?? FieldAppliesTo::Both->value)
                ?? FieldAppliesTo::Both,
            'max_length' => 100,
        ], $admin);
    }

    private function addMembership(
        User $admin,
        FieldSet $fieldSet,
        FieldSetVersion $draft,
        FieldDefinition $definition,
    ): void {
        $this->actingAs($admin)
            ->post(route('administration.dynamic-fields.field-sets.versions.memberships.store', [$fieldSet, $draft]), [
                'lock_version' => $fieldSet->fresh()->lock_version,
                'field_definition_id' => $definition->id,
                'field_definition_revision_id' => $definition->current_revision_id,
                'sort' => 20,
            ])
            ->assertRedirect();
    }

    private function activateDraft(User $admin, FieldSet $fieldSet, FieldSetVersion $draft): void
    {
        $this->actingAs($admin)
            ->post(route('administration.dynamic-fields.field-sets.versions.activate', [$fieldSet, $draft]), [
                'lock_version' => $fieldSet->fresh()->lock_version,
            ])
            ->assertRedirect();
    }
}
