<?php

namespace App\Support\PriceList;

use App\Enums\PriceListStatus;
use App\Exceptions\PriceListSelectionConflictException;
use App\Models\Inventory;
use App\Models\PriceList;
use Carbon\CarbonInterface;
use Illuminate\Validation\ValidationException;

/**
 * PO-PRI-YEAR-1: wählbare Preisjahre (aktuelles + Folgejahr) und Expected-ID-Schutz.
 *
 * Expected-Token (`expected_price_list_id` / Budget-Map) ist verpflichtend, wenn der Client
 * ausdrücklich `price_year` für eine Live-Bindung bzw. einen bewussten Rebind übermittelt
 * und für Inventar/Jahr eine aktive Liste existiert. Legacy-Payloads ohne `price_year`
 * behalten den bisherigen Live-Default ohne Expected-Pflicht. Unveränderte historische
 * Pins (kein Jahrwechsel) brauchen keinen Live-Expected-Abgleich.
 */
final class PriceListYearSelection
{
    public static function nextYear(?CarbonInterface $now = null): int
    {
        return PriceListCalendar::currentYear($now) + 1;
    }

    public static function isAllowedSelectableYear(int $year, ?CarbonInterface $now = null): bool
    {
        $current = PriceListCalendar::currentYear($now);

        return $year === $current || $year === $current + 1;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public static function hasExplicitPriceYear(array $payload): bool
    {
        return array_key_exists('price_year', $payload)
            && $payload['price_year'] !== null
            && $payload['price_year'] !== '';
    }

    /**
     * @return list<array{
     *     year: int,
     *     price_list_id: int|null,
     *     version: string|null,
     *     status: string|null,
     *     is_default: bool,
     *     available: bool
     * }>
     */
    public static function optionsForInventory(int $inventoryId, ?CarbonInterface $now = null): array
    {
        $current = PriceListCalendar::currentYear($now);
        $next = $current + 1;
        $options = [];

        $currentList = self::activeList($inventoryId, $current);
        $options[] = [
            'year' => $current,
            'price_list_id' => $currentList?->id,
            'version' => $currentList?->version,
            'status' => $currentList?->status?->value,
            'is_default' => true,
            'available' => $currentList !== null,
        ];

        $nextList = self::activeList($inventoryId, $next);
        if ($nextList !== null) {
            $options[] = [
                'year' => $next,
                'price_list_id' => $nextList->id,
                'version' => $nextList->version,
                'status' => $nextList->status->value,
                'is_default' => false,
                'available' => true,
            ];
        }

        return $options;
    }

    public static function activeList(int $inventoryId, int $year): ?PriceList
    {
        return PriceList::query()
            ->where('inventory_id', $inventoryId)
            ->where('status', PriceListStatus::Active)
            ->where('year', $year)
            ->orderByDesc('id')
            ->first();
    }

    /**
     * Gemeinsame Serialisierungsgrenze mit BL-P4-01a-Aktivierung.
     *
     * Reihenfolge (interleaved je Inventar, IDs aufsteigend):
     * je Inventar → Inventory-Zeile → zugehörige PriceLists der angegebenen Jahre
     * (Jahre aufsteigend, Listen-IDs aufsteigend). Keine globale
     * „erst alle Inventare, dann alle Preislisten“-Garantie.
     *
     * @param  list<int>|array<int, int>  $inventoryIds
     * @param  list<int>|array<int, int>  $years
     */
    public static function lockInventoriesForLiveBinding(array $inventoryIds, array $years = []): void
    {
        $ids = array_values(array_unique(array_filter(
            array_map(static fn ($id): int => (int) $id, $inventoryIds),
            static fn (int $id): bool => $id > 0,
        )));
        sort($ids);

        $uniqueYears = array_values(array_unique(array_filter(
            array_map(static fn ($year): int => (int) $year, $years),
            static fn (int $year): bool => $year >= 2000,
        )));
        sort($uniqueYears);

        foreach ($ids as $inventoryId) {
            Inventory::query()->whereKey($inventoryId)->lockForUpdate()->first();
            foreach ($uniqueYears as $year) {
                PriceList::query()
                    ->where('inventory_id', $inventoryId)
                    ->where('year', $year)
                    ->orderBy('id')
                    ->lockForUpdate()
                    ->get(['id']);
            }
        }
    }

    public static function assertAllowedLiveYear(int $year, string $errorKey = 'positions'): void
    {
        if (! self::isAllowedSelectableYear($year)) {
            throw ValidationException::withMessages([
                $errorKey => 'Das Preisjahr '.$year.' ist nicht wählbar. Erlaubt sind das aktuelle und das folgende Kalenderjahr.',
            ]);
        }
    }

    /**
     * @param  bool  $required  true bei ausdrücklichem price_year (Live-Bind/Rebind)
     */
    public static function assertExpectedMatchesResolved(
        mixed $expectedPriceListId,
        PriceList $resolved,
        bool $required = false,
        string $conflictMessage = 'Die aktive Preisliste hat sich geändert. Bitte neu laden und bewusst speichern.',
        string $missingKey = 'expected_price_list_id',
    ): void {
        if ($expectedPriceListId === null || $expectedPriceListId === '') {
            if ($required) {
                throw ValidationException::withMessages([
                    $missingKey => 'Die erwartete Preisliste fehlt. Bitte neu laden und bewusst speichern.',
                ]);
            }

            return;
        }

        if ((int) $expectedPriceListId !== (int) $resolved->id) {
            throw new PriceListSelectionConflictException($conflictMessage);
        }
    }

    /**
     * Prüft Expected-Map-Vollständigkeit erst, nachdem für jedes Inventar eine
     * aktive Liste des gewählten Jahres erfolgreich aufgelöst wurde.
     * Aufrufer muss Missing-Active zuvor bereits als fachlichen Fehler behandelt haben.
     *
     * @param  array<string, mixed>  $payload
     * @param  list<int>  $inventoryIds  Inventare mit nachweislich aktiver Liste (ASC empfohlen)
     */
    public static function assertBudgetExpectedMapComplete(array $payload, array $inventoryIds): void
    {
        if (! self::hasExplicitPriceYear($payload)) {
            return;
        }

        $map = $payload['expected_price_list_ids'] ?? null;
        if (! is_array($map)) {
            throw ValidationException::withMessages([
                'expected_price_list_ids' => 'Für das gewählte Preisjahr müssen die erwarteten Preislisten angegeben werden.',
            ]);
        }

        $ids = array_values(array_unique(array_map(static fn ($id): int => (int) $id, $inventoryIds)));
        sort($ids);

        foreach ($ids as $inventoryId) {
            if (self::expectedIdFromBudgetMap($map, $inventoryId) === null) {
                throw ValidationException::withMessages([
                    'expected_price_list_ids' => 'Für jedes Inventar muss die erwartete Preisliste angegeben werden.',
                ]);
            }
        }
    }

    /**
     * @param  array<array-key, mixed>  $map
     */
    public static function expectedIdFromBudgetMap(array $map, int $inventoryId): mixed
    {
        if (array_key_exists($inventoryId, $map)) {
            return $map[$inventoryId];
        }

        if (array_key_exists((string) $inventoryId, $map)) {
            return $map[(string) $inventoryId];
        }

        return null;
    }

    public static function missingYearPriceListMessage(string $inventoryName, int $year): string
    {
        return 'Für '.$inventoryName.' liegt keine aktive Preisliste für '.$year
            .' vor. Eine andere Jahrespreisliste wird nicht automatisch verwendet.';
    }

    /**
     * @param  array<int, int>  $inventoryIds
     * @return array<int, list<array{
     *     year: int,
     *     price_list_id: int|null,
     *     version: string|null,
     *     status: string|null,
     *     is_default: bool,
     *     available: bool
     * }>>
     */
    public static function optionsByInventoryIds(array $inventoryIds, ?CarbonInterface $now = null): array
    {
        $map = [];
        foreach (array_values(array_unique(array_filter($inventoryIds))) as $inventoryId) {
            $id = (int) $inventoryId;
            $map[$id] = self::optionsForInventory($id, $now);
        }

        return $map;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public static function resolveYearFromPayload(array $payload, ?int $fallback = null): int
    {
        if (self::hasExplicitPriceYear($payload)) {
            return (int) $payload['price_year'];
        }

        return $fallback ?? PriceListCalendar::currentYear();
    }
}
