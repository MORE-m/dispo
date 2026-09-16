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
use App\Support\Calculation\CalculationMethodFreezeDescriptor;
use App\Support\Calculation\CalculationMethodFreezeResolver;
use App\Support\Calculation\CalculationPositionMethodKeyNormalizer;
use App\Support\PriceList\PriceListCalendar;
use App\Support\PriceList\PriceListYearSelection;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

final class CatalogResolver
{
    private ?int $currentPositionIndex = null;

    public function __construct(
        private readonly CalculationMethodFreezeResolver $freezeResolver = new CalculationMethodFreezeResolver,
        private readonly CalculationPositionMethodKeyNormalizer $methodKeyNormalizer = new CalculationPositionMethodKeyNormalizer,
    ) {}

    /**
     * @param  array<string, mixed>  $position
     * @return array{
     *     inventory: Inventory,
     *     medium: AdvertisingMedium,
     *     rule: InventoryMediumRule|null,
     *     priceList: PriceList,
     *     rows: list<PlanRowInput>,
     *     time_ranges: list<TimeRangeInput>,
     *     planner_entries: list<PlannerEntryInput>,
     *     needs_spot_redistribution: bool,
     *     total_spot_count: int,
     *     spot_method: SpotCalculationMethod,
     *     freeze: CalculationMethodFreezeDescriptor,
     *     surcharge_percent: string,
     *     is_discountable: bool,
     *     is_ae_eligible: bool,
     *     inventory_medium_rule_id: int|null,
     *     inventory_changed: bool,
     *     medium_changed: bool
     * }
     */
    public function resolvePosition(array $position, ?CalculationPosition $existing = null, ?int $positionIndex = null): array
    {
        $previousPositionIndex = $this->currentPositionIndex;
        $this->currentPositionIndex = $positionIndex;

        try {
            return $this->resolvePositionInner($position, $existing);
        } finally {
            $this->currentPositionIndex = $previousPositionIndex;
        }
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
     *     planner_entries: list<PlannerEntryInput>,
     *     needs_spot_redistribution: bool,
     *     total_spot_count: int,
     *     spot_method: SpotCalculationMethod,
     *     freeze: CalculationMethodFreezeDescriptor,
     *     surcharge_percent: string,
     *     is_discountable: bool,
     *     is_ae_eligible: bool,
     *     inventory_medium_rule_id: int|null,
     *     inventory_changed: bool,
     *     medium_changed: bool
     * }
     */
    private function resolvePositionInner(array $position, ?CalculationPosition $existing): array
    {
        $inventoryId = (int) ($position['inventory_id'] ?? 0);
        $mediumId = (int) ($position['advertising_medium_id'] ?? 0);
        $methodIntent = $this->methodKeyNormalizer->normalize($position);

        $inventoryChanged = $existing !== null && $inventoryId !== (int) $existing->inventory_id;
        $mediumChanged = $existing !== null && $mediumId !== (int) $existing->advertising_medium_id;
        $priceYearChanged = $existing !== null
            && ! $inventoryChanged
            && $this->shouldRebindPriceYear($position, $existing);

        if ($existing !== null && ! $mediumChanged) {
            // Lesen des gespeicherten Keys: forExecution=false erlaubt unbekannte historische Versionen.
            // Ohne Inventar-/Jahrwechsel bleibt der Pin (resolveSnapshotPosition).
            // Inventarwechsel / bewusster Jahrwechsel → Live-Bind (bindActivePriceListForLive).
            // Methodenwechsel bei gleichem Inventar und Preisjahr: Freeze neu, Preisliste gepinnt (BL-P4-02a).
            $storedFreeze = $this->freezeResolver->resolveStoredPosition($existing, forExecution: false);
            $methodUnchanged = ! $methodIntent['present']
                || $methodIntent['key'] === $storedFreeze->calculationMethodKey;

            if ($methodUnchanged) {
                if ($inventoryChanged) {
                    return $this->resolveInventoryChangeKeepingFreeze(
                        $position,
                        $existing,
                        $inventoryId,
                        $mediumId,
                        $inventoryChanged,
                        $mediumChanged,
                    );
                }

                if ($priceYearChanged) {
                    return $this->resolvePriceYearRebindKeepingFreeze(
                        $position,
                        $existing,
                        $inventoryId,
                        $mediumId,
                        $inventoryChanged,
                        $mediumChanged,
                    );
                }

                return $this->resolveSnapshotPosition(
                    $position,
                    $existing,
                    $inventoryId,
                    $mediumId,
                    $inventoryChanged,
                    $mediumChanged,
                );
            }

            // BL-P4-02a: reiner Methodenwechsel ohne Inventar-/Jahrwechsel darf den Pin nicht live ersetzen.
            if (! $inventoryChanged && ! $priceYearChanged) {
                return $this->resolveMethodChangeKeepingPriceListPin(
                    $position,
                    $existing,
                    $inventoryId,
                    $mediumId,
                    $inventoryChanged,
                    $mediumChanged,
                    $methodIntent['key'],
                );
            }
        }

        // Neue Position, Mediumwechsel oder Inventarwechsel mit Methodenwechsel → Live-Bind.
        // Mediumwechsel ohne Jahrwechsel bei gleichem Inventar ersetzt den Pin (bestehendes Verhalten, nicht BL-P4-02a).
        $requestedMethod = $methodIntent['present'] ? $methodIntent['key'] : null;

        return $this->resolveActivePosition(
            $position,
            $inventoryId,
            $mediumId,
            $inventoryChanged,
            $mediumChanged,
            $requestedMethod,
            rewriteExplicitMethodFailure: $existing !== null && $methodIntent['present'],
        );
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
     *     planner_entries: list<PlannerEntryInput>,
     *     needs_spot_redistribution: bool,
     *     total_spot_count: int,
     *     spot_method: SpotCalculationMethod,
     *     freeze: CalculationMethodFreezeDescriptor,
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
        // Historischer Freeze ist maßgeblich – nicht Live-Kind, Aktivstatus oder Zuordnungen.
        // Methodenwechsel bei gleichem Inventar/Jahr: resolveMethodChangeKeepingPriceListPin.
        $freeze = $this->freezeResolver->resolveStoredPosition($existing, forExecution: true);

        $inventory = Inventory::query()->find($inventoryId);
        if ($inventory === null) {
            throw ValidationException::withMessages([
                'positions' => 'Der gespeicherte Sender existiert nicht mehr.',
            ]);
        }

        $medium = AdvertisingMedium::query()->find($mediumId);
        if ($medium === null) {
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

        [$rows, $timeRanges, $plannerEntries, $totalSpotCount, $needsRedistribution] = $this->resolvePlan(
            $position,
            $priceList,
            $inventory->name,
            $existing,
            useSnapshot: true,
            spotMethod: $freeze->legacySpotMethod(),
        );

        return [
            'inventory' => $inventory,
            'medium' => $medium,
            'rule' => $rule,
            'priceList' => $priceList,
            'rows' => $rows,
            'time_ranges' => $timeRanges,
            'planner_entries' => $plannerEntries,
            'needs_spot_redistribution' => $needsRedistribution,
            'total_spot_count' => $totalSpotCount,
            'spot_method' => $freeze->legacySpotMethod(),
            'freeze' => $freeze,
            'surcharge_percent' => (string) $existing->surcharge_percent,
            'is_discountable' => (bool) $existing->is_discountable,
            'is_ae_eligible' => (bool) $existing->is_ae_eligible,
            'inventory_medium_rule_id' => $existing->inventory_medium_rule_id,
            'inventory_changed' => $inventoryChanged,
            'medium_changed' => $mediumChanged,
        ];
    }

    /**
     * ADV-001c4a: reiner Inventarwechsel bei unverändertem Medium/Methodenschlüssel.
     * Freeze bytegenau erhalten; Inventar/Rule/Preisliste live prüfen.
     *
     * @param  array<string, mixed>  $position
     * @return array{
     *     inventory: Inventory,
     *     medium: AdvertisingMedium,
     *     rule: InventoryMediumRule,
     *     priceList: PriceList,
     *     rows: list<PlanRowInput>,
     *     time_ranges: list<TimeRangeInput>,
     *     planner_entries: list<PlannerEntryInput>,
     *     needs_spot_redistribution: bool,
     *     total_spot_count: int,
     *     spot_method: SpotCalculationMethod,
     *     freeze: CalculationMethodFreezeDescriptor,
     *     surcharge_percent: string,
     *     is_discountable: bool,
     *     is_ae_eligible: bool,
     *     inventory_medium_rule_id: int|null,
     *     inventory_changed: bool,
     *     medium_changed: bool
     * }
     */
    private function resolveInventoryChangeKeepingFreeze(
        array $position,
        CalculationPosition $existing,
        int $inventoryId,
        int $mediumId,
        bool $inventoryChanged,
        bool $mediumChanged,
    ): array {
        $freeze = $this->freezeResolver->resolveStoredPosition($existing, forExecution: true);

        $inventory = Inventory::query()
            ->whereKey($inventoryId)
            ->where('is_active', true)
            ->first();

        if ($inventory === null) {
            throw ValidationException::withMessages([
                'positions' => 'Sender oder Kombi ist unbekannt oder inaktiv.',
            ]);
        }

        $medium = AdvertisingMedium::query()->find($mediumId);
        if ($medium === null) {
            throw ValidationException::withMessages([
                'positions' => 'Das gespeicherte Werbemittel ist ungültig.',
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

        $priceList = $this->bindActivePriceListForLive($position, $inventory);
        if ($priceList === null) {
            throw ValidationException::withMessages([
                'positions' => $this->missingYearPriceListMessage(
                    $inventory->name,
                    $this->livePriceYearFromPayload($position),
                ),
            ]);
        }

        [$rows, $timeRanges, $plannerEntries, $totalSpotCount, $needsRedistribution] = $this->resolvePlan(
            $position,
            $priceList,
            $inventory->name,
            $existing,
            useSnapshot: false,
            spotMethod: $freeze->legacySpotMethod(),
        );

        return [
            'inventory' => $inventory,
            'medium' => $medium,
            'rule' => $rule,
            'priceList' => $priceList,
            'rows' => $rows,
            'time_ranges' => $timeRanges,
            'planner_entries' => $plannerEntries,
            'needs_spot_redistribution' => $needsRedistribution,
            'total_spot_count' => $totalSpotCount,
            'spot_method' => $freeze->legacySpotMethod(),
            'freeze' => $freeze,
            'surcharge_percent' => (string) $rule->surcharge_percent,
            'is_discountable' => (bool) $rule->is_discountable && (bool) $medium->is_discountable,
            'is_ae_eligible' => (bool) $rule->is_ae_eligible && (bool) $medium->is_ae_eligible,
            'inventory_medium_rule_id' => $rule->id,
            'inventory_changed' => $inventoryChanged,
            'medium_changed' => $mediumChanged,
        ];
    }

    /**
     * BL-P4-02a: Methodenwechsel bei unverändertem Inventar und Preisjahr.
     * Neuer Freeze über Live-Buchbarkeit; historische price_list_id/version bleiben.
     *
     * @param  array<string, mixed>  $position
     * @return array{
     *     inventory: Inventory,
     *     medium: AdvertisingMedium,
     *     rule: InventoryMediumRule|null,
     *     priceList: PriceList,
     *     rows: list<PlanRowInput>,
     *     time_ranges: list<TimeRangeInput>,
     *     planner_entries: list<PlannerEntryInput>,
     *     needs_spot_redistribution: bool,
     *     total_spot_count: int,
     *     spot_method: SpotCalculationMethod,
     *     freeze: CalculationMethodFreezeDescriptor,
     *     surcharge_percent: string,
     *     is_discountable: bool,
     *     is_ae_eligible: bool,
     *     inventory_medium_rule_id: int|null,
     *     inventory_changed: bool,
     *     medium_changed: bool
     * }
     */
    private function resolveMethodChangeKeepingPriceListPin(
        array $position,
        CalculationPosition $existing,
        int $inventoryId,
        int $mediumId,
        bool $inventoryChanged,
        bool $mediumChanged,
        ?string $requestedMethod,
    ): array {
        $inventory = Inventory::query()->find($inventoryId);
        if ($inventory === null) {
            throw ValidationException::withMessages([
                'positions' => 'Der gespeicherte Sender existiert nicht mehr.',
            ]);
        }

        $medium = AdvertisingMedium::query()->find($mediumId);
        if ($medium === null) {
            throw ValidationException::withMessages([
                'positions' => 'Das gespeicherte Werbemittel ist ungültig.',
            ]);
        }

        try {
            $freeze = $this->freezeResolver->resolveForNewCombination($medium, $requestedMethod);
        } catch (ValidationException $exception) {
            if ($requestedMethod !== null && trim($requestedMethod) !== '') {
                throw ValidationException::withMessages([
                    'positions' => 'Die Berechnungsmethode ist für dieses Werbemittel nicht mehr verfügbar. Bitte Auswahl aktualisieren.',
                ]);
            }

            throw $exception;
        }

        $priceList = PriceList::query()->with('items')->find($existing->price_list_id);
        if ($priceList === null) {
            throw ValidationException::withMessages([
                'positions' => 'Die gespeicherte Preisliste der Position existiert nicht mehr.',
            ]);
        }

        if ((int) $priceList->inventory_id !== (int) $inventory->id) {
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

        [$rows, $timeRanges, $plannerEntries, $totalSpotCount, $needsRedistribution] = $this->resolvePlan(
            $position,
            $priceList,
            $inventory->name,
            $existing,
            useSnapshot: true,
            spotMethod: $freeze->legacySpotMethod(),
        );

        return [
            'inventory' => $inventory,
            'medium' => $medium,
            'rule' => $rule,
            'priceList' => $priceList,
            'rows' => $rows,
            'time_ranges' => $timeRanges,
            'planner_entries' => $plannerEntries,
            'needs_spot_redistribution' => $needsRedistribution,
            'total_spot_count' => $totalSpotCount,
            'spot_method' => $freeze->legacySpotMethod(),
            'freeze' => $freeze,
            'surcharge_percent' => (string) $existing->surcharge_percent,
            'is_discountable' => (bool) $existing->is_discountable,
            'is_ae_eligible' => (bool) $existing->is_ae_eligible,
            'inventory_medium_rule_id' => $existing->inventory_medium_rule_id,
            'inventory_changed' => $inventoryChanged,
            'medium_changed' => $mediumChanged,
        ];
    }

    /**
     * PO-PRI-YEAR-1: bewusster Jahrwechsel an bestehender Position (Freeze bleibt).
     *
     * @param  array<string, mixed>  $position
     * @return array{
     *     inventory: Inventory,
     *     medium: AdvertisingMedium,
     *     rule: InventoryMediumRule,
     *     priceList: PriceList,
     *     rows: list<PlanRowInput>,
     *     time_ranges: list<TimeRangeInput>,
     *     planner_entries: list<PlannerEntryInput>,
     *     needs_spot_redistribution: bool,
     *     total_spot_count: int,
     *     spot_method: SpotCalculationMethod,
     *     freeze: CalculationMethodFreezeDescriptor,
     *     surcharge_percent: string,
     *     is_discountable: bool,
     *     is_ae_eligible: bool,
     *     inventory_medium_rule_id: int|null,
     *     inventory_changed: bool,
     *     medium_changed: bool
     * }
     */
    private function resolvePriceYearRebindKeepingFreeze(
        array $position,
        CalculationPosition $existing,
        int $inventoryId,
        int $mediumId,
        bool $inventoryChanged,
        bool $mediumChanged,
    ): array {
        $freeze = $this->freezeResolver->resolveStoredPosition($existing, forExecution: true);

        $inventory = Inventory::query()
            ->whereKey($inventoryId)
            ->where('is_active', true)
            ->first();

        if ($inventory === null) {
            throw ValidationException::withMessages([
                'positions' => 'Sender oder Kombi ist unbekannt oder inaktiv.',
            ]);
        }

        $medium = AdvertisingMedium::query()->find($mediumId);
        if ($medium === null) {
            throw ValidationException::withMessages([
                'positions' => 'Das gespeicherte Werbemittel ist ungültig.',
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

        $priceList = $this->bindActivePriceListForLive($position, $inventory);
        if ($priceList === null) {
            throw ValidationException::withMessages([
                'positions' => $this->missingYearPriceListMessage(
                    $inventory->name,
                    $this->livePriceYearFromPayload($position),
                ),
            ]);
        }

        [$rows, $timeRanges, $plannerEntries, $totalSpotCount, $needsRedistribution] = $this->resolvePlan(
            $position,
            $priceList,
            $inventory->name,
            $existing,
            useSnapshot: false,
            spotMethod: $freeze->legacySpotMethod(),
        );

        return [
            'inventory' => $inventory,
            'medium' => $medium,
            'rule' => $rule,
            'priceList' => $priceList,
            'rows' => $rows,
            'time_ranges' => $timeRanges,
            'planner_entries' => $plannerEntries,
            'needs_spot_redistribution' => $needsRedistribution,
            'total_spot_count' => $totalSpotCount,
            'spot_method' => $freeze->legacySpotMethod(),
            'freeze' => $freeze,
            'surcharge_percent' => (string) $rule->surcharge_percent,
            'is_discountable' => (bool) $rule->is_discountable && (bool) $medium->is_discountable,
            'is_ae_eligible' => (bool) $rule->is_ae_eligible && (bool) $medium->is_ae_eligible,
            'inventory_medium_rule_id' => $rule->id,
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
     *     planner_entries: list<PlannerEntryInput>,
     *     needs_spot_redistribution: bool,
     *     total_spot_count: int,
     *     spot_method: SpotCalculationMethod,
     *     freeze: CalculationMethodFreezeDescriptor,
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
        ?string $requestedMethod,
        bool $rewriteExplicitMethodFailure = false,
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

        if ($medium === null) {
            throw ValidationException::withMessages([
                'positions' => 'Nur Spot Classic ist derzeit für neue Kalkulationen freigegeben.',
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

        // Eine maßgebliche Live-Prüfung: FreezeResolver → AdvertisingMediumLiveBookability.
        try {
            $freeze = $this->freezeResolver->resolveForNewCombination($medium, $requestedMethod);
        } catch (ValidationException $exception) {
            if (
                $rewriteExplicitMethodFailure
                && $requestedMethod !== null
                && trim($requestedMethod) !== ''
            ) {
                throw ValidationException::withMessages([
                    'positions' => 'Die Berechnungsmethode ist für dieses Werbemittel nicht mehr verfügbar. Bitte Auswahl aktualisieren.',
                ]);
            }

            throw $exception;
        }

        $priceList = $this->bindActivePriceListForLive($position, $inventory);
        if ($priceList === null) {
            throw ValidationException::withMessages([
                'positions' => $this->missingYearPriceListMessage(
                    $inventory->name,
                    $this->livePriceYearFromPayload($position),
                ),
            ]);
        }

        [$rows, $timeRanges, $plannerEntries, $totalSpotCount, $needsRedistribution] = $this->resolvePlan(
            $position,
            $priceList,
            $inventory->name,
            null,
            useSnapshot: false,
            spotMethod: $freeze->legacySpotMethod(),
        );

        return [
            'inventory' => $inventory,
            'medium' => $medium,
            'rule' => $rule,
            'priceList' => $priceList,
            'rows' => $rows,
            'time_ranges' => $timeRanges,
            'planner_entries' => $plannerEntries,
            'needs_spot_redistribution' => $needsRedistribution,
            'total_spot_count' => $totalSpotCount,
            'spot_method' => $freeze->legacySpotMethod(),
            'freeze' => $freeze,
            'surcharge_percent' => (string) $rule->surcharge_percent,
            'is_discountable' => (bool) $rule->is_discountable && (bool) $medium->is_discountable,
            'is_ae_eligible' => (bool) $rule->is_ae_eligible && (bool) $medium->is_ae_eligible,
            'inventory_medium_rule_id' => $rule->id,
            'inventory_changed' => $inventoryChanged,
            'medium_changed' => $mediumChanged,
        ];
    }

    public function activePriceList(int $inventoryId, ?int $year = null): ?PriceList
    {
        $year ??= PriceListCalendar::currentYear();

        return PriceList::query()
            ->where('inventory_id', $inventoryId)
            ->where('status', PriceListStatus::Active)
            ->where('year', $year)
            ->with('items')
            ->orderByDesc('id')
            ->first();
    }

    /**
     * @param  array<string, mixed>  $position
     */
    private function shouldRebindPriceYear(array $position, CalculationPosition $existing): bool
    {
        if (! array_key_exists('price_year', $position) || $position['price_year'] === null || $position['price_year'] === '') {
            return false;
        }

        $pinnedYear = $this->pinnedPriceYear($existing);
        if ($pinnedYear === null) {
            return true;
        }

        return (int) $position['price_year'] !== $pinnedYear;
    }

    private function pinnedPriceYear(CalculationPosition $existing): ?int
    {
        if ($existing->relationLoaded('priceList') && $existing->priceList !== null) {
            return (int) $existing->priceList->year;
        }

        $year = PriceList::query()->whereKey($existing->price_list_id)->value('year');

        return $year === null ? null : (int) $year;
    }

    /**
     * @param  array<string, mixed>  $position
     */
    private function livePriceYearFromPayload(array $position): int
    {
        return PriceListYearSelection::resolveYearFromPayload($position);
    }

    /**
     * @param  array<string, mixed>  $position
     */
    private function bindActivePriceListForLive(array $position, Inventory $inventory): ?PriceList
    {
        $year = $this->livePriceYearFromPayload($position);
        PriceListYearSelection::assertAllowedLiveYear($year, 'positions');

        $priceList = $this->activePriceList($inventory->id, $year);
        if ($priceList === null) {
            return null;
        }

        PriceListYearSelection::assertExpectedMatchesResolved(
            $position['expected_price_list_id'] ?? null,
            $priceList,
            required: PriceListYearSelection::hasExplicitPriceYear($position),
        );

        return $priceList;
    }

    private function missingYearPriceListMessage(string $inventoryName, int $year): string
    {
        return PriceListYearSelection::missingYearPriceListMessage($inventoryName, $year);
    }

    /**
     * @param  array<string, mixed>  $position
     * @return array{0: list<PlanRowInput>, 1: list<TimeRangeInput>, 2: list<PlannerEntryInput>, 3: int, 4: bool}
     */
    private function resolvePlan(
        array $position,
        PriceList $priceList,
        string $inventoryName,
        ?CalculationPosition $existing,
        bool $useSnapshot,
        SpotCalculationMethod $spotMethod,
    ): array {
        if ($spotMethod === SpotCalculationMethod::Calendar) {
            $plannerEntries = $this->resolvePlannerEntries(
                $position,
                $priceList,
                $inventoryName,
                $existing,
                $useSnapshot,
            );
            $totalSpots = array_sum(array_map(
                fn (PlannerEntryInput $entry): int => $entry->spotCount,
                $plannerEntries,
            ));

            return [[], [], $plannerEntries, $totalSpots, false];
        }

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

                return [$rows, $timeRanges, [], $totalSpots, $needsRedistribution];
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

            return [$rows, $timeRanges, [], $legacyTotal, false];
        }

        return [$rows, [], [], $legacyTotal, $legacyAmbiguous];
    }

    /**
     * @param  array<string, mixed>  $position
     * @return list<PlannerEntryInput>
     */
    private function resolvePlannerEntries(
        array $position,
        PriceList $priceList,
        string $inventoryName,
        ?CalculationPosition $existing,
        bool $useSnapshot,
    ): array {
        $payloadEntries = $position['planner_entries'] ?? null;
        $existingEntries = $this->existingPlannerEntries($existing);

        if (is_array($payloadEntries) && $payloadEntries !== []) {
            $validated = (new PlannerEntryValidator)->validated(
                $payloadEntries,
                'positions',
                requireAtLeastOne: false,
            );

            if ($validated !== []) {
                return $this->hydratePlannerEntries(
                    $validated,
                    $priceList,
                    $inventoryName,
                    $existingEntries,
                    $useSnapshot,
                );
            }
        }

        if ($existingEntries->isNotEmpty()) {
            $entries = [];
            foreach ($existingEntries as $stored) {
                $date = $stored->dateIso();
                $entries[] = new PlannerEntryInput(
                    date: $date,
                    hour: (int) $stored->hour,
                    spotCount: (int) $stored->spot_count,
                    dayGroup: $stored->day_group,
                    secondPrice: (string) $stored->second_price,
                );
            }

            return $entries;
        }

        return [];
    }

    /**
     * @param  list<array{date: string, hour: int, spot_count: int, sort: int}>  $entries
     * @param  Collection<string, mixed>  $existingEntries
     * @return list<PlannerEntryInput>
     */
    private function hydratePlannerEntries(
        array $entries,
        PriceList $priceList,
        string $inventoryName,
        Collection $existingEntries,
        bool $useSnapshot,
    ): array {
        $this->assertPlannerEntryDatesMatchPriceYear($entries, $priceList);

        $plannerEntries = [];
        /** @var array<string, list<string>> $missingByDayGroup */
        $missingByDayGroup = [];

        foreach ($entries as $entry) {
            $dayGroup = DayGroupFromDate::resolve($entry['date']);
            $price = $this->resolvePlannerEntryPrice(
                $priceList,
                $entry['date'],
                $entry['hour'],
                $dayGroup,
                $existingEntries,
                $useSnapshot,
            );

            if ($price === null) {
                $missingByDayGroup[$dayGroup->label()][] = $entry['date'].' '.TimeRangeHours::formatHour($entry['hour']);

                continue;
            }

            $plannerEntries[] = new PlannerEntryInput(
                date: $entry['date'],
                hour: $entry['hour'],
                spotCount: $entry['spot_count'],
                dayGroup: $dayGroup,
                secondPrice: $price,
                sort: $entry['sort'],
            );
        }

        if ($missingByDayGroup !== []) {
            throw ValidationException::withMessages([
                'positions' => $this->formatMissingPriceMessage($inventoryName, $missingByDayGroup),
            ]);
        }

        return $plannerEntries;
    }

    /**
     * @param  list<array{date: string, hour: int, spot_count: int, sort: int}>  $entries
     */
    private function assertPlannerEntryDatesMatchPriceYear(array $entries, PriceList $priceList): void
    {
        $priceYear = (int) $priceList->year;
        $errors = [];
        $prefix = $this->plannerEntriesFieldPrefix();

        foreach ($entries as $entry) {
            $entryYear = (int) substr($entry['date'], 0, 4);
            if ($entryYear === $priceYear) {
                continue;
            }

            $fieldKey = $prefix.'.'.$entry['sort'].'.date';
            $errors[$fieldKey] = [
                sprintf(
                    'Das Datum %s gehört nicht zum Preisjahr %d dieser Position. Für ein weiteres Preisjahr legen Sie bitte eine getrennte Position an.',
                    $entry['date'],
                    $priceYear,
                ),
            ];
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }

    private function plannerEntriesFieldPrefix(): string
    {
        if ($this->currentPositionIndex !== null) {
            return 'positions.'.$this->currentPositionIndex.'.planner_entries';
        }

        return 'positions';
    }

    /**
     * @return Collection<string, mixed>
     */
    private function existingPlannerEntries(?CalculationPosition $existing): Collection
    {
        if ($existing === null || ! Schema::hasTable('calculation_position_planner_entries')) {
            return collect();
        }

        $entries = $existing->relationLoaded('plannerEntries')
            ? $existing->plannerEntries
            : $existing->plannerEntries()->get();

        return $entries->keyBy(function ($row): string {
            $date = $row->dateIso();

            return $date.'|'.$row->hour;
        });
    }

    /**
     * @param  Collection<string, mixed>  $existingEntries
     */
    private function resolvePlannerEntryPrice(
        PriceList $priceList,
        string $date,
        int $hour,
        DayGroup $dayGroup,
        Collection $existingEntries,
        bool $useSnapshot,
    ): ?string {
        $stored = $existingEntries->get($date.'|'.$hour);
        if ($useSnapshot && $stored !== null) {
            return (string) $stored->second_price;
        }

        return $this->findSecondPrice($priceList, $hour, $dayGroup);
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

            if ($item !== null) {
                $base[$group->value] = (string) $item->second_price;
            }
        }

        try {
            return DayGroupPrice::fromBaseMap($base, $dayGroup);
        } catch (\InvalidArgumentException) {
            return null;
        }
    }

    /**
     * Budget-/Re-Optimierung: aktuelle Live-Katalogoperation (kein historischer Freeze).
     *
     * @return array{
     *     inventory: Inventory,
     *     medium: AdvertisingMedium,
     *     rule: InventoryMediumRule,
     *     priceList: PriceList,
     *     freeze: CalculationMethodFreezeDescriptor,
     *     surcharge_percent: string,
     *     is_discountable: bool,
     *     is_ae_eligible: bool,
     *     inventory_medium_rule_id: int
     * }
     */
    public function resolveInventoryForBudget(int $inventoryId, int $mediumId, ?int $priceYear = null): array
    {
        $inventory = Inventory::query()->find($inventoryId);
        if ($inventory === null || ! $inventory->is_active) {
            throw ValidationException::withMessages([
                'budget_wish_inventory_ids' => 'Der gewählte Sender ist nicht verfügbar.',
            ]);
        }

        $medium = AdvertisingMedium::query()
            ->whereKey($mediumId)
            ->where('is_active', true)
            ->first();
        if ($medium === null) {
            throw ValidationException::withMessages([
                'budget_wish_inventory_ids' => 'Spot Classic ist nicht verfügbar.',
            ]);
        }

        $rule = InventoryMediumRule::query()
            ->where('inventory_id', $inventory->id)
            ->where('advertising_medium_id', $medium->id)
            ->where('is_active', true)
            ->first();

        if ($rule === null) {
            throw ValidationException::withMessages([
                'budget_wish_inventory_ids' => 'Die Kombination '.$inventory->name.'/Spot ist nicht zulässig.',
            ]);
        }

        try {
            // Eine maßgebliche Live-Prüfung (kein vorausgehender Doppel-Evaluate).
            $freeze = $this->freezeResolver->resolveForNewCombination(
                $medium,
                SpotCalculationMethod::Average->value,
            );
        } catch (ValidationException $exception) {
            $messages = [];
            foreach ($exception->errors() as $fieldMessages) {
                foreach ($fieldMessages as $message) {
                    $messages[] = $message;
                }
            }

            throw ValidationException::withMessages([
                'budget_wish_inventory_ids' => array_values(array_unique($messages)),
            ]);
        }

        $year = $priceYear ?? PriceListCalendar::currentYear();
        PriceListYearSelection::assertAllowedLiveYear($year, 'price_year');

        $priceList = $this->activePriceList($inventory->id, $year);
        if ($priceList === null) {
            throw ValidationException::withMessages([
                'budget_wish_inventory_ids' => $this->missingYearPriceListMessage($inventory->name, $year),
            ]);
        }

        return [
            'inventory' => $inventory,
            'medium' => $medium,
            'rule' => $rule,
            'priceList' => $priceList,
            'freeze' => $freeze,
            'surcharge_percent' => (string) $rule->surcharge_percent,
            'is_discountable' => (bool) $rule->is_discountable && (bool) $medium->is_discountable,
            'is_ae_eligible' => (bool) $rule->is_ae_eligible && (bool) $medium->is_ae_eligible,
            'inventory_medium_rule_id' => $rule->id,
        ];
    }
}
