<?php

namespace Tests\Feature\Calculation;

use App\Enums\Role;
use App\Models\Calculation;
use App\Models\InventoryMediumRule;
use App\Models\PriceList;
use App\Models\User;
use App\Support\PriceList\PriceListYearSelection;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesEightSpotInventoryCalculabilityCatalog;
use Tests\TestCase;

/**
 * Isolierter 8-Inventar-Kalkulierbarkeits-Smoke (Feature/Pest, SQLite :memory:).
 *
 * Deckt Preview → Store → Reload für alle acht MORE-Spot-Inventare plus
 * repräsentative Negativfälle. Kein Zugriff auf dispo_mat_core / dispo.
 *
 * Abdeckungsgrenze: Average + spot_classic; Backend-Wizard-Props (kein Browser-
 * Klickpfad); synthetische Fixture-Preise (keine realen Workbook-Werte).
 * „501/75“ ist nachgebildetes Abdeckungsmuster, kein Import-Validierungstest
 * (Importer: MoreSpotkalkulationMatCoreImportTest / WorkbookParserTest).
 */
class EightSpotInventoryCalculabilitySmokeTest extends TestCase
{
    use CreatesEightSpotInventoryCalculabilityCatalog;
    use RefreshDatabase;

    private const FROZEN_NOW = '2026-06-15 12:00:00';

    private const FROZEN_TIMEZONE = 'Europe/Berlin';

    private const FIXTURE_YEAR = 2026;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse(self::FROZEN_NOW, self::FROZEN_TIMEZONE));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function eightInventoryCodes(): array
    {
        return [
            'radio_hamburg' => ['inv_radio_hamburg'],
            'rock_antenne' => ['inv_rock_antenne_hamburg'],
            'oldie_antenne' => ['inv_80er_90er_oldie_antenne_hamburg'],
            'caravan_fm' => ['inv_caravan_fm'],
            'more_kombi' => ['inv_more_hamburg_kombi'],
            'more_kombi_plus' => ['inv_more_hamburg_kombi_plus'],
            'ffn' => ['inv_ffn_hamburg_plus'],
            'bollerwagen' => ['inv_radio_bollerwagen_dab_plus_hamburg'],
        ];
    }

    #[Test]
    public function fixture_matches_pri_ops_coverage_pattern_and_mat003_single_exclusions(): void
    {
        $catalog = $this->createEightSpotInventoryCalculabilityCatalog(year: self::FIXTURE_YEAR);
        $this->assertCount(8, $catalog['definitions']);

        $totalItems = 0;
        foreach ($catalog['definitions'] as $definition) {
            $inventory = $catalog['inventories'][$definition['code']];
            $list = $inventory->priceLists()->where('year', $catalog['year'])->where('status', 'active')->firstOrFail();
            $this->assertSame(
                $definition['expected_items'],
                $list->items()->count(),
                $definition['code'].' item count',
            );
            $totalItems += $list->items()->count();

            $hasSingle = InventoryMediumRule::query()
                ->where('inventory_id', $inventory->id)
                ->where('advertising_medium_id', $catalog['spotSingle']->id)
                ->where('is_active', true)
                ->exists();
            $this->assertSame($definition['allows_single'], $hasSingle, $definition['code'].' MAT-003 single');
        }

        // Nachgebildetes PRI-OPS-1-Muster (nicht Import-Validierung).
        $this->assertSame(501, $totalItems);
        $this->assertSame(75, (8 * 24 * 3) - $totalItems);
    }

    #[Test]
    public function wizard_create_lists_all_eight_inventories_with_bookable_spot_classic(): void
    {
        $catalog = $this->createEightSpotInventoryCalculabilityCatalog(year: self::FIXTURE_YEAR);
        $user = User::factory()->role(Role::Sales)->create();

        $response = $this->actingAs($user)->get(route('calculations.create'));
        $response->assertOk();

        $props = $response->original->getData()['page']['props'];
        $catalogProps = $props['catalog'];
        $this->assertIsArray($catalogProps);
        $this->assertArrayHasKey('inventories', $catalogProps);
        $this->assertArrayHasKey('media', $catalogProps);
        $this->assertArrayHasKey('rules', $catalogProps);

        $inventoryIds = collect($catalogProps['inventories'])->pluck('id')->all();
        $medium = collect($catalogProps['media'])->firstWhere('id', $catalog['spotClassic']->id);
        $rules = collect($catalogProps['rules']);

        $this->assertIsArray($medium);
        $this->assertTrue($medium['is_bookable_for_new_positions']);
        $methodKeys = collect($medium['calculation_method_options'] ?? [])
            ->map(fn ($option) => is_array($option) ? ($option['key'] ?? $option['value'] ?? null) : $option)
            ->filter()
            ->values()
            ->all();
        $this->assertContains('average', $methodKeys);

        foreach ($catalog['inventories'] as $inventory) {
            $this->assertContains($inventory->id, $inventoryIds, $inventory->code.' in wizard');
            $this->assertTrue(
                $rules->contains(
                    fn (array $rule): bool => (int) $rule['inventory_id'] === (int) $inventory->id
                        && (int) $rule['advertising_medium_id'] === (int) $catalog['spotClassic']->id
                        && (bool) ($rule['is_active'] ?? false),
                ),
                $inventory->code.' hat aktive spot_classic-Regel im Wizard',
            );
        }
    }

    #[Test]
    #[DataProvider('eightInventoryCodes')]
    public function preview_store_and_reload_preserve_price_snapshot_for_inventory(string $inventoryCode): void
    {
        $catalog = $this->createEightSpotInventoryCalculabilityCatalog(year: self::FIXTURE_YEAR);
        $definition = collect($catalog['definitions'])->firstWhere('code', $inventoryCode);
        $this->assertNotNull($definition);
        $inventory = $catalog['inventories'][$inventoryCode];
        $user = User::factory()->role(Role::Sales)->create();

        $length = 30;
        $spots = 10;
        $hour = (int) $definition['smoke_hour'];
        $endHourExclusive = $hour + 1;
        $dayGroup = 'mo_fr';
        $expectedGross = $this->expectedMediaGrossFromSecondPrice(
            (string) $definition['second_price'],
            $length,
            $spots,
        );
        $expectedPriceListId = (int) PriceList::query()
            ->where('inventory_id', $inventory->id)
            ->where('year', self::FIXTURE_YEAR)
            ->where('status', 'active')
            ->value('id');
        $this->assertGreaterThan(0, $expectedPriceListId);

        $payload = $this->averagePayload(
            $catalog,
            $inventory->id,
            startHour: $hour,
            endHourExclusive: $endHourExclusive,
            spots: $spots,
            length: $length,
            dayGroup: $dayGroup,
            expectedPriceListId: $expectedPriceListId,
        );

        $preview = $this->actingAs($user)->postJson(route('calculations.preview'), $payload);
        $preview->assertOk();
        $this->assertSame($expectedGross, $preview->json('totals.media_gross'));
        $this->assertSame((string) $definition['second_price'], $preview->json('totals.positions.0.average_second_price'));
        $this->assertSame($spots, $preview->json('totals.positions.0.spot_count'));

        $this->actingAs($user)->post(route('calculations.store'), $payload)->assertRedirect();

        $calculation = Calculation::query()->firstOrFail()->load(['positions.timeRanges', 'positions.priceList']);
        $position = $calculation->positions->firstOrFail();
        $range = $position->timeRanges->firstOrFail();

        $this->assertSame($expectedGross, (string) $calculation->media_gross);
        $this->assertSame($inventory->id, (int) $position->inventory_id);
        $this->assertSame($catalog['spotClassic']->id, (int) $position->advertising_medium_id);
        $this->assertSame('average', $position->spot_method?->value ?? (string) $position->spot_method);
        $this->assertSame($expectedPriceListId, (int) $position->price_list_id);
        $this->assertSame(self::FIXTURE_YEAR, (int) $position->priceList->year);
        $this->assertSame((string) $definition['second_price'], (string) $position->average_second_price);
        $this->assertSame($hour, (int) $range->start_hour);
        $this->assertSame($endHourExclusive, (int) $range->end_hour_exclusive);
        $this->assertSame($dayGroup, $range->day_group->value);
        $this->assertSame($spots, (int) $range->spot_count);

        $edit = $this->actingAs($user)->get(route('calculations.edit', $calculation));
        $edit->assertOk();
        $pageProps = $edit->original->getData()['page']['props'];
        $this->assertArrayHasKey('calculation', $pageProps);
        $this->assertArrayHasKey('savedSummary', $pageProps);

        $editCalc = $pageProps['calculation'];
        $savedSummary = $pageProps['savedSummary'];
        $this->assertIsArray($editCalc);
        $this->assertIsArray($savedSummary);
        $this->assertArrayHasKey('media_gross', $savedSummary);
        $this->assertSame($expectedGross, (string) $savedSummary['media_gross']);

        $this->assertSame($calculation->id, $editCalc['id']);
        $editPosition = $editCalc['positions'][0];
        $this->assertSame($inventory->id, (int) $editPosition['inventory_id']);
        $this->assertSame($catalog['spotClassic']->id, (int) $editPosition['advertising_medium_id']);
        $this->assertSame('average', (string) $editPosition['spot_method']);
        $this->assertSame(self::FIXTURE_YEAR, (int) $editPosition['price_year']);
        $this->assertSame($expectedPriceListId, (int) $editPosition['expected_price_list_id']);
        $this->assertIsArray($editPosition['time_ranges']);
        $this->assertCount(1, $editPosition['time_ranges']);
        $this->assertSame($hour, (int) $editPosition['time_ranges'][0]['start_hour']);
        $this->assertSame($endHourExclusive, (int) $editPosition['time_ranges'][0]['end_hour_exclusive']);
        $this->assertSame($dayGroup, (string) $editPosition['time_ranges'][0]['day_group']);
        $this->assertSame($spots, (int) $editPosition['time_ranges'][0]['spot_count']);

        $fresh = $calculation->fresh()->load(['positions.priceList', 'positions.timeRanges']);
        $this->assertSame($expectedGross, (string) $fresh->media_gross);
        $this->assertSame($inventory->id, (int) $fresh->positions->firstOrFail()->inventory_id);
        $this->assertSame($expectedPriceListId, (int) $fresh->positions->firstOrFail()->price_list_id);
        $this->assertSame(self::FIXTURE_YEAR, (int) $fresh->positions->firstOrFail()->priceList->year);
    }

    #[Test]
    public function missing_price_cell_fails_closed_without_zero_price(): void
    {
        $catalog = $this->createEightSpotInventoryCalculabilityCatalog(year: self::FIXTURE_YEAR);
        $inventory = $catalog['inventories']['inv_more_hamburg_kombi_plus'];
        $user = User::factory()->role(Role::Sales)->create();
        $expectedPriceListId = (int) PriceList::query()
            ->where('inventory_id', $inventory->id)
            ->where('year', self::FIXTURE_YEAR)
            ->where('status', 'active')
            ->value('id');

        // Stunde 3 liegt bewusst außerhalb 5–18
        $response = $this->actingAs($user)->postJson(
            route('calculations.preview'),
            $this->averagePayload(
                $catalog,
                $inventory->id,
                startHour: 3,
                endHourExclusive: 4,
                spots: 5,
                length: 30,
                expectedPriceListId: $expectedPriceListId,
            ),
        );

        $response->assertUnprocessable();
        $body = $response->json('message') ?? json_encode($response->json('errors'));
        $this->assertStringContainsString('MORE Hamburg-Kombi+', (string) $body);
        $this->assertStringContainsString('fehlen Preise', (string) $body);
        $this->assertStringNotContainsString('"media_gross":"0', json_encode($response->json()) ?: '');
    }

    #[Test]
    public function mat003_single_spot_on_kombi_plus_is_rejected(): void
    {
        $catalog = $this->createEightSpotInventoryCalculabilityCatalog(year: self::FIXTURE_YEAR);
        $inventory = $catalog['inventories']['inv_more_hamburg_kombi_plus'];
        $user = User::factory()->role(Role::Sales)->create();
        $expectedPriceListId = (int) PriceList::query()
            ->where('inventory_id', $inventory->id)
            ->where('year', self::FIXTURE_YEAR)
            ->where('status', 'active')
            ->value('id');

        $payload = $this->averagePayload(
            $catalog,
            $inventory->id,
            startHour: 10,
            endHourExclusive: 11,
            spots: 5,
            length: 30,
            expectedPriceListId: $expectedPriceListId,
        );
        $payload['positions'][0]['advertising_medium_id'] = $catalog['spotSingle']->id;

        $response = $this->actingAs($user)->postJson(route('calculations.preview'), $payload);
        $response->assertUnprocessable();
        $body = $response->json('message') ?? json_encode($response->json('errors'));
        $this->assertStringContainsString('nicht zulässig', (string) $body);
    }

    #[Test]
    public function year_without_active_price_list_fails_closed_without_fallback(): void
    {
        $catalog = $this->createEightSpotInventoryCalculabilityCatalog(year: self::FIXTURE_YEAR);
        $inventory = $catalog['inventories']['inv_radio_hamburg'];
        $user = User::factory()->role(Role::Sales)->create();
        $nextYear = PriceListYearSelection::nextYear();
        $this->assertSame(2027, $nextYear);

        $payload = $this->averagePayload(
            $catalog,
            $inventory->id,
            startHour: 10,
            endHourExclusive: 11,
            spots: 5,
            length: 30,
            expectedPriceListId: null,
        );
        $payload['positions'][0]['price_year'] = $nextYear;

        $response = $this->actingAs($user)->postJson(route('calculations.preview'), $payload);
        $response->assertUnprocessable();
        $body = $response->json('message') ?? json_encode($response->json('errors'));
        $expected = PriceListYearSelection::missingYearPriceListMessage($inventory->name, $nextYear);
        $this->assertStringContainsString($expected, (string) $body);
        $this->assertNull($response->json('totals.media_gross'));
    }

    /**
     * @param  array{spotClassic: mixed, year: int}  $catalog
     * @return array<string, mixed>
     */
    private function averagePayload(
        array $catalog,
        int $inventoryId,
        int $startHour,
        int $endHourExclusive,
        int $spots,
        int $length,
        string $dayGroup = 'mo_fr',
        ?int $expectedPriceListId = null,
    ): array {
        $payload = $this->withLiveSchemaFingerprint([
            'planning_mode' => 'manual',
            'order_discount_percent' => '0',
            'ae_enabled' => false,
            'positions' => [[
                'inventory_id' => $inventoryId,
                'advertising_medium_id' => $catalog['spotClassic']->id,
                'spot_method' => 'average',
                'length_seconds' => $length,
                'position_discount_percent' => '0',
                'ae_percent' => '0',
                'price_year' => $catalog['year'],
                'time_ranges' => [[
                    'start_hour' => $startHour,
                    'end_hour_exclusive' => $endHourExclusive,
                    'day_group' => $dayGroup,
                    'spot_count' => $spots,
                ]],
            ]],
        ]);

        if ($expectedPriceListId !== null) {
            $payload['positions'][0]['expected_price_list_id'] = $expectedPriceListId;
        }

        return $payload;
    }
}
