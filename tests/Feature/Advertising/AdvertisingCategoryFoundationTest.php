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

    /**
     * Literale Vertragserwartung – bewusst nicht nur aus dem Runtime-Katalog abgeleitet.
     *
     * @var list<array{key: string, name: string, sort: int}>
     */
    private const EXPECTED_CATEGORIES = [
        ['key' => 'spots', 'name' => 'Spots', 'sort' => 10],
        ['key' => 'special_advertising_formats', 'name' => 'SWF / Sonderwerbeformen', 'sort' => 20],
        ['key' => 'online_audio', 'name' => 'Online Audio', 'sort' => 30],
        ['key' => 'social_online', 'name' => 'Social Media / Online', 'sort' => 40],
        ['key' => 'events_promotion', 'name' => 'Events / Promotion', 'sort' => 50],
        ['key' => 'barter', 'name' => 'Gegengeschäft', 'sort' => 60],
    ];

    public function test_adv_001a_seeds_exactly_six_canonical_categories_with_stable_keys(): void
    {
        $expectedKeys = array_column(self::EXPECTED_CATEGORIES, 'key');

        $dbKeys = AdvertisingCategory::query()->orderBy('sort')->pluck('key')->all();
        $this->assertSame($expectedKeys, $dbKeys);
        $this->assertCount(6, $dbKeys);

        $runtimeKeys = CanonicalAdvertisingCategories::keys();
        $this->assertSame($expectedKeys, $runtimeKeys);

        foreach (self::EXPECTED_CATEGORIES as $expected) {
            $category = AdvertisingCategory::query()->where('key', $expected['key'])->firstOrFail();
            $this->assertSame($expected['name'], $category->name);
            $this->assertSame($expected['sort'], $category->sort);
            $this->assertTrue($category->is_active);
        }

        foreach (CanonicalAdvertisingCategories::definitions() as $definition) {
            $this->assertContains(
                $definition,
                self::EXPECTED_CATEGORIES,
                'Runtime-Katalog weicht vom literalen Initialdaten-Vertrag ab.',
            );
        }
    }

    public function test_adv_001a_category_key_is_unique(): void
    {
        $this->expectException(QueryException::class);

        AdvertisingCategory::factory()->create([
            'key' => 'spots',
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
            'spots',
            $medium->category()->firstOrFail()->key,
        );
    }

    public function test_adv_001a_category_and_medium_relations_work(): void
    {
        $spots = AdvertisingCategory::query()
            ->where('key', 'spots')
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
        $this->assertAdvertisingCategorySchemaComplete();
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
        $this->tearDownDependentCatalogMethodSchemaForAdv001aDown();
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

        $migration->up();

        $classic = DB::table('advertising_media')->where('id', $classicId)->first();

        $this->assertNotNull($classic);
        $this->assertSame('spot_classic', $classic->code);
        $this->assertSame($classicId, (int) $classic->id);
        $this->assertNotNull($classic->category_id);

        $spotsId = (int) DB::table('advertising_categories')
            ->where('key', 'spots')
            ->value('id');
        $this->assertSame($spotsId, (int) $classic->category_id);
        $this->assertAdvertisingCategorySchemaComplete();
    }

    public function test_adv_001a_upgrade_fails_closed_for_unknown_medium_codes(): void
    {
        /** @var object{up: callable, down: callable} $migration */
        $migration = require database_path('migrations/2026_09_07_100000_create_advertising_categories_tables.php');
        $this->tearDownDependentCatalogMethodSchemaForAdv001aDown();
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

        $caught = null;
        try {
            try {
                $migration->up();
                $this->fail('Erwartete RuntimeException für unbekannten Medium-Code.');
            } catch (\RuntimeException $exception) {
                $caught = $exception;
                $this->assertStringContainsString('ADV-001a Backfill abgebrochen', $exception->getMessage());
                $this->assertStringContainsString('unknown_legacy_medium', $exception->getMessage());
                $this->assertStringContainsString('Keine pauschale Default-Kategorie', $exception->getMessage());
            }
        } finally {
            DB::table('advertising_media')->where('code', 'unknown_legacy_medium')->delete();
            // Idempotentes up() vervollständigt auch einen partiellen Zwischenstand
            // (Kategorien + nullable category_id ohne FK/NOT NULL).
            $migration->up();
        }

        $this->assertInstanceOf(\RuntimeException::class, $caught);
        $this->assertAdvertisingCategorySchemaComplete();
        $this->assertTrue(
            AdvertisingMedium::query()->whereNull('category_id')->doesntExist(),
        );
        $this->assertSame(
            array_column(self::EXPECTED_CATEGORIES, 'key'),
            AdvertisingCategory::query()->orderBy('sort')->pluck('key')->all(),
        );
    }

    /**
     * ADV-001c2 legt referenzierende Zuordnungszeilen an. Unter RefreshDatabase
     * (SQLite-Transaktion) greift PRAGMA foreign_keys=OFF nicht – deshalb vor
     * ADV-001a-down abhängige Zeilen leeren, sonst scheitert DROP categories.
     */
    private function tearDownDependentCatalogMethodSchemaForAdv001aDown(): void
    {
        if (Schema::hasTable('advertising_categories')
            && Schema::hasColumn('advertising_categories', 'default_calculation_method_id')) {
            DB::table('advertising_categories')->update(['default_calculation_method_id' => null]);
        }

        if (Schema::hasTable('advertising_media')
            && Schema::hasColumn('advertising_media', 'default_calculation_method_id')) {
            DB::table('advertising_media')->update(['default_calculation_method_id' => null]);
        }

        if (Schema::hasTable('advertising_category_calculation_methods')) {
            DB::table('advertising_category_calculation_methods')->delete();
        }

        if (Schema::hasTable('advertising_medium_calculation_methods')) {
            DB::table('advertising_medium_calculation_methods')->delete();
        }
    }

    private function assertAdvertisingCategorySchemaComplete(): void
    {
        $this->assertTrue(Schema::hasTable('advertising_categories'));
        $this->assertTrue(Schema::hasColumn('advertising_media', 'category_id'));

        $column = collect(Schema::getColumns('advertising_media'))
            ->firstWhere('name', 'category_id');
        $this->assertNotNull($column);
        $this->assertFalse($column['nullable'], 'category_id muss NOT NULL sein.');
        $this->assertTrue(
            $this->advertisingMediaCategoryForeignKeyExists(),
            'Fremdschlüssel advertising_media.category_id fehlt.',
        );
    }

    private function advertisingMediaCategoryForeignKeyExists(): bool
    {
        if (Schema::getConnection()->getDriverName() === 'sqlite') {
            foreach (DB::select('PRAGMA foreign_key_list(advertising_media)') as $row) {
                if (($row->from ?? null) === 'category_id') {
                    return true;
                }
            }

            return false;
        }

        $database = Schema::getConnection()->getDatabaseName();

        return DB::table('information_schema.TABLE_CONSTRAINTS')
            ->where('CONSTRAINT_SCHEMA', $database)
            ->where('TABLE_NAME', 'advertising_media')
            ->where('CONSTRAINT_NAME', 'advertising_media_category_id_fk')
            ->where('CONSTRAINT_TYPE', 'FOREIGN KEY')
            ->exists();
    }
}
