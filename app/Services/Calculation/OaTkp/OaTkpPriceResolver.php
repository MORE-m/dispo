<?php

namespace App\Services\Calculation\OaTkp;

use App\Enums\PriceListStatus;
use App\Models\OaTkpPriceList;
use App\Models\OaTkpPriceListItem;
use App\Support\OnlineAudio\OaTkpCoreMedia;
use Illuminate\Validation\ValidationException;

/**
 * PO-CALC-TKP-1B: aktive OA-TKP-Preisauflösung (Jahr × Medium × Inventar).
 *
 * Keine Plattformdimension (FD-OA-PLAT). Fail-closed bei 0 oder >1 active Revision/Jahr.
 */
final class OaTkpPriceResolver
{
    /**
     * @return array{
     *     price_list_id: int,
     *     version: string,
     *     year: int,
     *     revision_number: int,
     *     list_tkp: string,
     *     minimum_tkp: string,
     *     item_id: int
     * }
     */
    public function resolveActive(int $year, int $advertisingMediumId, int $inventoryId, string $mediumCode): array
    {
        if (! OaTkpCoreMedia::contains($mediumCode)) {
            throw ValidationException::withMessages([
                'positions' => "Medium „{$mediumCode}“ ist kein TKP-Kernmedium.",
            ]);
        }

        if ($mediumCode === 'native_ad') {
            throw ValidationException::withMessages([
                'positions' => 'native_ad ist OUT für TKP.',
            ]);
        }

        $actives = OaTkpPriceList::query()
            ->where('year', $year)
            ->where('status', PriceListStatus::Active)
            ->orderBy('revision_number')
            ->get();

        if ($actives->isEmpty()) {
            throw ValidationException::withMessages([
                'positions' => "Keine aktive OA-TKP-Preislistenrevision für Jahr {$year}.",
            ]);
        }

        if ($actives->count() > 1) {
            throw ValidationException::withMessages([
                'positions' => "Mehrdeutige aktive OA-TKP-Preislistenrevisionen für Jahr {$year}.",
            ]);
        }

        /** @var OaTkpPriceList $list */
        $list = $actives->first();

        $item = OaTkpPriceListItem::query()
            ->where('oa_tkp_price_list_id', $list->id)
            ->where('advertising_medium_id', $advertisingMediumId)
            ->where('inventory_id', $inventoryId)
            ->first();

        if ($item === null) {
            throw ValidationException::withMessages([
                'positions' => 'Kein OA-TKP-Preis für Medium×Inventar in der aktiven Revision.',
            ]);
        }

        return [
            'price_list_id' => (int) $list->id,
            'version' => (string) $list->version,
            'year' => (int) $list->year,
            'revision_number' => (int) $list->revision_number,
            'list_tkp' => (string) $item->list_tkp,
            'minimum_tkp' => (string) $item->minimum_tkp,
            'item_id' => (int) $item->id,
        ];
    }
}
