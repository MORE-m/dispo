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
 * E2E-Seeder BL-P8-02b Rückfrage Vertrieb (Port 8036).
 */
class E2ESalesInquirySeeder extends Seeder
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

        $organization = Organization::factory()->create(['name' => 'E2E BL-P8-02b']);
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
            'code' => 'RH802B',
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
            'version' => 'e2e-blp802b-'.$inventory->code,
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
        $calc = $writer->create([
            'planning_mode' => 'manual',
            'customer_name' => 'BLP802B Rückfrage GmbH',
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

        /** @var list<int> $positionIds */
        $positionIds = array_values($calc->positions()->pluck('id')->all());
        $order = app(DispoOrderWriter::class)
            ->createFromCalculation($calc, $positionIds, $sales)
            ->order;

        $approvals = app(DispoOrderApprovalService::class);
        $confirmations = app(DispoOrderCustomerConfirmationService::class);
        $order = $confirmations->update(
            $order,
            $sales,
            $order->lock_version,
            true,
            'Kundenfreigabe liegt per E-Mail vor; Upload wird nachgereicht.',
        );
        $submitted = $approvals->submit($order, $sales, $order->lock_version);
        $approved = $approvals->approve($submitted, $approver, $submitted->lock_version, null, true);
        app(DispoOrderOperationalStatusService::class)->transition(
            $approved,
            $disposition,
            $approved->lock_version,
            DispoOrderStatus::InProgress,
        );
    }
}
