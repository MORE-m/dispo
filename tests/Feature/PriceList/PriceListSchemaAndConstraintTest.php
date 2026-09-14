<?php

namespace Tests\Feature\PriceList;

use App\Enums\PriceListStatus;
use App\Models\Inventory;
use App\Models\Organization;
use App\Models\PriceList;
use App\Services\Calculation\Decimal;
use App\Support\PriceList\PriceListItemContract;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Tests\TestCase;

class PriceListSchemaAndConstraintTest extends TestCase
{
    use DatabaseMigrations;

    public function test_migration_backfills_year_from_valid_from_without_rewriting_versions(): void
    {
        $organizationId = Organization::factory()->create()->id;
        $inventoryId = Inventory::factory()->create([
            'organization_id' => $organizationId,
            'code' => 'MIG',
        ])->id;

        $migration = $this->migration();
        $migration->down();

        $this->assertFalse(Schema::hasColumn('price_lists', 'year'));
        $this->assertFalse(Schema::hasColumn('price_lists', 'lock_version'));
        $this->assertFalse(Schema::hasColumn('price_lists', 'revision_number'));

        $id = DB::table('price_lists')->insertGetId([
            'inventory_id' => $inventoryId,
            'name' => 'Preisliste',
            'version' => 'e2e-MIG',
            'status' => PriceListStatus::Active->value,
            'valid_from' => '2026-09-09',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $itemId = DB::table('price_list_items')->insertGetId([
            'price_list_id' => $id,
            'hour' => 8,
            'day_group' => 'mo_fr',
            'second_price' => '1.2500',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $migration->up();

        $row = DB::table('price_lists')->where('id', $id)->first();
        $this->assertNotNull($row);
        $this->assertSame($id, (int) $row->id);
        $this->assertSame('e2e-MIG', $row->version);
        $this->assertSame(2026, (int) $row->year);
        $this->assertSame(1, (int) $row->revision_number);
        $this->assertSame(1, (int) $row->lock_version);
        $this->assertSame('2026-09-09', substr((string) $row->valid_from, 0, 10));

        $item = DB::table('price_list_items')->where('id', $itemId)->first();
        $this->assertNotNull($item);
        $this->assertSame('1.2500', Decimal::roundPrice((string) $item->second_price));
        $this->assertSame(8, (int) $item->hour);
    }

    public function test_up_refuses_null_valid_from_without_schema_or_data_change(): void
    {
        $inventoryId = $this->legacyInventoryId();
        $migration = $this->migration();
        $migration->down();
        $before = $this->schemaSnapshot();

        $id = DB::table('price_lists')->insertGetId([
            'inventory_id' => $inventoryId,
            'name' => 'Ohne Datum',
            'version' => 'legacy-null',
            'status' => PriceListStatus::Draft->value,
            'valid_from' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        try {
            $this->expectUpRejected($migration, 'valid_from');
            $this->assertSame($before, $this->schemaSnapshot());
            $this->assertFalse(Schema::hasColumn('price_lists', 'year'));
            $this->assertFalse(Schema::hasColumn('price_lists', 'lock_version'));
            $this->assertNull(DB::table('price_lists')->where('id', $id)->value('valid_from'));
            $this->assertSame('legacy-null', DB::table('price_lists')->where('id', $id)->value('version'));
        } finally {
            DB::table('price_list_items')->where('price_list_id', $id)->delete();
            DB::table('price_lists')->where('id', $id)->delete();
            $this->ensureMigrated($migration);
        }
    }

    public function test_up_refuses_active_year_collision_without_schema_or_data_change(): void
    {
        $inventoryId = $this->legacyInventoryId();
        $migration = $this->migration();
        $migration->down();
        $before = $this->schemaSnapshot();

        $firstId = DB::table('price_lists')->insertGetId([
            'inventory_id' => $inventoryId,
            'name' => 'Aktiv A',
            'version' => 'legacy-a',
            'status' => PriceListStatus::Active->value,
            'valid_from' => '2026-01-01',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $secondId = DB::table('price_lists')->insertGetId([
            'inventory_id' => $inventoryId,
            'name' => 'Aktiv B',
            'version' => 'legacy-b',
            'status' => PriceListStatus::Active->value,
            'valid_from' => '2026-06-01',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        try {
            $this->expectUpRejected($migration, 'mehrere aktive');
            $this->assertSame($before, $this->schemaSnapshot());
            $this->assertFalse(Schema::hasColumn('price_lists', 'year'));
            $this->assertSame(2, DB::table('price_lists')->whereIn('id', [$firstId, $secondId])->count());
            $this->assertSame(
                PriceListStatus::Active->value,
                DB::table('price_lists')->where('id', $firstId)->value('status'),
            );
            $this->assertSame(
                PriceListStatus::Active->value,
                DB::table('price_lists')->where('id', $secondId)->value('status'),
            );
        } finally {
            DB::table('price_lists')->whereIn('id', [$firstId, $secondId])->delete();
            $this->ensureMigrated($migration);
        }
    }

    public function test_down_refuses_duplicate_version_across_years_before_dropping_guards(): void
    {
        $inventory = Inventory::factory()->create();
        $first = PriceList::factory()->create([
            'inventory_id' => $inventory->id,
            'year' => 2025,
            'version' => 'shared',
            'status' => PriceListStatus::Archived,
        ]);
        $second = PriceList::factory()->create([
            'inventory_id' => $inventory->id,
            'year' => 2026,
            'version' => 'shared',
            'status' => PriceListStatus::Draft,
        ]);
        $before = $this->schemaSnapshot();
        $migration = $this->migration();

        try {
            $migration->down();
            $this->fail('down() hätte UNIQUE(inventory_id, version) verweigern müssen.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('nicht wiederherstellbar', $exception->getMessage());
        }

        $this->assertSame($before, $this->schemaSnapshot());
        $this->assertTrue(Schema::hasColumn('price_lists', 'year'));
        $this->assertTrue(Schema::hasColumn('price_lists', 'revision_number'));
        $this->assertTrue(Schema::hasColumn('price_lists', 'lock_version'));
        $driver = Schema::getConnection()->getDriverName();
        if ($driver === 'mysql') {
            $this->assertTrue(Schema::hasColumn('price_lists', 'active_inventory_id'));
            $this->assertTrue(Schema::hasColumn('price_lists', 'active_year'));
        }
        if ($driver === 'sqlite') {
            $indexes = collect(DB::select("PRAGMA index_list('price_lists')"))->pluck('name')->all();
            $this->assertContains('price_lists_one_active_per_inventory_year', $indexes);
        }
        $this->assertSame('shared', $first->fresh()->version);
        $this->assertSame('shared', $second->fresh()->version);
        $this->assertSame(2025, (int) $first->fresh()->year);
        $this->assertSame(2026, (int) $second->fresh()->year);

        $second->version = 'shared-b';
        $second->save();
    }

    public function test_down_and_up_roundtrip_keeps_restorable_legacy_identity(): void
    {
        $organizationId = Organization::factory()->create()->id;
        $inventoryId = Inventory::factory()->create([
            'organization_id' => $organizationId,
            'code' => 'RBT',
        ])->id;
        $migration = $this->migration();
        $migration->down();

        $id = DB::table('price_lists')->insertGetId([
            'inventory_id' => $inventoryId,
            'name' => 'Rollback',
            'version' => 'e2e-RBT',
            'status' => PriceListStatus::Active->value,
            'valid_from' => '2026-03-01',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $itemId = DB::table('price_list_items')->insertGetId([
            'price_list_id' => $id,
            'hour' => 10,
            'day_group' => 'sa',
            'second_price' => '2.5000',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $migration->up();
        $migration->down();
        $this->assertFalse(Schema::hasColumn('price_lists', 'year'));
        $this->assertSame('e2e-RBT', DB::table('price_lists')->where('id', $id)->value('version'));
        $this->assertSame('2026-03-01', substr((string) DB::table('price_lists')->where('id', $id)->value('valid_from'), 0, 10));
        $this->assertSame('2.5000', Decimal::roundPrice((string) DB::table('price_list_items')->where('id', $itemId)->value('second_price')));

        $migration->up();
        $row = DB::table('price_lists')->where('id', $id)->first();
        $this->assertSame($id, (int) $row->id);
        $this->assertSame('e2e-RBT', $row->version);
        $this->assertSame(2026, (int) $row->year);
        $this->assertSame(1, (int) $row->revision_number);
    }

    public function test_empty_database_has_year_lock_and_uniqueness_indexes(): void
    {
        $this->assertTrue(Schema::hasColumn('price_lists', 'year'));
        $this->assertTrue(Schema::hasColumn('price_lists', 'lock_version'));
        $this->assertTrue(Schema::hasColumn('price_lists', 'revision_number'));

        $driver = Schema::getConnection()->getDriverName();
        if ($driver === 'sqlite') {
            $indexes = collect(DB::select("PRAGMA index_list('price_lists')"))
                ->pluck('name')
                ->all();
            $this->assertContains('price_lists_one_active_per_inventory_year', $indexes);
            $sql = (string) DB::table('sqlite_master')
                ->where('name', 'price_lists_one_active_per_inventory_year')
                ->value('sql');
            $this->assertStringContainsString("status = 'active'", $sql);
        }

        if ($driver === 'mysql') {
            $this->assertTrue(Schema::hasColumn('price_lists', 'active_inventory_id'));
            $this->assertTrue(Schema::hasColumn('price_lists', 'active_year'));
            $indexes = collect(DB::select('SHOW INDEX FROM price_lists'))
                ->pluck('Key_name')
                ->all();
            $this->assertContains('price_lists_one_active_per_inventory_year', $indexes);
        }
    }

    public function test_multiple_drafts_and_archives_allowed_second_active_rejected(): void
    {
        $inventory = Inventory::factory()->create();

        PriceList::factory()->create([
            'inventory_id' => $inventory->id,
            'year' => 2026,
            'status' => PriceListStatus::Draft,
            'version' => 'd1',
        ]);
        PriceList::factory()->create([
            'inventory_id' => $inventory->id,
            'year' => 2026,
            'status' => PriceListStatus::Draft,
            'version' => 'd2',
        ]);
        PriceList::factory()->create([
            'inventory_id' => $inventory->id,
            'year' => 2026,
            'status' => PriceListStatus::Archived,
            'version' => 'a1',
        ]);
        PriceList::factory()->create([
            'inventory_id' => $inventory->id,
            'year' => 2026,
            'status' => PriceListStatus::Archived,
            'version' => 'a2',
        ]);
        PriceList::factory()->create([
            'inventory_id' => $inventory->id,
            'year' => 2026,
            'status' => PriceListStatus::Active,
            'version' => 'act-2026',
        ]);
        PriceList::factory()->create([
            'inventory_id' => $inventory->id,
            'year' => 2027,
            'status' => PriceListStatus::Active,
            'version' => 'act-2027',
        ]);

        $this->assertSame(2, PriceList::query()->where('inventory_id', $inventory->id)->where('status', PriceListStatus::Draft)->count());
        $this->assertSame(2, PriceList::query()->where('inventory_id', $inventory->id)->where('status', PriceListStatus::Archived)->count());
        $this->assertSame(2, PriceList::query()->where('inventory_id', $inventory->id)->where('status', PriceListStatus::Active)->count());

        $this->expectException(QueryException::class);
        PriceList::factory()->create([
            'inventory_id' => $inventory->id,
            'year' => 2026,
            'status' => PriceListStatus::Active,
            'version' => 'act-2026-b',
        ]);
    }

    public function test_item_contract_treats_empty_as_missing_not_zero(): void
    {
        $this->assertTrue(PriceListItemContract::isMissingPrice(''));
        $this->assertTrue(PriceListItemContract::isMissingPrice(null));
        $this->assertFalse(PriceListItemContract::isMissingPrice('0'));
        $this->assertFalse(PriceListItemContract::isMissingPrice('0,0000'));
    }

    /**
     * @return object{up: callable, down: callable}
     */
    private function migration(): object
    {
        return require database_path('migrations/2026_09_13_220000_add_year_lock_and_revision_to_price_lists.php');
    }

    private function legacyInventoryId(): int
    {
        $organizationId = Organization::factory()->create()->id;

        return Inventory::factory()->create([
            'organization_id' => $organizationId,
            'code' => 'LEG',
        ])->id;
    }

    /**
     * @return array{columns: list<string>, indexes: list<string>}
     */
    private function schemaSnapshot(): array
    {
        $columns = Schema::getColumnListing('price_lists');
        sort($columns);
        $driver = Schema::getConnection()->getDriverName();
        if ($driver === 'sqlite') {
            $indexes = collect(DB::select("PRAGMA index_list('price_lists')"))
                ->pluck('name')
                ->map(fn ($name): string => (string) $name)
                ->unique()
                ->sort()
                ->values()
                ->all();
        } else {
            $indexes = collect(DB::select('SHOW INDEX FROM price_lists'))
                ->pluck('Key_name')
                ->map(fn ($name): string => (string) $name)
                ->unique()
                ->sort()
                ->values()
                ->all();
        }

        return [
            'columns' => $columns,
            'indexes' => $indexes,
        ];
    }

    private function expectUpRejected(object $migration, string $needle): void
    {
        try {
            $migration->up();
            $this->fail('up() hätte den Altbestand verweigern müssen.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString($needle, $exception->getMessage());
        }
    }

    private function ensureMigrated(object $migration): void
    {
        if (! Schema::hasColumn('price_lists', 'year')) {
            $migration->up();
        }
    }
}
