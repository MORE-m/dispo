<?php

namespace Database\Seeders;

use App\Enums\CalculationKind;
use App\Enums\DayGroup;
use App\Enums\DispoOrderStatus;
use App\Enums\PriceListStatus;
use App\Enums\Role;
use App\Models\AdvertisingCategory;
use App\Models\AdvertisingMedium;
use App\Models\DispoOrder;
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
use Illuminate\Support\Facades\File;

/**
 * E2E-Seeder BL-P9-01b Material- + Audio-Uploads (Port 8041).
 */
class E2EMaterialAudioUploadSeeder extends Seeder
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

        $organization = Organization::factory()->create(['name' => 'E2E BL-P9-01b']);
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
            'code' => 'RH901B',
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
            'version' => 'e2e-blp901b-'.$inventory->code,
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
        $admin = User::query()->where('email', 'admin@example.com')->firstOrFail();

        $freeze = app(ConfigurationSnapshotFreezeService::class);
        $schemaFp = $freeze->resolveLiveSchemaForCalculationV3()['schema_fingerprint'];
        $classicFp = $freeze->resolveLivePositionSchema((int) $classic->id)['schema_fingerprint'];

        $ctx = compact(
            'sales',
            'approver',
            'disposition',
            'admin',
            'inventory',
            'classic',
            'schemaFp',
            'classicFp',
        );

        $orders = [];

        $draft = $this->createDraftOrder('BLP901B Draft Material GmbH', $ctx);
        $orders['draft'] = ['id' => $draft->id, 'number' => $draft->number];

        $materialMissing = $this->createApprovedOrder('BLP901B Material Missing GmbH', $ctx);
        $ops = app(DispoOrderOperationalStatusService::class);
        $materialMissing = $ops->transition(
            $materialMissing,
            $disposition,
            $materialMissing->lock_version,
            DispoOrderStatus::InProgress,
        );
        $materialMissing = $ops->transition(
            $materialMissing,
            $disposition,
            $materialMissing->lock_version,
            DispoOrderStatus::MaterialMissing,
        );
        $orders['materialMissing'] = [
            'id' => $materialMissing->id,
            'number' => $materialMissing->number,
        ];

        $audioMulti = $this->createApprovedOrder('BLP901B Audio Multi GmbH', $ctx);
        $audioMulti = $ops->transition(
            $audioMulti,
            $disposition,
            $audioMulti->lock_version,
            DispoOrderStatus::InProgress,
        );
        $orders['audioMulti'] = ['id' => $audioMulti->id, 'number' => $audioMulti->number];

        $playback = $this->createApprovedOrder('BLP901B Playback GmbH', $ctx);
        $playback = $ops->transition(
            $playback,
            $disposition,
            $playback->lock_version,
            DispoOrderStatus::InProgress,
        );
        $orders['playback'] = ['id' => $playback->id, 'number' => $playback->number];

        $archive = $this->createApprovedOrder('BLP901B Archive GmbH', $ctx);
        $archive = $ops->transition(
            $archive,
            $disposition,
            $archive->lock_version,
            DispoOrderStatus::InProgress,
        );
        $orders['archive'] = ['id' => $archive->id, 'number' => $archive->number];

        $disposed = $this->createApprovedOrder('BLP901B Disposed GmbH', $ctx);
        $disposed = $ops->transition(
            $disposed,
            $disposition,
            $disposed->lock_version,
            DispoOrderStatus::InProgress,
        );
        $disposed = $ops->transition(
            $disposed,
            $disposition,
            $disposed->lock_version,
            DispoOrderStatus::Disposed,
        );
        $orders['disposed'] = ['id' => $disposed->id, 'number' => $disposed->number];

        $reopened = $this->createApprovedOrder('BLP901B Reopened GmbH', $ctx);
        $reopened = $ops->transition(
            $reopened,
            $disposition,
            $reopened->lock_version,
            DispoOrderStatus::InProgress,
        );
        $reopened = $ops->transition(
            $reopened,
            $disposition,
            $reopened->lock_version,
            DispoOrderStatus::Disposed,
        );
        $reopened = $ops->transition(
            $reopened,
            $admin,
            $reopened->lock_version,
            DispoOrderStatus::InProgress,
            'Material nachreichen nach Reopen',
        );
        $orders['reopenedInProgress'] = [
            'id' => $reopened->id,
            'number' => $reopened->number,
        ];

        File::put(
            database_path('e2e-bl-p9-01b-orders.json'),
            json_encode($orders, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR),
        );
    }

    /**
     * @param  array{
     *     sales: User,
     *     inventory: Inventory,
     *     classic: AdvertisingMedium,
     *     schemaFp: string,
     *     classicFp: string
     * }  $ctx
     */
    private function createDraftOrder(string $customerName, array $ctx): DispoOrder
    {
        $writer = app(CalculationWriter::class);
        $dispoWriter = app(DispoOrderWriter::class);

        $calc = $writer->create([
            'planning_mode' => 'manual',
            'customer_name' => $customerName,
            'order_discount_percent' => '0',
            'schema_fingerprint' => $ctx['schemaFp'],
            'positions' => [[
                'inventory_id' => $ctx['inventory']->id,
                'advertising_medium_id' => $ctx['classic']->id,
                'schema_fingerprint' => $ctx['classicFp'],
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
        ], $ctx['sales']);

        /** @var list<int> $positionIds */
        $positionIds = array_values($calc->positions()->pluck('id')->all());

        return $dispoWriter->createFromCalculation($calc, $positionIds, $ctx['sales'])->order;
    }

    /**
     * @param  array{
     *     sales: User,
     *     approver: User,
     *     inventory: Inventory,
     *     classic: AdvertisingMedium,
     *     schemaFp: string,
     *     classicFp: string
     * }  $ctx
     */
    private function createApprovedOrder(string $customerName, array $ctx): DispoOrder
    {
        $order = $this->createDraftOrder($customerName, $ctx);
        $confirmations = app(DispoOrderCustomerConfirmationService::class);
        $approvals = app(DispoOrderApprovalService::class);

        $order = $confirmations->update(
            $order,
            $ctx['sales'],
            $order->lock_version,
            true,
            'E2E Ausnahme für Materialupload-Suite',
        );
        $order = $approvals->submit($order, $ctx['sales'], $order->lock_version);

        return $approvals->approve($order, $ctx['approver'], $order->lock_version, null, true);
    }
}
