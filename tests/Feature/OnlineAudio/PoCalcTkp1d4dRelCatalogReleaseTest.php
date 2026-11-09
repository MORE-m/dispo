<?php

namespace Tests\Feature\OnlineAudio;

use App\Enums\EngineCapabilityStatus;
use App\Enums\OaTargetingCategory;
use App\Enums\PriceListStatus;
use App\Enums\Role;
use App\Http\Controllers\CalculationController;
use App\Models\AdvertisingMedium;
use App\Models\Calculation;
use App\Models\Inventory;
use App\Models\InventoryMediumRule;
use App\Models\OaPlatform;
use App\Models\OaTargetingDefinition;
use App\Models\OaTkpPriceList;
use App\Models\OaTkpPriceListItem;
use App\Models\User;
use App\Services\Calculation\CalculationWriter;
use App\Support\Advertising\AdvertisingMediumLiveBookability;
use App\Support\Calculation\EngineProfileRegistry;
use App\Support\Calculation\OaTkpHttpReleaseGuard;
use App\Support\Calculation\OaTkpWizardCapability;
use App\Support\OnlineAudio\OaTkpCoreMedia;
use App\Support\PriceList\PriceListCalendar;
use Database\Seeders\InitialCatalogMatCoreSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * PO-CALC-TKP-1D4d-REL: echter CATALOG-Release oa_tkp×tkp = released/v1.
 *
 * Kein Test-Fake für Happy-Path. Soft-Open-Capability bleibt aus.
 */
class PoCalcTkp1d4dRelCatalogReleaseTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        EngineProfileRegistry::clearOaTkpPairFakeForTests();
        OaTkpWizardCapability::clearHttpMutationFakeForTests();
        parent::tearDown();
    }

    #[Test]
    public function catalog_is_released_v1_without_fake(): void
    {
        $this->assertSame(
            EngineCapabilityStatus::Released,
            EngineProfileRegistry::pairStatus('oa_tkp', 'tkp'),
        );
        $this->assertSame('v1', EngineProfileRegistry::currentReleasedVersion('oa_tkp', 'tkp'));
        $this->assertSame(
            EngineCapabilityStatus::Released,
            EngineProfileRegistry::statusForVersion('oa_tkp', 'tkp', 'v1'),
        );
        EngineProfileRegistry::validateDefinitions(
            (new \ReflectionClass(EngineProfileRegistry::class))
                ->getConstant('CATALOG'),
        );
        $this->assertTrue(OaTkpWizardCapability::allowsHttpMutation());
        $this->assertTrue(OaTkpWizardCapability::allowsWizardPreviewSurface());
        $this->assertFalse(OaTkpWizardCapability::isEnabled());
    }

    #[Test]
    public function five_core_media_bookable_and_wizard_preview_store_update_without_capability(): void
    {
        config([OaTkpWizardCapability::CONFIG_KEY => false]);
        $ctx = $this->seedContext();
        $bookability = app(AdvertisingMediumLiveBookability::class);

        foreach (OaTkpCoreMedia::codes() as $code) {
            $medium = AdvertisingMedium::query()->where('code', $code)->firstOrFail();
            $this->assertNull($medium->getAttributes()['kind'] ?? null, $code);
            $result = $bookability->evaluate($medium, 'tkp');
            $this->assertTrue($result->isBookableForNewPositions, $code);
            $this->assertSame('oa_tkp', $result->engineProfileKey, $code);
            $this->assertSame('tkp', $result->calculationMethodKey, $code);
            $this->assertSame('v1', $result->algorithmVersion, $code);
        }

        $this->actingAs($ctx['user'])
            ->get(route('calculations.create'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('calculations/wizard')
                ->where('oa_tkp_wizard_capability', false)
                ->where('oa_tkp_http_mutation_allowed', true)
                ->where('catalog.media', function ($media) {
                    $byCode = collect($media)->keyBy('code');
                    foreach (OaTkpCoreMedia::codes() as $code) {
                        $row = $byCode->get($code);
                        if ($row === null || ($row['is_bookable_for_new_positions'] ?? false) !== true) {
                            return false;
                        }
                        $keys = collect($row['calculation_method_options']['methods'] ?? [])->pluck('key')->all();
                        if (! in_array('tkp', $keys, true)) {
                            return false;
                        }
                    }

                    return true;
                })
            );

        $payload = $this->oaStorePayload($ctx);
        $this->actingAs($ctx['user'])->postJson(route('calculations.preview'), $payload)->assertOk();
        $this->actingAs($ctx['user'])->postJson(route('calculations.store'), $payload)->assertRedirect();

        $calc = Calculation::query()->firstOrFail();
        $position = $calc->positions()->firstOrFail();
        $this->assertSame('oa_tkp', $position->engine_profile_key);
        $this->assertSame('tkp', $position->calculation_method_key);
        $this->assertSame('v1', $position->algorithm_version);

        $update = $this->httpUpdatePayload($calc);
        $update['positions'][0]['impressions'] = 120_000;
        $update['positions'][0]['oa_platform_allocations'] = [[
            'oa_platform_id' => $ctx['platform']->id,
            'impressions' => 120_000,
        ]];
        $this->actingAs($ctx['user'])->putJson(route('calculations.update', $calc), $update)->assertRedirect();

        $reloaded = $calc->fresh(['positions']);
        $this->assertSame('v1', $reloaded->positions->firstOrFail()->algorithm_version);
    }

    #[Test]
    public function midroll_list_remains_eighty_twenty_without_adserver_plus_fifteen(): void
    {
        $ctx = $this->seedContext(mediumCode: 'midroll_music_environment');
        $item = OaTkpPriceListItem::query()
            ->where('oa_tkp_price_list_id', $ctx['list']->id)
            ->where('advertising_medium_id', $ctx['medium']->id)
            ->firstOrFail();
        $this->assertSame('80.0000', number_format((float) $item->list_tkp, 4, '.', ''));
        $this->assertSame('20.0000', number_format((float) $item->minimum_tkp, 4, '.', ''));

        $payload = $this->oaStorePayload($ctx, [
            'basis_tkp' => '80.0000',
            'impressions' => 100_000,
        ]);
        $this->actingAs($ctx['user'])->postJson(route('calculations.store'), $payload)->assertRedirect();
        $position = Calculation::query()->firstOrFail()->positions()->firstOrFail();
        $this->assertSame('80.0000', number_format((float) $position->final_tkp, 4, '.', ''));
        $this->assertNotSame('95.0000', number_format((float) $position->final_tkp, 4, '.', ''));
    }

    #[Test]
    public function negatives_native_standard_offer_budget_fixed_price_remain_out(): void
    {
        config([OaTkpWizardCapability::CONFIG_KEY => false]);
        $ctx = $this->seedContext();
        $bookability = app(AdvertisingMediumLiveBookability::class);

        $native = AdvertisingMedium::query()->where('code', 'native_ad')->firstOrFail();
        $this->assertFalse($bookability->evaluate($native, 'tkp')->isBookableForNewPositions);

        $controller = app(CalculationController::class);
        $method = new \ReflectionMethod($controller, 'wizardProps');
        $method->setAccessible(true);
        /** @var array<string, mixed> $props */
        $props = $method->invoke($controller, request(), null, false);
        $pre = collect($props['catalog']['media'] ?? [])->firstWhere('code', 'pre_stream');
        $this->assertFalse($pre['is_bookable_for_new_positions']);
        $this->assertNotContains(
            'tkp',
            collect($pre['calculation_method_options']['methods'] ?? [])->pluck('key')->all(),
        );

        $budgetPayload = $this->oaStorePayload($ctx);
        $budgetPayload['planning_mode'] = 'budget';
        try {
            OaTkpHttpReleaseGuard::assertBudgetNotTkp($budgetPayload);
            $this->fail('Expected budget rejection');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('Budgetvorschläge', collect($e->errors())->flatten()->implode(' '));
        }

        $fixed = $this->oaStorePayload($ctx);
        $fixed['positions'][0]['pricing_settlement_mode'] = 'fixed_price';
        $fixed['positions'][0]['fixed_price_nn'] = '100.00';
        $this->actingAs($ctx['user'])->postJson(route('calculations.store'), $fixed)->assertStatus(422);
        $this->assertSame(0, Calculation::query()->count());
    }

    #[Test]
    public function fake_released_remains_harmless_and_clears_in_teardown_path(): void
    {
        EngineProfileRegistry::fakeOaTkpPairReleasedForTests();
        $this->assertSame(
            EngineCapabilityStatus::Released,
            EngineProfileRegistry::pairStatus('oa_tkp', 'tkp'),
        );
        EngineProfileRegistry::clearOaTkpPairFakeForTests();
        $this->assertSame(
            EngineCapabilityStatus::Released,
            EngineProfileRegistry::pairStatus('oa_tkp', 'tkp'),
        );
    }

    #[Test]
    public function simulated_catalog_revert_to_planned_closes_new_bookings_but_keeps_v1_readable(): void
    {
        $this->seed(InitialCatalogMatCoreSeeder::class);
        $override = new \ReflectionProperty(EngineProfileRegistry::class, 'oaTkpPairOverrideForTests');
        $override->setAccessible(true);
        $override->setValue(null, [
            'pair_status' => EngineCapabilityStatus::Planned,
            'current_released_version' => null,
            'versions' => [
                'v1' => EngineCapabilityStatus::Implemented,
            ],
        ]);

        try {
            $this->assertSame(
                EngineCapabilityStatus::Planned,
                EngineProfileRegistry::pairStatus('oa_tkp', 'tkp'),
            );
            $this->assertNull(EngineProfileRegistry::currentReleasedVersion('oa_tkp', 'tkp'));
            $this->assertSame(
                EngineCapabilityStatus::Implemented,
                EngineProfileRegistry::statusForVersion('oa_tkp', 'tkp', 'v1'),
            );
            $this->assertFalse(OaTkpWizardCapability::allowsHttpMutation());

            $medium = AdvertisingMedium::query()->where('code', 'pre_stream')->firstOrFail();
            $this->assertFalse(
                app(AdvertisingMediumLiveBookability::class)->evaluate($medium, 'tkp')->isBookableForNewPositions,
            );

            // Historischer Lesepfad: Implemented bleibt für Freeze-Execution akzeptabel.
            $this->assertTrue(OaTkpWizardCapability::engineVersionIsImplemented());
        } finally {
            EngineProfileRegistry::clearOaTkpPairFakeForTests();
        }
    }

    /**
     * @return array{
     *     user: User,
     *     inventory: Inventory,
     *     medium: AdvertisingMedium,
     *     list: OaTkpPriceList,
     *     platform: OaPlatform
     * }
     */
    private function seedContext(string $mediumCode = 'pre_stream'): array
    {
        $this->seed(InitialCatalogMatCoreSeeder::class);
        $user = User::factory()->create(['role' => Role::Admin]);
        $inventory = Inventory::query()->where('code', 'inv_more_kombi_online_audio')->firstOrFail();
        $medium = AdvertisingMedium::query()->where('code', $mediumCode)->firstOrFail();

        foreach (OaTkpCoreMedia::codes() as $code) {
            $core = AdvertisingMedium::query()->where('code', $code)->firstOrFail();
            InventoryMediumRule::query()->firstOrCreate(
                [
                    'inventory_id' => $inventory->id,
                    'advertising_medium_id' => $core->id,
                ],
                ['is_active' => true],
            );
        }

        $year = PriceListCalendar::currentYear();
        $list = OaTkpPriceList::query()->create([
            'year' => $year,
            'name' => "OA TKP {$year} REL",
            'version' => "{$year}.1",
            'revision_number' => 1,
            'status' => PriceListStatus::Active,
            'published_at' => now(),
        ]);

        foreach (OaTkpCoreMedia::codes() as $code) {
            $core = AdvertisingMedium::query()->where('code', $code)->firstOrFail();
            $listTkp = $code === 'midroll_music_environment' ? '80.0000' : '56.0000';
            $minTkp = $code === 'midroll_music_environment' ? '20.0000' : '14.0000';
            OaTkpPriceListItem::query()->create([
                'oa_tkp_price_list_id' => $list->id,
                'advertising_medium_id' => $core->id,
                'inventory_id' => $inventory->id,
                'list_tkp' => $listTkp,
                'minimum_tkp' => $minTkp,
            ]);
        }

        OaTargetingDefinition::query()->create([
            'code' => 'dmp',
            'name' => 'DMP',
            'category' => OaTargetingCategory::Dmp,
            'tkp_surcharge' => '25.0000',
            'minimum_tkp_surcharge' => '5.0000',
            'is_active' => true,
            'sort_order' => 10,
        ]);

        $platform = OaPlatform::query()->firstOrCreate(
            ['code' => 'spotify'],
            [
                'name' => 'Spotify',
                'is_active' => true,
                'is_adserver_relevant' => false,
                'sort_order' => 10,
            ],
        );

        return compact('user', 'inventory', 'medium', 'list', 'platform');
    }

    /**
     * @param  array<string, mixed>  $ctx
     * @param  array<string, mixed>  $override
     * @return array<string, mixed>
     */
    private function oaStorePayload(array $ctx, array $override = []): array
    {
        $impressions = (int) ($override['impressions'] ?? 100_000);
        $basis = (string) ($override['basis_tkp'] ?? '56.0000');

        return $this->withLiveSchemaFingerprint([
            'planning_mode' => 'manual',
            'order_discount_percent' => '0',
            'positions' => [[
                'inventory_id' => $ctx['inventory']->id,
                'advertising_medium_id' => $ctx['medium']->id,
                'calculation_method_key' => 'tkp',
                'price_year' => PriceListCalendar::currentYear(),
                'expected_oa_tkp_price_list_id' => (int) $ctx['list']->id,
                'basis_tkp' => $basis,
                'impressions' => $impressions,
                'position_discount_percent' => '0',
                'ae_percent' => '0',
                'ae_enabled' => false,
                'oa_platform_allocations' => [[
                    'oa_platform_id' => $ctx['platform']->id,
                    'impressions' => $impressions,
                ]],
                'oa_targeting_selections' => [],
            ]],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function httpUpdatePayload(Calculation $calculation): array
    {
        $payload = app(CalculationWriter::class)->payloadFromCalculation($calculation->fresh(['positions']));
        $payload['lock_version'] = $calculation->lock_version;
        foreach ($payload['positions'] as $index => $position) {
            unset(
                $payload['positions'][$index]['engine_profile_key'],
                $payload['positions'][$index]['list_tkp_snapshot'],
                $payload['positions'][$index]['minimum_tkp_snapshot'],
                $payload['positions'][$index]['final_tkp'],
                $payload['positions'][$index]['targeting_surcharge_total'],
                $payload['positions'][$index]['below_minimum_tkp'],
                $payload['positions'][$index]['length_seconds'],
                $payload['positions'][$index]['total_spot_count'],
                $payload['positions'][$index]['plan_rows'],
                $payload['positions'][$index]['time_ranges'],
                $payload['positions'][$index]['planner_entries'],
            );
        }

        return $payload;
    }
}
