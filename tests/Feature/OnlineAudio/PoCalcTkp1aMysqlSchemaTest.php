<?php

namespace Tests\Feature\OnlineAudio;

use App\Enums\OaTargetingCategory;
use App\Enums\PriceListStatus;
use App\Models\OaAdserverSurcharge;
use App\Models\OaPlatform;
use App\Models\OaTargetingDefinition;
use App\Models\OaTkpPriceList;
use App\Models\OaTkpPriceListItem;
use Database\Seeders\InitialCatalogMatCoreSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * PO-CALC-TKP-1A: MySQL-Constraints / Decimal-Precision.
 *
 * @group mysql
 */
class PoCalcTkp1aMysqlSchemaTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function platform_code_is_unique_and_decimals_store_four_places(): void
    {
        if (DB::connection()->getDriverName() !== 'mysql') {
            $this->markTestSkipped('MySQL-only schema constraints');
        }

        OaPlatform::query()->create([
            'code' => 'fixture_mysql_plat',
            'name' => 'Fixture',
            'is_active' => true,
            'sort' => 1,
            'is_adserver_relevant' => false,
        ]);

        $this->expectException(QueryException::class);
        OaPlatform::query()->create([
            'code' => 'fixture_mysql_plat',
            'name' => 'Dup',
            'is_active' => true,
            'sort' => 2,
            'is_adserver_relevant' => false,
        ]);
    }

    #[Test]
    public function price_list_item_unique_and_decimal_precision(): void
    {
        if (DB::connection()->getDriverName() !== 'mysql') {
            $this->markTestSkipped('MySQL-only schema constraints');
        }

        $this->seed(InitialCatalogMatCoreSeeder::class);

        $list = OaTkpPriceList::query()->create([
            'year' => 2026,
            'name' => 'Fixture MySQL',
            'version' => '2026.1',
            'revision_number' => 1,
            'lock_version' => 1,
            'status' => PriceListStatus::Draft,
        ]);

        $mediumId = (int) DB::table('advertising_media')->where('code', 'pre_stream')->value('id');
        $inventoryId = (int) DB::table('inventories')->where('code', 'inv_more_kombi_online_audio')->value('id');

        OaTkpPriceListItem::query()->create([
            'oa_tkp_price_list_id' => $list->id,
            'advertising_medium_id' => $mediumId,
            'inventory_id' => $inventoryId,
            'list_tkp' => '12.5000',
            'minimum_tkp' => '8.1250',
        ]);

        $stored = OaTkpPriceListItem::query()->firstOrFail();
        $this->assertSame('12.5000', (string) $stored->list_tkp);
        $this->assertSame('8.1250', (string) $stored->minimum_tkp);

        $this->expectException(QueryException::class);
        OaTkpPriceListItem::query()->create([
            'oa_tkp_price_list_id' => $list->id,
            'advertising_medium_id' => $mediumId,
            'inventory_id' => $inventoryId,
            'list_tkp' => '1.0000',
            'minimum_tkp' => '1.0000',
        ]);
    }

    #[Test]
    public function targeting_and_adserver_revision_uniques(): void
    {
        if (DB::connection()->getDriverName() !== 'mysql') {
            $this->markTestSkipped('MySQL-only schema constraints');
        }

        OaTargetingDefinition::query()->create([
            'code' => 'fixture_tgt',
            'name' => 'Fixture',
            'category' => OaTargetingCategory::Dmp,
            'tkp_surcharge' => '2.0000',
            'revision_number' => 1,
            'is_active' => true,
        ]);

        OaTargetingDefinition::query()->create([
            'code' => 'fixture_tgt',
            'name' => 'Fixture r2',
            'category' => OaTargetingCategory::Dmp,
            'tkp_surcharge' => '3.0000',
            'revision_number' => 2,
            'is_active' => false,
        ]);

        $this->expectException(QueryException::class);
        OaTargetingDefinition::query()->create([
            'code' => 'fixture_tgt',
            'name' => 'Dup',
            'category' => OaTargetingCategory::Dmp,
            'tkp_surcharge' => '1.0000',
            'revision_number' => 1,
            'is_active' => true,
        ]);
    }

    #[Test]
    public function adserver_scope_json_persists_without_operative_seed(): void
    {
        if (DB::connection()->getDriverName() !== 'mysql') {
            $this->markTestSkipped('MySQL-only');
        }

        $this->assertSame(0, OaAdserverSurcharge::query()->count());

        OaAdserverSurcharge::query()->create([
            'code' => 'fixture_adserver',
            'name' => 'Fixture Adserver',
            'tkp_amount' => '15.0000',
            'revision_number' => 1,
            'is_active' => false,
            'scope_json' => ['note' => 'fixture-only; scope FD offen'],
        ]);

        $row = OaAdserverSurcharge::query()->firstOrFail();
        $this->assertSame('15.0000', (string) $row->tkp_amount);
        $this->assertFalse($row->is_active);
        $this->assertSame('fixture-only; scope FD offen', $row->scope_json['note']);
    }
}
