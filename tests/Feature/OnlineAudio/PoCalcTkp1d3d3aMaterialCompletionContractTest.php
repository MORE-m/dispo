<?php

namespace Tests\Feature\OnlineAudio;

use App\Enums\DayGroup;
use App\Enums\DispoOrderStatus;
use App\Enums\DispoOrderUploadCategory;
use App\Enums\EngineCapabilityStatus;
use App\Enums\OaTargetingCategory;
use App\Enums\PriceListStatus;
use App\Enums\Role;
use App\Models\AdvertisingMedium;
use App\Models\AuditEvent;
use App\Models\DispoOrder;
use App\Models\DispoOrderPosition;
use App\Models\DispoOrderUpload;
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
use App\Services\DispoOrder\DispoOrderCompletionReadiness;
use App\Services\DispoOrder\DispoOrderOperationalFlightService;
use App\Services\DispoOrder\DispoOrderOperationalStatusService;
use App\Services\DispoOrder\DispoOrderWriter;
use App\Support\Calculation\EngineProfileRegistry;
use App\Support\DispoOrder\OperationalFlightContract;
use App\Support\PriceList\PriceListCalendar;
use Database\Seeders\InitialCatalogMatCoreSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\EnsuresCustomerConfirmationException;
use Tests\TestCase;

/**
 * PO-CALC-TKP-1D3d-3a: OA nutzt bestehenden Materialworkflow; keine Spotproduktion;
 * Completion ohne Material-Gate; Ops-Flight bleibt hart.
 */
class PoCalcTkp1d3d3aMaterialCompletionContractTest extends TestCase
{
    use EnsuresCustomerConfirmationException;
    use RefreshDatabase;

    #[Test]
    public function oa_writer_rejects_production_lines_and_handoff_has_no_production_role(): void
    {
        $ctx = $this->seedContext();

        try {
            app(CalculationWriter::class)->create($this->payload([
                $this->oaPosition($ctx, [
                    'production_lines' => [[
                        'production_type' => 'spot_production',
                        'quantity' => '1',
                    ]],
                ]),
            ]), $ctx['user']);
            $this->fail('OA mit production_lines muss fehlschlagen.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('positions.0.production_lines', $e->errors());
        }

        $calc = app(CalculationWriter::class)->create($this->payload([
            $this->oaPosition($ctx, [
                'length_seconds' => 0,
            ]),
        ]), $ctx['user']);
        $this->assertSame(0, (int) $calc->positions->firstOrFail()->length_seconds);
        $this->assertSame(0, $calc->positions->firstOrFail()->productionLines()->count());

        $order = app(DispoOrderWriter::class)
            ->createFromCalculation($calc, [(int) $calc->positions->firstOrFail()->id], $ctx['user'])
            ->order;

        $this->assertSame(
            0,
            $order->positions()->where('line_role', DispoOrderPosition::LINE_ROLE_PRODUCTION)->count(),
        );
        $this->assertSame(0, (int) $order->positions()->where('line_role', DispoOrderPosition::LINE_ROLE_MEDIA)->value('length_seconds'));
    }

    #[Test]
    public function oa_dispo_accepts_material_upload_without_status_or_snapshot_mutation(): void
    {
        Storage::fake((string) config('dispo.files_disk'));
        $ctx = $this->seedContext();
        ['order' => $order, 'disposition' => $disposition, 'position' => $position] = $this->orderInProgress($ctx);

        $order = app(DispoOrderOperationalStatusService::class)->transition(
            $order,
            $disposition,
            $order->lock_version,
            DispoOrderStatus::MaterialMissing,
        );

        $snapshotBefore = [
            'impressions' => (int) $position->impressions,
            'final_tkp' => (string) $position->final_tkp,
            'list_id' => $position->oa_tkp_price_list_id,
            'platforms' => $position->oa_platform_allocations_snapshot,
            'engine' => $position->engine_profile_key,
            'method' => $position->calculation_method_key,
            'ops_start' => $position->operational_flight_start,
            'ops_end' => $position->operational_flight_end,
        ];
        $statusBefore = $order->status;
        $lockBefore = $order->lock_version;

        $response = $this->actingAs($disposition)
            ->withHeader('Accept', 'application/json')
            ->post(route('dispo-orders.uploads.store', $order), [
                'lock_version' => $order->lock_version,
                'category' => DispoOrderUploadCategory::AudioMotif->value,
                'file' => $this->mp3File(),
            ]);
        $response->assertOk();

        $order->refresh();
        $position->refresh();
        $upload = DispoOrderUpload::query()->where('dispo_order_id', $order->id)->firstOrFail();

        $this->assertSame($statusBefore, $order->status);
        $this->assertSame(DispoOrderStatus::MaterialMissing, $order->status);
        $this->assertSame($lockBefore + 1, $order->lock_version);
        $this->assertNull($upload->dispo_order_position_id);
        $this->assertSame(DispoOrderUploadCategory::AudioMotif, $upload->category);
        $this->assertSame(
            1,
            AuditEvent::query()
                ->where('action', 'dispo_order.upload.created')
                ->where('auditable_type', DispoOrder::class)
                ->where('auditable_id', $order->id)
                ->count(),
        );

        $this->assertSame($snapshotBefore['impressions'], (int) $position->impressions);
        $this->assertSame($snapshotBefore['final_tkp'], (string) $position->final_tkp);
        $this->assertSame($snapshotBefore['list_id'], $position->oa_tkp_price_list_id);
        $this->assertSame($snapshotBefore['platforms'], $position->oa_platform_allocations_snapshot);
        $this->assertSame($snapshotBefore['engine'], $position->engine_profile_key);
        $this->assertSame($snapshotBefore['method'], $position->calculation_method_key);
        $this->assertNull($position->operational_flight_start);
        $this->assertNull($position->operational_flight_end);
    }

    #[Test]
    public function mixed_order_keeps_oa_without_production_and_order_level_material_status(): void
    {
        Storage::fake((string) config('dispo.files_disk'));
        $ctx = $this->seedContext();
        $spot = $this->createSpotFixture();
        $calc = app(CalculationWriter::class)->create($this->payload([
            $this->spotAveragePosition($spot),
            $this->oaPosition($ctx),
        ]), $ctx['user']);
        $ids = $calc->positions()->orderBy('sort')->pluck('id')->map(fn ($id) => (int) $id)->all();
        $order = app(DispoOrderWriter::class)->createFromCalculation($calc, $ids, $ctx['user'])->order->fresh();

        $this->assertSame(0, $order->positions()->where('line_role', DispoOrderPosition::LINE_ROLE_PRODUCTION)->count());
        $this->assertSame(2, $order->positions()->where('line_role', DispoOrderPosition::LINE_ROLE_MEDIA)->count());

        ['order' => $order, 'disposition' => $disposition] = $this->advanceToInProgress($order, $ctx['user']);

        try {
            app(DispoOrderOperationalStatusService::class)->transition(
                $order,
                $disposition,
                $order->lock_version,
                DispoOrderStatus::Disposed,
            );
            $this->fail('Mixed ohne OA-Ops-Flight muss disposed blockieren.');
        } catch (ValidationException $e) {
            $this->assertStringContainsString(
                OperationalFlightContract::LABEL,
                collect($e->errors())->flatten()->implode(' '),
            );
        }
        $this->assertSame(DispoOrderStatus::InProgress, $order->fresh()->status);

        $order = app(DispoOrderOperationalStatusService::class)->transition(
            $order->fresh(),
            $disposition,
            $order->fresh()->lock_version,
            DispoOrderStatus::MaterialMissing,
        );
        $this->assertSame(DispoOrderStatus::MaterialMissing, $order->status);

        $this->actingAs($disposition)
            ->withHeader('Accept', 'application/json')
            ->post(route('dispo-orders.uploads.store', $order), [
                'lock_version' => $order->lock_version,
                'category' => DispoOrderUploadCategory::Briefing->value,
                'file' => $this->briefingFile(),
            ])
            ->assertOk();

        $order->refresh();
        $this->assertSame(DispoOrderStatus::MaterialMissing, $order->status);
        $this->assertSame(1, DispoOrderUpload::query()->where('dispo_order_id', $order->id)->count());
        $this->assertTrue(
            DispoOrderUpload::query()
                ->where('dispo_order_id', $order->id)
                ->whereNull('dispo_order_position_id')
                ->exists(),
        );

        $oa = $order->positions()
            ->where('line_role', DispoOrderPosition::LINE_ROLE_MEDIA)
            ->where('engine_profile_key', 'oa_tkp')
            ->firstOrFail();
        app(DispoOrderOperationalFlightService::class)->update(
            $order->fresh(),
            $oa,
            $disposition,
            $order->fresh()->lock_version,
            '2026-05-01',
            '2026-05-31',
        );

        // Flight erfüllt nur die Flight-Voraussetzung – Status bleibt material_missing
        // (kein Auto-Disposed; material_missing → disposed weiterhin verboten).
        $this->assertSame(DispoOrderStatus::MaterialMissing, $order->fresh()->status);
        $this->assertTrue(OperationalFlightContract::positionHasValidFlight($oa->fresh()));
    }

    #[Test]
    public function completion_matrix_has_six_checks_without_material_gate(): void
    {
        $ctx = $this->seedContext();
        ['order' => $order, 'disposition' => $disposition, 'position' => $position] = $this->orderInProgress($ctx);

        app(DispoOrderOperationalFlightService::class)->update(
            $order,
            $position,
            $disposition,
            $order->lock_version,
            '2026-05-01',
            '2026-05-31',
        );
        $order = app(DispoOrderOperationalStatusService::class)->transition(
            $order->fresh(),
            $disposition,
            $order->fresh()->lock_version,
            DispoOrderStatus::Disposed,
        );

        $evaluation = app(DispoOrderCompletionReadiness::class)->evaluate($order->fresh());
        $keys = array_column($evaluation['checks'], 'key');

        $this->assertSame([
            DispoOrderCompletionReadiness::CHECK_REQUIRED_FIELDS,
            DispoOrderCompletionReadiness::CHECK_OPEN_SALES_INQUIRY,
            DispoOrderCompletionReadiness::CHECK_INVOICE_END_MONTHS,
            DispoOrderCompletionReadiness::CHECK_OA_OPERATIONAL_FLIGHT,
            DispoOrderCompletionReadiness::CHECK_CUSTOMER_CONFIRMATION,
            DispoOrderCompletionReadiness::CHECK_APPROVALS,
        ], $keys);
        $this->assertCount(6, $keys);
        $this->assertNotContains('material', $keys);
        $this->assertNotContains('material_received', $keys);
        $this->assertNotContains('uploads', $keys);
        $this->assertNotContains('production', $keys);

        $flightCheck = collect($evaluation['checks'])
            ->firstWhere('key', DispoOrderCompletionReadiness::CHECK_OA_OPERATIONAL_FLIGHT);
        $this->assertTrue($flightCheck['passed']);
    }

    #[Test]
    public function force_complete_still_cannot_bypass_missing_oa_flight(): void
    {
        $ctx = $this->seedContext();
        ['order' => $order, 'disposition' => $disposition, 'position' => $position] = $this->orderInProgress($ctx);
        app(DispoOrderOperationalFlightService::class)->update(
            $order,
            $position,
            $disposition,
            $order->lock_version,
            '2026-05-01',
            '2026-05-31',
        );
        $order = app(DispoOrderOperationalStatusService::class)->transition(
            $order->fresh(),
            $disposition,
            $order->fresh()->lock_version,
            DispoOrderStatus::Disposed,
        );

        DB::table('dispo_order_positions')->where('id', $position->id)->update([
            'operational_flight_start' => null,
            'operational_flight_end' => null,
        ]);

        $admin = User::factory()->role(Role::Admin)->create();
        $this->actingAs($admin)->postJson(route('dispo-orders.complete', $order->fresh()), [
            'lock_version' => $order->fresh()->lock_version,
            'override_reason' => 'Admin versucht Material/Flight zu umgehen.',
        ])->assertUnprocessable();

        $this->assertSame(DispoOrderStatus::Disposed, $order->fresh()->status);
        $this->assertSame(0, AuditEvent::query()->where('action', 'dispo_order.completed')->count());
    }

    #[Test]
    public function release_protection_remains_planned(): void
    {
        $this->assertSame(
            EngineCapabilityStatus::Planned,
            EngineProfileRegistry::pairStatus('oa_tkp', 'tkp'),
        );
        $this->assertNull(EngineProfileRegistry::currentReleasedVersion('oa_tkp', 'tkp'));
    }

    private function mp3File(string $name = 'oa-motif.mp3'): UploadedFile
    {
        return new UploadedFile(
            base_path('tests/fixtures/audio-motif-sample.mp3'),
            $name,
            'audio/mpeg',
            null,
            true,
        );
    }

    private function briefingFile(string $name = 'oa-briefing.pdf'): UploadedFile
    {
        return new UploadedFile(
            base_path('tests/fixtures/customer-confirmation-sample.pdf'),
            $name,
            'application/pdf',
            null,
            true,
        );
    }

    /**
     * @return array{order: DispoOrder, disposition: User, position: DispoOrderPosition}
     */
    private function orderInProgress(array $ctx): array
    {
        $calc = app(CalculationWriter::class)->create($this->payload([
            $this->oaPosition($ctx),
        ]), $ctx['user']);
        $order = app(DispoOrderWriter::class)
            ->createFromCalculation($calc, [(int) $calc->positions->firstOrFail()->id], $ctx['user'])
            ->order
            ->fresh();

        return $this->advanceToInProgress($order, $ctx['user']);
    }

    /**
     * @return array{order: DispoOrder, disposition: User, position: DispoOrderPosition}
     */
    private function advanceToInProgress(DispoOrder $order, User $creator): array
    {
        $approver = User::factory()->role(Role::Sales)->create();
        $disposition = User::factory()->role(Role::Disposition)->create();
        $order = $this->seedCustomerConfirmationException($order, $creator);
        $approvals = app(DispoOrderApprovalService::class);
        $submitted = $approvals->submit($order, $creator, $order->lock_version);
        $order = $approvals->approve($submitted, $approver, $submitted->lock_version, null, true);
        $order = app(DispoOrderOperationalStatusService::class)->transition(
            $order,
            $disposition,
            $order->lock_version,
            DispoOrderStatus::InProgress,
        );

        return [
            'order' => $order,
            'disposition' => $disposition,
            'position' => $order->positions()
                ->where('line_role', DispoOrderPosition::LINE_ROLE_MEDIA)
                ->where('engine_profile_key', 'oa_tkp')
                ->firstOrFail(),
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $positions
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function payload(array $positions, array $overrides = []): array
    {
        return $this->withLiveSchemaFingerprint(array_merge([
            'planning_mode' => 'manual',
            'order_discount_percent' => '0',
            'ae_enabled' => false,
            'dynamic_field_values' => ['campaign_period' => null],
            'positions' => $positions,
        ], $overrides));
    }

    /**
     * @param  array<string, mixed>  $ctx
     * @param  array<string, mixed>  $override
     * @return array<string, mixed>
     */
    private function oaPosition(array $ctx, array $override = []): array
    {
        return array_merge([
            'inventory_id' => (int) $ctx['inventory']->id,
            'advertising_medium_id' => (int) $ctx['medium']->id,
            'calculation_method_key' => 'tkp',
            'impressions' => 100_000,
            'basis_tkp' => '10.0000',
            'oa_platform_allocations' => [
                ['oa_platform_id' => (int) $ctx['platform']->id, 'impressions' => 100_000],
            ],
            'oa_targeting_selections' => [],
            'position_discount_percent' => '0',
            'ae_percent' => '15',
        ], $override);
    }

    /**
     * @return array{medium: AdvertisingMedium, inventory: Inventory}
     */
    private function createSpotFixture(): array
    {
        $medium = AdvertisingMedium::query()->where('code', 'spot_classic')->firstOrFail();
        $inventory = Inventory::factory()->create(['name' => 'D3d3a Spot', 'code' => 'D3D3ASPOT']);
        InventoryMediumRule::factory()->create([
            'inventory_id' => $inventory->id,
            'advertising_medium_id' => $medium->id,
        ]);
        $list = PriceList::factory()->create([
            'inventory_id' => $inventory->id,
            'status' => PriceListStatus::Active,
            'year' => PriceListCalendar::currentYear(),
            'version' => 'D3D3A-SPOT',
            'valid_from' => now()->toDateString(),
        ]);
        foreach (range(0, 23) as $hour) {
            foreach ([DayGroup::MoFr, DayGroup::Sa, DayGroup::So] as $group) {
                PriceListItem::factory()->create([
                    'price_list_id' => $list->id,
                    'hour' => $hour,
                    'day_group' => $group,
                    'second_price' => '1.0000',
                ]);
            }
        }

        return compact('medium', 'inventory');
    }

    /**
     * @param  array{medium: AdvertisingMedium, inventory: Inventory}  $spot
     * @return array<string, mixed>
     */
    private function spotAveragePosition(array $spot): array
    {
        return [
            'inventory_id' => $spot['inventory']->id,
            'advertising_medium_id' => $spot['medium']->id,
            'spot_method' => 'average',
            'length_seconds' => 30,
            'total_spot_count' => 10,
            'position_discount_percent' => '0',
            'ae_percent' => '15',
            'plan_rows' => [['hour' => 8, 'day_group' => 'mo_fr']],
            'dynamic_field_values' => ['period_open' => true],
        ];
    }

    /**
     * @return array{
     *     user: User,
     *     medium: AdvertisingMedium,
     *     inventory: Inventory,
     *     platform: OaPlatform,
     *     targeting: OaTargetingDefinition,
     *     list: OaTkpPriceList
     * }
     */
    private function seedContext(): array
    {
        $this->seed(InitialCatalogMatCoreSeeder::class);
        $user = User::factory()->role(Role::Sales)->create();
        $medium = AdvertisingMedium::query()->where('code', 'pre_stream')->firstOrFail();
        $inventory = Inventory::query()->where('code', 'inv_more_kombi_online_audio')->firstOrFail();
        $list = OaTkpPriceList::query()->create([
            'year' => PriceListCalendar::currentYear(),
            'name' => 'Fixture D3d3a Material',
            'version' => PriceListCalendar::currentYear().'.3a',
            'revision_number' => 1,
            'lock_version' => 1,
            'status' => PriceListStatus::Active,
            'published_at' => now(),
        ]);
        OaTkpPriceListItem::query()->create([
            'oa_tkp_price_list_id' => $list->id,
            'advertising_medium_id' => (int) $medium->id,
            'inventory_id' => (int) $inventory->id,
            'list_tkp' => '12.0000',
            'minimum_tkp' => '8.0000',
        ]);
        $platform = OaPlatform::query()->create([
            'code' => 'fixture_d3d3a_plat',
            'name' => 'Fixture D3d3a Platform',
            'is_active' => true,
            'sort' => 1,
            'is_adserver_relevant' => false,
        ]);
        $targeting = OaTargetingDefinition::query()->create([
            'code' => 'geo_d3d3a',
            'name' => 'Geo D3d3a',
            'category' => OaTargetingCategory::Technisch,
            'tkp_surcharge' => '2.0000',
            'revision_number' => 1,
            'is_active' => true,
        ]);

        return compact('user', 'medium', 'inventory', 'platform', 'targeting', 'list');
    }
}
