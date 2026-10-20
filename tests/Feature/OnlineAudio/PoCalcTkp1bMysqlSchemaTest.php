<?php

namespace Tests\Feature\OnlineAudio;

use App\Enums\CalculationKind;
use App\Enums\CalculationStatus;
use App\Enums\OaTargetingCategory;
use App\Enums\PlanningMode;
use App\Enums\PriceListStatus;
use App\Enums\PricingSettlementMode;
use App\Enums\Role;
use App\Enums\SpotCalculationMethod;
use App\Models\AdvertisingMedium;
use App\Models\Calculation;
use App\Models\CalculationPosition;
use App\Models\CalculationPositionOaPlatformAllocation;
use App\Models\CalculationPositionOaTargetingSelection;
use App\Models\Inventory;
use App\Models\OaTkpPriceList;
use App\Models\OaTkpPriceListItem;
use App\Models\User;
use App\Services\Calculation\CalculationNumberSequencer;
use Database\Seeders\InitialCatalogMatCoreSeeder;
use Illuminate\Database\QueryException;
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

    #[Test]
    public function platform_and_targeting_codes_are_unique_per_position(): void
    {
        if (DB::connection()->getDriverName() !== 'mysql') {
            $this->markTestSkipped('MySQL-only');
        }

        $this->seed(InitialCatalogMatCoreSeeder::class);
        $position = $this->createBareTkpPosition();

        CalculationPositionOaPlatformAllocation::query()->create([
            'calculation_position_id' => $position->id,
            'platform_code' => 'fixture_a',
            'platform_name' => 'A',
            'impressions' => 50_000,
            'sort' => 10,
        ]);

        try {
            CalculationPositionOaPlatformAllocation::query()->create([
                'calculation_position_id' => $position->id,
                'platform_code' => 'fixture_a',
                'platform_name' => 'A duplicate',
                'impressions' => 50_000,
                'sort' => 20,
            ]);
            $this->fail('Expected unique violation for platform_code');
        } catch (QueryException) {
            // expected
        }

        CalculationPositionOaTargetingSelection::query()->create([
            'calculation_position_id' => $position->id,
            'targeting_code' => 'geo',
            'targeting_name' => 'Geo',
            'targeting_category' => OaTargetingCategory::Technisch->value,
            'tkp_surcharge_snapshot' => '2.0000',
            'targeting_revision_number' => 1,
            'sort' => 10,
        ]);

        try {
            CalculationPositionOaTargetingSelection::query()->create([
                'calculation_position_id' => $position->id,
                'targeting_code' => 'geo',
                'targeting_name' => 'Geo duplicate',
                'targeting_category' => OaTargetingCategory::Technisch->value,
                'tkp_surcharge_snapshot' => '2.0000',
                'targeting_revision_number' => 1,
                'sort' => 20,
            ]);
            $this->fail('Expected unique violation for targeting_code');
        } catch (QueryException) {
            // expected
        }
    }

    private function createBareTkpPosition(): CalculationPosition
    {
        $user = User::factory()->role(Role::Sales)->create();
        $medium = AdvertisingMedium::query()->where('code', 'pre_stream')->firstOrFail();
        $inventory = Inventory::query()->where('code', 'inv_more_kombi_online_audio')->firstOrFail();
        [$year, $seq, $number] = (new CalculationNumberSequencer)->next();

        $calculation = Calculation::query()->create([
            'number' => $number,
            'number_year' => $year,
            'number_seq' => $seq,
            'status' => CalculationStatus::Draft,
            'planning_mode' => PlanningMode::Manual,
            'advisor_id' => $user->id,
            'order_discount_percent' => '0',
            'ae_enabled' => false,
            'media_gross' => '0',
            'position_discount_total' => '0',
            'order_discount_total' => '0',
            'ae_total' => '0',
            'nn_invest' => '0',
            'requires_special_approval' => false,
            'lock_version' => 1,
        ]);

        return CalculationPosition::query()->create([
            'calculation_id' => $calculation->id,
            'inventory_id' => $inventory->id,
            'inventory_code' => $inventory->code,
            'inventory_name' => $inventory->name,
            'advertising_medium_id' => $medium->id,
            'advertising_medium_code' => $medium->code,
            'advertising_medium_name' => $medium->name,
            'kind' => CalculationKind::OaTkp,
            'spot_method' => SpotCalculationMethod::Tkp,
            'engine_profile_key' => 'oa_tkp',
            'calculation_method_key' => 'tkp',
            'calculation_method_name' => 'TKP',
            'algorithm_version' => 'v1',
            'length_seconds' => 0,
            'total_spot_count' => 0,
            'surcharge_percent' => '0',
            'position_discount_percent' => '0',
            'ae_percent' => '0',
            'is_discountable' => true,
            'is_ae_eligible' => true,
            'pricing_settlement_mode' => PricingSettlementMode::Normal,
            'impressions' => 100_000,
            'media_gross' => '1000.00',
            'position_discount_amount' => '0',
            'order_discount_amount' => '0',
            'ae_amount' => '0',
            'nn_invest' => '1000.00',
            'sort' => 10,
        ]);
    }
}
