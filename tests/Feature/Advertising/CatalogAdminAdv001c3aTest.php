<?php

namespace Tests\Feature\Advertising;

use App\Enums\CalculationKind;
use App\Enums\Role;
use App\Models\AdvertisingCategory;
use App\Models\AdvertisingMedium;
use App\Models\User;
use App\Support\Advertising\AdvertisingMediumLiveBookability;
use App\Support\Advertising\CanonicalAdvertisingCategories;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\CreatesSavedCalculation;
use Tests\Concerns\CreatesSpotClassicCatalog;
use Tests\TestCase;

/**
 * ADV-001c3a: engine-unabhängige Werbemittelpflege + Wizard-Buchbarkeit.
 */
class CatalogAdminAdv001c3aTest extends TestCase
{
    use CreatesSavedCalculation;
    use CreatesSpotClassicCatalog;
    use RefreshDatabase;

    public function test_create_null_kind_in_every_active_category(): void
    {
        $admin = User::factory()->role(Role::Admin)->create();
        $categories = AdvertisingCategory::query()
            ->where('is_active', true)
            ->orderBy('id')
            ->get();

        $this->assertGreaterThanOrEqual(6, $categories->count());

        foreach ($categories as $index => $category) {
            $code = 'c3a_cat_'.$category->key.'_'.$index;
            $this->actingAs($admin)
                ->post(route('administration.catalog.media.store'), [
                    'code' => $code,
                    'name' => 'C3a '.$category->name,
                    'category_id' => $category->id,
                    'default_length_seconds' => 30,
                    'sort' => $index,
                ])
                ->assertRedirect();

            $medium = AdvertisingMedium::query()->where('code', $code)->firstOrFail();
            $this->assertNull($medium->getAttributes()['kind'] ?? null);
            $this->assertSame($category->id, $medium->category_id);
            $this->assertTrue($medium->is_active);
        }
    }

    public function test_kind_payload_on_create_and_update_is_rejected(): void
    {
        $admin = User::factory()->role(Role::Admin)->create();
        $spots = $this->spots();

        $this->actingAs($admin)
            ->post(route('administration.catalog.media.store'), [
                'code' => 'c3a_kind_create',
                'name' => 'Kind Create',
                'kind' => CalculationKind::SpotClassic->value,
                'category_id' => $spots->id,
            ])
            ->assertSessionHasErrors('kind');

        $medium = AdvertisingMedium::factory()->create([
            'category_id' => $spots->id,
            'code' => 'c3a_kind_update',
            'kind' => null,
        ]);

        $this->actingAs($admin)
            ->putJson(route('administration.catalog.media.update', $medium), [
                'name' => 'Kind Update',
                'kind' => CalculationKind::SpotClassic->value,
                'default_length_seconds' => 30,
                'lock_version' => $medium->lock_version,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('kind');

        $this->assertNull($medium->fresh()->getAttributes()['kind'] ?? null);
    }

    public function test_null_kind_update_category_change_deactivate_reactivate(): void
    {
        $admin = User::factory()->role(Role::Admin)->create();
        $spots = $this->spots();
        $online = AdvertisingCategory::query()
            ->where('key', CanonicalAdvertisingCategories::ONLINE_AUDIO)
            ->firstOrFail();

        $medium = AdvertisingMedium::factory()->create([
            'category_id' => $spots->id,
            'code' => 'c3a_null_lifecycle',
            'name' => 'Null Lifecycle',
            'kind' => null,
            'is_active' => true,
        ]);

        $this->actingAs($admin)
            ->putJson(route('administration.catalog.media.update', $medium), [
                'name' => 'Null Lifecycle Neu',
                'default_length_seconds' => 40,
                'is_discountable' => true,
                'is_ae_eligible' => true,
                'sort' => 3,
                'lock_version' => $medium->lock_version,
            ])
            ->assertOk();

        $medium->refresh();
        $this->assertSame('Null Lifecycle Neu', $medium->name);
        $this->assertNull($medium->getAttributes()['kind'] ?? null);

        $preview = $this->actingAs($admin)
            ->postJson(route('administration.catalog.media.category-change-preview', $medium), [
                'category_id' => $online->id,
            ])
            ->assertOk()
            ->json();

        $this->assertTrue($preview['can_proceed']);
        $this->assertSame([], $preview['blocking_reasons']);

        $this->actingAs($admin)
            ->postJson(route('administration.catalog.media.category-change', $medium), [
                'category_id' => $online->id,
                'lock_version' => $medium->fresh()->lock_version,
                'fingerprint' => $preview['fingerprint'],
            ])
            ->assertOk();

        $this->assertSame($online->id, $medium->fresh()->category_id);

        $deactivatePreview = $this->actingAs($admin)
            ->postJson(route('administration.catalog.media.deactivate-preview', $medium))
            ->assertOk()
            ->json();

        $this->actingAs($admin)
            ->postJson(route('administration.catalog.media.deactivate', $medium), [
                'lock_version' => $medium->fresh()->lock_version,
                'fingerprint' => $deactivatePreview['fingerprint'],
            ])
            ->assertOk();

        $this->assertFalse($medium->fresh()->is_active);

        $this->actingAs($admin)
            ->postJson(route('administration.catalog.media.reactivate', $medium), [
                'lock_version' => $medium->fresh()->lock_version,
            ])
            ->assertOk();

        $this->assertTrue($medium->fresh()->is_active);
        $this->assertNull($medium->fresh()->getAttributes()['kind'] ?? null);
    }

    public function test_legacy_spot_classic_kind_remains_and_blocks_incompatible_category(): void
    {
        $admin = User::factory()->role(Role::Admin)->create();
        $spots = $this->spots();
        $online = AdvertisingCategory::query()
            ->where('key', CanonicalAdvertisingCategories::ONLINE_AUDIO)
            ->firstOrFail();

        $medium = AdvertisingMedium::factory()->create([
            'category_id' => $spots->id,
            'code' => 'c3a_legacy_spot',
            'kind' => CalculationKind::SpotClassic,
        ]);

        $preview = $this->actingAs($admin)
            ->postJson(route('administration.catalog.media.category-change-preview', $medium), [
                'category_id' => $online->id,
            ])
            ->assertOk()
            ->json();

        $this->assertFalse($preview['can_proceed']);
        $this->assertSame('kind_incompatible', $preview['blocking_reasons'][0]['code'] ?? null);
        $this->assertSame(CalculationKind::SpotClassic->value, $medium->fresh()->kind?->value);
    }

    public function test_admin_show_and_create_expose_bookability_without_kind_field(): void
    {
        $admin = User::factory()->role(Role::Admin)->create();
        $nullMedium = AdvertisingMedium::factory()->create([
            'code' => 'c3a_show_null',
            'kind' => null,
        ]);
        $spot = AdvertisingMedium::factory()->create([
            'code' => 'c3a_show_spot',
            'kind' => CalculationKind::SpotClassic,
        ]);

        $this->actingAs($admin)
            ->get(route('administration.catalog.media.create'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('administration/katalog/media/create')
                ->has('formOptions.categories')
                ->missing('formOptions.kinds')
                ->where('catalogNote', fn (string $note): bool => str_contains($note, 'Buchbarkeit')));

        $this->actingAs($admin)
            ->get(route('administration.catalog.media.show', $nullMedium))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('administration/katalog/media/show')
                ->where('medium.is_bookable_for_new_positions', false)
                ->where('medium.unbookable_reason', fn ($reason): bool => is_string($reason) && $reason !== '')
                ->where('medium.kind', null));

        $this->actingAs($admin)
            ->get(route('administration.catalog.media.show', $spot))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('medium.is_bookable_for_new_positions', true)
                ->where('medium.unbookable_reason', null));
    }

    public function test_wizard_props_mark_null_kind_unbookable_and_keep_historical_media(): void
    {
        $catalog = $this->createSpotClassicCatalog();
        $user = User::factory()->role(Role::Admin)->create();
        $calculation = $this->createSavedCalculation($catalog, [
            ['inventory_id' => $catalog['hamburg']->id],
        ], $user);

        $historical = $catalog['medium'];
        $historical->forceFill(['is_active' => false])->save();

        $nullMedium = AdvertisingMedium::factory()->create([
            'code' => 'c3a_wizard_null',
            'kind' => null,
            'is_active' => true,
        ]);

        $this->actingAs($user)
            ->get(route('calculations.edit', $calculation))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('calculations/wizard')
                ->has('catalog.media')
                ->where('catalog.media', function ($media) use ($nullMedium, $historical): bool {
                    $rows = collect($media);
                    $nullRow = $rows->firstWhere('id', $nullMedium->id);
                    $histRow = $rows->firstWhere('id', $historical->id);

                    return is_array($nullRow)
                        && $nullRow['is_bookable_for_new_positions'] === false
                        && is_string($nullRow['unbookable_reason'])
                        && $nullRow['unbookable_reason'] !== ''
                        && is_array($histRow)
                        && $histRow['is_active'] === false
                        && array_key_exists('is_bookable_for_new_positions', $histRow);
                }));
    }

    public function test_non_admin_cannot_access_media_admin(): void
    {
        $sales = User::factory()->role(Role::Sales)->create();

        $this->actingAs($sales)
            ->get(route('administration.catalog.media.index'))
            ->assertForbidden();

        $this->actingAs($sales)
            ->post(route('administration.catalog.media.store'), [
                'code' => 'c3a_forbidden',
                'name' => 'Forbidden',
                'category_id' => $this->spots()->id,
            ])
            ->assertForbidden();
    }

    public function test_create_page_lists_all_active_categories(): void
    {
        $admin = User::factory()->role(Role::Admin)->create();
        $activeKeys = AdvertisingCategory::query()
            ->where('is_active', true)
            ->orderBy('id')
            ->pluck('key')
            ->all();

        $this->actingAs($admin)
            ->get(route('administration.catalog.media.create'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('formOptions.categories', function ($categories) use ($activeKeys): bool {
                    $keys = collect($categories)->pluck('key')->all();

                    return $keys === $activeKeys;
                }));
    }

    public function test_wizard_media_serialization_avoids_calculation_method_queries_per_medium(): void
    {
        $catalog = $this->createSpotClassicCatalog();
        $user = User::factory()->role(Role::Admin)->create();

        AdvertisingMedium::factory()->count(3)->sequence(
            ['code' => 'c3a_q_null_a'],
            ['code' => 'c3a_q_null_b'],
            ['code' => 'c3a_q_null_c'],
        )->create([
            'category_id' => $catalog['medium']->category_id,
            'kind' => null,
            'is_active' => true,
        ]);

        $bookability = app(AdvertisingMediumLiveBookability::class);
        $media = AdvertisingMedium::query()
            ->with([
                'category.defaultCalculationMethod',
                'category.calculationMethodAssignments.calculationMethod',
                'defaultCalculationMethod',
                'calculationMethodAssignments.calculationMethod',
            ])
            ->where('is_active', true)
            ->get();

        $this->assertGreaterThanOrEqual(4, $media->count());

        DB::flushQueryLog();
        DB::enableQueryLog();

        foreach ($media as $medium) {
            $bookability->payloadForMedium($medium);
        }

        $methodQueries = collect(DB::getQueryLog())
            ->filter(function (array $query): bool {
                $sql = strtolower((string) ($query['query'] ?? ''));

                return str_contains($sql, 'from "calculation_methods"')
                    || str_contains($sql, 'from `calculation_methods`');
            })
            ->values();

        $this->assertCount(
            0,
            $methodQueries,
            'Eager-geladene Medien dürfen keine CalculationMethod::query() pro Medium auslösen.',
        );
    }

    private function spots(): AdvertisingCategory
    {
        return AdvertisingCategory::query()
            ->where('key', CanonicalAdvertisingCategories::SPOTS)
            ->firstOrFail();
    }
}
