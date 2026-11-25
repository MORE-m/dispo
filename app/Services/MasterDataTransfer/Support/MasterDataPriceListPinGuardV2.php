<?php

namespace App\Services\MasterDataTransfer\Support;

use App\Models\Inventory;
use App\Models\OaTkpPriceList;
use App\Models\OaTkpPriceListItem;
use App\Models\PriceList;
use App\Models\PriceListItem;
use App\Models\ProductionPriceList;
use App\Services\MasterDataTransfer\MasterDataTransferException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Erfasst und vergleicht Preislisten-Pins bestehender Kalkulationen und Dispoaufträge.
 */
final class MasterDataPriceListPinGuardV2
{
    /**
     * @return array{
     *     digest: string,
     *     binding_count: int,
     *     list_count: int,
     *     bindings: list<array{source: string, column: string, price_list_id: int}>,
     *     lists: array<string, array<string, mixed>>
     * }
     */
    public function capture(): array
    {
        $bindings = $this->collectBindings();
        $lists = [];

        foreach ($bindings as $binding) {
            $key = $binding['kind'].':'.$binding['price_list_id'];
            if (isset($lists[$key])) {
                continue;
            }
            $lists[$key] = match ($binding['kind']) {
                'spot' => $this->captureSpotList($binding['price_list_id']),
                'production' => $this->captureProductionList($binding['price_list_id']),
                'oa_tkp' => $this->captureOaTkpList($binding['price_list_id']),
                default => throw new MasterDataTransferException('Unbekannte Pin-Art: '.$binding['kind']),
            };
        }

        ksort($lists);
        $payload = [
            'bindings' => $bindings,
            'lists' => $lists,
        ];
        $digest = hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));

        return [
            'digest' => $digest,
            'binding_count' => count($bindings),
            'list_count' => count($lists),
            'bindings' => $bindings,
            'lists' => $lists,
        ];
    }

    /**
     * @param  array{digest: string, binding_count: int, list_count: int, bindings: list<array<string, mixed>>, lists: array<string, array<string, mixed>>}  $before
     * @param  array{digest: string, binding_count: int, list_count: int, bindings: list<array<string, mixed>>, lists: array<string, array<string, mixed>>}  $after
     * @return list<string>
     */
    public function diff(array $before, array $after): array
    {
        if ($before['digest'] === $after['digest']) {
            return [];
        }

        $conflicts = [];
        if ($before['bindings'] !== $after['bindings']) {
            $conflicts[] = 'Preislisten-Pin-Bindungen verändert (IDs/Quellen).';
        }
        foreach (array_keys($before['lists'] + $after['lists']) as $key) {
            $left = $before['lists'][$key] ?? null;
            $right = $after['lists'][$key] ?? null;
            if ($left === null || $right === null) {
                $conflicts[] = 'Preislisten-Pin Liste fehlt im Vergleich: '.$key;

                continue;
            }
            if ($left !== $right) {
                $conflicts[] = 'Preislisten-Pin Inhalt/Status/Fingerprint verändert: '.$key;
            }
        }

        if ($conflicts === []) {
            $conflicts[] = 'Preislisten-Pin-Digest abweichend.';
        }

        return $conflicts;
    }

    /**
     * Verhindert, dass der Importplan gepinnte Listen inhaltlich verändert.
     *
     * @param  array{lists: array<string, array<string, mixed>>}  $pins
     * @param  array<string, mixed>  $packageData
     * @return list<string>
     */
    public function conflictsAgainstPackage(array $pins, array $packageData): array
    {
        $conflicts = [];
        $spotByKey = [];
        foreach ($packageData['data/price_lists.json'] ?? [] as $row) {
            if (! is_array($row)) {
                continue;
            }
            $biz = (string) ($row['inventory_code'] ?? '').'|'.(int) ($row['year'] ?? 0).'|'.(int) ($row['revision_number'] ?? 0);
            $spotByKey[$biz] = $row;
        }
        $prodByKey = [];
        foreach ($packageData['data/production_price_lists.json'] ?? [] as $row) {
            if (! is_array($row)) {
                continue;
            }
            $biz = (string) ($row['inventory_code'] ?? '').'|'.(string) ($row['production_type'] ?? '').'|'.(int) ($row['year'] ?? 0).'|'.(int) ($row['revision_number'] ?? 0);
            $prodByKey[$biz] = $row;
        }
        $oaByKey = [];
        foreach ($packageData['data/oa_tkp_price_lists.json'] ?? [] as $row) {
            if (! is_array($row)) {
                continue;
            }
            $biz = (int) ($row['year'] ?? 0).'|'.(int) ($row['revision_number'] ?? 0);
            $oaByKey[$biz] = $row;
        }

        foreach ($pins['lists'] as $pinKey => $list) {
            $kind = (string) ($list['kind'] ?? '');
            if ($kind === 'spot') {
                $biz = (string) $list['business_key'];
                $pkg = $spotByKey[$biz] ?? null;
                if ($pkg === null) {
                    continue;
                }
                $items = [];
                foreach (($pkg['items'] ?? []) as $item) {
                    if (! is_array($item)) {
                        continue;
                    }
                    $items[] = [
                        'hour' => (int) $item['hour'],
                        'day_group' => (string) $item['day_group'],
                        'second_price' => MasterDataExportDecimal::canonicalize(
                            $item['second_price'],
                            MasterDataExportDecimal::PRICE_SCALE,
                        ),
                    ];
                }
                $fp = PriceListFingerprint::forSpotItems($items);
                $meta = PriceListFingerprint::spotMetadataTuple(
                    (string) $pkg['name'],
                    (string) $pkg['version'],
                    $pkg['valid_from'] ?? null,
                );
                if ($fp !== $list['fingerprint'] || $meta !== $list['metadata']) {
                    $conflicts[] = 'Gepinnte Spotpreisliste weicht vom Paket ab: '.$biz;
                }
            } elseif ($kind === 'production') {
                $biz = (string) $list['business_key'];
                $pkg = $prodByKey[$biz] ?? null;
                if ($pkg === null) {
                    continue;
                }
                $unit = MasterDataExportDecimal::canonicalize(
                    $pkg['unit_price'],
                    MasterDataExportDecimal::PRICE_SCALE,
                );
                $fp = PriceListFingerprint::forProduction($unit, (bool) $pkg['is_discountable'], (bool) $pkg['is_ae_eligible']);
                $meta = PriceListFingerprint::productionMetadataTuple((string) $pkg['name'], (string) $pkg['version']);
                if ($fp !== $list['fingerprint'] || $meta !== $list['metadata']) {
                    $conflicts[] = 'Gepinnte Produktionspreisliste weicht vom Paket ab: '.$biz;
                }
            } elseif ($kind === 'oa_tkp') {
                $biz = (string) $list['business_key'];
                $pkg = $oaByKey[$biz] ?? null;
                if ($pkg === null) {
                    continue;
                }
                $items = [];
                foreach (($pkg['items'] ?? []) as $item) {
                    if (! is_array($item)) {
                        continue;
                    }
                    $items[] = [
                        'inventory_code' => (string) $item['inventory_code'],
                        'medium_code' => (string) $item['medium_code'],
                        'list_tkp' => MasterDataExportDecimal::canonicalize(
                            $item['list_tkp'],
                            MasterDataExportDecimal::PRICE_SCALE,
                        ),
                        'minimum_tkp' => MasterDataExportDecimal::canonicalize(
                            $item['minimum_tkp'],
                            MasterDataExportDecimal::PRICE_SCALE,
                        ),
                    ];
                }
                $fp = PriceListFingerprint::forOaTkpItems($items);
                $meta = PriceListFingerprint::spotMetadataTuple(
                    (string) $pkg['name'],
                    (string) $pkg['version'],
                    $pkg['valid_from'] ?? null,
                );
                if ($fp !== $list['fingerprint'] || $meta !== $list['metadata']) {
                    $conflicts[] = 'Gepinnte OA-TKP-Liste weicht vom Paket ab: '.$biz;
                }
            }
        }

        return $conflicts;
    }

    /**
     * @return list<array{source: string, column: string, kind: string, price_list_id: int}>
     */
    private function collectBindings(): array
    {
        $bindings = [];

        if (Schema::hasTable('calculation_positions')) {
            foreach (DB::table('calculation_positions')->orderBy('id')->get(['id', 'price_list_id', 'oa_tkp_price_list_id']) as $row) {
                if ($row->price_list_id !== null) {
                    $bindings[] = [
                        'source' => 'calculation_positions:'.$row->id,
                        'column' => 'price_list_id',
                        'kind' => 'spot',
                        'price_list_id' => (int) $row->price_list_id,
                    ];
                }
                if (Schema::hasColumn('calculation_positions', 'oa_tkp_price_list_id') && $row->oa_tkp_price_list_id !== null) {
                    $bindings[] = [
                        'source' => 'calculation_positions:'.$row->id,
                        'column' => 'oa_tkp_price_list_id',
                        'kind' => 'oa_tkp',
                        'price_list_id' => (int) $row->oa_tkp_price_list_id,
                    ];
                }
            }
        }

        if (Schema::hasTable('calculation_position_production_lines')
            && Schema::hasColumn('calculation_position_production_lines', 'production_price_list_id')) {
            foreach (DB::table('calculation_position_production_lines')->orderBy('id')->get(['id', 'production_price_list_id']) as $row) {
                if ($row->production_price_list_id !== null) {
                    $bindings[] = [
                        'source' => 'calculation_position_production_lines:'.$row->id,
                        'column' => 'production_price_list_id',
                        'kind' => 'production',
                        'price_list_id' => (int) $row->production_price_list_id,
                    ];
                }
            }
        }

        if (Schema::hasTable('dispo_order_positions')) {
            $cols = ['id', 'price_list_id'];
            if (Schema::hasColumn('dispo_order_positions', 'production_price_list_id')) {
                $cols[] = 'production_price_list_id';
            }
            if (Schema::hasColumn('dispo_order_positions', 'oa_tkp_price_list_id')) {
                $cols[] = 'oa_tkp_price_list_id';
            }
            $hasProductionPin = in_array('production_price_list_id', $cols, true);
            $hasOaTkpPin = in_array('oa_tkp_price_list_id', $cols, true);
            foreach (DB::table('dispo_order_positions')->orderBy('id')->get($cols) as $row) {
                if ($row->price_list_id !== null) {
                    $bindings[] = [
                        'source' => 'dispo_order_positions:'.$row->id,
                        'column' => 'price_list_id',
                        'kind' => 'spot',
                        'price_list_id' => (int) $row->price_list_id,
                    ];
                }
                if ($hasProductionPin && $row->production_price_list_id !== null) {
                    $bindings[] = [
                        'source' => 'dispo_order_positions:'.$row->id,
                        'column' => 'production_price_list_id',
                        'kind' => 'production',
                        'price_list_id' => (int) $row->production_price_list_id,
                    ];
                }
                if ($hasOaTkpPin && $row->oa_tkp_price_list_id !== null) {
                    $bindings[] = [
                        'source' => 'dispo_order_positions:'.$row->id,
                        'column' => 'oa_tkp_price_list_id',
                        'kind' => 'oa_tkp',
                        'price_list_id' => (int) $row->oa_tkp_price_list_id,
                    ];
                }
            }
        }

        usort($bindings, fn (array $a, array $b): int => [$a['source'], $a['column'], $a['price_list_id']] <=> [$b['source'], $b['column'], $b['price_list_id']]);

        return $bindings;
    }

    /**
     * @return array<string, mixed>
     */
    private function captureSpotList(int $id): array
    {
        $list = PriceList::query()->find($id);
        if ($list === null) {
            throw new MasterDataTransferException('Gepinnte Spotpreisliste fehlt: '.$id);
        }
        $inventoryCode = (string) Inventory::query()->whereKey($list->inventory_id)->value('code');
        $items = [];
        foreach (
            PriceListItem::query()
                ->where('price_list_id', $list->id)
                ->orderBy('day_group')
                ->orderBy('hour')
                ->get(['hour', 'day_group', 'second_price']) as $i
        ) {
            $items[] = [
                'hour' => (int) $i->hour,
                'day_group' => $i->day_group->value,
                'second_price' => MasterDataExportDecimal::canonicalize(
                    $i->getAttributes()['second_price'] ?? $i->second_price,
                    MasterDataExportDecimal::PRICE_SCALE,
                ),
            ];
        }

        return [
            'kind' => 'spot',
            'id' => (int) $list->id,
            'business_key' => $inventoryCode.'|'.(int) $list->year.'|'.(int) $list->revision_number,
            'status' => $list->status->value,
            'version' => (string) $list->version,
            'metadata' => PriceListFingerprint::spotMetadataTuple(
                (string) $list->name,
                (string) $list->version,
                $list->valid_from?->toDateString(),
            ),
            'fingerprint' => PriceListFingerprint::forSpotItems($items),
            'item_count' => count($items),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function captureProductionList(int $id): array
    {
        $list = ProductionPriceList::query()->find($id);
        if ($list === null) {
            throw new MasterDataTransferException('Gepinnte Produktionspreisliste fehlt: '.$id);
        }
        $inventoryCode = (string) Inventory::query()->whereKey($list->inventory_id)->value('code');
        $unit = MasterDataExportDecimal::canonicalize(
            $list->getAttributes()['unit_price'] ?? $list->unit_price,
            MasterDataExportDecimal::PRICE_SCALE,
        );

        return [
            'kind' => 'production',
            'id' => (int) $list->id,
            'business_key' => $inventoryCode.'|'.$list->production_type->value.'|'.(int) $list->year.'|'.(int) $list->revision_number,
            'status' => $list->status->value,
            'version' => (string) $list->version,
            'metadata' => PriceListFingerprint::productionMetadataTuple((string) $list->name, (string) $list->version),
            'fingerprint' => PriceListFingerprint::forProduction(
                $unit,
                (bool) $list->is_discountable,
                (bool) $list->is_ae_eligible,
            ),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function captureOaTkpList(int $id): array
    {
        $list = OaTkpPriceList::query()->find($id);
        if ($list === null) {
            throw new MasterDataTransferException('Gepinnte OA-TKP-Liste fehlt: '.$id);
        }
        $items = [];
        foreach (OaTkpPriceListItem::query()->where('oa_tkp_price_list_id', $list->id)->orderBy('id')->get() as $item) {
            $inventoryCode = (string) Inventory::query()->whereKey($item->inventory_id)->value('code');
            $mediumCode = (string) DB::table('advertising_media')->where('id', $item->advertising_medium_id)->value('code');
            $items[] = [
                'inventory_code' => $inventoryCode,
                'medium_code' => $mediumCode,
                'list_tkp' => MasterDataExportDecimal::canonicalize(
                    $item->getAttributes()['list_tkp'] ?? $item->list_tkp,
                    MasterDataExportDecimal::PRICE_SCALE,
                ),
                'minimum_tkp' => MasterDataExportDecimal::canonicalize(
                    $item->getAttributes()['minimum_tkp'] ?? $item->minimum_tkp,
                    MasterDataExportDecimal::PRICE_SCALE,
                ),
            ];
        }

        return [
            'kind' => 'oa_tkp',
            'id' => (int) $list->id,
            'business_key' => (int) $list->year.'|'.(int) $list->revision_number,
            'status' => $list->status->value,
            'version' => (string) $list->version,
            'metadata' => PriceListFingerprint::spotMetadataTuple(
                (string) $list->name,
                (string) $list->version,
                $list->valid_from?->toDateString(),
            ),
            'fingerprint' => PriceListFingerprint::forOaTkpItems($items),
            'item_count' => count($items),
        ];
    }
}
