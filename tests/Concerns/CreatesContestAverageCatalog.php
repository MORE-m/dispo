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
 * PO-SWF-CONTEST-1: synthetische Contest-Fixtures.
 *
 * - A: 2,00 €/s · Default 25 s · +50 %
 * - B: 3,00 €/s · Default 25 s · +50 %
 * - C: Regel bewusst ungültig (Länge null / Aufschlag 0) für fail-closed
 */
trait CreatesContestAverageCatalog
{
    use AddsSwfCalendarMethodAssignment;

    /**
     * @return array{
     *     organization: Organization,
     *     contest: AdvertisingMedium,
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
    protected function createContestAverageCatalog(): array
    {
        $organization = Organization::factory()->create();
        $average = CalculationMethod::query()->where('key', 'average')->firstOrFail();
        $contest = AdvertisingMedium::factory()->swfContest()->create([
            'calculation_method_mode' => CalculationMethodMode::Override,
            'default_calculation_method_id' => $average->id,
        ]);
        $assignment = new AdvertisingMediumCalculationMethod;
        $assignment->advertising_medium_id = $contest->id;
        $assignment->calculation_method_id = $average->id;
        $assignment->engine_profile_key = EngineProfileRegistry::PROFILE_SWF_CONTEST;
        $assignment->is_active = true;
        $assignment->sort = 10;
        $assignment->lock_version = 1;
        $assignment->save();
        $this->assignSwfMediumCalendar($contest, EngineProfileRegistry::PROFILE_SWF_CONTEST);

        $spot = AdvertisingMedium::factory()->create();

        $a = Inventory::factory()->create([
            'organization_id' => $organization->id,
            'name' => 'Contest Testsender A',
            'code' => 'PTA',
            'sort' => 1,
        ]);
        $b = Inventory::factory()->create([
            'organization_id' => $organization->id,
            'name' => 'Contest Testsender B',
            'code' => 'PTB',
            'sort' => 2,
        ]);
        $c = Inventory::factory()->create([
            'organization_id' => $organization->id,
            'name' => 'Contest Testsender C',
            'code' => 'PTC',
            'sort' => 3,
        ]);

        $ruleA = InventoryMediumRule::factory()->create([
            'inventory_id' => $a->id,
            'advertising_medium_id' => $contest->id,
            'default_length_seconds' => 25,
            'surcharge_percent' => '50',
        ]);
        $ruleB = InventoryMediumRule::factory()->create([
            'inventory_id' => $b->id,
            'advertising_medium_id' => $contest->id,
            'default_length_seconds' => 25,
            'surcharge_percent' => '50',
        ]);
        $ruleC = InventoryMediumRule::factory()->create([
            'inventory_id' => $c->id,
            'advertising_medium_id' => $contest->id,
            'default_length_seconds' => null,
            'surcharge_percent' => '0',
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
            'contest' => $contest,
            'spot' => $spot,
            'a' => $a,
            'b' => $b,
            'c' => $c,
            'ruleA' => $ruleA,
            'ruleB' => $ruleB,
            'ruleC' => $ruleC,
            'listA' => $this->createContestPriceList($a, '2.0000'),
            'listB' => $this->createContestPriceList($b, '3.0000'),
            'listC' => $this->createContestPriceList($c, '2.0000'),
        ];
    }

    protected function createContestPriceList(Inventory $inventory, string $secondPrice, ?int $year = null): PriceList
    {
        $year ??= PriceListCalendar::currentYear();
        $list = PriceList::factory()->create([
            'inventory_id' => $inventory->id,
            'status' => PriceListStatus::Active,
            'year' => $year,
            'version' => 'contest-'.$inventory->code.'-'.$year,
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
    protected function contestPosition(Inventory $inventory, AdvertisingMedium $contest, int $spots, array $overrides = []): array
    {
        $ruleLength = InventoryMediumRule::query()
            ->where('inventory_id', $inventory->id)
            ->where('advertising_medium_id', $contest->id)
            ->value('default_length_seconds');

        return array_merge([
            'inventory_id' => $inventory->id,
            'advertising_medium_id' => $contest->id,
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
    protected function contestPayload(array $positions, array $overrides = []): array
    {
        return $this->withLiveSchemaFingerprint(array_merge([
            'planning_mode' => 'manual',
            'order_discount_percent' => '0',
            'positions' => $positions,
        ], $overrides));
    }
}
