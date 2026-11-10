<?php

namespace Tests\Feature\OnlineAudio;

use App\Enums\EngineCapabilityStatus;
use App\Enums\OaTargetingCategory;
use App\Enums\PriceListStatus;
use App\Enums\Role;
use App\Http\Controllers\CalculationController;
use App\Models\AdvertisingMedium;
use App\Models\AdvertisingMediumCalculationMethod;
use App\Models\Calculation;
use App\Models\CalculationMethod;
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
 * PO-CALC-TKP-1D4d-WIRE: nach Registry-Release bleiben die fünf V1-Medien bookable.
 *
 * Produktiver CATALOG bleibt planned; Release nur per Test-Fake.
 */
class PoCalcTkp1d4dWireReleasedBookabilityTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        EngineProfileRegistry::clearOaTkpPairFakeForTests();
        OaTkpWizardCapability::clearHttpMutationFakeForTests();
        parent::tearDown();
    }

    #[Test]
    public function registry_catalog_stays_planned_without_fake(): void
    {
        $this->assertSame(
            EngineCapabilityStatus::Planned,
            EngineProfileRegistry::pairStatus('oa_tkp', 'tkp'),
        );
        $this->assertNull(EngineProfileRegistry::currentReleasedVersion('oa_tkp', 'tkp'));
    }

    #[Test]
    public function all_five_core_media_are_bookable_after_simulated_release_without_capability(): void
    {
        config([OaTkpWizardCapability::CONFIG_KEY => false]);
        $this->seed(InitialCatalogMatCoreSeeder::class);
        $this->simulateRegistryRelease();

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
    }

    #[Test]
    public function wizard_exposes_five_media_and_allows_preview_store_update_after_release(): void
    {
        config([OaTkpWizardCapability::CONFIG_KEY => false]);
        $ctx = $this->seedContext();
        $this->simulateRegistryRelease();

        $this->assertTrue(OaTkpWizardCapability::allowsHttpMutation());
        $this->assertTrue(OaTkpWizardCapability::allowsWizardPreviewSurface());
        $this->assertFalse(OaTkpWizardCapability::isEnabled());

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
                        if ($row === null) {
                            return false;
                        }
                        if (($row['is_bookable_for_new_positions'] ?? false) !== true) {
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
        $this->actingAs($ctx['user'])
            ->postJson(route('calculations.preview'), $payload)
            ->assertOk();

        $this->actingAs($ctx['user'])
            ->postJson(route('calculations.store'), $payload)
            ->assertRedirect();

        $calc = Calculation::query()->firstOrFail();
        $update = $this->httpUpdatePayload($calc);
        $update['positions'][0]['impressions'] = 120_000;
        $update['positions'][0]['oa_platform_allocations'] = [[
            'oa_platform_id' => $ctx['platform']->id,
            'impressions' => 120_000,
        ]];

        $this->actingAs($ctx['user'])
            ->putJson(route('calculations.update', $calc), $update)
            ->assertRedirect();
    }

    #[Test]
    public function native_ad_and_non_oa_null_kind_stay_unbookable_after_release(): void
    {
        $this->seed(InitialCatalogMatCoreSeeder::class);
        $this->simulateRegistryRelease();
        $bookability = app(AdvertisingMediumLiveBookability::class);

        $native = AdvertisingMedium::query()->where('code', 'native_ad')->firstOrFail();
        $this->assertFalse($bookability->evaluate($native, 'tkp')->isBookableForNewPositions);

        $orphan = AdvertisingMedium::factory()->create([
            'code' => 'wire_null_kind_orphan',
            'kind' => null,
            'is_active' => true,
        ]);
        $this->assertFalse($bookability->evaluate($orphan)->isBookableForNewPositions);
        $this->assertSame(
            'Keine freigegebene Berechnungsmethode vorhanden.',
            $bookability->evaluate($orphan)->unbookableReason,
        );
    }

    #[Test]
    public function inactive_assignment_keeps_core_medium_unbookable_after_release(): void
    {
        $this->seed(InitialCatalogMatCoreSeeder::class);
        $this->simulateRegistryRelease();
        $medium = AdvertisingMedium::query()->where('code', 'pre_stream')->firstOrFail();
        $tkp = CalculationMethod::query()->where('key', 'tkp')->firstOrFail();
        AdvertisingMediumCalculationMethod::query()
            ->where('advertising_medium_id', $medium->id)
            ->where('calculation_method_id', $tkp->id)
            ->update(['is_active' => false]);

        $result = app(AdvertisingMediumLiveBookability::class)->evaluate($medium->fresh(), 'tkp');
        $this->assertFalse($result->isBookableForNewPositions);
    }

    #[Test]
    public function planned_without_capability_keeps_core_media_unbookable(): void
    {
        config([OaTkpWizardCapability::CONFIG_KEY => false]);
        $this->seed(InitialCatalogMatCoreSeeder::class);
        $medium = AdvertisingMedium::query()->where('code', 'pre_stream')->firstOrFail();
        $result = app(AdvertisingMediumLiveBookability::class)->evaluate($medium, 'tkp');
        $this->assertFalse($result->isBookableForNewPositions);
        $this->assertStringContainsString('noch nicht freigegeben', (string) $result->unbookableReason);
    }

    #[Test]
    public function standard_offer_props_keep_oa_unbookable_after_release(): void
    {
        config([OaTkpWizardCapability::CONFIG_KEY => false]);
        $ctx = $this->seedContext();
        $this->simulateRegistryRelease();

        // Spot-Kalkulation als SO-Quelle; Props kommen über showStandardOfferDraft-Pfad
        // indirekt – hier prüfen wir wizardProps(false) über den bestehenden Edit-ohne-SO
        // nicht; Materializer-Reject und Controller-Clamp werden über LiveBookability+Clamp
        // und einen expliziten Media-Payload-Check abgesichert.
        $this->actingAs($ctx['user'])
            ->get(route('calculations.create'))
            ->assertOk();

        // Clamp: forCalculationWizard=false wird in StandardOffer-Flows genutzt.
        // Direkter Aufruf über Reflection, um Isolation ohne SO-Fixture zu belegen.
        $controller = app(CalculationController::class);
        $method = new \ReflectionMethod($controller, 'wizardProps');
        $method->setAccessible(true);
        /** @var array<string, mixed> $props */
        $props = $method->invoke($controller, request(), null, false);
        $pre = collect($props['catalog']['media'] ?? [])->firstWhere('code', 'pre_stream');
        $this->assertNotNull($pre);
        $this->assertFalse($pre['is_bookable_for_new_positions']);
        $keys = collect($pre['calculation_method_options']['methods'] ?? [])->pluck('key')->all();
        $this->assertNotContains('tkp', $keys);
    }

    #[Test]
    public function budget_stays_out_after_registry_release(): void
    {
        $ctx = $this->seedContext();
        $this->simulateRegistryRelease();
        $payload = $this->oaStorePayload($ctx);
        $payload['planning_mode'] = 'budget';

        try {
            OaTkpHttpReleaseGuard::assertBudgetNotTkp($payload);
            $this->fail('Expected budget TKP rejection');
        } catch (ValidationException $e) {
            $this->assertStringContainsString(
                'Budgetvorschläge sind für TKP-Positionen nicht verfügbar',
                collect($e->errors())->flatten()->implode(' '),
            );
        }
    }

    #[Test]
    public function oa_fixed_price_settlement_stays_out_after_release(): void
    {
        $ctx = $this->seedContext();
        $this->simulateRegistryRelease();
        $payload = $this->oaStorePayload($ctx);
        $payload['positions'][0]['pricing_settlement_mode'] = 'fixed_price';
        $payload['positions'][0]['fixed_price_nn'] = '100.00';

        $this->actingAs($ctx['user'])
            ->postJson(route('calculations.store'), $payload)
            ->assertStatus(422);
        $this->assertSame(0, Calculation::query()->count());
    }

    private function simulateRegistryRelease(): void
    {
        EngineProfileRegistry::fakeOaTkpPairReleasedForTests();
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
    private function seedContext(): array
    {
        $this->seed(InitialCatalogMatCoreSeeder::class);
        $user = User::factory()->create(['role' => Role::Admin]);
        $inventory = Inventory::query()->where('code', 'inv_more_kombi_online_audio')->firstOrFail();
        $medium = AdvertisingMedium::query()->where('code', 'pre_stream')->firstOrFail();

        InventoryMediumRule::query()->firstOrCreate(
            [
                'inventory_id' => $inventory->id,
                'advertising_medium_id' => $medium->id,
            ],
            ['is_active' => true],
        );

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
            'name' => "OA TKP {$year} WIRE",
            'version' => "{$year}.1",
            'revision_number' => 1,
            'status' => PriceListStatus::Active,
            'valid_from' => "{$year}-01-01",
            'published_at' => now(),
        ]);
        OaTkpPriceListItem::query()->create([
            'oa_tkp_price_list_id' => $list->id,
            'advertising_medium_id' => $medium->id,
            'inventory_id' => $inventory->id,
            'list_tkp' => '12.0000',
            'minimum_tkp' => '8.0000',
        ]);

        $platform = OaPlatform::query()->create([
            'code' => 'wire_plat',
            'name' => 'WIRE Platform',
            'is_active' => true,
            'sort' => 10,
            'is_adserver_relevant' => false,
        ]);

        OaTargetingDefinition::query()->create([
            'code' => 'wire_geo',
            'name' => 'WIRE Geo',
            'category' => OaTargetingCategory::Technisch,
            'tkp_surcharge' => '1.0000',
            'revision_number' => 1,
            'is_active' => true,
        ]);

        return compact('user', 'inventory', 'medium', 'list', 'platform');
    }

    /**
     * @param  array<string, mixed>  $ctx
     * @return array<string, mixed>
     */
    private function oaStorePayload(array $ctx): array
    {
        return $this->withLiveSchemaFingerprint([
            'planning_mode' => 'manual',
            'order_discount_percent' => '0',
            'ae_enabled' => false,
            'positions' => [[
                'client_key' => (string) str()->uuid(),
                'inventory_id' => (int) $ctx['inventory']->id,
                'advertising_medium_id' => (int) $ctx['medium']->id,
                'calculation_method_key' => 'tkp',
                'price_year' => PriceListCalendar::currentYear(),
                'expected_oa_tkp_price_list_id' => (int) $ctx['list']->id,
                'impressions' => 100_000,
                'basis_tkp' => '12.0000',
                'oa_platform_allocations' => [[
                    'oa_platform_id' => $ctx['platform']->id,
                    'impressions' => 100_000,
                ]],
                'oa_targeting_selections' => [],
                'position_discount_percent' => '0',
                'ae_percent' => '15',
            ]],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function httpUpdatePayload(Calculation $calculation): array
    {
        $payload = app(CalculationWriter::class)->payloadFromCalculation($calculation->fresh());
        $payload['lock_version'] = $calculation->lock_version;
        foreach ($payload['positions'] as $index => $position) {
            unset(
                $payload['positions'][$index]['engine_profile_key'],
                $payload['positions'][$index]['calculation_method_name'],
                $payload['positions'][$index]['algorithm_version'],
                $payload['positions'][$index]['list_tkp_snapshot'],
                $payload['positions'][$index]['minimum_tkp_snapshot'],
                $payload['positions'][$index]['final_tkp'],
                $payload['positions'][$index]['targeting_surcharge_total'],
                $payload['positions'][$index]['below_minimum_tkp'],
                $payload['positions'][$index]['price_list_id'],
                $payload['positions'][$index]['price_list_version'],
                $payload['positions'][$index]['length_seconds'],
                $payload['positions'][$index]['total_spot_count'],
                $payload['positions'][$index]['plan_rows'],
                $payload['positions'][$index]['time_ranges'],
                $payload['positions'][$index]['planner_entries'],
            );
            if (empty($payload['positions'][$index]['client_key'])) {
                $payload['positions'][$index]['client_key'] = (string) str()->uuid();
            }
        }

        $payload['positions'] = $this->withPositionSchemaFingerprints($calculation, $payload['positions']);

        return $payload;
    }
}
