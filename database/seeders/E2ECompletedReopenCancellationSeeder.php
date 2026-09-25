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
use App\Services\DispoOrder\DispoOrderCompletionService;
use App\Services\DispoOrder\DispoOrderCustomerConfirmationService;
use App\Services\DispoOrder\DispoOrderInvoiceEndService;
use App\Services\DispoOrder\DispoOrderOperationalStatusService;
use App\Services\DispoOrder\DispoOrderWriter;
use App\Services\DynamicField\ConfigurationSnapshotFreezeService;
use App\Support\Advertising\CanonicalAdvertisingCategories;
use App\Support\E2E\E2EIsolatedEnvironmentGuard;
use App\Support\PriceList\PriceListCalendar;
use Illuminate\Database\Seeder;

/**
 * E2E-Seeder BL-P8-02e Completed-Reopen + Storno (Port 8039).
 */
class E2ECompletedReopenCancellationSeeder extends Seeder
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

        $organization = Organization::factory()->create(['name' => 'E2E BL-P8-02e']);
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
            'code' => 'RH802E',
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
            'version' => 'e2e-blp802e-'.$inventory->code,
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

        $ctx = compact('sales', 'approver', 'disposition', 'inventory', 'classic', 'schemaFp', 'classicFp');

        // A) Completed – für Admin-Wiederöffnung
        $this->completeOrder(
            $this->createApprovedInProgressOrder(
                'BLP802E Completed Reopen GmbH',
                '2026-03-01',
                '2026-03-31',
                $ctx,
            ),
            $disposition,
        );

        // B) Completed – frisch für AT-19 Storno
        $this->completeOrder(
            $this->createApprovedInProgressOrder(
                'BLP802E AT19 Cancel GmbH',
                '2026-04-01',
                '2026-04-30',
                $ctx,
            ),
            $disposition,
        );

        // C) Disposed – Storno aus Disponiert
        $orderC = $this->createApprovedInProgressOrder(
            'BLP802E Disposed Cancel GmbH',
            '2026-05-01',
            '2026-05-31',
            $ctx,
        );
        $this->disposeOrder($orderC, $disposition);

        // D) Completed – Sales/PM Negativ (kein Storno)
        $this->completeOrder(
            $this->createApprovedInProgressOrder(
                'BLP802E Sales Deny GmbH',
                '2026-06-01',
                '2026-06-30',
                $ctx,
            ),
            $disposition,
        );

        // E) Completed – Management sieht Reopen + Storno
        $this->completeOrder(
            $this->createApprovedInProgressOrder(
                'BLP802E Management GmbH',
                '2026-07-01',
                '2026-07-31',
                $ctx,
            ),
            $disposition,
        );
    }

    /**
     * @param  array{
     *     sales: User,
     *     approver: User,
     *     disposition: User,
     *     inventory: Inventory,
     *     classic: AdvertisingMedium,
     *     schemaFp: string,
     *     classicFp: string
     * }  $ctx
     */
    private function createApprovedInProgressOrder(
        string $customerName,
        string $periodStart,
        string $periodEnd,
        array $ctx,
    ): DispoOrder {
        $writer = app(CalculationWriter::class);
        $approvals = app(DispoOrderApprovalService::class);
        $confirmations = app(DispoOrderCustomerConfirmationService::class);
        $ops = app(DispoOrderOperationalStatusService::class);
        $dispoWriter = app(DispoOrderWriter::class);

        $calculation = $writer->create([
            'planning_mode' => 'manual',
            'customer_name' => $customerName,
            'order_discount_percent' => '0',
            'schema_fingerprint' => $ctx['schemaFp'],
            'dynamic_field_values' => ['campaign_period' => null],
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
                'dynamic_field_values' => [
                    'period_open' => false,
                    'position_flight_period' => [
                        'start' => $periodStart,
                        'end' => $periodEnd,
                    ],
                ],
            ]],
        ], $ctx['sales']);

        $order = $dispoWriter->createFromCalculation(
            $calculation,
            array_values($calculation->positions()->pluck('id')->all()),
            $ctx['sales'],
        )->order;

        $order = $confirmations->update(
            $order,
            $ctx['sales'],
            $order->lock_version,
            true,
            'Kundenfreigabe liegt per E-Mail vor; Upload wird nachgereicht.',
        );
        $order = $approvals->submit($order, $ctx['sales'], $order->lock_version);
        $order = $approvals->approve($order, $ctx['approver'], $order->lock_version, null, true);

        return $ops->transition($order, $ctx['disposition'], $order->lock_version, DispoOrderStatus::InProgress);
    }

    private function fillInvoiceMonthsAndDispose(DispoOrder $order, User $disposition): DispoOrder
    {
        $position = $order->positions()->firstOrFail();
        $invoiceEnd = app(DispoOrderInvoiceEndService::class);
        $order = $invoiceEnd->update(
            $order,
            $position,
            $disposition,
            $order->lock_version,
            [3],
        );

        return app(DispoOrderOperationalStatusService::class)->transition(
            $order,
            $disposition,
            $order->lock_version,
            DispoOrderStatus::Disposed,
        );
    }

    private function disposeOrder(DispoOrder $order, User $disposition): DispoOrder
    {
        return $this->fillInvoiceMonthsAndDispose($order, $disposition);
    }

    private function completeOrder(DispoOrder $order, User $disposition): DispoOrder
    {
        $order = $this->fillInvoiceMonthsAndDispose($order, $disposition);

        return app(DispoOrderCompletionService::class)->complete(
            $order,
            $disposition,
            $order->lock_version,
        );
    }
}
