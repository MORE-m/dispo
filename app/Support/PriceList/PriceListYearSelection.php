<?php

namespace App\Support\PriceList;

use App\Enums\PriceListStatus;
use App\Exceptions\FieldSetAssignmentConflictException;
use App\Models\PriceList;
use Carbon\CarbonInterface;
use Illuminate\Validation\ValidationException;

/**
 * PO-PRI-YEAR-1: wählbare Preisjahre (aktuelles + Folgejahr) und Expected-ID-Schutz.
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

    public static function assertAllowedLiveYear(int $year, string $errorKey = 'positions'): void
    {
        if (! self::isAllowedSelectableYear($year)) {
            throw ValidationException::withMessages([
                $errorKey => 'Das Preisjahr '.$year.' ist nicht wählbar. Erlaubt sind das aktuelle und das folgende Kalenderjahr.',
            ]);
        }
    }

    public static function assertExpectedMatchesResolved(
        mixed $expectedPriceListId,
        PriceList $resolved,
        string $message = 'Die aktive Preisliste hat sich geändert. Bitte neu laden und bewusst speichern.',
    ): void {
        if ($expectedPriceListId === null || $expectedPriceListId === '') {
            return;
        }

        if ((int) $expectedPriceListId !== (int) $resolved->id) {
            throw new FieldSetAssignmentConflictException($message);
        }
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
        if (array_key_exists('price_year', $payload) && $payload['price_year'] !== null && $payload['price_year'] !== '') {
            return (int) $payload['price_year'];
        }

        return $fallback ?? PriceListCalendar::currentYear();
    }
}
