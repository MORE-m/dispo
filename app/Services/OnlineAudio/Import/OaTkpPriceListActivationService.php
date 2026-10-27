<?php

namespace App\Services\OnlineAudio\Import;

use App\Enums\PriceListStatus;
use App\Models\OaTkpPriceList;
use App\Models\OaTkpPriceListItem;
use App\Support\OnlineAudio\OaTkpCoreMedia;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * PO-CALC-TKP-1D4c-2: Atomare Aktivierung einer OA-TKP-Draft-Revision.
 *
 * Archivierung eines Vorgängers nur mit archivePrevious=true.
 * Keine Item-Änderungen, kein Löschen historischer Listen.
 */
final class OaTkpPriceListActivationService
{
    public function __construct(
        private readonly OaTkpPriceListYearLock $yearLock,
    ) {}

    /**
     * @return array{
     *     dry_run: bool,
     *     year: int,
     *     revision_number: int,
     *     price_list_id: int|null,
     *     activated: bool,
     *     already_active: bool,
     *     archived_previous_ids: array<int, int>,
     *     items_count: int
     * }
     */
    public function activate(int $year, int $revision, bool $archivePrevious = false, bool $dryRun = false): array
    {
        if ($year < 2000 || $year > 2100) {
            throw new OaTkpStammdatenImportException('Aktivierung ungültig.', ['year ungültig.']);
        }
        if ($revision < 1) {
            throw new OaTkpStammdatenImportException('Aktivierung ungültig.', ['revision muss ≥ 1 sein.']);
        }

        $this->yearLock->acquire($year);

        try {
            return DB::transaction(function () use ($year, $revision, $archivePrevious, $dryRun): array {
                // Bestehende Zeilen des Jahres für die TX serialisieren (ergänzend zu GET_LOCK).
                OaTkpPriceList::query()
                    ->where('year', $year)
                    ->orderBy('id')
                    ->lockForUpdate()
                    ->get();

                $target = OaTkpPriceList::query()
                    ->where('year', $year)
                    ->where('revision_number', $revision)
                    ->first();

                if ($target === null) {
                    throw new OaTkpStammdatenImportException(
                        'Aktivierung ungültig.',
                        ["Jahr {$year} Revision {$revision}: Preisliste nicht gefunden."],
                    );
                }

                $itemsCount = $this->assertActivatable($target);

                $otherActives = OaTkpPriceList::query()
                    ->where('year', $year)
                    ->where('status', PriceListStatus::Active)
                    ->where('id', '!=', $target->id)
                    ->orderBy('revision_number')
                    ->get();

                if ($target->status === PriceListStatus::Active && $otherActives->isEmpty()) {
                    return [
                        'dry_run' => $dryRun,
                        'year' => $year,
                        'revision_number' => $revision,
                        'price_list_id' => (int) $target->id,
                        'activated' => false,
                        'already_active' => true,
                        'archived_previous_ids' => [],
                        'items_count' => $itemsCount,
                    ];
                }

                if ($target->status !== PriceListStatus::Draft && $target->status !== PriceListStatus::Active) {
                    throw new OaTkpStammdatenImportException(
                        'Aktivierung ungültig.',
                        [
                            "Jahr {$year} Revision {$revision}: Status „{$target->status->value}“ kann nicht aktiviert werden (erwartet draft).",
                        ],
                    );
                }

                if ($target->status === PriceListStatus::Active && $otherActives->isNotEmpty()) {
                    // Inkonsistenter Altstand: ohne archive-previous nicht heilen.
                    if (! $archivePrevious) {
                        throw new OaTkpStammdatenImportException(
                            'Aktivierung ungültig.',
                            [
                                "Jahr {$year}: mehrere active Revisionen vorhanden; --archive-previous erforderlich.",
                            ],
                        );
                    }
                }

                if ($otherActives->isNotEmpty() && ! $archivePrevious) {
                    $ids = $otherActives->pluck('revision_number')->implode(', ');
                    throw new OaTkpStammdatenImportException(
                        'Aktivierung ungültig.',
                        [
                            "Jahr {$year}: es existiert bereits eine active Revision ({$ids})."
                            .' Bitte --archive-previous setzen oder Vorgänger manuell archivieren.',
                        ],
                    );
                }

                if ($dryRun) {
                    return [
                        'dry_run' => true,
                        'year' => $year,
                        'revision_number' => $revision,
                        'price_list_id' => (int) $target->id,
                        'activated' => true,
                        'already_active' => false,
                        'archived_previous_ids' => array_values($otherActives->pluck('id')->map(fn ($id): int => (int) $id)->all()),
                        'items_count' => $itemsCount,
                    ];
                }

                $archivedIds = [];
                foreach ($otherActives as $previous) {
                    $previous->status = PriceListStatus::Archived;
                    if ($previous->archived_at === null) {
                        $previous->archived_at = Carbon::now();
                    }
                    $previous->lock_version = ((int) $previous->lock_version) + 1;
                    $previous->save();
                    $archivedIds[] = (int) $previous->id;
                }

                $target->status = PriceListStatus::Active;
                if ($target->published_at === null) {
                    $target->published_at = Carbon::now();
                }
                $target->archived_at = null;
                $target->lock_version = ((int) $target->lock_version) + 1;
                $target->save();

                $activeCount = OaTkpPriceList::query()
                    ->where('year', $year)
                    ->where('status', PriceListStatus::Active)
                    ->count();
                if ($activeCount !== 1) {
                    throw new OaTkpStammdatenImportException(
                        'Aktivierung fehlgeschlagen.',
                        ["Jahr {$year}: nach Aktivierung wären {$activeCount} active Revisionen (erwartet 1)."],
                    );
                }

                Log::info('oa_tkp.price_list.activated', [
                    'year' => $year,
                    'revision_number' => $revision,
                    'price_list_id' => (int) $target->id,
                    'archived_previous_ids' => $archivedIds,
                    'items_count' => $itemsCount,
                ]);

                return [
                    'dry_run' => false,
                    'year' => $year,
                    'revision_number' => $revision,
                    'price_list_id' => (int) $target->id,
                    'activated' => true,
                    'already_active' => false,
                    'archived_previous_ids' => $archivedIds,
                    'items_count' => $itemsCount,
                ];
            });
        } finally {
            $this->yearLock->release();
        }
    }

    private function assertActivatable(OaTkpPriceList $list): int
    {
        $items = OaTkpPriceListItem::query()
            ->where('oa_tkp_price_list_id', $list->id)
            ->with(['advertisingMedium:id,code', 'inventory:id,code'])
            ->get();

        if ($items->isEmpty()) {
            throw new OaTkpStammdatenImportException(
                'Aktivierung ungültig.',
                ["Jahr {$list->year} Revision {$list->revision_number}: keine Items vorhanden."],
            );
        }

        $errors = [];
        $seen = [];
        foreach ($items as $index => $item) {
            $line = $index + 1;
            $medium = $item->advertisingMedium;
            $inventory = $item->inventory;
            if ($medium === null || $inventory === null) {
                $errors[] = "Item {$line}: Medium oder Inventar fehlt / nicht auflösbar.";

                continue;
            }
            $mediumCode = (string) $medium->code;
            $inventoryCode = (string) $inventory->code;

            if ($mediumCode === 'native_ad' || ! OaTkpCoreMedia::contains($mediumCode)) {
                $errors[] = "Item {$line}: Medium „{$mediumCode}“ ist für OA-TKP unzulässig.";
            }

            $pair = $mediumCode.'|'.$inventoryCode;
            if (isset($seen[$pair])) {
                $errors[] = "Item {$line}: Duplikat Medium×Inventar „{$pair}“.";
            }
            $seen[$pair] = true;

            $listTkp = OaTkpImportDecimal::normalizePositive($item->list_tkp);
            $minimumTkp = OaTkpImportDecimal::normalizePositive($item->minimum_tkp);
            if ($listTkp === null) {
                $errors[] = "Item {$line}: list_tkp ungültig.";
            }
            if ($minimumTkp === null) {
                $errors[] = "Item {$line}: minimum_tkp ungültig.";
            }
            if (
                $listTkp !== null
                && $minimumTkp !== null
                && OaTkpImportDecimal::compare($minimumTkp, $listTkp) > 0
            ) {
                $errors[] = "Item {$line}: minimum_tkp darf list_tkp nicht überschreiten.";
            }
        }

        if ($errors !== []) {
            throw new OaTkpStammdatenImportException('Aktivierung ungültig.', $errors);
        }

        return $items->count();
    }
}
