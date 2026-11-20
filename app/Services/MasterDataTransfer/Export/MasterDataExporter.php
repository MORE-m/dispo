<?php

namespace App\Services\MasterDataTransfer\Export;

use App\Enums\DayGroup;
use App\Models\AdvertisingCategory;
use App\Models\AdvertisingCategoryCalculationMethod;
use App\Models\AdvertisingMedium;
use App\Models\AdvertisingMediumCalculationMethod;
use App\Models\CalculationMethod;
use App\Models\Inventory;
use App\Models\InventoryMediumRule;
use App\Models\Organization;
use App\Models\PriceList;
use App\Models\ProductionPriceList;
use App\Services\MasterDataTransfer\MasterDataTransferContract;
use App\Services\MasterDataTransfer\MasterDataTransferException;
use App\Services\MasterDataTransfer\Support\MasterDataPackage;
use App\Support\OnlineAudio\OaTkpCoreMedia;
use Illuminate\Support\Collection;

/**
 * EPIC-03-B: read-only Export der Spot-Stammdaten inkl. Draft-Preispayload.
 */
final class MasterDataExporter
{
    public function __construct(
        private readonly MasterDataPackage $packages,
    ) {}

    /**
     * @param  list<string>|null  $inventoryCodes
     * @return array{package_path: string, manifest: array<string, mixed>, counts: array<string, int>}
     */
    public function export(string $packagePath, ?array $inventoryCodes = null, ?string $gitSha = null): array
    {
        $codes = $inventoryCodes ?? MasterDataTransferContract::SPOT_INVENTORY_CODES;
        $codes = array_values(array_unique(array_map('strval', $codes)));
        sort($codes);

        if ($codes === []) {
            throw new MasterDataTransferException('Keine Inventar-Codes für den Export.');
        }

        $payload = $this->buildPayload($codes);
        $manifest = [
            'format' => MasterDataTransferContract::FORMAT,
            'schema_version' => MasterDataTransferContract::SCHEMA_VERSION,
            'epic' => MasterDataTransferContract::EPIC,
            'exported_at' => now()->utc()->toIso8601String(),
            'source' => [
                'database' => (string) config('database.connections.'.config('database.default').'.database'),
                'connection' => (string) config('database.default'),
                'git_sha' => $gitSha,
            ],
            'scope' => [
                'inventory_codes' => $codes,
                'price_years' => MasterDataTransferContract::PRICE_YEARS,
                'includes_online_audio' => false,
                'price_lists_export_as_draft' => true,
            ],
            'files' => [],
        ];

        $this->packages->write($packagePath, $manifest, $payload['files']);

        return [
            'package_path' => $packagePath,
            'manifest' => $manifest,
            'counts' => $payload['counts'],
        ];
    }

    /**
     * @param  list<string>  $inventoryCodes
     * @return array{files: array<string, mixed>, counts: array<string, int>}
     */
    private function buildPayload(array $inventoryCodes): array
    {
        // Read-only Snapshot ohne exklusive Sperren (kein lockForUpdate).
        $inventories = Inventory::query()
            ->whereIn('code', $inventoryCodes)
            ->orderBy('code')
            ->get();

        if ($inventories->count() !== count($inventoryCodes)) {
            $found = $inventories->pluck('code')->all();
            $missing = array_values(array_diff($inventoryCodes, $found));
            throw new MasterDataTransferException(
                'Inventare fehlen für Export.',
                array_map(fn (string $code) => 'Inventar fehlt: '.$code, $missing),
            );
        }

        $organizationIds = $inventories->pluck('organization_id')->unique()->values();
        if ($organizationIds->count() !== 1) {
            throw new MasterDataTransferException('Export erwartet genau eine Organisation für den Spot-Scope.');
        }

        $organization = Organization::query()->whereKey($organizationIds->first())->firstOrFail();

        $inventoryIds = $inventories->pluck('id')->all();
        $allowedCategoryIds = AdvertisingCategory::query()
            ->whereIn('key', MasterDataTransferContract::ALLOWED_CATEGORY_KEYS)
            ->pluck('id')
            ->all();

        $candidateMedia = AdvertisingMedium::query()
            ->whereIn('category_id', $allowedCategoryIds)
            ->whereNotIn('code', array_merge(
                OaTkpCoreMedia::codes(),
                ['native_ad'],
            ))
            ->orderBy('code')
            ->get();
        $allowedMediumIds = $candidateMedia->pluck('id')->all();

        $rules = InventoryMediumRule::query()
            ->whereIn('inventory_id', $inventoryIds)
            ->whereIn('advertising_medium_id', $allowedMediumIds)
            ->orderBy('inventory_id')
            ->orderBy('advertising_medium_id')
            ->get();

        $mediumIds = $rules->pluck('advertising_medium_id')->unique()->values()->all();
        $media = $candidateMedia->whereIn('id', $mediumIds)->values();

        $categoryIds = $media->pluck('category_id')->unique()->values()->all();
        $categories = AdvertisingCategory::query()
            ->whereIn('id', $categoryIds)
            ->orderBy('key')
            ->get();

        $methods = CalculationMethod::query()->orderBy('key')->get();
        $methodById = $methods->keyBy('id');

        $categoryMethods = AdvertisingCategoryCalculationMethod::query()
            ->whereIn('advertising_category_id', $categoryIds)
            ->orderBy('advertising_category_id')
            ->orderBy('calculation_method_id')
            ->get()
            ->filter(fn (AdvertisingCategoryCalculationMethod $row) => ! MasterDataTransferContract::isExcludedEngineProfile($row->engine_profile_key))
            ->values();

        $mediumMethods = AdvertisingMediumCalculationMethod::query()
            ->whereIn('advertising_medium_id', $mediumIds)
            ->orderBy('advertising_medium_id')
            ->orderBy('calculation_method_id')
            ->get()
            ->filter(fn (AdvertisingMediumCalculationMethod $row) => ! MasterDataTransferContract::isExcludedEngineProfile($row->engine_profile_key))
            ->values();

        $priceLists = PriceList::query()
            ->with(['items' => fn ($q) => $q->orderBy('day_group')->orderBy('hour')])
            ->whereIn('inventory_id', $inventoryIds)
            ->whereIn('year', MasterDataTransferContract::PRICE_YEARS)
            ->where('status', 'active')
            ->orderBy('inventory_id')
            ->orderBy('year')
            ->orderBy('revision_number')
            ->get();

        $this->assertPriceCoverage($inventories, $priceLists);

        $productionLists = ProductionPriceList::query()
            ->whereIn('inventory_id', $inventoryIds)
            ->whereIn('year', MasterDataTransferContract::PRICE_YEARS)
            ->where('status', 'active')
            ->orderBy('inventory_id')
            ->orderBy('production_type')
            ->orderBy('year')
            ->orderBy('revision_number')
            ->get();

        $inventoryById = $inventories->keyBy('id');
        $mediaById = $media->keyBy('id');
        $categoryById = $categories->keyBy('id');

        return $this->assembleFiles(
            $organization,
            $categories,
            $methods,
            $methodById,
            $inventories,
            $media,
            $rules,
            $categoryMethods,
            $mediumMethods,
            $priceLists,
            $productionLists,
            $inventoryById,
            $mediaById,
            $categoryById,
        );
    }

    /**
     * @param  Collection<int, AdvertisingCategory>  $categories
     * @param  Collection<int, CalculationMethod>  $methods
     * @param  Collection<int|string, CalculationMethod>  $methodById
     * @param  Collection<int, Inventory>  $inventories
     * @param  Collection<int, AdvertisingMedium>  $media
     * @param  Collection<int, InventoryMediumRule>  $rules
     * @param  Collection<int, AdvertisingCategoryCalculationMethod>  $categoryMethods
     * @param  Collection<int, AdvertisingMediumCalculationMethod>  $mediumMethods
     * @param  Collection<int, PriceList>  $priceLists
     * @param  Collection<int, ProductionPriceList>  $productionLists
     * @param  Collection<int|string, Inventory>  $inventoryById
     * @param  Collection<int|string, AdvertisingMedium>  $mediaById
     * @param  Collection<int|string, AdvertisingCategory>  $categoryById
     * @return array{files: array<string, mixed>, counts: array<string, int>}
     */
    private function assembleFiles(
        Organization $organization,
        Collection $categories,
        Collection $methods,
        Collection $methodById,
        Collection $inventories,
        Collection $media,
        Collection $rules,
        Collection $categoryMethods,
        Collection $mediumMethods,
        Collection $priceLists,
        Collection $productionLists,
        Collection $inventoryById,
        Collection $mediaById,
        Collection $categoryById,
    ): array {
        $files = [
            'data/organization.json' => [
                'name' => $organization->name,
            ],
            'data/advertising_categories.json' => $categories->map(fn (AdvertisingCategory $c) => [
                'key' => $c->key,
                'name' => $c->name,
                'is_active' => (bool) $c->is_active,
                'sort' => (int) $c->sort,
                'default_calculation_method_key' => $c->default_calculation_method_id === null
                    ? null
                    : $methodById->get($c->default_calculation_method_id)?->key,
            ])->values()->all(),
            'data/calculation_methods.json' => $methods->map(fn (CalculationMethod $m) => [
                'key' => $m->key,
                'name' => $m->name,
                'help_text' => $m->help_text,
                'is_active' => (bool) $m->is_active,
                'sort' => (int) $m->sort,
            ])->values()->all(),
            'data/inventories.json' => $inventories->map(fn (Inventory $i) => [
                'code' => $i->code,
                'name' => $i->name,
                'type' => $i->type->value,
                'is_active' => (bool) $i->is_active,
                'sort' => (int) $i->sort,
            ])->values()->all(),
            'data/advertising_media.json' => $media->map(function (AdvertisingMedium $m) use ($categoryById, $methodById) {
                $category = $categoryById->get($m->category_id);
                if ($category === null) {
                    throw new MasterDataTransferException('Kategorie fehlt für Medium '.$m->code);
                }

                return [
                    'code' => $m->code,
                    'name' => $m->name,
                    'category_key' => $category->key,
                    'kind' => $m->kind?->value,
                    'component_profile' => $m->component_profile?->value,
                    'calculation_method_mode' => $m->calculation_method_mode->value,
                    'default_calculation_method_key' => $m->default_calculation_method_id === null
                        ? null
                        : $methodById->get($m->default_calculation_method_id)?->key,
                    'default_length_seconds' => (int) $m->default_length_seconds,
                    'is_discountable' => (bool) $m->is_discountable,
                    'is_ae_eligible' => (bool) $m->is_ae_eligible,
                    'is_active' => (bool) $m->is_active,
                    'sort' => (int) $m->sort,
                ];
            })->values()->all(),
            'data/inventory_medium_rules.json' => $rules->map(function (InventoryMediumRule $rule) use ($inventoryById, $mediaById) {
                $inventory = $inventoryById->get($rule->inventory_id);
                $medium = $mediaById->get($rule->advertising_medium_id);
                if ($inventory === null || $medium === null) {
                    throw new MasterDataTransferException('Regelreferenz unauflösbar.');
                }

                return [
                    'inventory_code' => $inventory->code,
                    'advertising_medium_code' => $medium->code,
                    'is_active' => (bool) $rule->is_active,
                    'booking_code' => $rule->booking_code,
                    'planning_responsibility_key' => $rule->planning_responsibility_key,
                    'hint_text' => $rule->hint_text,
                    'sort' => (int) $rule->sort,
                    'default_length_seconds' => $rule->default_length_seconds === null ? null : (int) $rule->default_length_seconds,
                    'surcharge_percent' => $rule->surcharge_percent === null ? null : (string) $rule->surcharge_percent,
                    'is_discountable' => (bool) $rule->is_discountable,
                    'is_ae_eligible' => (bool) $rule->is_ae_eligible,
                    'component_calculation_strategy' => $rule->component_calculation_strategy->value,
                    'swf_allonge_confirmed_at' => $rule->swf_allonge_confirmed_at?->utc()->toIso8601String(),
                ];
            })->values()->all(),
            'data/advertising_category_calculation_methods.json' => $categoryMethods->map(function (AdvertisingCategoryCalculationMethod $row) use ($categoryById, $methodById) {
                $category = $categoryById->get($row->advertising_category_id);
                $method = $methodById->get($row->calculation_method_id);
                if ($category === null || $method === null) {
                    throw new MasterDataTransferException('Kategorie-Methodenzuordnung unauflösbar.');
                }

                return [
                    'category_key' => $category->key,
                    'calculation_method_key' => $method->key,
                    'engine_profile_key' => $row->engine_profile_key,
                    'is_active' => (bool) $row->is_active,
                    'sort' => (int) $row->sort,
                ];
            })->values()->all(),
            'data/advertising_medium_calculation_methods.json' => $mediumMethods->map(function (AdvertisingMediumCalculationMethod $row) use ($mediaById, $methodById) {
                $medium = $mediaById->get($row->advertising_medium_id);
                $method = $methodById->get($row->calculation_method_id);
                if ($medium === null || $method === null) {
                    throw new MasterDataTransferException('Medium-Methodenzuordnung unauflösbar.');
                }

                return [
                    'advertising_medium_code' => $medium->code,
                    'calculation_method_key' => $method->key,
                    'engine_profile_key' => $row->engine_profile_key,
                    'is_active' => (bool) $row->is_active,
                    'sort' => (int) $row->sort,
                ];
            })->values()->all(),
            'data/price_lists.json' => $priceLists->map(function (PriceList $list) use ($inventoryById) {
                $inventory = $inventoryById->get($list->inventory_id);
                if ($inventory === null) {
                    throw new MasterDataTransferException('Preisliste ohne Inventar.');
                }

                return [
                    'inventory_code' => $inventory->code,
                    'name' => $list->name,
                    'year' => (int) $list->year,
                    'version' => (string) $list->version,
                    'revision_number' => (int) $list->revision_number,
                    'source_status' => $list->status->value,
                    'import_status' => 'draft',
                    'valid_from' => $list->valid_from?->toDateString(),
                    'items' => $list->items->map(function ($item): array {
                        /** @var DayGroup $dayGroup */
                        $dayGroup = $item->day_group;

                        return [
                            'hour' => (int) $item->hour,
                            'day_group' => $dayGroup->value,
                            'second_price' => (string) $item->second_price,
                        ];
                    })->values()->all(),
                ];
            })->values()->all(),
            'data/production_price_lists.json' => $productionLists->map(function (ProductionPriceList $list) use ($inventoryById) {
                $inventory = $inventoryById->get($list->inventory_id);
                if ($inventory === null) {
                    throw new MasterDataTransferException('Produktionspreisliste ohne Inventar.');
                }

                return [
                    'inventory_code' => $inventory->code,
                    'production_type' => $list->production_type->value,
                    'year' => (int) $list->year,
                    'name' => $list->name,
                    'version' => (string) $list->version,
                    'revision_number' => (int) $list->revision_number,
                    'source_status' => $list->status->value,
                    'import_status' => 'draft',
                    'unit_price' => (string) $list->unit_price,
                    'is_discountable' => (bool) $list->is_discountable,
                    'is_ae_eligible' => (bool) $list->is_ae_eligible,
                ];
            })->values()->all(),
        ];

        return [
            'files' => $files,
            'counts' => [
                'inventories' => $inventories->count(),
                'advertising_media' => $media->count(),
                'inventory_medium_rules' => $rules->count(),
                'price_lists' => $priceLists->count(),
                'price_list_items' => $priceLists->sum(fn (PriceList $l) => $l->items->count()),
                'production_price_lists' => $productionLists->count(),
            ],
        ];
    }

    /**
     * @param  Collection<int, Inventory>  $inventories
     * @param  Collection<int, PriceList>  $priceLists
     */
    private function assertPriceCoverage($inventories, $priceLists): void
    {
        $errors = [];
        foreach ($inventories as $inventory) {
            foreach (MasterDataTransferContract::PRICE_YEARS as $year) {
                $has = $priceLists->contains(
                    fn (PriceList $list) => (int) $list->inventory_id === (int) $inventory->id && (int) $list->year === $year,
                );
                if (! $has) {
                    $errors[] = sprintf('Aktive Spotpreisliste fehlt: %s / %d', $inventory->code, $year);
                }
            }
        }
        if ($errors !== []) {
            throw new MasterDataTransferException('Preislisten-Abdeckung unvollständig.', $errors);
        }
    }
}
