<?php

namespace Tests\Feature\OnlineAudio;

use App\Enums\DayGroup;
use App\Enums\EngineCapabilityStatus;
use App\Enums\OaTargetingCategory;
use App\Enums\PriceListStatus;
use App\Enums\Role;
use App\Models\AdvertisingMedium;
use App\Models\DispoOrder;
use App\Models\DispoOrderPosition;
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
use App\Services\DispoOrder\DispoOrderWriter;
use App\Support\Calculation\EngineProfileRegistry;
use App\Support\Calculation\OaTkpWizardCapability;
use App\Support\PriceList\PriceListCalendar;
use Database\Seeders\InitialCatalogMatCoreSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * PO-CALC-TKP-1D3a: OA Dispo Snapshot/Handoff (ersetzt D1 fail-closed Gate).
 */
class PoCalcTkp1d3aDispoSnapshotTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function oa_only_handoff_persists_full_oa_snapshot_without_spot_production(): void
    {
        $ctx = $this->seedContext();
        $calc = app(CalculationWriter::class)->create($this->payload([
            $this->oaPosition($ctx, [
                'oa_targeting_selections' => [
                    ['oa_targeting_definition_id' => (int) $ctx['targeting']->id],
                ],
            ]),
        ]), $ctx['user']);

        $oa = $calc->positions->firstOrFail();
        $order = app(DispoOrderWriter::class)
            ->createFromCalculation($calc, [(int) $oa->id], $ctx['user'])
            ->order;

        $media = $order->positions()
            ->where('line_role', DispoOrderPosition::LINE_ROLE_MEDIA)
            ->get();
        $this->assertCount(1, $media);
        $this->assertSame(0, $order->positions()->where('line_role', DispoOrderPosition::LINE_ROLE_PRODUCTION)->count());

        $snap = $media->firstOrFail();
        $this->assertSame('oa_tkp', $snap->engine_profile_key);
        $this->assertSame('tkp', $snap->calculation_method_key);
        $this->assertSame(0, (int) $snap->length_seconds);
        $this->assertSame(0, (int) $snap->total_spot_count);
        $this->assertSame(100_000, (int) $snap->impressions);
        $this->assertSame('10.0000', (string) $snap->basis_tkp);
        $this->assertSame('12.0000', (string) $snap->list_tkp_snapshot);
        $this->assertSame('8.0000', (string) $snap->minimum_tkp_snapshot);
        $this->assertSame('12.0000', (string) $snap->final_tkp);
        $this->assertSame('2.0000', (string) $snap->targeting_surcharge_total);
        $this->assertSame((int) $oa->oa_tkp_price_list_id, (int) $snap->oa_tkp_price_list_id);
        $this->assertSame($oa->oa_tkp_price_list_version, $snap->oa_tkp_price_list_version);
        $this->assertSame((int) $oa->oa_tkp_price_list_year, (int) $snap->oa_tkp_price_list_year);
        $this->assertSame((int) $oa->oa_tkp_price_list_revision, (int) $snap->oa_tkp_price_list_revision);
        $this->assertNull($snap->price_list_id);
        $this->assertSame([], $snap->plan_rows_snapshot);
        $this->assertSame([], $snap->time_ranges_snapshot);
        $this->assertSame([], $snap->planner_entries_snapshot);
        $this->assertSame([], $snap->components_snapshot);

        $platforms = $snap->oa_platform_allocations_snapshot;
        $this->assertIsArray($platforms);
        $this->assertCount(1, $platforms);
        $this->assertSame((int) $ctx['platform']->id, (int) $platforms[0]['oa_platform_id']);
        $this->assertSame('fixture_d3a_plat', $platforms[0]['platform_code']);
        $this->assertSame(100_000, (int) $platforms[0]['impressions']);

        $targetings = $snap->oa_targeting_selections_snapshot;
        $this->assertIsArray($targetings);
        $this->assertCount(1, $targetings);
        $this->assertSame('geo_d3a', $targetings[0]['targeting_code']);
        $this->assertSame('2.0000', $targetings[0]['tkp_surcharge_snapshot']);
        $this->assertSame(1, (int) $targetings[0]['targeting_revision_number']);
    }

    #[Test]
    public function mixed_spot_and_oa_can_share_one_dispo_order(): void
    {
        $ctx = $this->seedContext();
        $spot = $this->createSpotFixture();
        $calc = app(CalculationWriter::class)->create($this->payload([
            $this->spotPosition($spot),
            $this->oaPosition($ctx),
        ]), $ctx['user']);

        $spotId = (int) $calc->positions->first(fn ($p): bool => $p->engine_profile_key === 'spot_classic')->id;
        $oaId = (int) $calc->positions->first(fn ($p): bool => $p->engine_profile_key === 'oa_tkp')->id;

        $order = app(DispoOrderWriter::class)
            ->createFromCalculation($calc, [$spotId, $oaId], $ctx['user'])
            ->order;

        $media = $order->positions()->where('line_role', DispoOrderPosition::LINE_ROLE_MEDIA)->get();
        $this->assertCount(2, $media);
        $this->assertNotNull($media->firstWhere('calculation_position_id', $spotId)?->total_spot_count);
        $this->assertSame(100_000, (int) $media->firstWhere('calculation_position_id', $oaId)?->impressions);
        $this->assertNull($media->firstWhere('calculation_position_id', $spotId)?->impressions);
    }

    #[Test]
    public function mixed_allows_spot_only_and_oa_only_partial_handoffs(): void
    {
        $ctx = $this->seedContext();
        $spot = $this->createSpotFixture();
        $calc = app(CalculationWriter::class)->create($this->payload([
            $this->spotPosition($spot),
            $this->oaPosition($ctx),
        ]), $ctx['user']);

        $spotId = (int) $calc->positions->first(fn ($p): bool => $p->engine_profile_key === 'spot_classic')->id;
        $oaId = (int) $calc->positions->first(fn ($p): bool => $p->engine_profile_key === 'oa_tkp')->id;

        $spotOrder = app(DispoOrderWriter::class)
            ->createFromCalculation($calc, [$spotId], $ctx['user'])
            ->order;
        $this->assertSame(1, $spotOrder->positions()->where('line_role', DispoOrderPosition::LINE_ROLE_MEDIA)->count());
        $this->assertSame($spotId, (int) $spotOrder->positions()->where('line_role', DispoOrderPosition::LINE_ROLE_MEDIA)->value('calculation_position_id'));

        $oaOrder = app(DispoOrderWriter::class)
            ->createFromCalculation($calc->fresh(), [$oaId], $ctx['user'])
            ->order;
        $this->assertSame(2, DispoOrder::query()->count());
        $this->assertSame(100_000, (int) $oaOrder->positions()->where('line_role', DispoOrderPosition::LINE_ROLE_MEDIA)->value('impressions'));
    }

    #[Test]
    public function incomplete_oa_position_rolls_back_without_persisting_order(): void
    {
        $ctx = $this->seedContext();
        $calc = app(CalculationWriter::class)->create($this->payload([
            $this->oaPosition($ctx),
        ]), $ctx['user']);
        $oa = $calc->positions->firstOrFail();
        $oa->forceFill(['impressions' => null])->save();

        try {
            app(DispoOrderWriter::class)->createFromCalculation($calc->fresh(), [(int) $oa->id], $ctx['user']);
            $this->fail('Erwartete ValidationException für unvollständige OA-Position.');
        } catch (ValidationException $exception) {
            $this->assertStringContainsString(
                'unvollständig',
                collect($exception->errors())->flatten()->implode(' '),
            );
        }

        $this->assertSame(0, DispoOrder::query()->count());
        $this->assertSame(0, DispoOrderPosition::query()->count());
    }

    #[Test]
    public function oa_snapshot_stays_frozen_after_calc_and_master_data_changes(): void
    {
        $ctx = $this->seedContext();
        $calc = app(CalculationWriter::class)->create($this->payload([
            $this->oaPosition($ctx, [
                'oa_targeting_selections' => [
                    ['oa_targeting_definition_id' => (int) $ctx['targeting']->id],
                ],
            ]),
        ]), $ctx['user']);
        $oaId = (int) $calc->positions->firstOrFail()->id;
        $order = app(DispoOrderWriter::class)
            ->createFromCalculation($calc, [$oaId], $ctx['user'])
            ->order;
        $snap = $order->positions()->where('line_role', DispoOrderPosition::LINE_ROLE_MEDIA)->firstOrFail();
        $frozenPlatformName = $snap->oa_platform_allocations_snapshot[0]['platform_name'];
        $frozenTargetingName = $snap->oa_targeting_selections_snapshot[0]['targeting_name'];
        $frozenListId = (int) $snap->oa_tkp_price_list_id;
        $frozenFinal = (string) $snap->final_tkp;

        $ctx['platform']->forceFill(['name' => 'RENAMED LIVE PLATFORM', 'is_active' => false])->save();
        $ctx['targeting']->forceFill(['name' => 'RENAMED LIVE TARGETING', 'is_active' => false])->save();
        OaTkpPriceListItem::query()
            ->where('oa_tkp_price_list_id', $frozenListId)
            ->update(['list_tkp' => '99.0000', 'minimum_tkp' => '90.0000']);

        $freshCalc = $calc->fresh();
        $updatePayload = app(CalculationWriter::class)->payloadFromCalculation($freshCalc);
        $updatePayload['lock_version'] = $freshCalc->lock_version;
        $updatePayload['positions'][0]['basis_tkp'] = '11.0000';
        $updatePayload['positions'][0]['expected_oa_tkp_price_list_id'] = $frozenListId;
        unset(
            $updatePayload['positions'][0]['engine_profile_key'],
            $updatePayload['positions'][0]['list_tkp_snapshot'],
            $updatePayload['positions'][0]['minimum_tkp_snapshot'],
            $updatePayload['positions'][0]['final_tkp'],
            $updatePayload['positions'][0]['targeting_surcharge_total'],
            $updatePayload['positions'][0]['below_minimum_tkp'],
            $updatePayload['positions'][0]['length_seconds'],
            $updatePayload['positions'][0]['total_spot_count'],
            $updatePayload['positions'][0]['plan_rows'],
            $updatePayload['positions'][0]['time_ranges'],
            $updatePayload['positions'][0]['planner_entries'],
        );
        app(CalculationWriter::class)->update($freshCalc, $updatePayload, $ctx['user']);

        $snap->refresh();
        $this->assertSame($frozenPlatformName, $snap->oa_platform_allocations_snapshot[0]['platform_name']);
        $this->assertSame($frozenTargetingName, $snap->oa_targeting_selections_snapshot[0]['targeting_name']);
        $this->assertSame($frozenListId, (int) $snap->oa_tkp_price_list_id);
        $this->assertSame($frozenFinal, (string) $snap->final_tkp);
        $this->assertNotSame('RENAMED LIVE PLATFORM', $snap->oa_platform_allocations_snapshot[0]['platform_name']);
    }

    #[Test]
    public function multiple_handoffs_of_same_oa_position_remain_allowed(): void
    {
        $ctx = $this->seedContext();
        $calc = app(CalculationWriter::class)->create($this->payload([
            $this->oaPosition($ctx),
        ]), $ctx['user']);
        $oaId = (int) $calc->positions->firstOrFail()->id;

        app(DispoOrderWriter::class)->createFromCalculation($calc, [$oaId], $ctx['user']);
        app(DispoOrderWriter::class)->createFromCalculation($calc->fresh(), [$oaId], $ctx['user']);

        $this->assertSame(2, DispoOrder::query()->count());
        $this->assertSame(
            2,
            DispoOrderPosition::query()
                ->where('line_role', DispoOrderPosition::LINE_ROLE_MEDIA)
                ->where('calculation_position_id', $oaId)
                ->count(),
        );
    }

    #[Test]
    public function registry_and_http_store_update_remain_closed(): void
    {
        $this->assertSame(
            EngineCapabilityStatus::Planned,
            EngineProfileRegistry::pairStatus('oa_tkp', 'tkp'),
        );
        $this->assertNull(EngineProfileRegistry::currentReleasedVersion('oa_tkp', 'tkp'));

        config([OaTkpWizardCapability::CONFIG_KEY => true]);
        $ctx = $this->seedContext();
        $payload = $this->payload([$this->oaPosition($ctx)]);

        $this->actingAs($ctx['user'])
            ->postJson(route('calculations.store'), $payload)
            ->assertStatus(422);

        $calc = app(CalculationWriter::class)->create($payload, $ctx['user']);
        $update = app(CalculationWriter::class)->payloadFromCalculation($calc);
        $update['lock_version'] = $calc->lock_version;
        foreach ($update['positions'] as $index => $position) {
            unset(
                $update['positions'][$index]['engine_profile_key'],
                $update['positions'][$index]['list_tkp_snapshot'],
                $update['positions'][$index]['minimum_tkp_snapshot'],
                $update['positions'][$index]['final_tkp'],
                $update['positions'][$index]['targeting_surcharge_total'],
                $update['positions'][$index]['below_minimum_tkp'],
                $update['positions'][$index]['length_seconds'],
                $update['positions'][$index]['total_spot_count'],
                $update['positions'][$index]['plan_rows'],
                $update['positions'][$index]['time_ranges'],
                $update['positions'][$index]['planner_entries'],
            );
        }

        $this->actingAs($ctx['user'])
            ->putJson(route('calculations.update', $calc), $update)
            ->assertStatus(422);
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
        $inventory = Inventory::factory()->create(['name' => 'D3a Dispo Spot', 'code' => 'D3ADISPOSPOT']);
        InventoryMediumRule::factory()->create([
            'inventory_id' => $inventory->id,
            'advertising_medium_id' => $medium->id,
        ]);
        $list = PriceList::factory()->create([
            'inventory_id' => $inventory->id,
            'status' => PriceListStatus::Active,
            'year' => PriceListCalendar::currentYear(),
            'version' => 'D3A-DISPO-SPOT',
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
    private function spotPosition(array $spot): array
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
            'name' => 'Fixture D3a Dispo',
            'version' => PriceListCalendar::currentYear().'.1',
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
            'code' => 'fixture_d3a_plat',
            'name' => 'Fixture D3a Platform',
            'is_active' => true,
            'sort' => 1,
            'is_adserver_relevant' => false,
        ]);
        $targeting = OaTargetingDefinition::query()->create([
            'code' => 'geo_d3a',
            'name' => 'Geo D3a',
            'category' => OaTargetingCategory::Technisch,
            'tkp_surcharge' => '2.0000',
            'revision_number' => 1,
            'is_active' => true,
        ]);

        return compact('user', 'medium', 'inventory', 'platform', 'targeting', 'list');
    }
}
