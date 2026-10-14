<?php

namespace Tests\Concerns;

use App\Enums\CalculationMethodMode;
use App\Enums\DayGroup;
use App\Enums\PriceListStatus;
use App\Models\AdvertisingMedium;
use App\Models\AdvertisingMediumCalculationMethod;
use App\Models\CalculationMethod;
use App\Models\Inventory;
use App\Models\InventoryMediumRule;
use App\Models\Organization;
use App\Models\PriceList;
use App\Models\PriceListItem;
use App\Support\Calculation\EngineProfileRegistry;
use App\Support\PriceList\PriceListCalendar;

/**
 * PO-ALLONGE-1: synthetische Allonge-Fixtures. Keine operativen Startwerte.
 *
 * - A: 2,00 €/s · Default 10 s · +30 % · bestätigt
 * - B: 3,00 €/s · Default 20 s · +10 % · bestätigt
 * - C: 30 s / 0 % · nicht bestätigt (Altdefault)
 */
trait CreatesAllongeAverageCatalog
{
    /**
     * @return array{
     *     organization: Organization,
     *     allonge: AdvertisingMedium,
     *     spot: AdvertisingMedium,
     *     a: Inventory,
     *     b: Inventory,
     *     c: Inventory,
     *     ruleA: InventoryMediumRule,
     *     ruleB: InventoryMediumRule,
     *     ruleC: InventoryMediumRule,
     *     listA: PriceList,
     *     listB: PriceList,
     *     listC: PriceList
     * }
     */
    protected function createAllongeAverageCatalog(): array
    {
        $organization = Organization::factory()->create();
        $average = CalculationMethod::query()->where('key', 'average')->firstOrFail();
        $allonge = AdvertisingMedium::factory()->swfAllonge()->create([
            'calculation_method_mode' => CalculationMethodMode::Override,
            'default_calculation_method_id' => $average->id,
        ]);
        $assignment = new AdvertisingMediumCalculationMethod;
        $assignment->advertising_medium_id = $allonge->id;
        $assignment->calculation_method_id = $average->id;
        $assignment->engine_profile_key = EngineProfileRegistry::PROFILE_SWF_ALLONGE;
        $assignment->is_active = true;
        $assignment->sort = 10;
        $assignment->lock_version = 1;
        $assignment->save();

        $spot = AdvertisingMedium::factory()->create();

        $a = Inventory::factory()->create([
            'organization_id' => $organization->id,
            'name' => 'Allonge Testsender A',
            'code' => 'ATA',
            'sort' => 1,
        ]);
        $b = Inventory::factory()->create([
            'organization_id' => $organization->id,
            'name' => 'Allonge Testsender B',
            'code' => 'ATB',
            'sort' => 2,
        ]);
        $c = Inventory::factory()->create([
            'organization_id' => $organization->id,
            'name' => 'Allonge Testsender C',
            'code' => 'ATC',
            'sort' => 3,
        ]);

        $confirmedAt = now()->subDay();
        $ruleA = InventoryMediumRule::factory()->create([
            'inventory_id' => $a->id,
            'advertising_medium_id' => $allonge->id,
            'default_length_seconds' => 10,
            'surcharge_percent' => '30',
            'swf_allonge_confirmed_at' => $confirmedAt,
        ]);
        $ruleB = InventoryMediumRule::factory()->create([
            'inventory_id' => $b->id,
            'advertising_medium_id' => $allonge->id,
            'default_length_seconds' => 20,
            'surcharge_percent' => '10',
            'swf_allonge_confirmed_at' => $confirmedAt,
        ]);
        $ruleC = InventoryMediumRule::factory()->create([
            'inventory_id' => $c->id,
            'advertising_medium_id' => $allonge->id,
            'default_length_seconds' => 30,
            'surcharge_percent' => '0',
            'swf_allonge_confirmed_at' => null,
        ]);

        foreach ([$a, $b, $c] as $inventory) {
            InventoryMediumRule::factory()->create([
                'inventory_id' => $inventory->id,
                'advertising_medium_id' => $spot->id,
                'default_length_seconds' => 30,
                'surcharge_percent' => '0',
            ]);
        }

        return [
            'organization' => $organization,
            'allonge' => $allonge,
            'spot' => $spot,
            'a' => $a,
            'b' => $b,
            'c' => $c,
            'ruleA' => $ruleA,
            'ruleB' => $ruleB,
            'ruleC' => $ruleC,
            'listA' => $this->createAllongePriceList($a, '2.0000'),
            'listB' => $this->createAllongePriceList($b, '3.0000'),
            'listC' => $this->createAllongePriceList($c, '2.0000'),
        ];
    }

    protected function createAllongePriceList(Inventory $inventory, string $secondPrice, ?int $year = null): PriceList
    {
        $year ??= PriceListCalendar::currentYear();
        $list = PriceList::factory()->create([
            'inventory_id' => $inventory->id,
            'status' => PriceListStatus::Active,
            'year' => $year,
            'version' => 'allonge-'.$inventory->code.'-'.$year,
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
    protected function allongePosition(Inventory $inventory, AdvertisingMedium $allonge, int $spots, array $overrides = []): array
    {
        $ruleLength = InventoryMediumRule::query()
            ->where('inventory_id', $inventory->id)
            ->where('advertising_medium_id', $allonge->id)
            ->value('default_length_seconds');

        return array_merge([
            'inventory_id' => $inventory->id,
            'advertising_medium_id' => $allonge->id,
            'spot_method' => 'average',
            'length_seconds' => (int) ($ruleLength ?? 0),
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
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    protected function allongePayload(array $positions, array $overrides = []): array
    {
        return $this->withLiveSchemaFingerprint(array_merge([
            'planning_mode' => 'manual',
            'order_discount_percent' => '0',
            'positions' => $positions,
        ], $overrides));
    }
}
