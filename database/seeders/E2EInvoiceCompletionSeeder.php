<?php

namespace Database\Seeders;

use App\Enums\CalculationKind;
use App\Enums\DayGroup;
use App\Enums\DispoOrderStatus;
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
use App\Services\DispoOrder\DispoOrderApprovalService;
use App\Services\DispoOrder\DispoOrderCustomerConfirmationService;
use App\Services\DispoOrder\DispoOrderOperationalStatusService;
use App\Services\DispoOrder\DispoOrderWriter;
use App\Services\DynamicField\ConfigurationSnapshotFreezeService;
use App\Support\Advertising\CanonicalAdvertisingCategories;
use App\Support\E2E\E2EIsolatedEnvironmentGuard;
use App\Support\PriceList\PriceListCalendar;
use Illuminate\Database\Seeder;

/**
 * E2E-Seeder BL-P8-02d Rechnung per Ende + Completion (Port 8038).
 */
class E2EInvoiceCompletionSeeder extends Seeder
{
    public function run(): void
    {
        E2EIsolatedEnvironmentGuard::assertSafeForE2ESeeding();

        foreach ([
            ['email' => 'sales@example.com', 'name' => 'E2E Vertrieb', 'role' => Role::Sales],
            ['email' => 'sales-b@example.com', 'name' => 'E2E Vertrieb B', 'role' => Role::Sales],
            ['email' => 'disposition@example.com', 'name' => 'E2E Disposition', 'role' => Role::Disposition],
            ['email' => 'pm@example.com', 'name' => 'E2E PM', 'role' => Role::ProductManagement],
            ['email' => 'admin@example.com', 'name' => 'E2E Admin', 'role' => Role::Admin],
            ['email' => 'management@example.com', 'name' => 'E2E Management', 'role' => Role::Management],
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

        $organization = Organization::factory()->create(['name' => 'E2E BL-P8-02d']);
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
            'code' => 'RH802D',
            'sort' => 1,
            'logo_path' => null,
        ]);

        InventoryMediumRule::factory()->create([
            'inventory_id' => $inventory->id,
            'advertising_medium_id' => $classic->id,
        ]);

        $year = PriceListCalendar::currentYear();
        $list = PriceList::factory()->create([
            'inventory_id' => $inventory->id,
            'status' => PriceListStatus::Active,
            'year' => $year,
            'version' => 'e2e-blp802d-'.$inventory->code,
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

        $sales = User::query()->where('email', 'sales@example.com')->firstOrFail();
        $approver = User::query()->where('email', 'sales-b@example.com')->firstOrFail();
        $disposition = User::query()->where('email', 'disposition@example.com')->firstOrFail();
        $freeze = app(ConfigurationSnapshotFreezeService::class);
        $schemaFp = $freeze->resolveLiveSchemaForCalculationV3()['schema_fingerprint'];
        $classicFp = $freeze->resolveLivePositionSchema((int) $classic->id)['schema_fingerprint'];

        $writer = app(CalculationWriter::class);
        $approvals = app(DispoOrderApprovalService::class);
        $confirmations = app(DispoOrderCustomerConfirmationService::class);
        $ops = app(DispoOrderOperationalStatusService::class);
        $dispoWriter = app(DispoOrderWriter::class);

        // A) Happy Path: in_progress, konkrete Periode, ohne Rechnung-per-Ende
        $calcHappy = $writer->create([
            'planning_mode' => 'manual',
            'customer_name' => 'BLP802D Happy Path GmbH',
            'order_discount_percent' => '0',
            'schema_fingerprint' => $schemaFp,
            'dynamic_field_values' => ['campaign_period' => null],
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
                'dynamic_field_values' => [
                    'period_open' => false,
                    'position_flight_period' => [
                        'start' => '2026-03-01',
                        'end' => '2026-03-31',
                    ],
                ],
            ]],
        ], $sales);

        $happy = $dispoWriter->createFromCalculation(
            $calcHappy,
            array_values($calcHappy->positions()->pluck('id')->all()),
            $sales,
        )->order;
        $happy = $confirmations->update(
            $happy,
            $sales,
            $happy->lock_version,
            true,
            'Kundenfreigabe liegt per E-Mail vor; Upload wird nachgereicht.',
        );
        $happy = $approvals->submit($happy, $sales, $happy->lock_version);
        $happy = $approvals->approve($happy, $approver, $happy->lock_version, null, true);
        $ops->transition($happy, $disposition, $happy->lock_version, DispoOrderStatus::InProgress);

        // B) AT-18: disposed, konkrete Periode, Rechnung-per-Ende fehlt
        $calcAt18 = $writer->create([
            'planning_mode' => 'manual',
            'customer_name' => 'BLP802D AT-18 Blocked GmbH',
            'order_discount_percent' => '0',
            'schema_fingerprint' => $schemaFp,
            'dynamic_field_values' => ['campaign_period' => null],
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
                'dynamic_field_values' => [
                    'period_open' => false,
                    'position_flight_period' => [
                        'start' => '2026-04-01',
                        'end' => '2026-04-30',
                    ],
                ],
            ]],
        ], $sales);

        $blocked = $dispoWriter->createFromCalculation(
            $calcAt18,
            array_values($calcAt18->positions()->pluck('id')->all()),
            $sales,
        )->order;
        $blocked = $confirmations->update(
            $blocked,
            $sales,
            $blocked->lock_version,
            true,
            'Kundenfreigabe liegt per E-Mail vor; Upload wird nachgereicht.',
        );
        $blocked = $approvals->submit($blocked, $sales, $blocked->lock_version);
        $blocked = $approvals->approve($blocked, $approver, $blocked->lock_version, null, true);
        $blocked = $ops->transition($blocked, $disposition, $blocked->lock_version, DispoOrderStatus::InProgress);
        $ops->transition($blocked, $disposition, $blocked->lock_version, DispoOrderStatus::Disposed);

        // C) Admin-Override: disposed, konkrete Periode, Rechnung-per-Ende fehlt
        $calcOverride = $writer->create([
            'planning_mode' => 'manual',
            'customer_name' => 'BLP802D Override GmbH',
            'order_discount_percent' => '0',
            'schema_fingerprint' => $schemaFp,
            'dynamic_field_values' => ['campaign_period' => null],
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
                'dynamic_field_values' => [
                    'period_open' => false,
                    'position_flight_period' => [
                        'start' => '2026-05-01',
                        'end' => '2026-05-31',
                    ],
                ],
            ]],
        ], $sales);

        $override = $dispoWriter->createFromCalculation(
            $calcOverride,
            array_values($calcOverride->positions()->pluck('id')->all()),
            $sales,
        )->order;
        $override = $confirmations->update(
            $override,
            $sales,
            $override->lock_version,
            true,
            'Kundenfreigabe liegt per E-Mail vor; Upload wird nachgereicht.',
        );
        $override = $approvals->submit($override, $sales, $override->lock_version);
        $override = $approvals->approve($override, $approver, $override->lock_version, null, true);
        $override = $ops->transition($override, $disposition, $override->lock_version, DispoOrderStatus::InProgress);
        $ops->transition($override, $disposition, $override->lock_version, DispoOrderStatus::Disposed);
    }
}
