<?php

namespace App\Services\OnlineAudio\Import;

use App\Enums\PriceListStatus;
use App\Models\AdvertisingMedium;
use App\Models\Inventory;
use App\Models\OaTkpPriceList;
use App\Models\OaTkpPriceListItem;
use Illuminate\Support\Facades\DB;

/**
 * PO-CALC-TKP-1A: Import-Grundlage für OA-TKP-Preislisten (Jahr × Medium × Inventar).
 *
 * Keine Plattformdimension (FD-OA-PLAT offen). Keine produktiven Fake-Seeds.
 */
final class OaTkpPriceListImporter
{
    /** @var list<string> */
    private const CORE_MEDIA = [
        'pre_stream',
        'in_stream',
        'pre_stream_influencer',
        'in_stream_influencer',
        'midroll_music_environment',
    ];

    /**
     * @param  array{
     *     year: int,
     *     name?: string,
     *     version?: string,
     *     revision_number?: int,
     *     status?: string,
     *     valid_from?: string|null,
     *     items: list<array{
     *         medium_code: string,
     *         inventory_code: string,
     *         list_tkp: string|float|int,
     *         minimum_tkp: string|float|int
     *     }>
     * }  $payload
     * @return array{price_list_id: int|null, items_written: int, dry_run: bool}
     */
    public function import(array $payload, bool $dryRun = false): array
    {
        $errors = [];
        $year = (int) ($payload['year'] ?? 0);
        if ($year < 2000 || $year > 2100) {
            $errors[] = 'year ungültig.';
        }

        $revision = (int) ($payload['revision_number'] ?? 1);
        if ($revision < 1) {
            $errors[] = 'revision_number muss ≥ 1 sein.';
        }

        $statusRaw = (string) ($payload['status'] ?? PriceListStatus::Draft->value);
        $status = PriceListStatus::tryFrom($statusRaw);
        if ($status === null) {
            $errors[] = 'status ungültig (draft|active|archived).';
        }

        $items = $payload['items'] ?? null;
        if (! is_array($items) || $items === []) {
            $errors[] = 'items fehlen.';
            $items = [];
        }

        $seenPairs = [];
        $resolvedItems = [];

        foreach ($items as $index => $item) {
            $line = $index + 1;
            $mediumCode = trim((string) ($item['medium_code'] ?? ''));
            $inventoryCode = trim((string) ($item['inventory_code'] ?? ''));
            $listTkp = $this->normalizePositiveDecimal($item['list_tkp'] ?? null);
            $minimumTkp = $this->normalizePositiveDecimal($item['minimum_tkp'] ?? null);

            if ($mediumCode === '') {
                $errors[] = "Item {$line}: medium_code fehlt.";
            } elseif ($mediumCode === 'native_ad') {
                $errors[] = "Item {$line}: native_ad ist OUT für TKP-Kern.";
            } elseif (! in_array($mediumCode, self::CORE_MEDIA, true)) {
                $errors[] = "Item {$line}: medium_code „{$mediumCode}“ ist kein TKP-Kernmedium.";
            }

            if ($inventoryCode === '') {
                $errors[] = "Item {$line}: inventory_code fehlt.";
            }

            $pairKey = $mediumCode.'|'.$inventoryCode;
            if ($mediumCode !== '' && $inventoryCode !== '' && isset($seenPairs[$pairKey])) {
                $errors[] = "Item {$line}: Duplikat Medium×Inventar „{$pairKey}“.";
            } elseif ($mediumCode !== '' && $inventoryCode !== '') {
                $seenPairs[$pairKey] = true;
            }

            if ($listTkp === null) {
                $errors[] = "Item {$line}: list_tkp ungültig (muss > 0 sein).";
            }
            if ($minimumTkp === null) {
                $errors[] = "Item {$line}: minimum_tkp ungültig (muss > 0 sein).";
            }
            if ($listTkp !== null && $minimumTkp !== null && bccomp($minimumTkp, $listTkp, 4) === 1) {
                $errors[] = "Item {$line}: minimum_tkp darf list_tkp nicht überschreiten.";
            }

            $mediumId = $mediumCode !== ''
                ? AdvertisingMedium::query()->where('code', $mediumCode)->value('id')
                : null;
            $inventoryId = $inventoryCode !== ''
                ? Inventory::query()->where('code', $inventoryCode)->value('id')
                : null;

            if ($mediumCode !== '' && $mediumId === null) {
                $errors[] = "Item {$line}: unbekanntes Medium „{$mediumCode}“.";
            }
            if ($inventoryCode !== '' && $inventoryId === null) {
                $errors[] = "Item {$line}: unbekanntes Inventory „{$inventoryCode}“.";
            }

            if ($mediumId !== null && $inventoryId !== null && $listTkp !== null && $minimumTkp !== null) {
                $resolvedItems[] = [
                    'advertising_medium_id' => (int) $mediumId,
                    'inventory_id' => (int) $inventoryId,
                    'list_tkp' => $listTkp,
                    'minimum_tkp' => $minimumTkp,
                ];
            }
        }

        if ($errors !== []) {
            throw new OaTkpStammdatenImportException('TKP-Preislisten-Import ungültig.', $errors);
        }

        if ($dryRun) {
            return [
                'price_list_id' => null,
                'items_written' => count($resolvedItems),
                'dry_run' => true,
            ];
        }

        /** @var PriceListStatus $status */
        $listId = null;

        DB::transaction(function () use ($payload, $year, $revision, $status, $resolvedItems, &$listId): void {
            if ($status === PriceListStatus::Active) {
                $otherActive = OaTkpPriceList::query()
                    ->where('year', $year)
                    ->where('status', PriceListStatus::Active)
                    ->where(function ($q) use ($revision): void {
                        $q->where('revision_number', '!=', $revision);
                    })
                    ->exists();
                if ($otherActive) {
                    throw new OaTkpStammdatenImportException(
                        'TKP-Preislisten-Import ungültig.',
                        ["Jahr {$year}: es existiert bereits eine active Revision."],
                    );
                }
            }

            $list = OaTkpPriceList::query()->firstOrNew([
                'year' => $year,
                'revision_number' => $revision,
            ]);

            $list->fill([
                'name' => (string) ($payload['name'] ?? "OA TKP {$year} r{$revision}"),
                'version' => (string) ($payload['version'] ?? "{$year}.{$revision}"),
                'status' => $status,
                'valid_from' => $payload['valid_from'] ?? null,
                'lock_version' => $list->exists ? ((int) $list->lock_version) + 1 : 1,
                'published_at' => $status === PriceListStatus::Active ? ($list->published_at ?? now()) : null,
                'archived_at' => $status === PriceListStatus::Archived ? ($list->archived_at ?? now()) : null,
            ]);
            $list->save();

            OaTkpPriceListItem::query()->where('oa_tkp_price_list_id', $list->id)->delete();
            foreach ($resolvedItems as $item) {
                OaTkpPriceListItem::query()->create([
                    'oa_tkp_price_list_id' => $list->id,
                    ...$item,
                ]);
            }

            $listId = (int) $list->id;
        });

        return [
            'price_list_id' => $listId,
            'items_written' => count($resolvedItems),
            'dry_run' => false,
        ];
    }

    private function normalizePositiveDecimal(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (! is_numeric($value)) {
            return null;
        }

        $normalized = number_format((float) $value, 4, '.', '');
        if (bccomp($normalized, '0', 4) !== 1) {
            return null;
        }

        return $normalized;
    }
}
