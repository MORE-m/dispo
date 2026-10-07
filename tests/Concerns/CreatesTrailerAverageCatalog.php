<?php

namespace Tests\Concerns;

use App\Enums\DayGroup;
use App\Enums\PriceListStatus;
use App\Models\AdvertisingCategory;
use App\Models\AdvertisingCategoryCalculationMethod;
use App\Models\AdvertisingMedium;
use App\Models\CalculationMethod;
use App\Models\Inventory;
use App\Models\InventoryMediumRule;
use App\Models\Organization;
use App\Models\PriceList;
use App\Models\PriceListItem;
use App\Support\Advertising\CanonicalAdvertisingCategories;
use App\Support\Calculation\EngineProfileRegistry;
use App\Support\PriceList\PriceListCalendar;

/**
 * BL-P5-01a / PO-BLP501A-1 (A1 + B1): synthetische Trailer-Average-Fixtures.
 *
 * Zwei Trailer-Inventare (kein Anspruch auf operative Werte; keine RHH-Seeds für ROCK/OLDIE/CARAVAN):
 *
 * - A: Ø-Sekundenpreis 2,00 · Länge 20 s · Aufschlag 30 %  → 10 × 2,00 × 20 × 1,30 = 520,00
 * - B: Ø-Sekundenpreis 1,50 · Länge 15 s · Aufschlag 50 %  → 5 × 1,50 × 15 × 1,50 = 168,75
 *
 * Summe A + B = 688,75 (vor Rabatt/AE).
 *
 * Die Kategorie-Zuordnung average → swf_trailer wird hier explizit angelegt (frische Test-DB
 * hat bewusst nur den ADV-001c2-Spot-Katalog; Produktivpfad: Migration/Bootstrapper).
 */
trait CreatesTrailerAverageCatalog
{
    /**
     * @return array{
     *     organization: Organization,
     *     trailer: AdvertisingMedium,
     *     spot: AdvertisingMedium,
     *     otherSwf: AdvertisingMedium,
     *     a: Inventory,
     *     b: Inventory,
     *     ruleA: InventoryMediumRule,
     *     ruleB: InventoryMediumRule,
     *     listA: PriceList,
     *     listB: PriceList
     * }
     */
    protected function createTrailerAverageCatalog(): array
    {
        $this->assignSwfCategoryAverageToTrailerProfile();

        $organization = Organization::factory()->create();
        $trailer = AdvertisingMedium::factory()->swfTrailer()->create();
        $spot = AdvertisingMedium::factory()->create();
        $otherSwf = AdvertisingMedium::factory()->swfWithoutKind()->create();

        $a = Inventory::factory()->create([
            'organization_id' => $organization->id,
            'name' => 'Trailer Testsender A',
            'code' => 'TTA',
            'sort' => 1,
        ]);
        $b = Inventory::factory()->create([
            'organization_id' => $organization->id,
            'name' => 'Trailer Testsender B',
            'code' => 'TTB',
            'sort' => 2,
        ]);

        $ruleA = InventoryMediumRule::factory()->create([
            'inventory_id' => $a->id,
            'advertising_medium_id' => $trailer->id,
            'default_length_seconds' => 20,
            'surcharge_percent' => '30',
        ]);
        $ruleB = InventoryMediumRule::factory()->create([
            'inventory_id' => $b->id,
            'advertising_medium_id' => $trailer->id,
            'default_length_seconds' => 15,
            'surcharge_percent' => '50',
        ]);

        foreach ([$a, $b] as $inventory) {
            InventoryMediumRule::factory()->create([
                'inventory_id' => $inventory->id,
                'advertising_medium_id' => $spot->id,
                'default_length_seconds' => 30,
                'surcharge_percent' => '0',
            ]);
            InventoryMediumRule::factory()->create([
                'inventory_id' => $inventory->id,
                'advertising_medium_id' => $otherSwf->id,
            ]);
        }

        $listA = $this->createTrailerPriceList($a, '2.0000');
        $listB = $this->createTrailerPriceList($b, '1.5000');

        return [
            'organization' => $organization,
            'trailer' => $trailer,
            'spot' => $spot,
            'otherSwf' => $otherSwf,
            'a' => $a,
            'b' => $b,
            'ruleA' => $ruleA,
            'ruleB' => $ruleB,
            'listA' => $listA,
            'listB' => $listB,
        ];
    }

    protected function assignSwfCategoryAverageToTrailerProfile(): void
    {
        $category = AdvertisingCategory::query()
            ->where('key', CanonicalAdvertisingCategories::SPECIAL_ADVERTISING_FORMATS)
            ->firstOrFail();
        $average = CalculationMethod::query()->where('key', 'average')->firstOrFail();

        $assignment = AdvertisingCategoryCalculationMethod::query()
            ->where('advertising_category_id', $category->id)
            ->where('calculation_method_id', $average->id)
            ->first() ?? new AdvertisingCategoryCalculationMethod;
        $assignment->advertising_category_id = $category->id;
        $assignment->calculation_method_id = $average->id;
        $assignment->engine_profile_key = EngineProfileRegistry::PROFILE_SWF_TRAILER;
        $assignment->is_active = true;
        $assignment->sort = 10;
        $assignment->lock_version = 1;
        $assignment->save();

        $category->default_calculation_method_id = $average->id;
        $category->save();
    }

    protected function createTrailerPriceList(Inventory $inventory, string $secondPrice): PriceList
    {
        $list = PriceList::factory()->create([
            'inventory_id' => $inventory->id,
            'status' => PriceListStatus::Active,
            'year' => PriceListCalendar::currentYear(),
            'version' => 'trailer-'.$inventory->code,
            'valid_from' => now()->toDateString(),
        ]);

        foreach (range(0, 23) as $hour) {
            foreach ([DayGroup::MoFr, DayGroup::Sa, DayGroup::So] as $group) {
                PriceListItem::factory()->create([
                    'price_list_id' => $list->id,
                    'hour' => $hour,
                    'day_group' => $group,
                    'second_price' => $secondPrice,
                ]);
            }
        }

        return $list;
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    protected function trailerPosition(Inventory $inventory, AdvertisingMedium $trailer, int $spots, array $overrides = []): array
    {
        // Wizard-Verhalten: Länge = Regel-Standardlänge, sonst Medium-Default (30) – serverseitig
        // darf Letzteres für Trailer NICHT als gültige Konfiguration durchgehen.
        $ruleLength = InventoryMediumRule::query()
            ->where('inventory_id', $inventory->id)
            ->where('advertising_medium_id', $trailer->id)
            ->value('default_length_seconds');

        return array_merge([
            'inventory_id' => $inventory->id,
            'advertising_medium_id' => $trailer->id,
            'spot_method' => 'average',
            'length_seconds' => (int) ($ruleLength ?? $trailer->default_length_seconds),
            'position_discount_percent' => '0',
            'ae_percent' => '0',
            'time_ranges' => [[
                'start_hour' => 8,
                'end_hour_exclusive' => 9,
                'day_group' => 'mo_fr',
                'spot_count' => $spots,
                'sort' => 0,
            ]],
        ], $overrides);
    }

    /**
     * @param  list<array<string, mixed>>  $positions
     * @return array<string, mixed>
     */
    protected function trailerPayload(array $positions, array $overrides = []): array
    {
        return $this->withLiveSchemaFingerprint(array_merge([
            'planning_mode' => 'manual',
            'order_discount_percent' => '0',
            'positions' => $positions,
        ], $overrides));
    }
}
