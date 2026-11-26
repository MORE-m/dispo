<?php

namespace App\Services\MasterDataTransfer\Support;

use App\Models\AdvertisingMedium;
use App\Models\Inventory;
use App\Models\OaTkpPriceList;
use App\Models\OaTkpPriceListItem;
use App\Models\PriceList;
use App\Models\PriceListItem;
use App\Models\ProductionPriceList;
use App\Services\MasterDataTransfer\MasterDataTransferException;
use Illuminate\Support\Facades\DB;
use PDO;

/**
 * Slice 6b-H / 7: einheitliche, rein lesende Sicht auf Preislisten (Paket- und Staging-Seite).
 *
 * Fingerabdruck und Metadaten werden exakt wie im Importer/Pin-Guard gebildet
 * (PriceListFingerprint + MasterDataExportDecimal), damit Paket- und Staging-Seite vergleichbar sind.
 * Alle Methoden sind schreibfrei.
 */
final class MasterDataPriceListIndexV2
{
    public const FAMILY_SPOT = 'spot';

    public const FAMILY_PRODUCTION = 'production';

    public const FAMILY_OA_TKP = 'oa_tkp';

    public const FAMILIES = [self::FAMILY_SPOT, self::FAMILY_PRODUCTION, self::FAMILY_OA_TKP];

    /**
     * Preislisten-Einträge aus dem (validierten) Paket, deterministisch sortiert.
     *
     * @param  array<string, mixed>  $data  MasterDataPackage::load()['data']
     * @return list<array{family: string, key: string, slot: string, source_status: string, fingerprint: string, metadata: array<string, mixed>, year: int, revision: int, inventory_code: ?string, production_type: ?string}>
     */
    public static function fromPackage(array $data): array
    {
        $entries = [];

        foreach ($data['data/price_lists.json'] ?? [] as $row) {
            if (! is_array($row)) {
                continue;
            }
            $items = [];
            foreach (is_array($row['items'] ?? null) ? $row['items'] : [] as $item) {
                $items[] = [
                    'hour' => (int) $item['hour'],
                    'day_group' => (string) $item['day_group'],
                    'second_price' => MasterDataExportDecimal::canonicalize($item['second_price'], MasterDataExportDecimal::PRICE_SCALE),
                ];
            }
            $inventoryCode = (string) ($row['inventory_code'] ?? '');
            $year = (int) ($row['year'] ?? 0);
            $revision = (int) ($row['revision_number'] ?? 0);
            $entries[] = [
                'family' => self::FAMILY_SPOT,
                'key' => self::spotKey($inventoryCode, $year, $revision),
                'slot' => self::spotSlot($inventoryCode, $year),
                'source_status' => (string) ($row['source_status'] ?? ''),
                'fingerprint' => PriceListFingerprint::forSpotItems($items),
                'metadata' => PriceListFingerprint::spotMetadataTuple((string) $row['name'], (string) $row['version'], $row['valid_from'] ?? null),
                'year' => $year,
                'revision' => $revision,
                'inventory_code' => $inventoryCode,
                'production_type' => null,
            ];
        }

        foreach ($data['data/production_price_lists.json'] ?? [] as $row) {
            if (! is_array($row)) {
                continue;
            }
            $inventoryCode = (string) ($row['inventory_code'] ?? '');
            $type = (string) ($row['production_type'] ?? '');
            $year = (int) ($row['year'] ?? 0);
            $revision = (int) ($row['revision_number'] ?? 0);
            $unit = MasterDataExportDecimal::canonicalize($row['unit_price'] ?? null, MasterDataExportDecimal::MONEY_SCALE);
            $entries[] = [
                'family' => self::FAMILY_PRODUCTION,
                'key' => self::productionKey($inventoryCode, $type, $year, $revision),
                'slot' => self::productionSlot($inventoryCode, $type, $year),
                'source_status' => (string) ($row['source_status'] ?? ''),
                'fingerprint' => PriceListFingerprint::forProduction($unit, (bool) $row['is_discountable'], (bool) $row['is_ae_eligible']),
                'metadata' => PriceListFingerprint::productionMetadataTuple((string) $row['name'], (string) $row['version']),
                'year' => $year,
                'revision' => $revision,
                'inventory_code' => $inventoryCode,
                'production_type' => $type,
            ];
        }

        foreach ($data['data/oa_tkp_price_lists.json'] ?? [] as $row) {
            if (! is_array($row)) {
                continue;
            }
            $items = [];
            foreach (is_array($row['items'] ?? null) ? $row['items'] : [] as $item) {
                $items[] = [
                    'inventory_code' => (string) $item['inventory_code'],
                    'medium_code' => (string) $item['medium_code'],
                    'list_tkp' => MasterDataExportDecimal::canonicalize($item['list_tkp'], MasterDataExportDecimal::PRICE_SCALE),
                    'minimum_tkp' => MasterDataExportDecimal::canonicalize($item['minimum_tkp'], MasterDataExportDecimal::PRICE_SCALE),
                ];
            }
            $year = (int) ($row['year'] ?? 0);
            $revision = (int) ($row['revision_number'] ?? 0);
            $entries[] = [
                'family' => self::FAMILY_OA_TKP,
                'key' => self::oaKey($year, $revision),
                'slot' => self::oaSlot($year),
                'source_status' => (string) ($row['source_status'] ?? ''),
                'fingerprint' => PriceListFingerprint::forOaTkpItems($items),
                'metadata' => PriceListFingerprint::spotMetadataTuple((string) $row['name'], (string) $row['version'], $row['valid_from'] ?? null),
                'year' => $year,
                'revision' => $revision,
                'inventory_code' => null,
                'production_type' => null,
            ];
        }

        usort($entries, fn (array $a, array $b): int => [$a['family'], $a['key']] <=> [$b['family'], $b['key']]);

        return $entries;
    }

    /**
     * Kompakte Fingerabdruck-Zusammenfassung des Pakets (für Receipts).
     *
     * @param  array<string, mixed>  $data
     * @return array{families: array<string, array{count: int, by_source_status: array<string, int>, digest: string}>, digest: string}
     */
    public static function packageFingerprintSummary(array $data): array
    {
        $entries = self::fromPackage($data);
        $families = [];
        foreach (self::FAMILIES as $family) {
            $subset = array_values(array_filter($entries, fn (array $e): bool => $e['family'] === $family));
            $byStatus = [];
            foreach ($subset as $e) {
                $byStatus[$e['source_status']] = ($byStatus[$e['source_status']] ?? 0) + 1;
            }
            ksort($byStatus);
            $families[$family] = [
                'count' => count($subset),
                'by_source_status' => $byStatus,
                'digest' => hash('sha256', MasterDataProofSignerV2::canonicalJson(array_map(
                    fn (array $e): array => [
                        'key' => $e['key'],
                        'source_status' => $e['source_status'],
                        'fingerprint' => $e['fingerprint'],
                        'metadata' => $e['metadata'],
                    ],
                    $subset,
                ))),
            ];
        }

        return [
            'families' => $families,
            'digest' => hash('sha256', MasterDataProofSignerV2::canonicalJson($families)),
        ];
    }

    /**
     * Staging-Liste zum Paketeintrag (oder null, wenn nicht vorhanden).
     *
     * @param  array{family: string, year: int, revision: int, inventory_code: ?string, production_type: ?string}  $entry
     * @return array<string, mixed>|null
     */
    public function stagingFor(array $entry): ?array
    {
        return $this->withStringifiedFetches(function () use ($entry): ?array {
            switch ($entry['family']) {
                case self::FAMILY_SPOT:
                    $inventory = Inventory::query()->where('code', (string) $entry['inventory_code'])->first();
                    if ($inventory === null) {
                        return null;
                    }
                    $list = PriceList::query()
                        ->where('inventory_id', $inventory->id)
                        ->where('year', $entry['year'])
                        ->where('revision_number', $entry['revision'])
                        ->first();

                    return $list === null ? null : $this->snapshotSpot($list, (string) $inventory->code);

                case self::FAMILY_PRODUCTION:
                    $inventory = Inventory::query()->where('code', (string) $entry['inventory_code'])->first();
                    if ($inventory === null) {
                        return null;
                    }
                    $list = ProductionPriceList::query()
                        ->where('inventory_id', $inventory->id)
                        ->where('production_type', (string) $entry['production_type'])
                        ->where('year', $entry['year'])
                        ->where('revision_number', $entry['revision'])
                        ->first();

                    return $list === null ? null : $this->snapshotProduction($list, (string) $inventory->code);

                case self::FAMILY_OA_TKP:
                    $list = OaTkpPriceList::query()
                        ->where('year', $entry['year'])
                        ->where('revision_number', $entry['revision'])
                        ->first();

                    return $list === null ? null : $this->snapshotOaTkp($list);
            }

            throw new MasterDataTransferException('Unbekannte Preislistenfamilie: '.$entry['family']);
        });
    }

    /**
     * Alle aktiven Staging-Listen im selben Slot (inkl. der Liste selbst, falls aktiv).
     *
     * @param  array{family: string, year: int, inventory_code: ?string, production_type: ?string}  $entry
     * @return list<array<string, mixed>>
     */
    public function activeInSlot(array $entry): array
    {
        return $this->withStringifiedFetches(function () use ($entry): array {
            $out = [];
            switch ($entry['family']) {
                case self::FAMILY_SPOT:
                    $inventory = Inventory::query()->where('code', (string) $entry['inventory_code'])->first();
                    if ($inventory === null) {
                        return [];
                    }
                    foreach (PriceList::query()->where('inventory_id', $inventory->id)->where('year', $entry['year'])->where('status', 'active')->orderBy('id')->get() as $list) {
                        $out[] = $this->snapshotSpot($list, (string) $inventory->code);
                    }
                    break;
                case self::FAMILY_PRODUCTION:
                    $inventory = Inventory::query()->where('code', (string) $entry['inventory_code'])->first();
                    if ($inventory === null) {
                        return [];
                    }
                    foreach (ProductionPriceList::query()
                        ->where('inventory_id', $inventory->id)
                        ->where('production_type', (string) $entry['production_type'])
                        ->where('year', $entry['year'])
                        ->where('status', 'active')->orderBy('id')->get() as $list) {
                        $out[] = $this->snapshotProduction($list, (string) $inventory->code);
                    }
                    break;
                case self::FAMILY_OA_TKP:
                    foreach (OaTkpPriceList::query()->where('year', $entry['year'])->where('status', 'active')->orderBy('id')->get() as $list) {
                        $out[] = $this->snapshotOaTkp($list);
                    }
                    break;
            }

            return $out;
        });
    }

    /**
     * @return array<string, mixed>
     */
    private function snapshotSpot(PriceList $list, string $inventoryCode): array
    {
        $items = [];
        foreach (PriceListItem::query()->where('price_list_id', $list->id)->orderBy('day_group')->orderBy('hour')->get() as $i) {
            $items[] = [
                'hour' => (int) $i->hour,
                'day_group' => $i->day_group->value,
                'second_price' => MasterDataExportDecimal::canonicalize($i->getAttributes()['second_price'], MasterDataExportDecimal::PRICE_SCALE),
            ];
        }

        return [
            'family' => self::FAMILY_SPOT,
            'id' => (int) $list->id,
            'key' => self::spotKey($inventoryCode, (int) $list->year, (int) $list->revision_number),
            'slot' => self::spotSlot($inventoryCode, (int) $list->year),
            'status' => $list->status->value,
            'lock_version' => (int) $list->lock_version,
            'fingerprint' => PriceListFingerprint::forSpotItems($items),
            'metadata' => PriceListFingerprint::spotMetadataTuple((string) $list->name, (string) $list->version, $list->valid_from?->toDateString()),
            'items' => $items,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function snapshotProduction(ProductionPriceList $list, string $inventoryCode): array
    {
        $unit = MasterDataExportDecimal::canonicalize($list->getAttributes()['unit_price'], MasterDataExportDecimal::MONEY_SCALE);

        return [
            'family' => self::FAMILY_PRODUCTION,
            'id' => (int) $list->id,
            'key' => self::productionKey($inventoryCode, $list->production_type->value, (int) $list->year, (int) $list->revision_number),
            'slot' => self::productionSlot($inventoryCode, $list->production_type->value, (int) $list->year),
            'status' => $list->status->value,
            'lock_version' => (int) $list->lock_version,
            'fingerprint' => PriceListFingerprint::forProduction($unit, (bool) $list->is_discountable, (bool) $list->is_ae_eligible),
            'metadata' => PriceListFingerprint::productionMetadataTuple((string) $list->name, (string) $list->version),
            'unit_price' => $unit,
            'production_type' => $list->production_type->value,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function snapshotOaTkp(OaTkpPriceList $list): array
    {
        $items = [];
        foreach (OaTkpPriceListItem::query()->where('oa_tkp_price_list_id', $list->id)->orderBy('id')->get() as $item) {
            $inventoryCode = (string) Inventory::query()->whereKey($item->inventory_id)->value('code');
            $mediumCode = (string) AdvertisingMedium::query()->whereKey($item->advertising_medium_id)->value('code');
            $items[] = [
                'inventory_code' => $inventoryCode,
                'medium_code' => $mediumCode,
                'list_tkp' => MasterDataExportDecimal::canonicalize($item->getAttributes()['list_tkp'], MasterDataExportDecimal::PRICE_SCALE),
                'minimum_tkp' => MasterDataExportDecimal::canonicalize($item->getAttributes()['minimum_tkp'], MasterDataExportDecimal::PRICE_SCALE),
            ];
        }

        return [
            'family' => self::FAMILY_OA_TKP,
            'id' => (int) $list->id,
            'key' => self::oaKey((int) $list->year, (int) $list->revision_number),
            'slot' => self::oaSlot((int) $list->year),
            'status' => $list->status->value,
            'lock_version' => (int) $list->lock_version,
            'fingerprint' => PriceListFingerprint::forOaTkpItems($items),
            'metadata' => PriceListFingerprint::spotMetadataTuple((string) $list->name, (string) $list->version, $list->valid_from?->toDateString()),
            'items' => $items,
        ];
    }

    public static function spotKey(string $inventoryCode, int $year, int $revision): string
    {
        return sprintf('%s|%d|r%d', $inventoryCode, $year, $revision);
    }

    public static function productionKey(string $inventoryCode, string $type, int $year, int $revision): string
    {
        return sprintf('%s|%s|%d|r%d', $inventoryCode, $type, $year, $revision);
    }

    public static function oaKey(int $year, int $revision): string
    {
        return $year.'|r'.$revision;
    }

    public static function spotSlot(string $inventoryCode, int $year): string
    {
        return $inventoryCode.'|'.$year;
    }

    public static function productionSlot(string $inventoryCode, string $type, int $year): string
    {
        return $inventoryCode.'|'.$type.'|'.$year;
    }

    public static function oaSlot(int $year): string
    {
        return (string) $year;
    }

    /**
     * SQLite/PDO liefert DECIMAL ggf. als Float — Strings erzwingen, damit keine Präzision verloren geht.
     *
     * @template T
     *
     * @param  \Closure(): T  $callback
     * @return T
     */
    private function withStringifiedFetches(\Closure $callback): mixed
    {
        $pdo = DB::connection()->getPdo();
        $previous = $pdo->getAttribute(PDO::ATTR_STRINGIFY_FETCHES);
        $pdo->setAttribute(PDO::ATTR_STRINGIFY_FETCHES, true);
        try {
            return $callback();
        } finally {
            $pdo->setAttribute(PDO::ATTR_STRINGIFY_FETCHES, $previous);
        }
    }
}
