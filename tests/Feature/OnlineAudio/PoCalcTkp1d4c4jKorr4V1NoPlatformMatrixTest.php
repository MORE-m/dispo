<?php

namespace Tests\Feature\OnlineAudio;

use App\Enums\EngineCapabilityStatus;
use App\Enums\OaTargetingCategory;
use App\Enums\PriceListStatus;
use App\Enums\Role;
use App\Models\AdvertisingMedium;
use App\Models\Calculation;
use App\Models\CalculationPositionOaPlatformAllocation;
use App\Models\Inventory;
use App\Models\OaPlatform;
use App\Models\OaTargetingDefinition;
use App\Models\OaTkpPriceList;
use App\Models\OaTkpPriceListItem;
use App\Models\User;
use App\Services\Calculation\CalculationWriter;
use App\Services\DispoOrder\DispoOrderWriter;
use App\Services\OnlineAudio\Import\OaTkpImportDecimal;
use App\Support\Calculation\EngineProfileRegistry;
use App\Support\Calculation\OaTkpWizardCapability;
use App\Support\OnlineAudio\OaTkpCoreMedia;
use App\Support\PriceList\PriceListCalendar;
use Database\Seeders\InitialCatalogMatCoreSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\EnsuresOaTkpInventoryMediumRules;
use Tests\TestCase;

/**
 * PO-CALC-TKP-1D4c-4j-KORR-4: V1-Abnahmematrix ohne Plattformauswahl.
 *
 * Deckt den Happy Path je Kernmedium (Preview/Save/0 Allocations/Adoption/Show),
 * Midroll-Bundle, historische Allocations und Future-Validierung nicht-leerer Zeilen.
 * Keine Registry-Freigabe, keine Runtime-Fachlogik.
 */
class PoCalcTkp1d4c4jKorr4V1NoPlatformMatrixTest extends TestCase
{
    use EnsuresOaTkpInventoryMediumRules;
    use RefreshDatabase;

    protected function tearDown(): void
    {
        OaTkpWizardCapability::clearHttpMutationFakeForTests();
        parent::tearDown();
    }

    /**
     * @return list<array{0: string}>
     */
    public static function v1MediumCodes(): array
    {
        return array_map(
            static fn (string $code): array => [$code],
            OaTkpCoreMedia::codes(),
        );
    }

    #[Test]
    public function registry_remains_planned(): void
    {
        $this->assertSame(
            EngineCapabilityStatus::Released,
            EngineProfileRegistry::pairStatus('oa_tkp', 'tkp'),
        );
        $this->assertSame('v1', EngineProfileRegistry::currentReleasedVersion('oa_tkp', 'tkp'));
    }

    #[Test]
    #[DataProvider('v1MediumCodes')]
    public function v1_medium_happy_path_without_platforms(string $mediumCode): void
    {
        $ctx = $this->seedContextForMedium($mediumCode);
        $this->simulateRelease();
        config([OaTkpWizardCapability::CONFIG_KEY => true]);

        $payload = $this->oaStorePayload($ctx, [
            'oa_platform_allocations' => [],
            'oa_targeting_selections' => [
                ['targeting_code' => $ctx['targeting']->code],
            ],
        ]);

        $preview = $this->actingAs($ctx['user'])
            ->postJson(route('calculations.preview'), $payload)
            ->assertOk();
        $tkp = $preview->json('totals.positions.0.method_result.tkp');
        $this->assertSame('10.0000', $tkp['basis_tkp']);
        $this->assertSame('12.0000', $tkp['list_tkp']);
        $this->assertSame('8.0000', $tkp['minimum_tkp']);
        $this->assertSame('2.0000', $tkp['targeting_surcharge_total']);
        $this->assertSame('12.0000', $tkp['final_tkp']);
        $this->assertSame('1200.00', $preview->json('totals.positions.0.media_gross'));

        $this->actingAs($ctx['user'])
            ->post(route('calculations.store'), $payload)
            ->assertRedirect();

        $calculation = Calculation::query()->firstOrFail();
        $position = $calculation->positions()->firstOrFail();
        $this->assertSame($mediumCode, (string) $position->advertising_medium_code);
        $this->assertSame(0, $position->oaPlatformAllocations()->count());
        $this->assertSame(0, CalculationPositionOaPlatformAllocation::query()->count());
        $this->assertSame(0, OaTkpImportDecimal::compare((string) $position->final_tkp, '12.0000'));
        $this->assertSame('1200.00', (string) $position->media_gross);

        $reloaded = app(CalculationWriter::class)->payloadFromCalculation($calculation->fresh());
        $this->assertSame([], $reloaded['positions'][0]['oa_platform_allocations']);
        $this->assertSame($mediumCode, AdvertisingMedium::query()
            ->findOrFail((int) $reloaded['positions'][0]['advertising_medium_id'])
            ->code);

        $order = app(DispoOrderWriter::class)
            ->createFromCalculation($calculation->fresh(), [(int) $position->id], $ctx['user'])
            ->order;
        $snap = $order->positions()->firstOrFail();
        $this->assertSame([], $snap->oa_platform_allocations_snapshot);
        $this->assertSame(100_000, (int) $snap->impressions);
        $this->assertSame(0, OaTkpImportDecimal::compare((string) $snap->final_tkp, '12.0000'));

        $this->actingAs($ctx['user'])
            ->get(route('dispo-orders.show', $order))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('dispo-orders/show')
                ->where('order.positions.0.oa_platform_allocations_snapshot', [])
                ->where('order.positions.0.impressions', 100_000)
                ->where('order.positions.0.final_tkp', '12.0000')
                ->where('order.positions.0.calculation_method_key', 'tkp'));

        $this->assertSame($mediumCode, (string) $snap->advertising_medium_code);
    }

    #[Test]
    #[DataProvider('v1MediumCodes')]
    public function empty_platforms_do_not_change_price_versus_allocations_present(string $mediumCode): void
    {
        $ctx = $this->seedContextForMedium($mediumCode);
        $writer = app(CalculationWriter::class);

        $withPlatforms = $writer->create($this->oaStorePayload($ctx, [
            'oa_platform_allocations' => [[
                'oa_platform_id' => (int) $ctx['platform']->id,
                'impressions' => 100_000,
            ]],
            'oa_targeting_selections' => [
                ['targeting_code' => $ctx['targeting']->code],
            ],
        ]), $ctx['user'])->positions->firstOrFail();

        $withoutPlatforms = $writer->create($this->oaStorePayload($ctx, [
            'oa_platform_allocations' => [],
            'oa_targeting_selections' => [
                ['targeting_code' => $ctx['targeting']->code],
            ],
        ]), $ctx['user'])->positions->firstOrFail();

        $this->assertSame(0, OaTkpImportDecimal::compare(
            (string) $withPlatforms->basis_tkp,
            (string) $withoutPlatforms->basis_tkp,
        ));
        $this->assertSame(0, OaTkpImportDecimal::compare(
            (string) $withPlatforms->minimum_tkp_snapshot,
            (string) $withoutPlatforms->minimum_tkp_snapshot,
        ));
        $this->assertSame(0, OaTkpImportDecimal::compare(
            (string) $withPlatforms->targeting_surcharge_total,
            (string) $withoutPlatforms->targeting_surcharge_total,
        ));
        $this->assertSame(0, OaTkpImportDecimal::compare(
            (string) $withPlatforms->final_tkp,
            (string) $withoutPlatforms->final_tkp,
        ));
        $this->assertSame((string) $withPlatforms->media_gross, (string) $withoutPlatforms->media_gross);
        $this->assertSame(0, $withoutPlatforms->oaPlatformAllocations()->count());
        $this->assertSame(1, $withPlatforms->oaPlatformAllocations()->count());
    }

    #[Test]
    public function midroll_music_environment_is_spotify_youtube_deezer_bundle_without_allocations(): void
    {
        $ctx = $this->seedContextForMedium('midroll_music_environment');
        $name = (string) $ctx['medium']->name;
        $this->assertMatchesRegularExpression('/spotify/i', $name);
        $this->assertMatchesRegularExpression('/youtube/i', $name);
        $this->assertMatchesRegularExpression('/deezer/i', $name);

        $this->simulateRelease();

        $this->actingAs($ctx['user'])
            ->post(route('calculations.store'), $this->oaStorePayload($ctx, [
                'oa_platform_allocations' => [],
            ]))
            ->assertRedirect();

        $position = Calculation::query()->firstOrFail()->positions()->firstOrFail();
        $this->assertSame('midroll_music_environment', (string) $position->advertising_medium_code);
        $this->assertSame(0, $position->oaPlatformAllocations()->count());
        $this->assertSame(0, OaTkpImportDecimal::compare((string) $position->final_tkp, '10.0000'));
    }

    #[Test]
    public function historical_position_with_platforms_remains_readable_in_snapshot_and_show(): void
    {
        $ctx = $this->seedContextForMedium('pre_stream');
        $calc = app(CalculationWriter::class)->create($this->oaStorePayload($ctx, [
            'oa_platform_allocations' => [[
                'oa_platform_id' => (int) $ctx['platform']->id,
                'impressions' => 100_000,
            ]],
        ]), $ctx['user']);
        $position = $calc->positions->firstOrFail();
        $this->assertSame(1, $position->oaPlatformAllocations()->count());

        $order = app(DispoOrderWriter::class)
            ->createFromCalculation($calc, [(int) $position->id], $ctx['user'])
            ->order;
        $snap = $order->positions()->firstOrFail();
        $platforms = $snap->oa_platform_allocations_snapshot;
        $this->assertIsArray($platforms);
        $this->assertCount(1, $platforms);
        $this->assertSame((int) $ctx['platform']->id, (int) $platforms[0]['oa_platform_id']);
        $this->assertSame($ctx['platform']->code, $platforms[0]['platform_code']);
        $this->assertSame(100_000, (int) $platforms[0]['impressions']);

        $frozenName = $platforms[0]['platform_name'];
        $ctx['platform']->forceFill(['name' => 'LIVE RENAMED PLATFORM'])->save();

        $this->actingAs($ctx['user'])
            ->get(route('dispo-orders.show', $order))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('dispo-orders/show')
                ->where('order.positions.0.oa_platform_allocations_snapshot.0.platform_name', $frozenName)
                ->where('order.positions.0.oa_platform_allocations_snapshot.0.impressions', 100_000));
        $this->assertNotSame('LIVE RENAMED PLATFORM', $frozenName);
    }

    #[Test]
    public function non_empty_allocations_remain_validated(): void
    {
        $ctx = $this->seedContextForMedium('in_stream');
        $this->simulateRelease();

        $unknown = $this->oaStorePayload($ctx, [
            'oa_platform_allocations' => [[
                'platform_code' => 'does_not_exist',
                'impressions' => 100_000,
            ]],
        ]);
        $this->actingAs($ctx['user'])->postJson(route('calculations.store'), $unknown)
            ->assertStatus(422);
        $this->assertSame(0, Calculation::query()->count());

        $badSum = $this->oaStorePayload($ctx, [
            'oa_platform_allocations' => [[
                'oa_platform_id' => (int) $ctx['platform']->id,
                'impressions' => 10,
            ]],
        ]);
        $this->actingAs($ctx['user'])->postJson(route('calculations.store'), $badSum)
            ->assertStatus(422);
        $this->assertSame(0, Calculation::query()->count());
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
    private function seedContextForMedium(string $mediumCode): array
    {
        $this->seed(InitialCatalogMatCoreSeeder::class);
        $this->ensureOaInventoryMediumRules([
            [$mediumCode, 'inv_more_kombi_online_audio'],
        ]);

        $user = User::factory()->role(Role::Sales)->create();
        $medium = AdvertisingMedium::query()->where('code', $mediumCode)->firstOrFail();
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
            'code' => 'fixture_tgt_korr4_'.$mediumCode,
            'name' => 'Fixture Targeting KORR4 '.$mediumCode,
            'category' => OaTargetingCategory::Technisch,
            'tkp_surcharge' => '2.0000',
            'revision_number' => 1,
            'is_active' => true,
        ]);
        $platform = OaPlatform::query()->create([
            'code' => 'fixture_plat_korr4_'.$mediumCode,
            'name' => 'Fixture Platform KORR4 '.$mediumCode,
            'is_active' => true,
            'sort' => 1,
            'is_adserver_relevant' => false,
        ]);

        return compact('user', 'medium', 'inventory', 'list', 'targeting', 'platform');
    }

    private function simulateRelease(): void
    {
        OaTkpWizardCapability::fakeHttpMutationAllowedForTests(true);
    }

    /**
     * @param  array<string, mixed>  $ctx
     * @param  array<string, mixed>  $override
     * @return array<string, mixed>
     */
    private function oaStorePayload(array $ctx, array $override = []): array
    {
        return $this->withLiveSchemaFingerprint([
            'planning_mode' => 'manual',
            'order_discount_percent' => '0',
            'ae_enabled' => false,
            'positions' => [$this->oaPosition($ctx, $override)],
        ]);
    }

    /**
     * @param  array<string, mixed>  $ctx
     * @param  array<string, mixed>  $override
     * @return array<string, mixed>
     */
    private function oaPosition(array $ctx, array $override = []): array
    {
        return array_merge([
            'client_key' => (string) str()->uuid(),
            'inventory_id' => (int) $ctx['inventory']->id,
            'advertising_medium_id' => (int) $ctx['medium']->id,
            'calculation_method_key' => 'tkp',
            'price_year' => PriceListCalendar::currentYear(),
            'expected_oa_tkp_price_list_id' => (int) $ctx['list']->id,
            'impressions' => 100_000,
            'basis_tkp' => '10.0000',
            'oa_platform_allocations' => [],
            'oa_targeting_selections' => [],
            'position_discount_percent' => '0',
            'ae_percent' => '15',
        ], $override);
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
            'name' => "Fixture KORR4 {$year}.{$revision}-{$mediumId}",
            'version' => "{$year}.{$revision}.{$mediumId}",
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
