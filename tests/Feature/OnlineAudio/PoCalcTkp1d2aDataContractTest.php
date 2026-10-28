<?php

namespace Tests\Feature\OnlineAudio;

use App\Enums\DayGroup;
use App\Enums\EngineCapabilityStatus;
use App\Enums\OaTargetingCategory;
use App\Enums\PriceListStatus;
use App\Enums\Role;
use App\Models\AdvertisingMedium;
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
use App\Support\Calculation\EngineProfileRegistry;
use App\Support\Calculation\OaTkpWizardCapability;
use App\Support\PriceList\PriceListCalendar;
use Database\Seeders\InitialCatalogMatCoreSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * PO-CALC-TKP-1D2a: Wizard Data Contract / Metadata / Preview Soft-Open.
 *
 * Registry bleibt planned; HTTP Store/Update bleiben closed; Capability steuert Preview/Props.
 */
class PoCalcTkp1d2aDataContractTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function registry_stays_planned_after_capability_on(): void
    {
        $this->enableCapability();
        $this->assertSame(
            EngineCapabilityStatus::Planned,
            EngineProfileRegistry::pairStatus('oa_tkp', 'tkp'),
        );
        $this->assertNull(EngineProfileRegistry::currentReleasedVersion('oa_tkp', 'tkp'));
        $this->assertSame(
            EngineCapabilityStatus::Implemented,
            EngineProfileRegistry::statusForVersion('oa_tkp', 'tkp', 'v1'),
        );
    }

    #[Test]
    public function preview_blocked_when_capability_off(): void
    {
        config([OaTkpWizardCapability::CONFIG_KEY => false]);
        $ctx = $this->seedContext();

        $response = $this->actingAs($ctx['user'])->postJson(route('calculations.preview'), $this->previewPayload($ctx));

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['positions']);
    }

    #[Test]
    public function preview_succeeds_with_method_result_when_capability_on(): void
    {
        $this->enableCapability();
        $ctx = $this->seedContext();

        $response = $this->actingAs($ctx['user'])->postJson(route('calculations.preview'), $this->previewPayload($ctx));

        $response->assertOk();
        $tkp = $response->json('totals.positions.0.method_result.tkp');
        $this->assertSame('tkp', $response->json('totals.positions.0.method_result.method'));
        $this->assertSame('10.0000', $tkp['basis_tkp']);
        $this->assertSame('12.0000', $tkp['list_tkp']);
        $this->assertSame('8.0000', $tkp['minimum_tkp']);
        $this->assertSame('2.0000', $tkp['targeting_surcharge_total']);
        $this->assertSame('12.0000', $tkp['final_tkp']);
        $this->assertFalse($tkp['below_minimum_tkp']);
        $this->assertSame(100_000, $tkp['impressions']);
        $this->assertSame('1200.00', $response->json('totals.positions.0.media_gross'));
        $this->assertFalse($response->json('totals.requires_special_approval'));
    }

    #[Test]
    public function under_min_preview_exposes_flag_and_reason(): void
    {
        $this->enableCapability();
        $ctx = $this->seedContext();
        // FD-D4c-4b: final = basis + Listenaufschlag (2) muss unter effektivem Min (8) liegen.
        $payload = $this->previewPayload($ctx, ['basis_tkp' => '5.0000']);

        $response = $this->actingAs($ctx['user'])->postJson(route('calculations.preview'), $payload);

        $response->assertOk();
        $tkp = $response->json('totals.positions.0.method_result.tkp');
        $this->assertSame('7.0000', $tkp['final_tkp']);
        $this->assertSame('8.0000', $tkp['minimum_tkp']);
        $this->assertSame('8.0000', $tkp['price_list_minimum_tkp']);
        $this->assertTrue($tkp['below_minimum_tkp']);
        $this->assertTrue($response->json('totals.requires_special_approval'));
        $codes = collect($response->json('totals.special_approval_reasons'))->pluck('code')->all();
        $this->assertContains('oa_tkp_basis_below_minimum', $codes);
    }

    /** FD-D4c-4b Fall 8: Basis < Grundmin, Final ≥ effektiv → keine Sonderfreigabe. */
    #[Test]
    public function basis_below_list_min_but_final_above_effective_needs_no_special_approval(): void
    {
        $this->enableCapability();
        $ctx = $this->seedContext();
        // basis 7 < list-min 8, final 9 ≥ effective 8
        $payload = $this->previewPayload($ctx, ['basis_tkp' => '7.0000']);

        $response = $this->actingAs($ctx['user'])->postJson(route('calculations.preview'), $payload);

        $response->assertOk();
        $tkp = $response->json('totals.positions.0.method_result.tkp');
        $this->assertSame('9.0000', $tkp['final_tkp']);
        $this->assertFalse($tkp['below_minimum_tkp']);
        $this->assertFalse($response->json('totals.requires_special_approval'));
    }

    #[Test]
    public function mixed_preview_only_oa_position_has_tkp_method_result(): void
    {
        $this->enableCapability();
        $ctx = $this->seedContext();
        $spot = $this->createSpotFixture();

        $payload = $this->withLiveSchemaFingerprint([
            'planning_mode' => 'manual',
            'order_discount_percent' => '0',
            'ae_enabled' => false,
            'positions' => [
                $this->spotPosition($spot),
                $this->oaPosition($ctx),
            ],
        ]);

        $response = $this->actingAs($ctx['user'])->postJson(route('calculations.preview'), $payload);

        $response->assertOk();
        $this->assertNull($response->json('totals.positions.0.method_result'));
        $this->assertSame('tkp', $response->json('totals.positions.1.method_result.method'));
        $this->assertSame('12.0000', $response->json('totals.positions.1.method_result.tkp.final_tkp'));
    }

    #[Test]
    public function store_and_update_remain_blocked_with_capability_on(): void
    {
        $this->enableCapability();
        $ctx = $this->seedContext();
        $payload = $this->previewPayload($ctx);

        $this->actingAs($ctx['user'])
            ->postJson(route('calculations.store'), $payload)
            ->assertStatus(422)
            ->assertJsonValidationErrors(['positions']);

        $calc = app(CalculationWriter::class)->create($payload, $ctx['user']);
        $updatePayload = app(CalculationWriter::class)->payloadFromCalculation($calc);
        $updatePayload['lock_version'] = $calc->lock_version;
        foreach ($updatePayload['positions'] as $index => $position) {
            unset(
                $updatePayload['positions'][$index]['engine_profile_key'],
                $updatePayload['positions'][$index]['list_tkp_snapshot'],
                $updatePayload['positions'][$index]['minimum_tkp_snapshot'],
                $updatePayload['positions'][$index]['final_tkp'],
                $updatePayload['positions'][$index]['targeting_surcharge_total'],
                $updatePayload['positions'][$index]['below_minimum_tkp'],
                $updatePayload['positions'][$index]['length_seconds'],
                $updatePayload['positions'][$index]['total_spot_count'],
                $updatePayload['positions'][$index]['plan_rows'],
                $updatePayload['positions'][$index]['time_ranges'],
                $updatePayload['positions'][$index]['planner_entries'],
            );
        }

        $response = $this->actingAs($ctx['user'])
            ->putJson(route('calculations.update', $calc), $updatePayload);
        $response->assertStatus(422);
        $this->assertSame(
            'Kalkulationsart TKP ist noch nicht freigegeben.',
            $response->json('errors.positions.0')
            ?? $response->json('message'),
        );
    }

    #[Test]
    public function metadata_happy_path_and_capability_gate(): void
    {
        config([OaTkpWizardCapability::CONFIG_KEY => false]);
        $ctx = $this->seedContext();

        $denied = $this->actingAs($ctx['user'])->getJson(route('calculations.oa-tkp.pricing-metadata', [
            'advertising_medium_id' => $ctx['medium']->id,
            'inventory_id' => $ctx['inventory']->id,
        ]));
        $denied->assertStatus(422);

        $this->enableCapability();
        $ok = $this->actingAs($ctx['user'])->getJson(route('calculations.oa-tkp.pricing-metadata', [
            'advertising_medium_id' => $ctx['medium']->id,
            'inventory_id' => $ctx['inventory']->id,
            'price_year' => PriceListCalendar::currentYear(),
        ]));
        $ok->assertOk();
        $meta = $ok->json('metadata');
        $this->assertSame((int) $ctx['list']->id, $meta['expected_oa_tkp_price_list_id']);
        $this->assertSame('12.0000', $meta['list_tkp']);
        $this->assertSame('8.0000', $meta['minimum_tkp']);
        $this->assertContains(PriceListCalendar::currentYear(), $meta['available_price_years']);
    }

    #[Test]
    public function metadata_rejects_native_and_missing_item(): void
    {
        $this->enableCapability();
        $ctx = $this->seedContext();
        $native = AdvertisingMedium::query()->where('code', 'native_ad')->firstOrFail();

        $this->actingAs($ctx['user'])->getJson(route('calculations.oa-tkp.pricing-metadata', [
            'advertising_medium_id' => $native->id,
            'inventory_id' => $ctx['inventory']->id,
        ]))->assertStatus(422);

        $otherInventory = Inventory::factory()->create(['code' => 'D2A_NO_ITEM']);
        $this->actingAs($ctx['user'])->getJson(route('calculations.oa-tkp.pricing-metadata', [
            'advertising_medium_id' => $ctx['medium']->id,
            'inventory_id' => $otherInventory->id,
            'price_year' => PriceListCalendar::currentYear(),
        ]))->assertStatus(422);
    }

    #[Test]
    public function metadata_unauthorized_guest_denied(): void
    {
        $this->enableCapability();
        $ctx = $this->seedContext();

        $this->getJson(route('calculations.oa-tkp.pricing-metadata', [
            'advertising_medium_id' => $ctx['medium']->id,
            'inventory_id' => $ctx['inventory']->id,
        ]))->assertUnauthorized();
    }

    #[Test]
    public function wizard_props_expose_options_platforms_targetings_under_capability(): void
    {
        $this->enableCapability();
        $ctx = $this->seedContext();

        $this->actingAs($ctx['user'])
            ->get(route('calculations.create'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('calculations/wizard')
                ->where('oa_tkp_wizard_capability', true)
                ->where('oa_tkp_http_mutation_allowed', false)
                ->has('catalog.oa_platforms', 1)
                ->has('catalog.oa_targetings', 1)
                ->where('catalog.oa_platforms.0.code', 'fixture_plat_a')
                ->where('catalog.media', function ($media) {
                    $preStream = collect($media)->firstWhere('code', 'pre_stream');
                    $keys = collect($preStream['calculation_method_options']['methods'] ?? [])->pluck('key')->all();

                    return in_array('tkp', $keys, true);
                })
            );
    }

    #[Test]
    public function wizard_props_hide_tkp_options_when_capability_off(): void
    {
        config([OaTkpWizardCapability::CONFIG_KEY => false]);
        $ctx = $this->seedContext();

        $this->actingAs($ctx['user'])
            ->get(route('calculations.create'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('calculations/wizard')
                ->where('oa_tkp_wizard_capability', false)
                ->where('oa_tkp_http_mutation_allowed', false)
                ->where('catalog.oa_platforms', [])
                ->where('catalog.oa_targetings', [])
                ->where('catalog.media', function ($media) {
                    $preStream = collect($media)->firstWhere('code', 'pre_stream');
                    $keys = collect($preStream['calculation_method_options']['methods'] ?? [])->pluck('key')->all();

                    return ! in_array('tkp', $keys, true);
                })
            );
    }

    #[Test]
    public function edit_props_keep_historical_pin_and_inactive_snapshots(): void
    {
        $this->enableCapability();
        $ctx = $this->seedContext();
        $writer = app(CalculationWriter::class);
        $calc = $writer->create($this->previewPayload($ctx), $ctx['user']);
        $pinnedListId = (int) $ctx['list']->id;

        // Neue Active-Revision – Edit Props behalten historischen Pin.
        $ctx['list']->update(['status' => PriceListStatus::Archived]);
        $rev2 = $this->createActivePriceList(
            PriceListCalendar::currentYear(),
            2,
            (int) $ctx['medium']->id,
            (int) $ctx['inventory']->id,
            '15.0000',
            '9.0000',
        );

        $ctx['platform']->update(['is_active' => false]);
        $ctx['targeting']->update(['is_active' => false]);
        OaTargetingDefinition::query()->create([
            'code' => 'fixture_tgt',
            'name' => 'Fixture Targeting Rev2',
            'category' => OaTargetingCategory::Technisch,
            'tkp_surcharge' => '3.5000',
            'revision_number' => 2,
            'is_active' => true,
        ]);

        $this->actingAs($ctx['user'])
            ->get(route('calculations.edit', $calc))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('calculations/wizard')
                ->where('calculation.positions.0.expected_oa_tkp_price_list_id', $pinnedListId)
                ->where('calculation.positions.0.oa_platform_allocations.0.platform_code', 'fixture_plat_a')
                ->where('calculation.positions.0.oa_platform_allocations.0.platform_name', 'Fixture Platform A')
                ->where('calculation.positions.0.oa_targeting_selections.0.targeting_code', 'fixture_tgt')
                ->where('calculation.positions.0.oa_targeting_selections.0.tkp_surcharge_snapshot', '2.0000')
                ->where('calculation.positions.0.oa_targeting_selections.0.targeting_revision_number', 1)
                ->where('catalog.oa_platforms', [])
                ->where('catalog.oa_targetings.0.revision_number', 2)
                ->where('catalog.oa_targetings.0.tkp_surcharge', '3.5000')
                ->where('calculation.positions.0.expected_oa_tkp_price_list_id', fn ($id): bool => (int) $id !== (int) $rev2->id)
            );
    }

    #[Test]
    public function metadata_returns_current_active_for_rebind_candidate(): void
    {
        $this->enableCapability();
        $ctx = $this->seedContext();
        $ctx['list']->update(['status' => PriceListStatus::Archived]);
        $rev2 = $this->createActivePriceList(
            PriceListCalendar::currentYear(),
            2,
            (int) $ctx['medium']->id,
            (int) $ctx['inventory']->id,
            '15.0000',
            '9.0000',
        );

        $response = $this->actingAs($ctx['user'])->getJson(route('calculations.oa-tkp.pricing-metadata', [
            'advertising_medium_id' => $ctx['medium']->id,
            'inventory_id' => $ctx['inventory']->id,
            'price_year' => PriceListCalendar::currentYear(),
        ]));

        $response->assertOk();
        $this->assertSame((int) $rev2->id, $response->json('metadata.expected_oa_tkp_price_list_id'));
        $this->assertSame('15.0000', $response->json('metadata.list_tkp'));
    }

    private function enableCapability(): void
    {
        config([OaTkpWizardCapability::CONFIG_KEY => true]);
    }

    /**
     * @param  array<string, mixed>  $override
     * @return array<string, mixed>
     */
    private function previewPayload(array $ctx, array $override = []): array
    {
        return $this->withLiveSchemaFingerprint([
            'planning_mode' => 'manual',
            'order_discount_percent' => '0',
            'ae_enabled' => false,
            'positions' => [$this->oaPosition($ctx, $override)],
        ]);
    }

    /**
     * @param  array{
     *     medium: AdvertisingMedium,
     *     inventory: Inventory,
     *     list: OaTkpPriceList,
     *     targeting: OaTargetingDefinition,
     *     platform: OaPlatform
     * }  $ctx
     * @param  array<string, mixed>  $override
     * @return array<string, mixed>
     */
    private function oaPosition(array $ctx, array $override = []): array
    {
        return array_merge([
            'client_key' => (string) str()->uuid(),
            'inventory_id' => $ctx['inventory']->id,
            'advertising_medium_id' => $ctx['medium']->id,
            'calculation_method_key' => 'tkp',
            'price_year' => PriceListCalendar::currentYear(),
            'expected_oa_tkp_price_list_id' => $ctx['list']->id,
            'impressions' => 100_000,
            'basis_tkp' => '10.0000',
            'oa_platform_allocations' => [
                ['oa_platform_id' => (int) $ctx['platform']->id, 'impressions' => 100_000],
            ],
            'oa_targeting_selections' => [
                ['targeting_code' => 'fixture_tgt'],
            ],
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
        $inventory = Inventory::factory()->create(['name' => 'D2a Spot', 'code' => 'D2ASPOT']);
        InventoryMediumRule::factory()->create([
            'inventory_id' => $inventory->id,
            'advertising_medium_id' => $medium->id,
        ]);
        $list = PriceList::factory()->create([
            'inventory_id' => $inventory->id,
            'status' => PriceListStatus::Active,
            'year' => PriceListCalendar::currentYear(),
            'version' => 'D2A-SPOT',
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
            'client_key' => (string) str()->uuid(),
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
     *     list: OaTkpPriceList,
     *     targeting: OaTargetingDefinition,
     *     platform: OaPlatform
     * }
     */
    private function seedContext(): array
    {
        $this->seed(InitialCatalogMatCoreSeeder::class);
        $user = User::factory()->role(Role::Sales)->create();
        $medium = AdvertisingMedium::query()->where('code', 'pre_stream')->firstOrFail();
        $inventory = Inventory::query()->where('code', 'inv_more_kombi_online_audio')->firstOrFail();
        $list = $this->createActivePriceList(
            PriceListCalendar::currentYear(),
            1,
            (int) $medium->id,
            (int) $inventory->id,
            '12.0000',
            '8.0000',
        );
        $targeting = OaTargetingDefinition::query()->create([
            'code' => 'fixture_tgt',
            'name' => 'Fixture Targeting',
            'category' => OaTargetingCategory::Technisch,
            'tkp_surcharge' => '2.0000',
            'revision_number' => 1,
            'is_active' => true,
        ]);
        $platform = OaPlatform::query()->create([
            'code' => 'fixture_plat_a',
            'name' => 'Fixture Platform A',
            'is_active' => true,
            'sort' => 1,
            'is_adserver_relevant' => false,
        ]);

        return compact('user', 'medium', 'inventory', 'list', 'targeting', 'platform');
    }

    private function createActivePriceList(
        int $year,
        int $revision,
        int $mediumId,
        int $inventoryId,
        string $listTkp,
        string $minimumTkp,
    ): OaTkpPriceList {
        $list = OaTkpPriceList::query()->create([
            'year' => $year,
            'name' => "Fixture D2a {$year}.{$revision}",
            'version' => "{$year}.{$revision}",
            'revision_number' => $revision,
            'lock_version' => 1,
            'status' => PriceListStatus::Active,
            'published_at' => now(),
        ]);

        OaTkpPriceListItem::query()->create([
            'oa_tkp_price_list_id' => $list->id,
            'advertising_medium_id' => $mediumId,
            'inventory_id' => $inventoryId,
            'list_tkp' => $listTkp,
            'minimum_tkp' => $minimumTkp,
        ]);

        return $list;
    }
}
