<?php

namespace Tests\Feature\Calculation;

use App\Enums\Role;
use App\Models\Calculation;
use App\Models\InventoryMediumRule;
use App\Models\PriceList;
use App\Models\User;
use App\Support\PriceList\PriceListCalendar;
use App\Support\PriceList\PriceListYearSelection;
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
 * Abdeckungsgrenze: Average + spot_classic; kein Playwright/UI-Klickpfad;
 * Calendar/Festpreis-Method-Key nicht im Scope.
 */
class EightSpotInventoryCalculabilitySmokeTest extends TestCase
{
    use CreatesEightSpotInventoryCalculabilityCatalog;
    use RefreshDatabase;

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
    public function fixture_matches_pri_ops_coverage_and_mat003_single_exclusions(): void
    {
        $catalog = $this->createEightSpotInventoryCalculabilityCatalog();
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

        $this->assertSame(501, $totalItems);
        $this->assertSame(
            75,
            (8 * 24 * 3) - $totalItems,
            'Import-Raster 8×24×3 minus befüllte Zellen = 75 bewusst fehlende Basiszellen',
        );
    }

    #[Test]
    public function wizard_create_lists_all_eight_inventories_with_bookable_spot_classic(): void
    {
        $catalog = $this->createEightSpotInventoryCalculabilityCatalog();
        $user = User::factory()->role(Role::Sales)->create();

        $response = $this->actingAs($user)->get(route('calculations.create'));
        $response->assertOk();

        $props = $response->original->getData()['page']['props'];
        $catalogProps = $props['catalog'] ?? [];
        $inventoryIds = collect($catalogProps['inventories'] ?? [])->pluck('id')->all();
        $medium = collect($catalogProps['media'] ?? [])->firstWhere('id', $catalog['spotClassic']->id);
        $rules = collect($catalogProps['rules'] ?? []);

        $this->assertNotNull($medium);
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
        $catalog = $this->createEightSpotInventoryCalculabilityCatalog();
        $definition = collect($catalog['definitions'])->firstWhere('code', $inventoryCode);
        $this->assertNotNull($definition);
        $inventory = $catalog['inventories'][$inventoryCode];
        $user = User::factory()->role(Role::Sales)->create();

        $length = 30;
        $spots = 10;
        $hour = (int) $definition['smoke_hour'];
        $expectedGross = $this->expectedMediaGrossFromSecondPrice(
            (string) $definition['second_price'],
            $length,
            $spots,
        );

        $payload = $this->averagePayload(
            $catalog,
            $inventory->id,
            startHour: $hour,
            endHourExclusive: $hour + 1,
            spots: $spots,
            length: $length,
        );

        $preview = $this->actingAs($user)->postJson(route('calculations.preview'), $payload);
        $preview->assertOk();
        $this->assertSame($expectedGross, $preview->json('totals.media_gross'));
        $this->assertSame((string) $definition['second_price'], $preview->json('totals.positions.0.average_second_price'));
        $this->assertSame(10, $preview->json('totals.positions.0.spot_count'));

        $this->actingAs($user)->post(route('calculations.store'), $payload)->assertRedirect();

        $calculation = Calculation::query()->firstOrFail()->load(['positions.timeRanges', 'positions.priceList']);
        $position = $calculation->positions->firstOrFail();

        $this->assertSame($expectedGross, (string) $calculation->media_gross);
        $this->assertSame($inventory->id, (int) $position->inventory_id);
        $this->assertSame($catalog['spotClassic']->id, (int) $position->advertising_medium_id);
        $this->assertSame('average', $position->spot_method?->value ?? (string) $position->spot_method);
        $this->assertSame($catalog['year'], (int) $position->priceList->year);
        $this->assertSame((string) $definition['second_price'], (string) $position->average_second_price);

        $edit = $this->actingAs($user)->get(route('calculations.edit', $calculation));
        $edit->assertOk();
        $pageProps = $edit->original->getData()['page']['props'];
        $editCalc = $pageProps['calculation'];
        $savedSummary = $pageProps['savedSummary'] ?? $pageProps['saved_summary'] ?? null;
        $this->assertSame($calculation->id, $editCalc['id']);
        $this->assertSame($inventory->id, (int) $editCalc['positions'][0]['inventory_id']);
        $this->assertSame($catalog['spotClassic']->id, (int) $editCalc['positions'][0]['advertising_medium_id']);
        $this->assertSame('average', (string) $editCalc['positions'][0]['spot_method']);
        $this->assertSame($catalog['year'], (int) $editCalc['positions'][0]['price_year']);
        $this->assertSame((string) $definition['second_price'], (string) $position->average_second_price);
        if (is_array($savedSummary)) {
            $this->assertSame($expectedGross, (string) $savedSummary['media_gross']);
        }

        $fresh = $calculation->fresh()->load(['positions.priceList']);
        $this->assertSame($expectedGross, (string) $fresh->media_gross);
        $this->assertSame($inventory->id, (int) $fresh->positions->firstOrFail()->inventory_id);
        $this->assertSame($catalog['year'], (int) $fresh->positions->firstOrFail()->priceList->year);
    }

    #[Test]
    public function missing_price_cell_fails_closed_without_zero_price(): void
    {
        $catalog = $this->createEightSpotInventoryCalculabilityCatalog();
        $inventory = $catalog['inventories']['inv_more_hamburg_kombi_plus'];
        $user = User::factory()->role(Role::Sales)->create();

        // Stunde 3 liegt bewusst außerhalb 5–18
        $response = $this->actingAs($user)->postJson(
            route('calculations.preview'),
            $this->averagePayload($catalog, $inventory->id, startHour: 3, endHourExclusive: 4, spots: 5, length: 30),
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
        $catalog = $this->createEightSpotInventoryCalculabilityCatalog();
        $inventory = $catalog['inventories']['inv_more_hamburg_kombi_plus'];
        $user = User::factory()->role(Role::Sales)->create();

        $payload = $this->averagePayload(
            $catalog,
            $inventory->id,
            startHour: 10,
            endHourExclusive: 11,
            spots: 5,
            length: 30,
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
        $catalog = $this->createEightSpotInventoryCalculabilityCatalog(year: PriceListCalendar::currentYear());
        $inventory = $catalog['inventories']['inv_radio_hamburg'];
        $user = User::factory()->role(Role::Sales)->create();
        $nextYear = PriceListYearSelection::nextYear();

        $payload = $this->averagePayload(
            $catalog,
            $inventory->id,
            startHour: 10,
            endHourExclusive: 11,
            spots: 5,
            length: 30,
        );
        $payload['positions'][0]['price_year'] = $nextYear;

        $response = $this->actingAs($user)->postJson(route('calculations.preview'), $payload);
        $response->assertUnprocessable();
        $body = $response->json('message') ?? json_encode($response->json('errors'));
        $expected = PriceListYearSelection::missingYearPriceListMessage($inventory->name, $nextYear);
        $this->assertStringContainsString($expected, (string) $body);
        // Kein stiller Fallback auf 2026-Liste
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
        ?int $calculationId = null,
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
                    'day_group' => 'mo_fr',
                    'spot_count' => $spots,
                ]],
            ]],
        ]);

        if ($calculationId !== null) {
            $payload['id'] = $calculationId;
        }

        // Expected-Token wenn price_year gesetzt und Liste existiert
        $listId = PriceList::query()
            ->where('inventory_id', $inventoryId)
            ->where('year', $catalog['year'])
            ->where('status', 'active')
            ->value('id');
        if ($listId !== null) {
            $payload['positions'][0]['expected_price_list_id'] = (int) $listId;
        }

        return $payload;
    }
}
