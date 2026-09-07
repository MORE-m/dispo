<?php

namespace Tests\Feature\Advertising;

use App\Models\AdvertisingCategory;
use App\Models\AdvertisingMedium;
use App\Support\Advertising\CanonicalAdvertisingCategories;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * ADV-001a: Oberkategorie-Datenbasis (ADV-001 Datenanteil; nicht vollständiges ADV-001).
 */
class AdvertisingCategoryFoundationTest extends TestCase
{
    use RefreshDatabase;

    public function test_adv_001a_seeds_exactly_six_canonical_categories_with_stable_keys(): void
    {
        $keys = AdvertisingCategory::query()->orderBy('sort')->pluck('key')->all();

        $this->assertSame(CanonicalAdvertisingCategories::keys(), $keys);
        $this->assertCount(6, $keys);

        foreach (CanonicalAdvertisingCategories::definitions() as $definition) {
            $category = AdvertisingCategory::query()->where('key', $definition['key'])->firstOrFail();
            $this->assertSame($definition['name'], $category->name);
            $this->assertSame($definition['sort'], $category->sort);
            $this->assertTrue($category->is_active);
        }
    }

    public function test_adv_001a_category_key_is_unique(): void
    {
        $this->expectException(QueryException::class);

        AdvertisingCategory::factory()->create([
            'key' => CanonicalAdvertisingCategories::SPOTS,
            'name' => 'Duplikat',
        ]);
    }

    public function test_adv_001a_every_medium_has_category_and_spot_classic_maps_to_spots(): void
    {
        $medium = AdvertisingMedium::factory()->create(['code' => 'spot_classic']);

        $this->assertNotNull($medium->category_id);
        $this->assertTrue(
            AdvertisingMedium::query()->whereNull('category_id')->doesntExist(),
        );

        $this->assertSame(
            CanonicalAdvertisingCategories::SPOTS,
            $medium->category()->firstOrFail()->key,
        );
    }

    public function test_adv_001a_category_and_medium_relations_work(): void
    {
        $spots = AdvertisingCategory::query()
            ->where('key', CanonicalAdvertisingCategories::SPOTS)
            ->firstOrFail();

        $medium = AdvertisingMedium::factory()->create([
            'code' => 'spot_classic_rel',
            'category_id' => $spots->id,
        ]);

        $this->assertTrue($medium->category->is($spots));
        $this->assertTrue(
            $spots->advertisingMedia()->whereKey($medium->id)->exists(),
        );
    }

    public function test_adv_001a_category_id_column_is_not_nullable(): void
    {
        $column = collect(Schema::getColumns('advertising_media'))
            ->firstWhere('name', 'category_id');

        $this->assertNotNull($column);
        $this->assertFalse($column['nullable']);
    }

    public function test_adv_001a_deleting_referenced_category_is_restricted(): void
    {
        $medium = AdvertisingMedium::factory()->create(['code' => 'spot_classic_fk']);
        $category = $medium->category()->firstOrFail();

        $this->expectException(QueryException::class);
        $category->delete();
    }

    public function test_adv_001a_deactivating_category_does_not_mutate_media(): void
    {
        $medium = AdvertisingMedium::factory()->create(['code' => 'spot_classic_deact']);
        $before = [
            'id' => $medium->id,
            'code' => $medium->code,
            'category_id' => $medium->category_id,
            'name' => $medium->name,
        ];

        $category = $medium->category()->firstOrFail();
        $category->is_active = false;
        $category->save();

        $medium->refresh();
        $this->assertSame($before['id'], $medium->id);
        $this->assertSame($before['code'], $medium->code);
        $this->assertSame($before['category_id'], $medium->category_id);
        $this->assertSame($before['name'], $medium->name);
        $this->assertFalse($category->fresh()->is_active);
    }

    public function test_adv_001a_upgrade_backfill_preserves_medium_ids_and_codes(): void
    {
        /** @var object{up: callable, down: callable} $migration */
        $migration = require database_path('migrations/2026_09_07_100000_create_advertising_categories_tables.php');
        $migration->down();

        $this->assertFalse(Schema::hasTable('advertising_categories'));
        $this->assertFalse(Schema::hasColumn('advertising_media', 'category_id'));

        $now = now();
        $classicId = DB::table('advertising_media')->insertGetId([
            'name' => 'Spot Classic',
            'code' => 'spot_classic',
            'kind' => 'spot_classic',
            'default_length_seconds' => 30,
            'is_discountable' => true,
            'is_ae_eligible' => true,
            'is_active' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $altId = DB::table('advertising_media')->insertGetId([
            'name' => 'Spot Classic Alt',
            'code' => 'spot_classic_alt',
            'kind' => 'spot_classic',
            'default_length_seconds' => 30,
            'is_discountable' => true,
            'is_ae_eligible' => true,
            'is_active' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $migration->up();

        $classic = DB::table('advertising_media')->where('id', $classicId)->first();
        $alt = DB::table('advertising_media')->where('id', $altId)->first();

        $this->assertNotNull($classic);
        $this->assertNotNull($alt);
        $this->assertSame('spot_classic', $classic->code);
        $this->assertSame('spot_classic_alt', $alt->code);
        $this->assertSame($classicId, (int) $classic->id);
        $this->assertSame($altId, (int) $alt->id);
        $this->assertNotNull($classic->category_id);
        $this->assertNotNull($alt->category_id);

        $spotsId = (int) DB::table('advertising_categories')
            ->where('key', CanonicalAdvertisingCategories::SPOTS)
            ->value('id');
        $this->assertSame($spotsId, (int) $classic->category_id);
        $this->assertSame($spotsId, (int) $alt->category_id);
    }

    public function test_adv_001a_upgrade_fails_closed_for_unknown_medium_codes(): void
    {
        /** @var object{up: callable, down: callable} $migration */
        $migration = require database_path('migrations/2026_09_07_100000_create_advertising_categories_tables.php');
        $migration->down();

        $now = now();
        DB::table('advertising_media')->insert([
            'name' => 'Unbekanntes Medium',
            'code' => 'unknown_legacy_medium',
            'kind' => 'spot_classic',
            'default_length_seconds' => 30,
            'is_discountable' => true,
            'is_ae_eligible' => true,
            'is_active' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        try {
            $migration->up();
            $this->fail('Erwartete RuntimeException für unbekannten Medium-Code.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('ADV-001a Backfill abgebrochen', $exception->getMessage());
            $this->assertStringContainsString('unknown_legacy_medium', $exception->getMessage());
            $this->assertStringContainsString('Keine pauschale Default-Kategorie', $exception->getMessage());
        }

        // Cleanup für RefreshDatabase-Nachbarn: Migration erneut erfolgreich fahren.
        DB::table('advertising_media')->where('code', 'unknown_legacy_medium')->delete();
        if (! Schema::hasTable('advertising_categories')) {
            $migration->up();
        } elseif (! Schema::hasColumn('advertising_media', 'category_id')) {
            $migration->up();
        }
    }
}
