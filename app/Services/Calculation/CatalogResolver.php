<?php

namespace App\Services\Calculation;

use App\Enums\CalculationKind;
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
     *     time_ranges: list<TimeRangeInput>,
     *     needs_spot_redistribution: bool,
     *     total_spot_count: int,
     *     spot_method: SpotCalculationMethod,
     *     surcharge_percent: string,
     *     is_discountable: bool,
     *     is_ae_eligible: bool,
     *     inventory_medium_rule_id: int|null,
     *     inventory_changed: bool,
     *     medium_changed: bool
     * }
     */
    public function resolvePosition(array $position, ?CalculationPosition $existing = null): array
    {
        $inventoryId = (int) ($position['inventory_id'] ?? 0);
        $mediumId = (int) ($position['advertising_medium_id'] ?? 0);

        $inventoryChanged = $existing !== null && $inventoryId !== $existing->inventory_id;
        $mediumChanged = $existing !== null && $mediumId !== $existing->advertising_medium_id;
        $combinationChanged = $inventoryChanged || $mediumChanged;
        $useSnapshot = $existing !== null && ! $combinationChanged;

        if ($useSnapshot) {
            return $this->resolveSnapshotPosition($position, $existing, $inventoryId, $mediumId, $inventoryChanged, $mediumChanged);
        }

        return $this->resolveActivePosition($position, $inventoryId, $mediumId, $inventoryChanged, $mediumChanged);
    }

    /**
     * @param  array<string, mixed>  $position
     * @return array{
     *     inventory: Inventory,
     *     medium: AdvertisingMedium,
     *     rule: InventoryMediumRule|null,
     *     priceList: PriceList,
     *     rows: list<PlanRowInput>,
     *     time_ranges: list<TimeRangeInput>,
     *     needs_spot_redistribution: bool,
     *     total_spot_count: int,
     *     spot_method: SpotCalculationMethod,
     *     surcharge_percent: string,
     *     is_discountable: bool,
     *     is_ae_eligible: bool,
     *     inventory_medium_rule_id: int|null,
     *     inventory_changed: bool,
     *     medium_changed: bool
     * }
     */
    private function resolveSnapshotPosition(
        array $position,
        CalculationPosition $existing,
        int $inventoryId,
        int $mediumId,
        bool $inventoryChanged,
        bool $mediumChanged,
    ): array {
        $inventory = Inventory::query()->find($inventoryId);
        if ($inventory === null) {
            throw ValidationException::withMessages([
                'positions' => 'Der gespeicherte Sender existiert nicht mehr.',
            ]);
        }

        $medium = AdvertisingMedium::query()->find($mediumId);
        if ($medium === null || (string) $medium->getAttributes()['kind'] !== CalculationKind::SpotClassic->value) {
            throw ValidationException::withMessages([
                'positions' => 'Das gespeicherte Werbemittel ist ungültig.',
            ]);
        }

        $priceList = PriceList::query()->with('items')->find($existing->price_list_id);
        if ($priceList === null) {
            throw ValidationException::withMessages([
                'positions' => 'Die gespeicherte Preisliste der Position existiert nicht mehr.',
            ]);
        }

        if ($priceList->inventory_id !== $inventory->id) {
            throw ValidationException::withMessages([
                'positions' => 'Die gespeicherte Preisliste der Position ist ungültig.',
            ]);
        }

        $rule = $existing->inventory_medium_rule_id !== null
            ? InventoryMediumRule::query()->find($existing->inventory_medium_rule_id)
            : InventoryMediumRule::query()
                ->where('inventory_id', $inventory->id)
                ->where('advertising_medium_id', $medium->id)
                ->first();

        $spotMethod = isset($position['spot_method'])
            ? SpotCalculationMethod::from((string) $position['spot_method'])
            : $existing->spot_method;

        if (! $spotMethod->isImplementedInGateB()) {
            throw ValidationException::withMessages([
                'positions' => 'Kalkulationsart '.$spotMethod->label().' ist noch nicht freigegeben.',
            ]);
        }

        [$rows, $timeRanges, $totalSpotCount, $needsRedistribution] = $this->resolvePlan(
            $position,
            $priceList,
            $inventory->name,
            $existing,
            useSnapshot: true,
        );

        return [
            'inventory' => $inventory,
            'medium' => $medium,
            'rule' => $rule,
            'priceList' => $priceList,
            'rows' => $rows,
            'time_ranges' => $timeRanges,
            'needs_spot_redistribution' => $needsRedistribution,
            'total_spot_count' => $totalSpotCount,
            'spot_method' => $spotMethod,
            'surcharge_percent' => (string) $existing->surcharge_percent,
            'is_discountable' => (bool) $existing->is_discountable,
            'is_ae_eligible' => (bool) $existing->is_ae_eligible,
            'inventory_medium_rule_id' => $existing->inventory_medium_rule_id,
            'inventory_changed' => $inventoryChanged,
            'medium_changed' => $mediumChanged,
        ];
    }

    /**
     * @param  array<string, mixed>  $position
     * @return array{
     *     inventory: Inventory,
     *     medium: AdvertisingMedium,
     *     rule: InventoryMediumRule,
     *     priceList: PriceList,
     *     rows: list<PlanRowInput>,
     *     time_ranges: list<TimeRangeInput>,
     *     needs_spot_redistribution: bool,
     *     total_spot_count: int,
     *     spot_method: SpotCalculationMethod,
     *     surcharge_percent: string,
     *     is_discountable: bool,
     *     is_ae_eligible: bool,
     *     inventory_medium_rule_id: int|null,
     *     inventory_changed: bool,
     *     medium_changed: bool
     * }
     */
    private function resolveActivePosition(
        array $position,
        int $inventoryId,
        int $mediumId,
        bool $inventoryChanged,
        bool $mediumChanged,
    ): array {
        $inventory = Inventory::query()
            ->whereKey($inventoryId)
            ->where('is_active', true)
            ->first();

        if ($inventory === null) {
            throw ValidationException::withMessages([
                'positions' => 'Sender oder Kombi ist unbekannt oder inaktiv.',
            ]);
        }

        $medium = AdvertisingMedium::query()
            ->whereKey($mediumId)
            ->where('is_active', true)
            ->first();

        if ($medium === null || (string) $medium->getAttributes()['kind'] !== CalculationKind::SpotClassic->value) {
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
            : SpotCalculationMethod::Average;

        if (! $spotMethod->isImplementedInGateB()) {
            throw ValidationException::withMessages([
                'positions' => 'Kalkulationsart '.$spotMethod->label().' ist noch nicht freigegeben.',
            ]);
        }

        $priceList = $this->activePriceList($inventory->id);
        if ($priceList === null) {
            throw ValidationException::withMessages([
                'positions' => 'Für '.$inventory->name.' liegt keine aktive Preisliste vor.',
            ]);
        }

        [$rows, $timeRanges, $totalSpotCount, $needsRedistribution] = $this->resolvePlan(
            $position,
            $priceList,
            $inventory->name,
            null,
            useSnapshot: false,
        );

        return [
            'inventory' => $inventory,
            'medium' => $medium,
            'rule' => $rule,
            'priceList' => $priceList,
            'rows' => $rows,
            'time_ranges' => $timeRanges,
            'needs_spot_redistribution' => $needsRedistribution,
            'total_spot_count' => $totalSpotCount,
            'spot_method' => $spotMethod,
            'surcharge_percent' => (string) $rule->surcharge_percent,
            'is_discountable' => (bool) $rule->is_discountable && (bool) $medium->is_discountable,
            'is_ae_eligible' => (bool) $rule->is_ae_eligible && (bool) $medium->is_ae_eligible,
            'inventory_medium_rule_id' => $rule->id,
            'inventory_changed' => $inventoryChanged,
            'medium_changed' => $mediumChanged,
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
     * @return array{0: list<PlanRowInput>, 1: list<TimeRangeInput>, 2: int, 3: bool}
     */
    private function resolvePlan(
        array $position,
        PriceList $priceList,
        string $inventoryName,
        ?CalculationPosition $existing,
        bool $useSnapshot,
    ): array {
        $existingRows = $this->existingPlanRows($existing);
        $payloadRanges = $position['time_ranges'] ?? null;

        if (is_array($payloadRanges) && $payloadRanges !== []) {
            $ranges = (new TimeRangeValidator)->validated(
                $payloadRanges,
                'positions',
                requireAtLeastOne: false,
            );

            if ($ranges !== []) {
                [$timeRanges, $rows] = $this->hydrateTimeRanges(
                    $ranges,
                    $priceList,
                    $inventoryName,
                    $existingRows,
                    $useSnapshot,
                );
                $totalSpots = array_sum(array_map(fn (TimeRangeInput $range): int => $range->spotCount, $timeRanges));
                $needsRedistribution = $this->redistributionStillOpen($existing, $totalSpots);

                return [$rows, $timeRanges, $totalSpots, $needsRedistribution];
            }
        }

        $rows = $this->resolveHourRows($position, $priceList, $existingRows, $useSnapshot, $inventoryName);
        $legacyTotal = (int) ($position['total_spot_count'] ?? ($existing !== null ? $existing->total_spot_count : 0));
        $uniqueKeys = $this->uniqueHourKeys($rows);
        $legacyAmbiguous = count($uniqueKeys) > 1;

        if (count($uniqueKeys) === 1 && $rows !== []) {
            $row = $rows[0];
            $timeRanges = [new TimeRangeInput(
                startHour: $row->hour,
                endHourExclusive: $row->hour + 1,
                dayGroup: $row->dayGroup,
                spotCount: max(0, $legacyTotal),
                hours: [$row],
            )];

            return [$rows, $timeRanges, $legacyTotal, false];
        }

        return [$rows, [], $legacyTotal, $legacyAmbiguous];
    }

    /**
     * @param  list<array{start_hour: int, end_hour_exclusive: int, day_group: string, spot_count: int, sort: int}>  $ranges
     * @param  Collection<string, mixed>  $existingRows
     * @return array{0: list<TimeRangeInput>, 1: list<PlanRowInput>}
     */
    private function hydrateTimeRanges(
        array $ranges,
        PriceList $priceList,
        string $inventoryName,
        Collection $existingRows,
        bool $useSnapshot,
    ): array {
        $timeRanges = [];
        $rows = [];
        /** @var array<string, list<string>> $missingByDayGroup */
        $missingByDayGroup = [];

        foreach ($ranges as $range) {
            $dayGroup = DayGroup::from($range['day_group']);
            $hours = [];

            foreach (TimeRangeHours::expand($range['start_hour'], $range['end_hour_exclusive']) as $hour) {
                $price = $this->resolveHourPrice($priceList, $hour, $dayGroup, $existingRows, $useSnapshot);
                if ($price === null) {
                    $missingByDayGroup[$dayGroup->label()][] = TimeRangeHours::formatHour($hour);

                    continue;
                }

                $row = new PlanRowInput($hour, $dayGroup, 0, $price);
                $hours[] = $row;
                $rows[] = $row;
            }

            $timeRanges[] = new TimeRangeInput(
                startHour: $range['start_hour'],
                endHourExclusive: $range['end_hour_exclusive'],
                dayGroup: $dayGroup,
                spotCount: $range['spot_count'],
                hours: $hours,
                sort: $range['sort'],
            );
        }

        if ($missingByDayGroup !== []) {
            throw ValidationException::withMessages([
                'positions' => $this->formatMissingPriceMessage($inventoryName, $missingByDayGroup),
            ]);
        }

        return [$timeRanges, $rows];
    }

    /**
     * @param  Collection<string, mixed>  $existingRows
     * @param  array<string, mixed>  $position
     * @return list<PlanRowInput>
     */
    private function resolveHourRows(
        array $position,
        PriceList $priceList,
        Collection $existingRows,
        bool $useSnapshot,
        string $inventoryName,
    ): array {
        $rows = [];
        /** @var array<string, list<string>> $missingByDayGroup */
        $missingByDayGroup = [];

        foreach ($position['plan_rows'] ?? [] as $index => $row) {
            $dayGroup = DayGroup::from((string) $row['day_group']);
            $hour = (int) $row['hour'];

            if ($hour < 0 || $hour > 23) {
                throw ValidationException::withMessages([
                    "positions.0.plan_rows.{$index}.hour" => 'Preisstunden müssen 0–23 sein.',
                ]);
            }

            $price = $this->resolveHourPrice($priceList, $hour, $dayGroup, $existingRows, $useSnapshot);
            if ($price === null) {
                $missingByDayGroup[$dayGroup->label()][] = TimeRangeHours::formatHour($hour);

                continue;
            }

            $rows[] = new PlanRowInput($hour, $dayGroup, 0, $price);
        }

        if ($rows === [] && $existingRows->isNotEmpty()) {
            foreach ($existingRows as $stored) {
                $rows[] = new PlanRowInput(
                    hour: $stored->hour,
                    dayGroup: $stored->day_group,
                    spotCount: 0,
                    secondPrice: (string) $stored->second_price,
                );
            }
        }

        if ($missingByDayGroup !== []) {
            throw ValidationException::withMessages([
                'positions' => $this->formatMissingPriceMessage($inventoryName, $missingByDayGroup),
            ]);
        }

        return $rows;
    }

    /**
     * @param  array<string, list<string>>  $missingByDayGroup
     */
    private function formatMissingPriceMessage(string $inventoryName, array $missingByDayGroup): string
    {
        $parts = [];

        foreach ($missingByDayGroup as $dayGroupLabel => $hours) {
            $uniqueHours = array_values(array_unique($hours));
            sort($uniqueHours);
            $parts[] = $dayGroupLabel.' in den Stunden '.implode(', ', $uniqueHours);
        }

        return 'Für '.$inventoryName.' fehlen Preise für '.implode(' und ', $parts).'.';
    }

    /**
     * @return Collection<string, mixed>
     */
    private function existingPlanRows(?CalculationPosition $existing): Collection
    {
        if ($existing === null) {
            return collect();
        }

        $rows = $existing->relationLoaded('planRows')
            ? $existing->planRows
            : $existing->planRows()->get();

        return $rows->keyBy(fn ($row) => $row->hour.'|'.$row->day_group->value);
    }

    /**
     * @param  Collection<string, mixed>  $existingRows
     */
    private function resolveHourPrice(
        PriceList $priceList,
        int $hour,
        DayGroup $dayGroup,
        Collection $existingRows,
        bool $useSnapshot,
    ): ?string {
        $stored = $existingRows->get($hour.'|'.$dayGroup->value);
        if ($useSnapshot && $stored !== null) {
            return (string) $stored->second_price;
        }

        return $this->findSecondPrice($priceList, $hour, $dayGroup);
    }

    private function redistributionStillOpen(?CalculationPosition $existing, int $assignedSpots): bool
    {
        if ($existing === null || ! $existing->needs_spot_redistribution) {
            return false;
        }

        return $assignedSpots !== (int) $existing->total_spot_count;
    }

    /**
     * @param  list<PlanRowInput>  $rows
     * @return list<string>
     */
    private function uniqueHourKeys(array $rows): array
    {
        $keys = [];
        foreach ($rows as $row) {
            $keys[$row->hour.'|'.$row->dayGroup->value] = true;
        }

        return array_keys($keys);
    }

    public function secondPrice(PriceList $priceList, int $hour, DayGroup $dayGroup): string
    {
        $price = $this->findSecondPrice($priceList, $hour, $dayGroup);
        if ($price === null) {
            throw ValidationException::withMessages([
                'positions' => 'Kein Sekundenpreis für Stunde '.$hour.' ('.$dayGroup->label().').',
            ]);
        }

        return $price;
    }

    public function findSecondPrice(PriceList $priceList, int $hour, DayGroup $dayGroup): ?string
    {
        $base = [];

        foreach ([DayGroup::MoFr, DayGroup::Sa, DayGroup::So] as $group) {
            $item = $priceList->items->first(
                fn (PriceListItem $candidate): bool => $candidate->hour === $hour
                    && $candidate->day_group === $group,
            );

            if ($item === null) {
                return null;
            }

            $base[$group->value] = (string) $item->second_price;
        }

        return DayGroupPrice::fromBaseMap($base, $dayGroup);
    }
}
