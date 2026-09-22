<?php

namespace Database\Seeders;

use App\Enums\CalculationKind;
use App\Enums\DayGroup;
use App\Enums\DerivedCampaignPeriodStatus;
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
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;

/**
 * E2E-Seeder DSP-DCP-001 abgeleiteter Kampagnenzeitraum (Port 8034).
 */
class E2EDerivedCampaignPeriodSeeder extends Seeder
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

        $organization = Organization::factory()->create(['name' => 'E2E DSP-DCP-001']);
        $spotsCategoryId = (int) AdvertisingCategory::query()
            ->where('key', CanonicalAdvertisingCategories::SPOTS)
            ->value('id');

        $classic = AdvertisingMedium::factory()->create([
            'code' => 'spot_classic',
            'name' => 'Spot Classic',
            'category_id' => $spotsCategoryId,
            'kind' => CalculationKind::SpotClassic,
        ]);

        $inventory = Inventory::factory()->create([
            'organization_id' => $organization->id,
            'name' => 'Radio Hamburg',
            'code' => 'RH-DCP',
            'sort' => 1,
            'logo_path' => null,
        ]);
        $inventoryB = Inventory::factory()->create([
            'organization_id' => $organization->id,
            'name' => 'ROCK ANTENNE Hamburg',
            'code' => 'RAH-DCP',
            'sort' => 2,
            'logo_path' => null,
        ]);

        foreach ([$inventory, $inventoryB] as $inv) {
            InventoryMediumRule::factory()->create([
                'inventory_id' => $inv->id,
                'advertising_medium_id' => $classic->id,
            ]);
        }

        $year = PriceListCalendar::currentYear();
        foreach ([$inventory, $inventoryB] as $inv) {
            $list = PriceList::factory()->create([
                'inventory_id' => $inv->id,
                'status' => PriceListStatus::Active,
                'year' => $year,
                'version' => 'e2e-dcp-'.$inv->code,
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
        $writer = app(CalculationWriter::class);
        $dispoWriter = app(DispoOrderWriter::class);

        $calStart = sprintf('%04d-10-01', $year);
        $calEnd = sprintf('%04d-10-20', $year);
        $avgStart = sprintf('%04d-10-05', $year);
        $avgEnd = sprintf('%04d-10-31', $year);

        // A complete: calendar + closed average → global 01.10–31.10
        $completeCalc = $writer->create([
            'planning_mode' => 'manual',
            'customer_name' => 'DCP Complete GmbH',
            'order_discount_percent' => '0',
            'schema_fingerprint' => $schemaFp,
            'dynamic_field_values' => [
                'campaign_period' => ['start' => $calStart, 'end' => $avgEnd],
            ],
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
                        ['date' => $calStart, 'hour' => 8, 'spot_count' => 2],
                        ['date' => $calEnd, 'hour' => 10, 'spot_count' => 1],
                    ],
                    'dynamic_field_values' => [
                        'period_open' => true,
                        'position_flight_period' => null,
                    ],
                ],
                [
                    'inventory_id' => $inventoryB->id,
                    'advertising_medium_id' => $classic->id,
                    'schema_fingerprint' => $classicFp,
                    'spot_method' => 'average',
                    'calculation_method_key' => 'average',
                    'length_seconds' => 30,
                    'total_spot_count' => 10,
                    'position_discount_percent' => '0',
                    'ae_percent' => '0',
                    'plan_rows' => [['hour' => 8, 'day_group' => 'mo_fr']],
                    'dynamic_field_values' => [
                        'period_open' => false,
                        'position_flight_period' => ['start' => $avgStart, 'end' => $avgEnd],
                    ],
                ],
            ],
        ], $sales);
        $completeOrder = $dispoWriter->createFromCalculation(
            $completeCalc,
            array_values($completeCalc->positions()->pluck('id')->all()),
            $sales,
        )->order;

        // B partial: calendar + open average
        $partialCalc = $writer->create([
            'planning_mode' => 'manual',
            'customer_name' => 'DCP Partial GmbH',
            'order_discount_percent' => '0',
            'schema_fingerprint' => $schemaFp,
            'dynamic_field_values' => ['campaign_period' => null],
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
                        ['date' => $calStart, 'hour' => 8, 'spot_count' => 2],
                        ['date' => $calEnd, 'hour' => 10, 'spot_count' => 1],
                    ],
                    'dynamic_field_values' => [
                        'period_open' => true,
                        'position_flight_period' => null,
                    ],
                ],
                [
                    'inventory_id' => $inventoryB->id,
                    'advertising_medium_id' => $classic->id,
                    'schema_fingerprint' => $classicFp,
                    'spot_method' => 'average',
                    'calculation_method_key' => 'average',
                    'length_seconds' => 30,
                    'total_spot_count' => 5,
                    'position_discount_percent' => '0',
                    'ae_percent' => '0',
                    'plan_rows' => [['hour' => 9, 'day_group' => 'mo_fr']],
                    'dynamic_field_values' => [
                        'period_open' => true,
                        'position_flight_period' => null,
                    ],
                ],
            ],
        ], $sales);
        $partialOrder = $dispoWriter->createFromCalculation(
            $partialCalc,
            array_values($partialCalc->positions()->pluck('id')->all()),
            $sales,
        )->order;

        // C open: only open average
        $openCalc = $writer->create([
            'planning_mode' => 'manual',
            'customer_name' => 'DCP Open GmbH',
            'order_discount_percent' => '0',
            'schema_fingerprint' => $schemaFp,
            'dynamic_field_values' => ['campaign_period' => null],
            'positions' => [[
                'inventory_id' => $inventory->id,
                'advertising_medium_id' => $classic->id,
                'schema_fingerprint' => $classicFp,
                'spot_method' => 'average',
                'calculation_method_key' => 'average',
                'length_seconds' => 30,
                'total_spot_count' => 5,
                'position_discount_percent' => '0',
                'ae_percent' => '0',
                'plan_rows' => [['hour' => 8, 'day_group' => 'mo_fr']],
                'dynamic_field_values' => [
                    'period_open' => true,
                    'position_flight_period' => null,
                ],
            ]],
        ], $sales);
        $openOrder = $dispoWriter->createFromCalculation(
            $openCalc,
            array_values($openCalc->positions()->pluck('id')->all()),
            $sales,
        )->order;

        // D conflict: calc campaign differs from derived calendar
        $conflictCalcStart = sprintf('%04d-01-01', $year);
        $conflictCalcEnd = sprintf('%04d-01-31', $year);
        $conflictCalc = $writer->create([
            'planning_mode' => 'manual',
            'customer_name' => 'DCP Conflict GmbH',
            'order_discount_percent' => '0',
            'schema_fingerprint' => $schemaFp,
            'dynamic_field_values' => [
                'campaign_period' => ['start' => $conflictCalcStart, 'end' => $conflictCalcEnd],
            ],
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
                    ['date' => $calStart, 'hour' => 8, 'spot_count' => 1],
                    ['date' => $calEnd, 'hour' => 9, 'spot_count' => 1],
                ],
                'dynamic_field_values' => [
                    'period_open' => true,
                    'position_flight_period' => null,
                ],
            ]],
        ], $sales);
        $conflictOrder = $dispoWriter->createFromCalculation(
            $conflictCalc,
            array_values($conflictCalc->positions()->pluck('id')->all()),
            $sales,
        )->order;

        // E identical: calc matches derived
        $identicalCalc = $writer->create([
            'planning_mode' => 'manual',
            'customer_name' => 'DCP Identical GmbH',
            'order_discount_percent' => '0',
            'schema_fingerprint' => $schemaFp,
            'dynamic_field_values' => [
                'campaign_period' => ['start' => $calStart, 'end' => $calEnd],
            ],
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
                    ['date' => $calStart, 'hour' => 8, 'spot_count' => 1],
                    ['date' => $calEnd, 'hour' => 9, 'spot_count' => 1],
                ],
                'dynamic_field_values' => [
                    'period_open' => true,
                    'position_flight_period' => null,
                ],
            ]],
        ], $sales);
        $identicalOrder = $dispoWriter->createFromCalculation(
            $identicalCalc,
            array_values($identicalCalc->positions()->pluck('id')->all()),
            $sales,
        )->order;

        // F legacy: create then force legacy (simulates pre-feature orders)
        $legacyCalc = $writer->create([
            'planning_mode' => 'manual',
            'customer_name' => 'DCP Legacy GmbH',
            'order_discount_percent' => '0',
            'schema_fingerprint' => $schemaFp,
            'dynamic_field_values' => ['campaign_period' => null],
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
                    ['date' => $calStart, 'hour' => 8, 'spot_count' => 1],
                ],
                'dynamic_field_values' => [
                    'period_open' => true,
                    'position_flight_period' => null,
                ],
            ]],
        ], $sales);
        $legacyOrder = $dispoWriter->createFromCalculation(
            $legacyCalc,
            array_values($legacyCalc->positions()->pluck('id')->all()),
            $sales,
        )->order;
        DB::table('dispo_orders')->where('id', $legacyOrder->id)->update([
            'derived_campaign_period_start' => null,
            'derived_campaign_period_end' => null,
            'derived_campaign_period_status' => DerivedCampaignPeriodStatus::Legacy->value,
            'derived_campaign_period_at' => null,
            'derived_campaign_period_snapshot' => null,
        ]);

        $fixture = [
            'year' => $year,
            'complete' => [
                'id' => $completeOrder->id,
                'number' => $completeOrder->number,
                'expected_start' => $calStart,
                'expected_end' => $avgEnd,
                'status' => 'complete',
            ],
            'partial' => [
                'id' => $partialOrder->id,
                'number' => $partialOrder->number,
                'expected_start' => $calStart,
                'expected_end' => $calEnd,
                'status' => 'partial',
            ],
            'open' => [
                'id' => $openOrder->id,
                'number' => $openOrder->number,
                'status' => 'open',
            ],
            'conflict' => [
                'id' => $conflictOrder->id,
                'number' => $conflictOrder->number,
                'expected_start' => $calStart,
                'expected_end' => $calEnd,
                'calc_start' => $conflictCalcStart,
                'calc_end' => $conflictCalcEnd,
                'status' => 'complete',
            ],
            'identical' => [
                'id' => $identicalOrder->id,
                'number' => $identicalOrder->number,
                'expected_start' => $calStart,
                'expected_end' => $calEnd,
                'status' => 'complete',
            ],
            'legacy' => [
                'id' => $legacyOrder->fresh()->id,
                'number' => $legacyOrder->fresh()->number,
                'status' => 'legacy',
            ],
        ];

        File::put(
            database_path('e2e-derived-campaign-period-orders.json'),
            json_encode($fixture, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR),
        );
    }
}
