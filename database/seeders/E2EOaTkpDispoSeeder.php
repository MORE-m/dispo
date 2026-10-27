<?php

namespace Database\Seeders;

use App\Enums\DayGroup;
use App\Enums\DispoOrderStatus;
use App\Enums\OaTargetingCategory;
use App\Enums\PriceListStatus;
use App\Enums\Role;
use App\Models\AdvertisingMedium;
use App\Models\Calculation;
use App\Models\DispoOrder;
use App\Models\Inventory;
use App\Models\InventoryMediumRule;
use App\Models\OaPlatform;
use App\Models\OaTargetingDefinition;
use App\Models\OaTkpPriceList;
use App\Models\OaTkpPriceListItem;
use App\Models\PriceList;
use App\Models\PriceListItem;
use App\Models\User;
use App\Services\Calculation\CalculationWriter;
use App\Services\DispoOrder\DispoOrderApprovalService;
use App\Services\DispoOrder\DispoOrderCustomerConfirmationService;
use App\Services\DispoOrder\DispoOrderOperationalStatusService;
use App\Services\DispoOrder\DispoOrderWriter;
use App\Services\DynamicField\ConfigurationSnapshotFreezeService;
use App\Support\E2E\E2EIsolatedEnvironmentGuard;
use App\Support\PriceList\PriceListCalendar;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\File;

/**
 * E2E-Seeder PO-CALC-TKP-1D3d-4 OA Playwright Smoke (Port 8073).
 *
 * Persistierte OA-TKP-Daten ausschließlich über CalculationWriter (kein HTTP-Bypass,
 * keine Registry-Manipulation). Fail-closed: nur APP_ENV=testing + E2E_SERVER=1.
 */
class E2EOaTkpDispoSeeder extends Seeder
{
    public const string FIXTURE_PATH = 'e2e-po-calc-tkp-d3d4-orders.json';

    public const string PLATFORM_CODE = 'e2e_d3d4_plat';

    public const int OA_IMPRESSIONS = 100_000;

    public const string OA_BASIS_TKP = '10.0000';

    public const string OA_LIST_TKP = '12.0000';

    public const string OA_MIN_TKP = '8.0000';

    public const string CALC_FLIGHT_START = '2026-04-01';

    public const string CALC_FLIGHT_END = '2026-04-30';

    public function run(): void
    {
        E2EIsolatedEnvironmentGuard::assertSafeForE2ESeeding();

        foreach ([
            ['email' => 'sales@example.com', 'name' => 'E2E Vertrieb', 'role' => Role::Sales],
            ['email' => 'sales-b@example.com', 'name' => 'E2E Vertrieb B', 'role' => Role::Sales],
            ['email' => 'disposition@example.com', 'name' => 'E2E Disposition', 'role' => Role::Disposition],
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

        $this->call(InitialCatalogMatCoreSeeder::class);

        $sales = User::query()->where('email', 'sales@example.com')->firstOrFail();
        $approver = User::query()->where('email', 'sales-b@example.com')->firstOrFail();
        $disposition = User::query()->where('email', 'disposition@example.com')->firstOrFail();

        $medium = AdvertisingMedium::query()->where('code', 'pre_stream')->firstOrFail();
        $inventory = Inventory::query()->where('code', 'inv_more_kombi_online_audio')->firstOrFail();
        $spotMedium = AdvertisingMedium::query()->where('code', 'spot_classic')->firstOrFail();

        $list = OaTkpPriceList::query()->create([
            'year' => PriceListCalendar::currentYear(),
            'name' => 'E2E D3d-4 OA TKP',
            'version' => PriceListCalendar::currentYear().'.d3d4',
            'revision_number' => 1,
            'lock_version' => 1,
            'status' => PriceListStatus::Active,
            'published_at' => now(),
        ]);
        OaTkpPriceListItem::query()->create([
            'oa_tkp_price_list_id' => $list->id,
            'advertising_medium_id' => (int) $medium->id,
            'inventory_id' => (int) $inventory->id,
            'list_tkp' => self::OA_LIST_TKP,
            'minimum_tkp' => self::OA_MIN_TKP,
        ]);

        $platform = OaPlatform::query()->create([
            'code' => self::PLATFORM_CODE,
            'name' => 'E2E D3d4 Platform',
            'is_active' => true,
            'sort' => 1,
            'is_adserver_relevant' => false,
        ]);
        OaTargetingDefinition::query()->create([
            'code' => 'geo_e2e_d3d4',
            'name' => 'Geo E2E D3d4',
            'category' => OaTargetingCategory::Technisch,
            'tkp_surcharge' => '0.0000',
            'revision_number' => 1,
            'is_active' => true,
        ]);

        $spotInventory = Inventory::factory()->create([
            'name' => 'E2E D3d4 Spot Radio',
            'code' => 'E2ED3D4SPOT',
            'sort' => 90,
            'logo_path' => null,
        ]);
        InventoryMediumRule::factory()->create([
            'inventory_id' => $spotInventory->id,
            'advertising_medium_id' => $spotMedium->id,
            'default_length_seconds' => 30,
            'surcharge_percent' => '0',
        ]);
        $spotList = PriceList::factory()->create([
            'inventory_id' => $spotInventory->id,
            'status' => PriceListStatus::Active,
            'year' => PriceListCalendar::currentYear(),
            'version' => 'e2e-d3d4-spot',
            'valid_from' => now()->toDateString(),
        ]);
        foreach (range(0, 23) as $hour) {
            foreach ([DayGroup::MoFr, DayGroup::Sa, DayGroup::So] as $group) {
                PriceListItem::factory()->create([
                    'price_list_id' => $spotList->id,
                    'hour' => $hour,
                    'day_group' => $group,
                    'second_price' => '1.0000',
                ]);
            }
        }

        $freeze = app(ConfigurationSnapshotFreezeService::class);
        $schemaFp = $freeze->resolveLiveSchemaForCalculationV3()['schema_fingerprint'];
        $oaFp = $freeze->resolveLivePositionSchema((int) $medium->id)['schema_fingerprint'];
        $spotFp = $freeze->resolveLivePositionSchema((int) $spotMedium->id)['schema_fingerprint'];

        $ctx = [
            'sales' => $sales,
            'approver' => $approver,
            'disposition' => $disposition,
            'medium' => $medium,
            'inventory' => $inventory,
            'platform' => $platform,
            'spotMedium' => $spotMedium,
            'spotInventory' => $spotInventory,
            'schemaFp' => $schemaFp,
            'oaFp' => $oaFp,
            'spotFp' => $spotFp,
        ];

        $handoffCalc = $this->createOaCalculation('E2E D3d4 Handoff GmbH', $ctx);
        $oaInProgress = $this->createOaOrderAtInProgress('E2E D3d4 OA Flight GmbH', $ctx);
        $mixedInProgress = $this->createMixedOrderAtInProgress('E2E D3d4 Mixed GmbH', $ctx);
        $materialOa = $this->createOaOrderAtInProgress('E2E D3d4 Material GmbH', $ctx);

        $oaPosition = $oaInProgress->positions()->orderBy('sort')->firstOrFail();
        $mixedOa = $mixedInProgress->positions()
            ->where('engine_profile_key', 'oa_tkp')
            ->firstOrFail();
        $mixedSpot = $mixedInProgress->positions()
            ->where('engine_profile_key', 'spot_classic')
            ->firstOrFail();
        $materialPosition = $materialOa->positions()->orderBy('sort')->firstOrFail();

        $fixture = [
            'handoffCalc' => [
                'id' => (int) $handoffCalc->id,
                'number' => (string) $handoffCalc->number,
            ],
            'oaInProgress' => [
                'id' => (int) $oaInProgress->id,
                'number' => (string) $oaInProgress->number,
                'positionId' => (int) $oaPosition->id,
                'finalTkp' => (string) $oaPosition->final_tkp,
                'impressions' => (int) $oaPosition->impressions,
                'calcFlightStart' => self::CALC_FLIGHT_START,
                'calcFlightEnd' => self::CALC_FLIGHT_END,
            ],
            'mixedInProgress' => [
                'id' => (int) $mixedInProgress->id,
                'number' => (string) $mixedInProgress->number,
                'oaPositionId' => (int) $mixedOa->id,
                'spotPositionId' => (int) $mixedSpot->id,
            ],
            'materialOa' => [
                'id' => (int) $materialOa->id,
                'number' => (string) $materialOa->number,
                'positionId' => (int) $materialPosition->id,
                'status' => $materialOa->status->value,
                'finalTkp' => (string) $materialPosition->final_tkp,
            ],
            'httpStoreProbe' => [
                'inventory_id' => (int) $inventory->id,
                'advertising_medium_id' => (int) $medium->id,
                'oa_platform_id' => (int) $platform->id,
                'schema_fingerprint' => $schemaFp,
                'position_schema_fingerprint' => $oaFp,
            ],
            'release' => [
                'engine_profile_key' => 'oa_tkp',
                'calculation_method_key' => 'tkp',
                'pair_status' => 'planned',
                'current_released_version' => null,
            ],
        ];

        File::put(
            database_path(self::FIXTURE_PATH),
            json_encode($fixture, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR),
        );
    }

    /**
     * @param  array<string, mixed>  $ctx
     */
    private function createOaCalculation(string $customerName, array $ctx): Calculation
    {
        return app(CalculationWriter::class)->create(
            $this->payload([$this->oaPosition($ctx)], $customerName, $ctx),
            $ctx['sales'],
        );
    }

    /**
     * @param  array<string, mixed>  $ctx
     */
    private function createOaOrderAtInProgress(string $customerName, array $ctx): DispoOrder
    {
        $calc = $this->createOaCalculation($customerName, $ctx);
        $oaId = (int) $calc->positions->firstOrFail()->id;
        $order = app(DispoOrderWriter::class)
            ->createFromCalculation($calc, [$oaId], $ctx['sales'])
            ->order
            ->fresh();

        return $this->advanceToInProgress($order, $ctx);
    }

    /**
     * @param  array<string, mixed>  $ctx
     */
    private function createMixedOrderAtInProgress(string $customerName, array $ctx): DispoOrder
    {
        $calc = app(CalculationWriter::class)->create(
            $this->payload([
                $this->spotAveragePosition($ctx),
                $this->oaPosition($ctx),
            ], $customerName, $ctx),
            $ctx['sales'],
        );
        /** @var list<int> $ids */
        $ids = $calc->positions()->orderBy('sort')->pluck('id')->map(fn ($id) => (int) $id)->all();
        $order = app(DispoOrderWriter::class)
            ->createFromCalculation($calc, $ids, $ctx['sales'])
            ->order
            ->fresh();

        return $this->advanceToInProgress($order, $ctx);
    }

    /**
     * @param  array<string, mixed>  $ctx
     */
    private function advanceToInProgress(DispoOrder $order, array $ctx): DispoOrder
    {
        $confirmations = app(DispoOrderCustomerConfirmationService::class);
        $approvals = app(DispoOrderApprovalService::class);
        $ops = app(DispoOrderOperationalStatusService::class);

        $order = $confirmations->update(
            $order,
            $ctx['sales'],
            $order->lock_version,
            true,
            'E2E D3d-4 Kundenfreigabe-Ausnahme (isolierter Smoke).',
        );
        $submitted = $approvals->submit($order, $ctx['sales'], $order->lock_version);
        $order = $approvals->approve($submitted, $ctx['approver'], $submitted->lock_version, null, true);

        return $ops->transition(
            $order,
            $ctx['disposition'],
            $order->lock_version,
            DispoOrderStatus::InProgress,
        );
    }

    /**
     * @param  list<array<string, mixed>>  $positions
     * @param  array<string, mixed>  $ctx
     * @return array<string, mixed>
     */
    private function payload(array $positions, string $customerName, array $ctx): array
    {
        return [
            'planning_mode' => 'manual',
            'customer_name' => $customerName,
            'order_discount_percent' => '0',
            'ae_enabled' => false,
            'schema_fingerprint' => $ctx['schemaFp'],
            'dynamic_field_values' => ['campaign_period' => null],
            'positions' => $positions,
        ];
    }

    /**
     * @param  array<string, mixed>  $ctx
     * @return array<string, mixed>
     */
    private function oaPosition(array $ctx): array
    {
        return [
            'inventory_id' => (int) $ctx['inventory']->id,
            'advertising_medium_id' => (int) $ctx['medium']->id,
            'schema_fingerprint' => $ctx['oaFp'],
            'calculation_method_key' => 'tkp',
            'impressions' => self::OA_IMPRESSIONS,
            'basis_tkp' => self::OA_BASIS_TKP,
            'oa_platform_allocations' => [
                [
                    'oa_platform_id' => (int) $ctx['platform']->id,
                    'impressions' => self::OA_IMPRESSIONS,
                ],
            ],
            'oa_targeting_selections' => [],
            'position_discount_percent' => '0',
            'ae_percent' => '15',
            'dynamic_field_values' => [
                'period_open' => false,
                'position_flight_period' => [
                    'start' => self::CALC_FLIGHT_START,
                    'end' => self::CALC_FLIGHT_END,
                ],
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $ctx
     * @return array<string, mixed>
     */
    private function spotAveragePosition(array $ctx): array
    {
        return [
            'inventory_id' => (int) $ctx['spotInventory']->id,
            'advertising_medium_id' => (int) $ctx['spotMedium']->id,
            'schema_fingerprint' => $ctx['spotFp'],
            'spot_method' => 'average',
            'length_seconds' => 30,
            'total_spot_count' => 10,
            'position_discount_percent' => '0',
            'ae_percent' => '15',
            'plan_rows' => [['hour' => 8, 'day_group' => 'mo_fr']],
            'dynamic_field_values' => ['period_open' => true],
        ];
    }
}
