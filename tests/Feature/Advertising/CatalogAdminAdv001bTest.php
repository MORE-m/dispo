<?php

namespace Tests\Feature\Advertising;

use App\Enums\CalculationKind;
use App\Enums\FieldAppliesTo;
use App\Enums\FieldScope;
use App\Enums\FieldType;
use App\Enums\Role;
use App\Models\AdvertisingCategory;
use App\Models\AdvertisingMedium;
use App\Models\AuditEvent;
use App\Models\ConfigurationSnapshot;
use App\Models\FieldSet;
use App\Models\FieldSetVersion;
use App\Models\User;
use App\Services\Advertising\Admin\CatalogImpactPreviewService;
use App\Services\DynamicField\Admin\FieldDefinitionCustomWriter;
use App\Services\DynamicField\Assignment\FieldSetAssignmentAdminWriter;
use App\Support\Advertising\CanonicalAdvertisingCategories;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * ADV-001b / PO-ADV001b-1..9 / AUTH-001 / AUD-001
 */
class CatalogAdminAdv001bTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_open_catalog_hub_and_non_admin_gets_403(): void
    {
        $admin = User::factory()->role(Role::Admin)->create();
        $sales = User::factory()->role(Role::Sales)->create();

        $this->actingAs($admin)
            ->get(route('administration.catalog.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('administration/katalog/index')
                ->has('links', 3)
                ->where('boundaryNote', fn (string $note): bool => str_contains($note, 'Dynamische Felder')));

        $this->actingAs($admin)
            ->get(route('administration.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('modules', fn ($modules): bool => collect($modules)->contains(
                    fn (array $m): bool => $m['key'] === 'catalog' && $m['available'] === true,
                )));

        $this->actingAs($sales)
            ->get(route('administration.catalog.index'))
            ->assertForbidden();
    }

    public function test_category_create_free_key_duplicate_and_immutable_key(): void
    {
        $admin = User::factory()->role(Role::Admin)->create();

        $this->actingAs($admin)
            ->post(route('administration.catalog.categories.store'), [
                'key' => 'custom_promo_pack',
                'name' => 'Custom Promo',
                'sort' => 200,
            ])
            ->assertRedirect();

        $category = AdvertisingCategory::query()->where('key', 'custom_promo_pack')->firstOrFail();
        $this->assertSame(1, $category->lock_version);

        $this->actingAs($admin)
            ->post(route('administration.catalog.categories.store'), [
                'key' => 'custom_promo_pack',
                'name' => 'Duplikat',
            ])
            ->assertSessionHasErrors('key');

        $this->actingAs($admin)
            ->putJson(route('administration.catalog.categories.update', $category), [
                'name' => 'Custom Promo Renamed',
                'sort' => 210,
                'key' => 'hacked_key',
                'lock_version' => $category->lock_version,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('key');

        $this->actingAs($admin)
            ->putJson(route('administration.catalog.categories.update', $category), [
                'name' => 'Custom Promo Renamed',
                'sort' => 210,
                'lock_version' => $category->lock_version,
            ])
            ->assertOk();

        $category->refresh();
        $this->assertSame('custom_promo_pack', $category->key);
        $this->assertSame('Custom Promo Renamed', $category->name);
        $this->assertSame(210, $category->sort);
        $this->assertSame(2, $category->lock_version);

        $this->assertTrue(AuditEvent::query()->where('action', 'advertising_category.created')->exists());
        $this->assertTrue(AuditEvent::query()->where('action', 'advertising_category.updated')->exists());
    }

    public function test_category_deactivate_blocked_with_active_media_allowed_with_inactive_only(): void
    {
        $admin = User::factory()->role(Role::Admin)->create();
        $category = AdvertisingCategory::factory()->create([
            'key' => 'temp_block_cat',
            'name' => 'Temp Block',
            'is_active' => true,
        ]);
        $spots = AdvertisingCategory::query()->where('key', CanonicalAdvertisingCategories::SPOTS)->firstOrFail();
        $medium = AdvertisingMedium::factory()->create([
            'category_id' => $spots->id,
            'code' => 'temp_block_medium',
            'is_active' => true,
        ]);
        DB::table('advertising_media')->where('id', $medium->id)->update([
            'category_id' => $category->id,
        ]);
        $medium->refresh();

        $preview = app(CatalogImpactPreviewService::class)->previewCategoryDeactivate($category);
        $this->assertFalse($preview['can_proceed']);
        $this->assertNotEmpty($preview['active_media']);

        $this->actingAs($admin)
            ->postJson(route('administration.catalog.categories.deactivate', $category), [
                'lock_version' => $category->lock_version,
                'fingerprint' => $preview['fingerprint'],
            ])
            ->assertUnprocessable();

        $medium->forceFill(['is_active' => false])->save();

        $preview2 = app(CatalogImpactPreviewService::class)->previewCategoryDeactivate($category->fresh());
        $this->assertTrue($preview2['can_proceed']);

        $this->actingAs($admin)
            ->postJson(route('administration.catalog.categories.deactivate', $category), [
                'lock_version' => $category->fresh()->lock_version,
                'fingerprint' => $preview2['fingerprint'],
            ])
            ->assertOk()
            ->assertJsonPath('is_active', false);

        $this->assertFalse($category->fresh()->is_active);
        $this->assertTrue(AuditEvent::query()->where('action', 'advertising_category.deactivated')->exists());

        $this->actingAs($admin)
            ->postJson(route('administration.catalog.categories.reactivate', $category), [
                'lock_version' => $category->fresh()->lock_version,
            ])
            ->assertOk()
            ->assertJsonPath('is_active', true);

        $this->assertTrue(AuditEvent::query()->where('action', 'advertising_category.reactivated')->exists());
    }

    public function test_medium_create_rejects_kind_payload_and_duplicate_code(): void
    {
        $admin = User::factory()->role(Role::Admin)->create();
        $spots = AdvertisingCategory::query()->where('key', CanonicalAdvertisingCategories::SPOTS)->firstOrFail();
        $online = AdvertisingCategory::query()->where('key', CanonicalAdvertisingCategories::ONLINE_AUDIO)->firstOrFail();

        $this->actingAs($admin)
            ->post(route('administration.catalog.media.store'), [
                'code' => 'spot_ui_one',
                'name' => 'Spot UI One',
                'kind' => CalculationKind::SpotClassic->value,
                'category_id' => $online->id,
                'default_length_seconds' => 20,
            ])
            ->assertSessionHasErrors(['kind']);

        $this->actingAs($admin)
            ->post(route('administration.catalog.media.store'), [
                'code' => 'spot_ui_one',
                'name' => 'Spot UI One',
                'category_id' => $spots->id,
                'default_length_seconds' => 20,
                'sort' => 5,
            ])
            ->assertRedirect();

        $created = AdvertisingMedium::query()->where('code', 'spot_ui_one')->firstOrFail();
        $this->assertNull($created->getAttributes()['kind'] ?? null);

        $this->actingAs($admin)
            ->post(route('administration.catalog.media.store'), [
                'code' => 'spot_ui_one',
                'name' => 'Duplikat',
                'category_id' => $spots->id,
            ])
            ->assertSessionHasErrors('code');

        $medium = AdvertisingMedium::query()->where('code', 'spot_ui_one')->firstOrFail();
        $this->assertSame(5, $medium->sort);
        $this->assertSame(1, $medium->lock_version);
    }

    public function test_medium_code_immutable_and_metadata_editable(): void
    {
        $admin = User::factory()->role(Role::Admin)->create();
        $spots = AdvertisingCategory::query()->where('key', CanonicalAdvertisingCategories::SPOTS)->firstOrFail();
        $medium = AdvertisingMedium::factory()->create([
            'category_id' => $spots->id,
            'code' => 'spot_meta_edit',
            'name' => 'Meta Edit',
        ]);

        $this->actingAs($admin)
            ->putJson(route('administration.catalog.media.update', $medium), [
                'name' => 'Meta Edit Neu',
                'code' => 'hacked',
                'default_length_seconds' => 45,
                'is_discountable' => false,
                'is_ae_eligible' => false,
                'sort' => 9,
                'lock_version' => $medium->lock_version,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('code');

        $this->actingAs($admin)
            ->putJson(route('administration.catalog.media.update', $medium), [
                'name' => 'Meta Edit Neu',
                'default_length_seconds' => 45,
                'is_discountable' => false,
                'is_ae_eligible' => false,
                'sort' => 9,
                'lock_version' => $medium->lock_version,
            ])
            ->assertOk();

        $medium->refresh();
        $this->assertSame('spot_meta_edit', $medium->code);
        $this->assertSame('Meta Edit Neu', $medium->name);
        $this->assertSame(45, $medium->default_length_seconds);
        $this->assertFalse($medium->is_discountable);
        $this->assertFalse($medium->is_ae_eligible);
        $this->assertSame(9, $medium->sort);
    }

    public function test_medium_category_change_preview_fingerprint_and_history_safe(): void
    {
        $admin = User::factory()->role(Role::Admin)->create();
        $spots = AdvertisingCategory::query()->where('key', CanonicalAdvertisingCategories::SPOTS)->firstOrFail();
        $online = AdvertisingCategory::query()->where('key', CanonicalAdvertisingCategories::ONLINE_AUDIO)->firstOrFail();
        $medium = AdvertisingMedium::factory()->create([
            'category_id' => $spots->id,
            'code' => 'spot_change_cat',
            'name' => 'Change Cat',
        ]);

        $core = FieldSet::query()->where('key', 'system_calculation_core')->firstOrFail();
        $this->assertNotNull($core->active_version_id);

        $snapshotId = DB::table('configuration_snapshots')->insertGetId([
            'field_set_id' => $core->id,
            'field_set_version_id' => $core->active_version_id,
            'source' => 'seed_active',
            'format_version' => ConfigurationSnapshot::FORMAT_VERSION_CONTEXTUAL_FREEZE,
            'schema_fingerprint' => str_repeat('a', 64),
            'context_advertising_medium_id' => $medium->id,
            'context_advertising_medium_code' => $medium->code,
            'context_advertising_medium_name' => $medium->name,
            'context_advertising_category_id' => $spots->id,
            'context_advertising_category_key' => $spots->key,
            'context_advertising_category_name' => $spots->name,
            'created_at' => now(),
        ]);

        $previewBlocked = app(CatalogImpactPreviewService::class)->previewMediumCategoryChange($medium, [
            'target_category_id' => $online->id,
        ]);
        $this->assertFalse($previewBlocked['can_proceed']);

        DB::table('advertising_media')->where('id', $medium->id)->update(['category_id' => $online->id]);
        $medium->refresh();

        $preview = app(CatalogImpactPreviewService::class)->previewMediumCategoryChange($medium, [
            'target_category_id' => $spots->id,
        ]);
        $this->assertTrue($preview['can_proceed']);

        $this->actingAs($admin)
            ->postJson(route('administration.catalog.media.category-change', $medium), [
                'category_id' => $spots->id,
                'lock_version' => $medium->lock_version,
                'fingerprint' => str_repeat('0', 64),
            ])
            ->assertStatus(409);

        $this->actingAs($admin)
            ->postJson(route('administration.catalog.media.category-change', $medium), [
                'category_id' => $spots->id,
                'lock_version' => $medium->lock_version,
                'fingerprint' => $preview['fingerprint'],
            ])
            ->assertOk();

        $medium->refresh();
        $this->assertSame($spots->id, $medium->category_id);

        $row = DB::table('configuration_snapshots')->where('id', $snapshotId)->first();
        $this->assertSame($spots->id, (int) $row->context_advertising_category_id);
        $this->assertSame($spots->key, $row->context_advertising_category_key);
        $this->assertSame($spots->name, $row->context_advertising_category_name);
        $this->assertTrue(AuditEvent::query()->where('action', 'advertising_medium.category_changed')->exists());
    }

    public function test_medium_deactivate_reactivate_and_assignment_rules(): void
    {
        $admin = User::factory()->role(Role::Admin)->create();
        $spots = AdvertisingCategory::query()->where('key', CanonicalAdvertisingCategories::SPOTS)->firstOrFail();
        $medium = AdvertisingMedium::factory()->create([
            'category_id' => $spots->id,
            'code' => 'spot_lifecycle',
            'is_active' => true,
        ]);

        $fieldSet = $this->createAssignableFreeFieldSet($admin, 'cat_asg_'.$this->suffix());
        $writer = app(FieldSetAssignmentAdminWriter::class);
        $assignment = $writer->create([
            'field_set_id' => $fieldSet->id,
            'target_layer' => 'advertising_medium',
            'advertising_medium_id' => $medium->id,
            'applies_to_process' => FieldAppliesTo::Calculation->value,
            'sort' => 1,
        ], $admin);

        // Aktives Assignment für PO-ADV001b-4-Warnung (ohne Konfliktmatrix der Activate-Pipeline).
        DB::table('field_set_assignments')->where('id', $assignment->id)->update(['is_active' => 1]);
        $assignment->refresh();
        $this->assertTrue($assignment->is_active);

        $preview = app(CatalogImpactPreviewService::class)->previewMediumDeactivate($medium);
        $this->assertNotNull($preview['reactivation_assignment_warning']);

        $this->actingAs($admin)
            ->postJson(route('administration.catalog.media.deactivate', $medium), [
                'lock_version' => $medium->lock_version,
                'fingerprint' => $preview['fingerprint'],
            ])
            ->assertOk();

        $this->assertFalse($medium->fresh()->is_active);
        $this->assertTrue($assignment->fresh()->is_active);

        $fieldSet2 = $this->createAssignableFreeFieldSet($admin, 'cat_asg2_'.$this->suffix());
        try {
            $writer->create([
                'field_set_id' => $fieldSet2->id,
                'target_layer' => 'advertising_medium',
                'advertising_medium_id' => $medium->id,
                'applies_to_process' => FieldAppliesTo::Calculation->value,
            ], $admin);
            $this->fail('Expected validation for inactive medium target');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('advertising_medium_id', $exception->errors());
        }

        $this->actingAs($admin)
            ->postJson(route('administration.catalog.media.reactivate', $medium), [
                'lock_version' => $medium->fresh()->lock_version,
            ])
            ->assertOk();

        $this->assertTrue($medium->fresh()->is_active);

        $medium->forceFill([
            'is_active' => false,
            'lock_version' => $medium->fresh()->lock_version + 1,
        ])->save();
        $spots->forceFill(['is_active' => false])->save();

        $this->actingAs($admin)
            ->postJson(route('administration.catalog.media.reactivate', $medium), [
                'lock_version' => $medium->fresh()->lock_version,
            ])
            ->assertUnprocessable();
    }

    public function test_stale_lock_version_returns_409(): void
    {
        $admin = User::factory()->role(Role::Admin)->create();
        $category = AdvertisingCategory::factory()->create([
            'key' => 'lock_cat_'.$this->suffix(),
            'name' => 'Lock Cat',
        ]);

        $this->actingAs($admin)
            ->putJson(route('administration.catalog.categories.update', $category), [
                'name' => 'Lock Cat 2',
                'sort' => 1,
                'lock_version' => $category->lock_version + 5,
            ])
            ->assertStatus(409);
    }

    public function test_category_rename_does_not_mutate_frozen_snapshot_context(): void
    {
        $spots = AdvertisingCategory::query()->where('key', CanonicalAdvertisingCategories::SPOTS)->firstOrFail();
        $frozenName = $spots->name;
        $core = FieldSet::query()->where('key', 'system_calculation_core')->firstOrFail();

        $snapshotId = DB::table('configuration_snapshots')->insertGetId([
            'field_set_id' => $core->id,
            'field_set_version_id' => $core->active_version_id,
            'source' => 'seed_active',
            'format_version' => ConfigurationSnapshot::FORMAT_VERSION_CONTEXTUAL_FREEZE,
            'schema_fingerprint' => str_repeat('b', 64),
            'context_advertising_medium_id' => null,
            'context_advertising_medium_code' => null,
            'context_advertising_medium_name' => null,
            'context_advertising_category_id' => $spots->id,
            'context_advertising_category_key' => $spots->key,
            'context_advertising_category_name' => $frozenName,
            'created_at' => now(),
        ]);

        $admin = User::factory()->role(Role::Admin)->create();
        $this->actingAs($admin)
            ->putJson(route('administration.catalog.categories.update', $spots), [
                'name' => 'Spots Renamed',
                'sort' => $spots->sort,
                'lock_version' => $spots->lock_version,
            ])
            ->assertOk();

        $row = DB::table('configuration_snapshots')->where('id', $snapshotId)->first();
        $this->assertSame($frozenName, $row->context_advertising_category_name);
        $this->assertSame('Spots Renamed', $spots->fresh()->name);
    }

    private function suffix(): string
    {
        return bin2hex(random_bytes(3));
    }

    private function createAssignableFreeFieldSet(User $admin, string $key): FieldSet
    {
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
            'scope' => FieldScope::Header,
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
}
