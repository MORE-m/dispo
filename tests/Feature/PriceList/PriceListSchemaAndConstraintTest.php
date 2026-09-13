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
}
