<?php

namespace App\Services\Calculation;

use App\Enums\DayGroup;
use App\Enums\PriceListStatus;
use App\Enums\SpotCalculationMethod;
use App\Models\AdvertisingMedium;
use App\Models\CalculationPosition;
use App\Models\Inventory;
use App\Models\InventoryMediumRule;
use App\Models\PriceList;
use App\Models\PriceListItem;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

final class CatalogResolver
{
    /**
     * @param  array<string, mixed>  $position
     * @return array{
     *     inventory: Inventory,
     *     medium: AdvertisingMedium,
     *     rule: InventoryMediumRule|null,
     *     priceList: PriceList,
     *     rows: list<PlanRowInput>,
     *     total_spot_count: int,
     *     spot_method: SpotCalculationMethod,
     *     surcharge_percent: string,
     *     is_discountable: bool,
     *     is_ae_eligible: bool,
     *     inventory_medium_rule_id: int|null,
     *     inventory_changed: bool
     * }
     */
    public function resolvePosition(array $position, ?CalculationPosition $existing = null): array
    {
        $inventory = Inventory::query()
            ->whereKey($position['inventory_id'] ?? 0)
            ->where('is_active', true)
            ->first();

        if ($inventory === null) {
            throw ValidationException::withMessages([
                'positions' => 'Sender oder Kombi ist unbekannt oder inaktiv.',
            ]);
        }

        $medium = AdvertisingMedium::query()
            ->whereKey($position['advertising_medium_id'] ?? 0)
            ->where('is_active', true)
            ->first();

        if ($medium === null || $medium->code !== 'spot_classic') {
            throw ValidationException::withMessages([
                'positions' => 'Nur Spot Classic ist in diesem Umfang zulässig.',
            ]);
        }

        $rule = InventoryMediumRule::query()
            ->where('inventory_id', $inventory->id)
            ->where('advertising_medium_id', $medium->id)
            ->where('is_active', true)
            ->first();

        if ($rule === null) {
            throw ValidationException::withMessages([
                'positions' => 'Die Kombination Sender/Werbemittel ist nicht zulässig.',
            ]);
        }

        $spotMethod = isset($position['spot_method'])
            ? SpotCalculationMethod::from((string) $position['spot_method'])
            : ($existing !== null ? $existing->spot_method : SpotCalculationMethod::Average);

        if (! $spotMethod->isImplementedInGateB()) {
            throw ValidationException::withMessages([
                'positions' => 'Kalkulationsart '.$spotMethod->label().' ist noch nicht freigegeben.',
            ]);
        }

        $inventoryChanged = $existing !== null
            && (int) ($position['inventory_id'] ?? 0) !== $existing->inventory_id;

        $useSnapshot = $existing !== null && ! $inventoryChanged;

        if ($useSnapshot) {
            $priceList = PriceList::query()->with('items')->find($existing->price_list_id);
            if ($priceList === null || $priceList->inventory_id !== $inventory->id) {
                throw ValidationException::withMessages([
                    'positions' => 'Die gespeicherte Preisliste der Position ist ungültig.',
                ]);
            }

            $surchargePercent = (string) $existing->surcharge_percent;
            $isDiscountable = (bool) $existing->is_discountable;
            $isAeEligible = (bool) $existing->is_ae_eligible;
            $ruleId = $existing->inventory_medium_rule_id;
        } else {
            $priceList = $this->activePriceList($inventory->id);
            if ($priceList === null) {
                throw ValidationException::withMessages([
                    'positions' => 'Für '.$inventory->name.' liegt keine aktive Preisliste vor.',
                ]);
            }

            $surchargePercent = (string) $rule->surcharge_percent;
            $isDiscountable = (bool) $rule->is_discountable && (bool) $medium->is_discountable;
            $isAeEligible = (bool) $rule->is_ae_eligible && (bool) $medium->is_ae_eligible;
            $ruleId = $rule->id;
        }

        $totalSpotCount = (int) ($position['total_spot_count'] ?? ($existing !== null ? $existing->total_spot_count : 0));
        $rows = $this->resolveRows($position, $priceList, $existing, $useSnapshot);

        return [
            'inventory' => $inventory,
            'medium' => $medium,
            'rule' => $rule,
            'priceList' => $priceList,
            'rows' => $rows,
            'total_spot_count' => $totalSpotCount,
            'spot_method' => $spotMethod,
            'surcharge_percent' => $surchargePercent,
            'is_discountable' => $isDiscountable,
            'is_ae_eligible' => $isAeEligible,
            'inventory_medium_rule_id' => $ruleId,
            'inventory_changed' => $inventoryChanged,
        ];
    }

    public function activePriceList(int $inventoryId): ?PriceList
    {
        /** @var Collection<int, PriceList> $activeLists */
        $activeLists = PriceList::query()
            ->where('inventory_id', $inventoryId)
            ->where('status', PriceListStatus::Active)
            ->with('items')
            ->orderByDesc('valid_from')
            ->orderByDesc('id')
            ->get();

        if ($activeLists->isEmpty()) {
            return null;
        }

        return $activeLists->first();
    }

    /**
     * @param  array<string, mixed>  $position
     * @return list<PlanRowInput>
     */
    private function resolveRows(
        array $position,
        PriceList $priceList,
        ?CalculationPosition $existing,
        bool $useSnapshot,
    ): array {
        $rows = [];
        $existingRows = $existing?->relationLoaded('planRows')
            ? $existing->planRows->keyBy(fn ($row) => $row->hour.'|'.$row->day_group->value)
            : ($existing !== null
                ? $existing->planRows()->get()->keyBy(fn ($row) => $row->hour.'|'.$row->day_group->value)
                : collect());

        foreach ($position['plan_rows'] ?? [] as $index => $row) {
            $dayGroup = DayGroup::from((string) $row['day_group']);
            $hour = (int) $row['hour'];
            $key = $hour.'|'.$dayGroup->value;

            if ($hour < 0 || $hour > 23) {
                throw ValidationException::withMessages([
                    "positions.0.plan_rows.{$index}.hour" => 'Preisstunden müssen 0–23 sein.',
                ]);
            }

            $stored = $existingRows->get($key);
            if ($useSnapshot && $stored !== null) {
                $secondPrice = (string) $stored->second_price;
            } else {
                $secondPrice = $this->secondPrice($priceList, $hour, $dayGroup);
            }

            $rows[] = new PlanRowInput(
                hour: $hour,
                dayGroup: $dayGroup,
                spotCount: 0,
                secondPrice: $secondPrice,
            );
        }

        if ($rows === [] && $existing !== null) {
            foreach ($existingRows as $stored) {
                $rows[] = new PlanRowInput(
                    hour: $stored->hour,
                    dayGroup: $stored->day_group,
                    spotCount: 0,
                    secondPrice: (string) $stored->second_price,
                );
            }
        }

        return $rows;
    }

    public function secondPrice(PriceList $priceList, int $hour, DayGroup $dayGroup): string
    {
        $base = [];

        foreach ([DayGroup::MoFr, DayGroup::Sa, DayGroup::So] as $group) {
            $item = $priceList->items->first(
                fn (PriceListItem $candidate): bool => $candidate->hour === $hour
                    && $candidate->day_group === $group,
            );

            if ($item === null) {
                throw ValidationException::withMessages([
                    'positions' => 'Kein Sekundenpreis für Stunde '.$hour.' ('.$group->label().').',
                ]);
            }

            $base[$group->value] = (string) $item->second_price;
        }

        return DayGroupPrice::fromBaseMap($base, $dayGroup);
    }
}
