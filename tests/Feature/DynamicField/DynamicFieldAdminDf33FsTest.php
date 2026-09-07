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
use App\Services\DynamicField\Admin\FieldSetKeySlugger;
use App\Services\DynamicField\ConfigurationSnapshotMaterializer;
use Illuminate\Database\QueryException;
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

        $this->assertSame(
            [(int) $calc->id, (int) $dispo->id],
            FieldSet::query()->orderBy('id')->pluck('id')->all(),
        );
        $this->assertNotNull($calc->active_version_id);
        $this->assertNotNull($dispo->active_version_id);
        $this->assertGreaterThan(0, FieldSetVersion::query()->where('field_set_id', $calc->id)->count());
        $this->assertGreaterThan(0, FieldSetVersionField::query()->count());

        $this->assertTrue($calc->is_system);
        $this->assertSame(FieldAppliesTo::Calculation, $calc->applies_to);
        $this->assertFalse($calc->is_assignable);
        $this->assertTrue($dispo->is_system);
        $this->assertSame(FieldAppliesTo::DispoOrder, $dispo->applies_to);
        $this->assertFalse($dispo->is_assignable);
        $this->assertFieldSetMetadataColumnsAreNotNull();
        $this->assertDatabaseHas('field_sets', [
            'key' => AdminFieldSetCatalog::CALCULATION_CORE,
            'is_system' => 1,
            'applies_to' => 'calculation',
            'is_assignable' => 0,
        ]);
    }

    public function test_migration_fails_closed_on_unknown_fieldset(): void
    {
        $migration = $this->loadFieldSetContainerMigration();
        $unknownId = null;

        try {
            $unknownId = DB::table('field_sets')->insertGetId([
                'key' => 'legacy_unknown_set',
                'name' => 'Unbekannt',
                'is_system' => false,
                'applies_to' => 'both',
                'is_assignable' => false,
                'active_version_id' => null,
                'lock_version' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $method = new \ReflectionMethod($migration, 'backfillCoreMetadataFailClosed');
            $method->setAccessible(true);

            try {
                $method->invoke($migration);
                $this->fail('Expected RuntimeException for unknown fieldset.');
            } catch (\Throwable $exception) {
                $this->assertInstanceOf(\RuntimeException::class, $exception);
                $this->assertStringContainsString((string) $unknownId, $exception->getMessage());
                $this->assertStringContainsString('legacy_unknown_set', $exception->getMessage());
            }
        } finally {
            if ($unknownId !== null) {
                DB::table('field_sets')->where('id', $unknownId)->delete();
            }
        }

        $this->assertDatabaseMissing('field_sets', ['key' => 'legacy_unknown_set']);
        $this->assertFieldSetMetadataColumnsAreNotNull();
        $this->assertSame(
            2,
            FieldSet::query()->whereIn('key', [
                AdminFieldSetCatalog::CALCULATION_CORE,
                AdminFieldSetCatalog::DISPO_ORDER_CORE,
            ])->count(),
        );
        $this->assertTrue(
            FieldSet::query()->where('key', AdminFieldSetCatalog::CALCULATION_CORE)->where('is_system', true)->exists(),
        );
    }

    public function test_container_metadata_columns_reject_null_on_database_level(): void
    {
        $this->assertFieldSetMetadataColumnsAreNotNull();

        $base = [
            'key' => 'null_guard_'.bin2hex(random_bytes(4)),
            'name' => 'Null Guard',
            'is_system' => false,
            'applies_to' => 'both',
            'is_assignable' => false,
            'active_version_id' => null,
            'lock_version' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ];

        foreach (['is_system', 'applies_to', 'is_assignable'] as $column) {
            $payload = $base;
            $payload['key'] = 'null_guard_'.$column.'_'.bin2hex(random_bytes(3));
            $payload[$column] = null;

            try {
                DB::table('field_sets')->insert($payload);
                $this->fail("Expected database rejection for NULL {$column}.");
            } catch (\Throwable $exception) {
                $this->assertTrue(
                    $exception instanceof QueryException
                    || $exception instanceof \PDOException,
                    $exception::class.': '.$exception->getMessage(),
                );
            }
        }

        $id = DB::table('field_sets')->insertGetId($base);
        foreach (['is_system', 'applies_to', 'is_assignable'] as $column) {
            try {
                DB::table('field_sets')->where('id', $id)->update([$column => null]);
                $this->fail("Expected database rejection for NULL update of {$column}.");
            } catch (\Throwable $exception) {
                $this->assertTrue(
                    $exception instanceof QueryException
                    || $exception instanceof \PDOException,
                    $exception::class.': '.$exception->getMessage(),
                );
            }
        }

        DB::table('field_sets')->where('id', $id)->delete();
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
            ->assertSessionHasErrors('key');

        $fieldSet->refresh();
        $this->assertSame('reporting_set', $fieldSet->key);
        $this->assertSame('Freies Set', $fieldSet->name);
    }

    public function test_generated_and_explicit_keys_never_exceed_64_characters(): void
    {
        $admin = User::factory()->role(Role::Admin)->create();
        $slugger = app(FieldSetKeySlugger::class);

        $exact64 = str_repeat('a', 64);
        $this->assertSame(64, strlen($exact64));
        $generatedExact = $slugger->uniqueSlugFromName($exact64);
        $this->assertSame($exact64, $generatedExact);
        $this->assertLessThanOrEqual(64, strlen($generatedExact));

        $this->actingAs($admin)
            ->post(route('administration.dynamic-fields.field-sets.store'), [
                'name' => $exact64,
                'applies_to' => FieldAppliesTo::Both->value,
            ])
            ->assertRedirect();
        $this->assertTrue(FieldSet::query()->where('key', $exact64)->exists());
        $this->assertSame(64, strlen((string) FieldSet::query()->where('key', $exact64)->value('key')));

        $tooLongName = str_repeat('b', 80);
        $truncated = $slugger->uniqueSlugFromName($tooLongName);
        $this->assertSame(64, strlen($truncated));
        $this->assertSame(str_repeat('b', 64), $truncated);

        $this->actingAs($admin)
            ->post(route('administration.dynamic-fields.field-sets.store'), [
                'name' => $tooLongName,
                'applies_to' => FieldAppliesTo::Calculation->value,
            ])
            ->assertRedirect();
        $longSet = FieldSet::query()->where('key', $truncated)->firstOrFail();
        $this->assertSame(64, strlen($longSet->key));

        $collisionBase = str_repeat('c', 64);
        FieldSet::query()->where('key', $collisionBase)->delete();
        DB::table('field_sets')->insert([
            'key' => $collisionBase,
            'name' => 'Existing Long',
            'is_system' => false,
            'applies_to' => 'both',
            'is_assignable' => false,
            'active_version_id' => null,
            'lock_version' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $collided = $slugger->uniqueSlugFromName($collisionBase);
        $this->assertNotSame($collisionBase, $collided);
        $this->assertLessThanOrEqual(64, strlen($collided));
        $this->assertStringEndsWith('_2', $collided);
        $this->assertSame(64, strlen($collided));

        $this->actingAs($admin)
            ->post(route('administration.dynamic-fields.field-sets.store'), [
                'name' => 'Explicit Too Long',
                'key' => str_repeat('d', 65),
                'applies_to' => FieldAppliesTo::Both->value,
            ])
            ->assertSessionHasErrors('key');

        $this->assertSame(
            0,
            FieldSet::query()->get()->filter(fn (FieldSet $set): bool => strlen($set->key) > 64)->count(),
        );
    }

    public function test_store_and_update_reject_protected_metadata_fields(): void
    {
        $admin = User::factory()->role(Role::Admin)->create();

        $this->actingAs($admin)
            ->post(route('administration.dynamic-fields.field-sets.store'), [
                'name' => 'Protected Create',
                'key' => 'protected_create',
                'applies_to' => FieldAppliesTo::Both->value,
                'is_system' => true,
                'is_assignable' => true,
                'active_version_id' => 999,
                'lock_version' => 9,
            ])
            ->assertSessionHasErrors([
                'is_system',
                'is_assignable',
                'active_version_id',
                'lock_version',
            ]);

        $this->assertDatabaseMissing('field_sets', ['key' => 'protected_create']);

        $fieldSet = $this->createFreeFieldSet($admin, [
            'name' => 'Protected Update',
            'key' => 'protected_update',
        ]);
        $before = $fieldSet->only([
            'key',
            'name',
            'is_system',
            'applies_to',
            'is_assignable',
            'active_version_id',
            'lock_version',
        ]);

        $this->actingAs($admin)
            ->put(route('administration.dynamic-fields.field-sets.update', $fieldSet), [
                'lock_version' => $fieldSet->lock_version,
                'name' => 'Protected Update Changed',
                'key' => 'hacked',
                'is_system' => true,
                'is_assignable' => true,
                'active_version_id' => 123,
            ])
            ->assertSessionHasErrors([
                'key',
                'is_system',
                'is_assignable',
                'active_version_id',
            ]);

        $fieldSet->refresh();
        $this->assertSame($before['key'], $fieldSet->key);
        $this->assertSame($before['name'], $fieldSet->name);
        $this->assertSame((bool) $before['is_system'], $fieldSet->is_system);
        $this->assertSame(
            $before['applies_to'] instanceof FieldAppliesTo
                ? $before['applies_to']->value
                : $before['applies_to'],
            $fieldSet->applies_to->value,
        );
        $this->assertSame((bool) $before['is_assignable'], $fieldSet->is_assignable);
        $this->assertSame($before['active_version_id'], $fieldSet->active_version_id);
        $this->assertSame((int) $before['lock_version'], $fieldSet->lock_version);
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

    /**
     * @return object{up: callable, down: callable}
     */
    private function loadFieldSetContainerMigration(): object
    {
        $path = database_path('migrations/2026_09_07_200000_add_fieldset_container_metadata.php');
        $code = file_get_contents($path);
        $this->assertNotFalse($code);
        $code = preg_replace('/^<\?php\s*/', '', $code, 1);
        /** @var object{up: callable, down: callable} $migration */
        $migration = eval($code);

        return $migration;
    }

    private function assertFieldSetMetadataColumnsAreNotNull(): void
    {
        $driver = Schema::getConnection()->getDriverName();

        if ($driver === 'sqlite') {
            $notNull = [];
            foreach (DB::select('PRAGMA table_info(field_sets)') as $row) {
                $notNull[$row->name] = (int) $row->notnull === 1;
            }
            $this->assertTrue($notNull['is_system'] ?? false);
            $this->assertTrue($notNull['applies_to'] ?? false);
            $this->assertTrue($notNull['is_assignable'] ?? false);

            $fkPresent = false;
            foreach (DB::select('PRAGMA foreign_key_list(field_sets)') as $row) {
                if (($row->from ?? null) === 'active_version_id') {
                    $fkPresent = true;
                }
            }
            $this->assertTrue($fkPresent);

            return;
        }

        $database = Schema::getConnection()->getDatabaseName();
        foreach (['is_system', 'applies_to', 'is_assignable'] as $column) {
            $row = DB::table('information_schema.COLUMNS')
                ->where('TABLE_SCHEMA', $database)
                ->where('TABLE_NAME', 'field_sets')
                ->where('COLUMN_NAME', $column)
                ->first();
            $this->assertNotNull($row);
            $this->assertSame('NO', $row->IS_NULLABLE);
        }
    }
}
