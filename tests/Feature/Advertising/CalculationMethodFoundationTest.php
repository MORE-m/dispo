<?php

namespace Tests\Feature\Advertising;

use App\Enums\CalculationKind;
use App\Enums\CalculationMethodMode;
use App\Models\AdvertisingCategory;
use App\Models\AdvertisingCategoryCalculationMethod;
use App\Models\AdvertisingMedium;
use App\Models\AdvertisingMediumCalculationMethod;
use App\Models\CalculationMethod;
use App\Support\Advertising\CanonicalAdvertisingCategories;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * ADV-001c1: Schema-Fundament calculation_methods / Zuordnungen / Mode / Defaults.
 * Keine Runtime-Anbindung, kein Zuordnungs-Backfill.
 * Migrations-Upgrade/Roundtrip: CalculationMethodFoundationMigrationTest.
 */
class CalculationMethodFoundationTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @var list<array{key: string, name: string, sort: int}>
     */
    private const EXPECTED_METHODS = [
        ['key' => 'average', 'name' => 'Durchschnitt', 'sort' => 10],
        ['key' => 'calendar', 'name' => 'Kalenderplaner', 'sort' => 20],
        ['key' => 'fixed_price', 'name' => 'Festpreis', 'sort' => 30],
        ['key' => 'tkp', 'name' => 'TKP', 'sort' => 40],
        ['key' => 'free_position', 'name' => 'Freie Preisposition', 'sort' => 50],
    ];

    public function test_migration_seeds_exactly_five_calculation_methods(): void
    {
        $keys = CalculationMethod::query()->orderBy('sort')->pluck('key')->all();
        $this->assertSame(array_column(self::EXPECTED_METHODS, 'key'), $keys);
        $this->assertCount(5, $keys);

        foreach (self::EXPECTED_METHODS as $expected) {
            $method = CalculationMethod::query()->where('key', $expected['key'])->firstOrFail();
            $this->assertSame($expected['name'], $method->name);
            $this->assertSame($expected['sort'], $method->sort);
            $this->assertTrue($method->is_active);
            $this->assertNull($method->help_text);
            $this->assertSame(1, $method->lock_version);
        }
    }

    public function test_migration_creates_no_category_or_medium_method_assignments(): void
    {
        $this->assertSame(0, AdvertisingCategoryCalculationMethod::query()->count());
        $this->assertSame(0, AdvertisingMediumCalculationMethod::query()->count());
    }

    public function test_existing_media_default_to_inherit_mode(): void
    {
        $medium = AdvertisingMedium::factory()->create(['code' => 'spot_classic_mode_check']);

        $this->assertSame(CalculationMethodMode::Inherit, $medium->calculation_method_mode);
        $this->assertSame(
            'inherit',
            (string) DB::table('advertising_media')->where('id', $medium->id)->value('calculation_method_mode'),
        );
    }

    public function test_invalid_calculation_method_mode_is_rejected_on_update(): void
    {
        $medium = AdvertisingMedium::factory()->create(['code' => 'spot_classic_bad_mode_update']);

        $this->expectException(QueryException::class);

        DB::table('advertising_media')->where('id', $medium->id)->update([
            'calculation_method_mode' => 'merge',
        ]);
    }

    public function test_invalid_calculation_method_mode_is_rejected_on_insert(): void
    {
        $categoryId = (int) AdvertisingCategory::query()
            ->where('key', CanonicalAdvertisingCategories::SPOTS)
            ->value('id');

        $this->expectException(QueryException::class);

        DB::table('advertising_media')->insert([
            'category_id' => $categoryId,
            'name' => 'Bad Mode Insert',
            'code' => 'spot_classic_bad_mode_insert',
            'kind' => CalculationKind::SpotClassic->value,
            'calculation_method_mode' => 'merge',
            'default_length_seconds' => 30,
            'is_discountable' => true,
            'is_ae_eligible' => true,
            'is_active' => true,
            'sort' => 0,
            'lock_version' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_default_calculation_method_fks_are_nullable(): void
    {
        $this->assertTrue(Schema::hasColumn('advertising_categories', 'default_calculation_method_id'));
        $this->assertTrue(Schema::hasColumn('advertising_media', 'default_calculation_method_id'));

        $category = AdvertisingCategory::query()
            ->where('key', CanonicalAdvertisingCategories::SPOTS)
            ->firstOrFail();
        $this->assertNull($category->default_calculation_method_id);

        $medium = AdvertisingMedium::factory()->create(['code' => 'spot_classic_default_null']);
        $this->assertNull($medium->default_calculation_method_id);
    }

    public function test_default_fk_rejects_unknown_method_id(): void
    {
        $category = AdvertisingCategory::query()
            ->where('key', CanonicalAdvertisingCategories::SPOTS)
            ->firstOrFail();

        $this->expectException(QueryException::class);

        DB::table('advertising_categories')->where('id', $category->id)->update([
            'default_calculation_method_id' => 9_999_999,
        ]);
    }

    public function test_referenced_calculation_method_cannot_be_deleted_while_category_default_points_to_it(): void
    {
        $method = CalculationMethod::query()->where('key', 'average')->firstOrFail();
        $category = AdvertisingCategory::query()
            ->where('key', CanonicalAdvertisingCategories::SPOTS)
            ->firstOrFail();

        DB::table('advertising_categories')->where('id', $category->id)->update([
            'default_calculation_method_id' => $method->id,
        ]);

        $this->expectException(QueryException::class);
        $method->delete();
    }

    public function test_referenced_calculation_method_cannot_be_deleted_while_medium_default_points_to_it(): void
    {
        $method = CalculationMethod::query()->where('key', 'calendar')->firstOrFail();
        $medium = AdvertisingMedium::factory()->create(['code' => 'spot_classic_medium_default_fk']);

        DB::table('advertising_media')->where('id', $medium->id)->update([
            'default_calculation_method_id' => $method->id,
        ]);

        $this->expectException(QueryException::class);
        $method->delete();
    }

    public function test_referenced_calculation_method_cannot_be_deleted_while_assignment_exists(): void
    {
        $method = CalculationMethod::query()->where('key', 'tkp')->firstOrFail();
        $category = AdvertisingCategory::query()
            ->where('key', CanonicalAdvertisingCategories::ONLINE_AUDIO)
            ->firstOrFail();

        AdvertisingCategoryCalculationMethod::factory()->create([
            'advertising_category_id' => $category->id,
            'calculation_method_id' => $method->id,
        ]);

        $this->expectException(QueryException::class);
        $method->delete();
    }

    public function test_category_cannot_be_deleted_while_method_assignment_exists(): void
    {
        $category = AdvertisingCategory::factory()->create(['key' => 'tmp_cat_for_restrict']);
        $method = CalculationMethod::query()->where('key', 'average')->firstOrFail();

        AdvertisingCategoryCalculationMethod::factory()->create([
            'advertising_category_id' => $category->id,
            'calculation_method_id' => $method->id,
        ]);

        $this->expectException(QueryException::class);
        $category->delete();
    }

    public function test_medium_cannot_be_deleted_while_method_assignment_exists(): void
    {
        $medium = AdvertisingMedium::factory()->create(['code' => 'spot_classic_assign_restrict']);
        $method = CalculationMethod::query()->where('key', 'average')->firstOrFail();

        AdvertisingMediumCalculationMethod::factory()->create([
            'advertising_medium_id' => $medium->id,
            'calculation_method_id' => $method->id,
        ]);

        $this->expectException(QueryException::class);
        $medium->delete();
    }

    public function test_category_method_assignment_unique_and_allows_different_methods(): void
    {
        $category = AdvertisingCategory::query()
            ->where('key', CanonicalAdvertisingCategories::SPOTS)
            ->firstOrFail();
        $average = CalculationMethod::query()->where('key', 'average')->firstOrFail();
        $calendar = CalculationMethod::query()->where('key', 'calendar')->firstOrFail();

        AdvertisingCategoryCalculationMethod::factory()->create([
            'advertising_category_id' => $category->id,
            'calculation_method_id' => $average->id,
            'engine_profile_key' => null,
        ]);
        AdvertisingCategoryCalculationMethod::factory()->create([
            'advertising_category_id' => $category->id,
            'calculation_method_id' => $calendar->id,
            'engine_profile_key' => null,
        ]);

        $this->assertSame(2, AdvertisingCategoryCalculationMethod::query()
            ->where('advertising_category_id', $category->id)
            ->count());

        $this->expectException(QueryException::class);
        AdvertisingCategoryCalculationMethod::factory()->create([
            'advertising_category_id' => $category->id,
            'calculation_method_id' => $average->id,
            'engine_profile_key' => 'spot_classic',
        ]);
    }

    public function test_medium_method_assignment_unique_independent_of_engine_profile_key(): void
    {
        $medium = AdvertisingMedium::factory()->create(['code' => 'spot_classic_unique_methods']);
        $average = CalculationMethod::query()->where('key', 'average')->firstOrFail();
        $fixed = CalculationMethod::query()->where('key', 'fixed_price')->firstOrFail();

        $row = AdvertisingMediumCalculationMethod::factory()->create([
            'advertising_medium_id' => $medium->id,
            'calculation_method_id' => $average->id,
            'engine_profile_key' => null,
        ]);
        $this->assertNull($row->engine_profile_key);

        AdvertisingMediumCalculationMethod::factory()->create([
            'advertising_medium_id' => $medium->id,
            'calculation_method_id' => $fixed->id,
            'engine_profile_key' => null,
        ]);

        $this->expectException(QueryException::class);
        AdvertisingMediumCalculationMethod::factory()->create([
            'advertising_medium_id' => $medium->id,
            'calculation_method_id' => $average->id,
            'engine_profile_key' => 'other_profile',
        ]);
    }

    public function test_engine_profile_key_is_not_mass_assignable_on_category_assignment(): void
    {
        $category = AdvertisingCategory::query()
            ->where('key', CanonicalAdvertisingCategories::EVENTS_PROMOTION)
            ->firstOrFail();
        $method = CalculationMethod::query()->where('key', 'free_position')->firstOrFail();

        $assignment = new AdvertisingCategoryCalculationMethod;
        $assignment->fill([
            'advertising_category_id' => $category->id,
            'calculation_method_id' => $method->id,
            'engine_profile_key' => 'should_be_ignored',
            'is_active' => true,
            'sort' => 0,
        ]);
        $assignment->lock_version = 1;
        $assignment->save();

        $this->assertNull($assignment->fresh()?->engine_profile_key);
    }

    public function test_engine_profile_key_is_not_mass_assignable_on_medium_assignment(): void
    {
        $medium = AdvertisingMedium::factory()->create(['code' => 'spot_classic_mass_assign_med']);
        $method = CalculationMethod::query()->where('key', 'average')->firstOrFail();

        $assignment = new AdvertisingMediumCalculationMethod;
        $assignment->fill([
            'advertising_medium_id' => $medium->id,
            'calculation_method_id' => $method->id,
            'engine_profile_key' => 'should_be_ignored',
            'is_active' => true,
            'sort' => 0,
        ]);
        $assignment->lock_version = 1;
        $assignment->save();

        $this->assertNull($assignment->fresh()?->engine_profile_key);
    }

    public function test_engine_profile_key_can_be_set_via_explicit_property_assignment(): void
    {
        $category = AdvertisingCategory::query()
            ->where('key', CanonicalAdvertisingCategories::SOCIAL_ONLINE)
            ->firstOrFail();
        $method = CalculationMethod::query()->where('key', 'fixed_price')->firstOrFail();

        $assignment = new AdvertisingCategoryCalculationMethod;
        $assignment->advertising_category_id = $category->id;
        $assignment->calculation_method_id = $method->id;
        $assignment->engine_profile_key = 'spot_classic';
        $assignment->is_active = true;
        $assignment->sort = 0;
        $assignment->lock_version = 1;
        $assignment->save();

        $this->assertSame('spot_classic', $assignment->fresh()?->engine_profile_key);

        $medium = AdvertisingMedium::factory()->create(['code' => 'spot_classic_explicit_profile']);
        $mediumAssignment = new AdvertisingMediumCalculationMethod;
        $mediumAssignment->advertising_medium_id = $medium->id;
        $mediumAssignment->calculation_method_id = $method->id;
        $mediumAssignment->engine_profile_key = 'spot_classic';
        $mediumAssignment->is_active = true;
        $mediumAssignment->sort = 0;
        $mediumAssignment->lock_version = 1;
        $mediumAssignment->save();

        $this->assertSame('spot_classic', $mediumAssignment->fresh()?->engine_profile_key);
    }

    public function test_kind_column_remains_not_null(): void
    {
        $this->assertTrue(Schema::hasColumn('advertising_media', 'kind'));

        $medium = AdvertisingMedium::factory()->create(['code' => 'spot_classic_kind_nn']);
        $this->assertNotNull($medium->getAttributes()['kind']);

        $this->expectException(QueryException::class);
        DB::table('advertising_media')->where('id', $medium->id)->update(['kind' => null]);
    }
}
