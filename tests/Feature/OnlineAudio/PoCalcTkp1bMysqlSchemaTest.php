<?php

namespace Tests\Feature\OnlineAudio;

use App\Enums\PriceListStatus;
use App\Models\CalculationPositionOaPlatformAllocation;
use App\Models\CalculationPositionOaTargetingSelection;
use App\Models\OaTkpPriceList;
use App\Models\OaTkpPriceListItem;
use Database\Seeders\InitialCatalogMatCoreSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * PO-CALC-TKP-1B: MySQL-Persistenz für TKP-Felder / Allocation / Targeting.
 *
 * @group mysql
 */
class PoCalcTkp1bMysqlSchemaTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function tkp_snapshot_decimals_and_child_tables_persist(): void
    {
        if (DB::connection()->getDriverName() !== 'mysql') {
            $this->markTestSkipped('MySQL-only');
        }

        $this->seed(InitialCatalogMatCoreSeeder::class);

        $mediumId = (int) DB::table('advertising_media')->where('code', 'pre_stream')->value('id');
        $inventoryId = (int) DB::table('inventories')->where('code', 'inv_more_kombi_online_audio')->value('id');

        $list = OaTkpPriceList::query()->create([
            'year' => 2026,
            'name' => 'Fixture',
            'version' => '2026.1',
            'revision_number' => 1,
            'lock_version' => 1,
            'status' => PriceListStatus::Draft,
        ]);
        OaTkpPriceListItem::query()->create([
            'oa_tkp_price_list_id' => $list->id,
            'advertising_medium_id' => $mediumId,
            'inventory_id' => $inventoryId,
            'list_tkp' => '999999999999.9999',
            'minimum_tkp' => '1.0000',
        ]);

        $this->assertSame(
            '999999999999.9999',
            (string) OaTkpPriceListItem::query()->firstOrFail()->list_tkp,
        );

        $this->assertTrue(DB::getSchemaBuilder()->hasTable('calculation_position_oa_platform_allocations'));
        $this->assertTrue(DB::getSchemaBuilder()->hasTable('calculation_position_oa_targeting_selections'));
        $this->assertTrue(DB::getSchemaBuilder()->hasColumn('calculation_positions', 'final_tkp'));
        $this->assertSame(0, CalculationPositionOaPlatformAllocation::query()->count());
        $this->assertSame(0, CalculationPositionOaTargetingSelection::query()->count());
    }
}
