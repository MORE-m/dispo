<?php

namespace Tests\Feature\Advertising;

use App\Enums\Role;
use App\Models\AdvertisingCategory;
use App\Models\AdvertisingCategoryCalculationMethod;
use App\Models\AdvertisingMedium;
use App\Models\AdvertisingMediumCalculationMethod;
use App\Models\AuditEvent;
use App\Models\CalculationMethod;
use App\Models\User;
use App\Services\Advertising\Admin\CalculationMethodImpactPreviewService;
use App\Support\Advertising\CanonicalAdvertisingCategories;
use App\Support\Calculation\EngineProfileRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * ADV-001c3b1: Methodenstammdaten und sicherer Lifecycle.
 */
class CatalogAdminAdv001c3b1Test extends TestCase
{
    use RefreshDatabase;

    public function test_hub_lists_methods_tile_and_index_requires_admin(): void
    {
        $admin = User::factory()->role(Role::Admin)->create();
        $sales = User::factory()->role(Role::Sales)->create();

        $this->actingAs($admin)
            ->get(route('administration.catalog.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('administration/katalog/index')
                ->has('links', 3)
                ->where('links.2.title', 'Berechnungsmethoden'));

        $this->actingAs($admin)
            ->get(route('administration.catalog.methods.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('administration/katalog/methods/index')
                ->has('methods')
                ->where('boundaryNote', fn (string $note): bool => str_contains($note, 'nicht automatisch buchbar')));

        $this->actingAs($sales)
            ->get(route('administration.catalog.methods.index'))
            ->assertForbidden();
    }

    public function test_no_create_or_delete_routes_for_methods(): void
    {
        $names = collect(Route::getRoutes())->map->getName()->filter()->values();
        $this->assertFalse($names->contains('administration.catalog.methods.store'));
        $this->assertFalse($names->contains('administration.catalog.methods.destroy'));
        $this->assertFalse($names->contains('administration.catalog.methods.create'));
    }

    public function test_detail_shows_registry_pairs_and_assignment_counts(): void
    {
        $admin = User::factory()->role(Role::Admin)->create();
        $average = CalculationMethod::query()->where('key', 'average')->firstOrFail();
        $tkp = CalculationMethod::query()->where('key', 'tkp')->firstOrFail();

        $this->actingAs($admin)
            ->get(route('administration.catalog.methods.show', $average))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('administration/katalog/methods/show')
                ->where('method.key', 'average')
                ->where('method.registry_pairs.0.engine_profile_key', 'spot_classic')
                ->where('method.registry_pairs.0.pair_status', 'released')
                ->where('method.registry_pairs.0.current_released_version', 'v1')
                ->where('method.active_category_assignments_count', fn ($n) => (int) $n >= 1));

        $this->actingAs($admin)
            ->get(route('administration.catalog.methods.show', $tkp))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('method.key', 'tkp')
                ->where('method.registry_pairs', [])
                ->where('method.registry_summary', 'Keinem technischen Profil zugeordnet'));
    }

    public function test_registry_pairs_are_sorted_deterministically_for_multi_profile(): void
    {
        $pairs = EngineProfileRegistry::pairsForMethodKey('average');
        $this->assertSame(['spot_classic'], array_column($pairs, 'engine_profile_key'));
        $this->assertSame('released', $pairs[0]['pair_status']);
        $this->assertSame('v1', $pairs[0]['current_released_version']);

        $planned = EngineProfileRegistry::pairsForMethodKey('calendar');
        $this->assertSame('planned', $planned[0]['pair_status']);
        $this->assertNull($planned[0]['current_released_version']);

        $none = EngineProfileRegistry::pairsForMethodKey('tkp');
        $this->assertSame([], $none);
    }

    public function test_metadata_update_allows_name_help_sort_and_audits(): void
    {
        $admin = User::factory()->role(Role::Admin)->create();
        $method = CalculationMethod::query()->where('key', 'tkp')->firstOrFail();

        $this->actingAs($admin)
            ->putJson(route('administration.catalog.methods.update', $method), [
                'name' => 'TKP umbenannt',
                'help_text' => 'Hilfetext TKP',
                'sort' => 55,
                'lock_version' => $method->lock_version,
            ])
            ->assertOk()
            ->assertJsonPath('method.name', 'TKP umbenannt');

        $method->refresh();
        $this->assertSame('tkp', $method->key);
        $this->assertSame('TKP umbenannt', $method->name);
        $this->assertSame('Hilfetext TKP', $method->help_text);
        $this->assertSame(55, $method->sort);
        $this->assertTrue($method->is_active);
        $this->assertSame(2, $method->lock_version);

        $audit = AuditEvent::query()->where('action', 'calculation_method.updated')->latest('id')->first();
        $this->assertNotNull($audit);
        $this->assertSame('TKP umbenannt', $audit->new_values['name'] ?? null);
        $this->assertArrayNotHasKey('engine_profile_key', $audit->new_values ?? []);
    }

    public function test_metadata_rejects_prohibited_fields_and_key_change(): void
    {
        $admin = User::factory()->role(Role::Admin)->create();
        $method = CalculationMethod::query()->where('key', 'free_position')->firstOrFail();

        foreach (['is_active' => false, 'engine_profile_key' => 'spot_classic', 'algorithm_version' => 'v9', 'key' => 'hacked'] as $field => $value) {
            $this->actingAs($admin)
                ->putJson(route('administration.catalog.methods.update', $method), [
                    'name' => $method->name,
                    'sort' => $method->sort,
                    'lock_version' => $method->lock_version,
                    $field => $value,
                ])
                ->assertUnprocessable()
                ->assertJsonValidationErrors($field);
        }

        $method->refresh();
        $this->assertSame('free_position', $method->key);
        $this->assertSame(1, $method->lock_version);
    }

    public function test_metadata_lock_version_conflict_returns_409(): void
    {
        $admin = User::factory()->role(Role::Admin)->create();
        $method = CalculationMethod::query()->where('key', 'fixed_price')->firstOrFail();

        $this->actingAs($admin)
            ->putJson(route('administration.catalog.methods.update', $method), [
                'name' => $method->name,
                'sort' => $method->sort,
                'lock_version' => $method->lock_version + 5,
            ])
            ->assertStatus(409);
    }

    public function test_deactivate_blocked_by_active_category_assignment(): void
    {
        $admin = User::factory()->role(Role::Admin)->create();
        $average = CalculationMethod::query()->where('key', 'average')->firstOrFail();

        $preview = $this->actingAs($admin)
            ->postJson(route('administration.catalog.methods.deactivate-preview', $average))
            ->assertOk()
            ->json();

        $this->assertFalse($preview['can_proceed']);
        $this->assertNotEmpty($preview['active_category_assignments']);
        $this->assertSame(64, strlen($preview['fingerprint']));

        $this->actingAs($admin)
            ->postJson(route('administration.catalog.methods.deactivate', $average), [
                'lock_version' => $average->lock_version,
                'fingerprint' => $preview['fingerprint'],
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('method');

        $average->refresh();
        $this->assertTrue($average->is_active);
        $this->assertSame(0, AuditEvent::query()->where('action', 'calculation_method.deactivated')->count());
        $this->assertTrue(
            AdvertisingCategoryCalculationMethod::query()
                ->where('calculation_method_id', $average->id)
                ->where('is_active', true)
                ->exists(),
        );
    }

    public function test_deactivate_blocked_by_active_medium_assignment(): void
    {
        $admin = User::factory()->role(Role::Admin)->create();
        $method = CalculationMethod::query()->where('key', 'tkp')->firstOrFail();
        $spots = AdvertisingCategory::query()->where('key', CanonicalAdvertisingCategories::SPOTS)->firstOrFail();
        $medium = AdvertisingMedium::factory()->create([
            'category_id' => $spots->id,
            'code' => 'tkp_block_medium',
            'kind' => null,
            'is_active' => true,
        ]);
        AdvertisingMediumCalculationMethod::factory()->create([
            'advertising_medium_id' => $medium->id,
            'calculation_method_id' => $method->id,
            'is_active' => true,
            'engine_profile_key' => null,
        ]);

        $preview = app(CalculationMethodImpactPreviewService::class)->previewDeactivate($method);
        $this->assertFalse($preview['can_proceed']);
        $this->assertCount(1, $preview['active_medium_assignments']);

        $this->actingAs($admin)
            ->postJson(route('administration.catalog.methods.deactivate', $method), [
                'lock_version' => $method->lock_version,
                'fingerprint' => $preview['fingerprint'],
            ])
            ->assertUnprocessable();

        $method->refresh();
        $this->assertTrue($method->is_active);
    }

    public function test_inactive_assignments_do_not_block_and_deactivate_reactivates_cleanly(): void
    {
        $admin = User::factory()->role(Role::Admin)->create();
        $method = CalculationMethod::query()->where('key', 'free_position')->firstOrFail();
        $spots = AdvertisingCategory::query()->where('key', CanonicalAdvertisingCategories::SPOTS)->firstOrFail();
        $medium = AdvertisingMedium::factory()->create([
            'category_id' => $spots->id,
            'code' => 'free_inactive_asg',
            'kind' => null,
        ]);

        $catAsg = AdvertisingCategoryCalculationMethod::factory()->create([
            'advertising_category_id' => $spots->id,
            'calculation_method_id' => $method->id,
            'is_active' => false,
            'engine_profile_key' => null,
        ]);
        $medAsg = AdvertisingMediumCalculationMethod::factory()->create([
            'advertising_medium_id' => $medium->id,
            'calculation_method_id' => $method->id,
            'is_active' => false,
            'engine_profile_key' => null,
        ]);

        $defaultBefore = $spots->default_calculation_method_id;

        $preview = $this->actingAs($admin)
            ->postJson(route('administration.catalog.methods.deactivate-preview', $method))
            ->assertOk()
            ->json();
        $this->assertTrue($preview['can_proceed']);
        $this->assertSame([], $preview['active_category_assignments']);
        $this->assertSame([], $preview['active_medium_assignments']);

        $this->actingAs($admin)
            ->postJson(route('administration.catalog.methods.deactivate', $method), [
                'lock_version' => $method->lock_version,
                'fingerprint' => $preview['fingerprint'],
            ])
            ->assertOk();

        $method->refresh();
        $this->assertFalse($method->is_active);
        $catAsg->refresh();
        $medAsg->refresh();
        $this->assertFalse($catAsg->is_active);
        $this->assertFalse($medAsg->is_active);
        $spots->refresh();
        $this->assertSame($defaultBefore, $spots->default_calculation_method_id);
        $this->assertTrue(AuditEvent::query()->where('action', 'calculation_method.deactivated')->exists());

        $this->actingAs($admin)
            ->postJson(route('administration.catalog.methods.reactivate', $method), [
                'lock_version' => $method->lock_version,
            ])
            ->assertOk();

        $method->refresh();
        $this->assertTrue($method->is_active);
        $catAsg->refresh();
        $medAsg->refresh();
        $this->assertFalse($catAsg->is_active);
        $this->assertFalse($medAsg->is_active);
        $spots->refresh();
        $this->assertSame($defaultBefore, $spots->default_calculation_method_id);
        $this->assertTrue(AuditEvent::query()->where('action', 'calculation_method.reactivated')->exists());
    }

    public function test_fingerprint_drift_returns_409(): void
    {
        $admin = User::factory()->role(Role::Admin)->create();
        $method = CalculationMethod::query()->where('key', 'free_position')->firstOrFail();

        $preview = app(CalculationMethodImpactPreviewService::class)->previewDeactivate($method);

        $this->actingAs($admin)
            ->postJson(route('administration.catalog.methods.deactivate', $method), [
                'lock_version' => $method->lock_version,
                'fingerprint' => str_repeat('a', 64),
            ])
            ->assertStatus(409);

        $this->assertNotSame($preview['fingerprint'], str_repeat('a', 64));
        $method->refresh();
        $this->assertTrue($method->is_active);
    }

    public function test_multiple_dependencies_listed_deterministically(): void
    {
        $method = CalculationMethod::query()->where('key', 'calendar')->firstOrFail();
        $preview = app(CalculationMethodImpactPreviewService::class)->previewDeactivate($method);

        $ids = array_column($preview['active_category_assignments'], 'assignment_id');
        $sorted = $ids;
        sort($sorted);
        $this->assertSame($sorted, $ids);
    }

    public function test_spot_classic_average_registry_unchanged_after_metadata(): void
    {
        $admin = User::factory()->role(Role::Admin)->create();
        $average = CalculationMethod::query()->where('key', 'average')->firstOrFail();
        $profileBefore = AdvertisingCategoryCalculationMethod::query()
            ->where('calculation_method_id', $average->id)
            ->where('is_active', true)
            ->orderBy('id')
            ->value('engine_profile_key');
        $this->assertSame('spot_classic', $profileBefore);

        $this->actingAs($admin)
            ->putJson(route('administration.catalog.methods.update', $average), [
                'name' => 'Durchschnitt',
                'help_text' => $average->help_text,
                'sort' => $average->sort,
                'lock_version' => $average->lock_version,
            ])
            ->assertOk();

        $pairs = EngineProfileRegistry::pairsForMethodKey('average');
        $this->assertSame('released', $pairs[0]['pair_status']);
        $this->assertSame('v1', $pairs[0]['current_released_version']);
        $this->assertSame(
            'spot_classic',
            AdvertisingCategoryCalculationMethod::query()
                ->where('calculation_method_id', $average->id)
                ->where('is_active', true)
                ->orderBy('id')
                ->value('engine_profile_key'),
        );
        $this->assertSame(
            'spot_classic',
            DB::table('advertising_category_calculation_methods')
                ->where('calculation_method_id', $average->id)
                ->where('is_active', 1)
                ->value('engine_profile_key'),
        );
    }
}
