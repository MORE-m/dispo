<?php

namespace Database\Seeders;

use App\Enums\CalculationKind;
use App\Enums\DayGroup;
use App\Enums\PriceListStatus;
use App\Enums\Role;
use App\Models\AdvertisingCategory;
use App\Models\AdvertisingMedium;
use App\Models\Inventory;
use App\Models\InventoryMediumRule;
use App\Models\Organization;
use App\Models\PriceList;
use App\Models\PriceListItem;
use App\Models\User;
use App\Services\Calculation\CalculationWriter;
use App\Services\DispoOrder\DispoOrderWriter;
use App\Services\DynamicField\ConfigurationSnapshotFreezeService;
use App\Support\Advertising\CanonicalAdvertisingCategories;
use App\Support\E2E\E2EIsolatedEnvironmentGuard;
use App\Support\PriceList\PriceListCalendar;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\File;

/**
 * E2E-Seeder SPT-008 Spotverteilungs-Export (Port 8033).
 */
class E2ESpotDistributionExportSeeder extends Seeder
{
    public function run(): void
    {
        E2EIsolatedEnvironmentGuard::assertSafeForE2ESeeding();

        foreach ([
            ['email' => 'sales@example.com', 'name' => 'E2E Vertrieb', 'role' => Role::Sales],
            ['email' => 'disposition@example.com', 'name' => 'E2E Disposition', 'role' => Role::Disposition],
            ['email' => 'pm@example.com', 'name' => 'E2E PM', 'role' => Role::ProductManagement],
            ['email' => 'admin@example.com', 'name' => 'E2E Admin', 'role' => Role::Admin],
        ] as $attrs) {
            User::query()->updateOrCreate(
                ['email' => $attrs['email']],
                [
                    'name' => $attrs['name'],
                    'password' => 'password',
                    'role' => $attrs['role'],
                ],
            );
        }

        if (Organization::query()->exists()) {
            return;
        }

        $organization = Organization::factory()->create(['name' => 'E2E SPT-008']);
        $spotsCategoryId = (int) AdvertisingCategory::query()
            ->where('key', CanonicalAdvertisingCategories::SPOTS)
            ->value('id');

        $classic = AdvertisingMedium::factory()->create([
            'code' => 'spot_classic',
            'name' => 'Spot Classic',
            'category_id' => $spotsCategoryId,
            'kind' => CalculationKind::SpotClassic,
        ]);
        $tandem = AdvertisingMedium::factory()->tandem()->create([
            'code' => 'spot_tandem',
            'category_id' => $spotsCategoryId,
        ]);

        $inventory = Inventory::factory()->create([
            'organization_id' => $organization->id,
            'name' => 'Radio Hamburg',
            'code' => 'RH008',
            'sort' => 1,
            'logo_path' => null,
        ]);
        $inventoryB = Inventory::factory()->create([
            'organization_id' => $organization->id,
            'name' => 'ROCK ANTENNE Hamburg',
            'code' => 'RAH008',
            'sort' => 2,
            'logo_path' => null,
        ]);

        foreach ([$classic, $tandem] as $medium) {
            foreach ([$inventory, $inventoryB] as $inv) {
                InventoryMediumRule::factory()->create([
                    'inventory_id' => $inv->id,
                    'advertising_medium_id' => $medium->id,
                ]);
            }
        }

        $year = PriceListCalendar::currentYear();
        foreach ([$inventory, $inventoryB] as $inv) {
            $list = PriceList::factory()->create([
                'inventory_id' => $inv->id,
                'status' => PriceListStatus::Active,
                'year' => $year,
                'version' => 'e2e-spt008-'.$inv->code,
                'valid_from' => now()->toDateString(),
            ]);
            foreach (range(0, 23) as $hour) {
                foreach ([DayGroup::MoFr, DayGroup::Sa, DayGroup::So] as $group) {
                    PriceListItem::factory()->create([
                        'price_list_id' => $list->id,
                        'hour' => $hour,
                        'day_group' => $group,
                        'second_price' => '2.0000',
                    ]);
                }
            }
        }

        $sales = User::query()->where('email', 'sales@example.com')->firstOrFail();
        $freeze = app(ConfigurationSnapshotFreezeService::class);
        $schemaFp = $freeze->resolveLiveSchemaForCalculationV3()['schema_fingerprint'];
        $classicFp = $freeze->resolveLivePositionSchema((int) $classic->id)['schema_fingerprint'];
        $tandemFp = $freeze->resolveLivePositionSchema((int) $tandem->id)['schema_fingerprint'];
        $date = sprintf('%04d-09-14', $year);
        $writer = app(CalculationWriter::class);
        $dispoWriter = app(DispoOrderWriter::class);

        $calendarCalc = $writer->create([
            'planning_mode' => 'manual',
            'customer_name' => 'SPT008 Calendar GmbH',
            'order_discount_percent' => '0',
            'schema_fingerprint' => $schemaFp,
            'positions' => [[
                'inventory_id' => $inventory->id,
                'advertising_medium_id' => $classic->id,
                'schema_fingerprint' => $classicFp,
                'spot_method' => 'calendar',
                'calculation_method_key' => 'calendar',
                'length_seconds' => 30,
                'position_discount_percent' => '0',
                'ae_percent' => '0',
                'planner_entries' => [
                    ['date' => $date, 'hour' => 8, 'spot_count' => 3],
                    ['date' => $date, 'hour' => 10, 'spot_count' => 2],
                ],
            ]],
        ], $sales);
        /** @var list<int> $calendarPositionIds */
        $calendarPositionIds = array_values($calendarCalc->positions()->pluck('id')->all());
        $calendarOrder = $dispoWriter->createFromCalculation(
            $calendarCalc,
            $calendarPositionIds,
            $sales,
        )->order;

        $tandemCalc = $writer->create([
            'planning_mode' => 'manual',
            'customer_name' => 'SPT008 Tandem GmbH',
            'order_discount_percent' => '0',
            'schema_fingerprint' => $schemaFp,
            'positions' => [[
                'inventory_id' => $inventory->id,
                'advertising_medium_id' => $tandem->id,
                'schema_fingerprint' => $tandemFp,
                'spot_method' => 'calendar',
                'calculation_method_key' => 'calendar',
                'length_seconds' => 30,
                'component_calculation_strategy' => 'shared_total_length',
                'position_discount_percent' => '0',
                'ae_percent' => '0',
                'planner_entries' => [
                    ['date' => $date, 'hour' => 8, 'spot_count' => 4],
                ],
                'components' => [
                    ['role' => 'main_spot', 'label' => 'Hauptspot', 'length_seconds' => 20, 'sort' => 1],
                    ['role' => 'reminder', 'label' => 'Reminder', 'length_seconds' => 10, 'sort' => 2],
                ],
            ]],
        ], $sales);
        /** @var list<int> $tandemPositionIds */
        $tandemPositionIds = array_values($tandemCalc->positions()->pluck('id')->all());
        $tandemOrder = $dispoWriter->createFromCalculation(
            $tandemCalc,
            $tandemPositionIds,
            $sales,
        )->order;

        $mixedCalc = $writer->create([
            'planning_mode' => 'manual',
            'customer_name' => 'SPT008 Mixed GmbH',
            'order_discount_percent' => '0',
            'schema_fingerprint' => $schemaFp,
            'positions' => [
                [
                    'inventory_id' => $inventory->id,
                    'advertising_medium_id' => $classic->id,
                    'schema_fingerprint' => $classicFp,
                    'spot_method' => 'calendar',
                    'calculation_method_key' => 'calendar',
                    'length_seconds' => 30,
                    'position_discount_percent' => '0',
                    'ae_percent' => '0',
                    'planner_entries' => [
                        ['date' => $date, 'hour' => 9, 'spot_count' => 1],
                    ],
                ],
                [
                    'inventory_id' => $inventoryB->id,
                    'advertising_medium_id' => $classic->id,
                    'schema_fingerprint' => $classicFp,
                    'spot_method' => 'average',
                    'length_seconds' => 30,
                    'total_spot_count' => 10,
                    'position_discount_percent' => '0',
                    'ae_percent' => '0',
                    'plan_rows' => [['hour' => 8, 'day_group' => 'mo_fr']],
                    'time_ranges' => [[
                        'start_hour' => 8,
                        'end_hour_exclusive' => 9,
                        'day_group' => 'mo_fr',
                        'spot_count' => 10,
                    ]],
                ],
            ],
        ], $sales);
        /** @var list<int> $mixedPositionIds */
        $mixedPositionIds = array_values($mixedCalc->positions()->pluck('id')->all());
        $mixedOrder = $dispoWriter->createFromCalculation(
            $mixedCalc,
            $mixedPositionIds,
            $sales,
        )->order;

        $averageCalc = $writer->create([
            'planning_mode' => 'manual',
            'customer_name' => 'SPT008 Average GmbH',
            'order_discount_percent' => '0',
            'schema_fingerprint' => $schemaFp,
            'positions' => [[
                'inventory_id' => $inventory->id,
                'advertising_medium_id' => $classic->id,
                'schema_fingerprint' => $classicFp,
                'spot_method' => 'average',
                'length_seconds' => 30,
                'total_spot_count' => 10,
                'position_discount_percent' => '0',
                'ae_percent' => '0',
                'plan_rows' => [['hour' => 8, 'day_group' => 'mo_fr']],
                'time_ranges' => [[
                    'start_hour' => 8,
                    'end_hour_exclusive' => 9,
                    'day_group' => 'mo_fr',
                    'spot_count' => 10,
                ]],
            ]],
        ], $sales);
        /** @var list<int> $averagePositionIds */
        $averagePositionIds = array_values($averageCalc->positions()->pluck('id')->all());
        $averageOrder = $dispoWriter->createFromCalculation(
            $averageCalc,
            $averagePositionIds,
            $sales,
        )->order;

        $multiCalc = $writer->create([
            'planning_mode' => 'manual',
            'customer_name' => 'SPT008 Multi Calendar GmbH',
            'order_discount_percent' => '0',
            'schema_fingerprint' => $schemaFp,
            'positions' => [
                [
                    'inventory_id' => $inventory->id,
                    'advertising_medium_id' => $classic->id,
                    'schema_fingerprint' => $classicFp,
                    'spot_method' => 'calendar',
                    'calculation_method_key' => 'calendar',
                    'length_seconds' => 30,
                    'position_discount_percent' => '0',
                    'ae_percent' => '0',
                    'planner_entries' => [
                        ['date' => $date, 'hour' => 8, 'spot_count' => 2],
                        ['date' => $date, 'hour' => 9, 'spot_count' => 1],
                    ],
                ],
                [
                    'inventory_id' => $inventoryB->id,
                    'advertising_medium_id' => $classic->id,
                    'schema_fingerprint' => $classicFp,
                    'spot_method' => 'calendar',
                    'calculation_method_key' => 'calendar',
                    'length_seconds' => 30,
                    'position_discount_percent' => '0',
                    'ae_percent' => '0',
                    'planner_entries' => [
                        ['date' => $date, 'hour' => 8, 'spot_count' => 5],
                    ],
                ],
                [
                    'inventory_id' => $inventory->id,
                    'advertising_medium_id' => $tandem->id,
                    'schema_fingerprint' => $tandemFp,
                    'spot_method' => 'calendar',
                    'calculation_method_key' => 'calendar',
                    'length_seconds' => 30,
                    'component_calculation_strategy' => 'shared_total_length',
                    'position_discount_percent' => '0',
                    'ae_percent' => '0',
                    'planner_entries' => [
                        ['date' => $date, 'hour' => 8, 'spot_count' => 3],
                    ],
                    'components' => [
                        ['role' => 'main_spot', 'label' => 'Hauptspot', 'length_seconds' => 20, 'sort' => 1],
                        ['role' => 'reminder', 'label' => 'Reminder', 'length_seconds' => 10, 'sort' => 2],
                    ],
                ],
                [
                    'inventory_id' => $inventoryB->id,
                    'advertising_medium_id' => $classic->id,
                    'schema_fingerprint' => $classicFp,
                    'spot_method' => 'average',
                    'length_seconds' => 30,
                    'total_spot_count' => 10,
                    'position_discount_percent' => '0',
                    'ae_percent' => '0',
                    'plan_rows' => [['hour' => 8, 'day_group' => 'mo_fr']],
                    'time_ranges' => [[
                        'start_hour' => 8,
                        'end_hour_exclusive' => 9,
                        'day_group' => 'mo_fr',
                        'spot_count' => 10,
                    ]],
                ],
            ],
        ], $sales);
        /** @var list<int> $multiPositionIds */
        $multiPositionIds = array_values($multiCalc->positions()->pluck('id')->all());
        $multiOrder = $dispoWriter->createFromCalculation(
            $multiCalc,
            $multiPositionIds,
            $sales,
        )->order;

        File::put(database_path('e2e-spt008-orders.json'), json_encode([
            'calendar' => ['id' => $calendarOrder->id, 'number' => $calendarOrder->number],
            'tandem' => ['id' => $tandemOrder->id, 'number' => $tandemOrder->number],
            'mixed' => ['id' => $mixedOrder->id, 'number' => $mixedOrder->number],
            'average' => ['id' => $averageOrder->id, 'number' => $averageOrder->number],
            'multi' => [
                'id' => $multiOrder->id,
                'number' => $multiOrder->number,
                'expected' => [
                    'total_positions' => 4,
                    'calendar_positions' => 3,
                    'average_positions' => 1,
                    'xlsx_rows' => 4,
                    'qty_by_position_label' => [
                        'Position 1' => 3,
                        'Position 2' => 5,
                        'Position 3' => 3,
                    ],
                ],
            ],
        ], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
    }
}
