<?php

namespace App\Services\MasterDataTransfer\Export;

use App\Enums\DayGroup;
use App\Enums\FieldSetAssignmentTargetLayer;
use App\Enums\PriceListStatus;
use App\Models\AdvertisingCategory;
use App\Models\AdvertisingCategoryCalculationMethod;
use App\Models\AdvertisingMedium;
use App\Models\AdvertisingMediumCalculationMethod;
use App\Models\CalculationMethod;
use App\Models\FieldDefinition;
use App\Models\FieldDefinitionRevision;
use App\Models\FieldDefinitionRevisionOption;
use App\Models\FieldRule;
use App\Models\FieldSet;
use App\Models\FieldSetAssignment;
use App\Models\FieldSetVersion;
use App\Models\FieldSetVersionField;
use App\Models\Inventory;
use App\Models\InventoryMediumRule;
use App\Models\OaAdserverSurcharge;
use App\Models\OaPlatform;
use App\Models\OaPlatformMediumRule;
use App\Models\OaTargetingDefinition;
use App\Models\OaTkpPriceList;
use App\Models\Organization;
use App\Models\PriceList;
use App\Models\ProductionPriceList;
use App\Services\MasterDataTransfer\MasterDataTransferContractV2;
use App\Services\MasterDataTransfer\MasterDataTransferException;
use App\Services\MasterDataTransfer\Support\MasterDataExportDecimal;
use App\Services\MasterDataTransfer\Support\MasterDataExportReadSnapshot;
use App\Services\MasterDataTransfer\Support\MasterDataPackage;
use Illuminate\Support\Collection;

/**
 * EPIC-03-F Slice 2: vollständiger read-only Export aller Whitelist-Fachtabellen (Schema 2.0.0).
 */
final class MasterDataExporterV2
{
    public function __construct(
        private readonly MasterDataPackage $packages,
    ) {}

    /**
     * @return array{
     *     package_path: string,
     *     manifest: array<string, mixed>,
     *     counts: array<string, int>,
     *     source_price_status_summary: array<string, array{active: int, archived: int, draft: int}>
     * }
     */
    public function export(string $packagePath, ?string $gitSha = null): array
    {
        $payload = MasterDataExportReadSnapshot::run(fn (): array => $this->buildPayload());
        $manifest = [
            'format' => MasterDataTransferContractV2::FORMAT,
            'schema_version' => MasterDataTransferContractV2::SCHEMA_VERSION,
            'epic' => MasterDataTransferContractV2::EPIC,
            'git_sha' => $gitSha ?? 'unknown',
            'exported_at' => now()->utc()->toIso8601String(),
            'source_database' => (string) config('database.connections.'.config('database.default').'.database'),
            'includes_users' => false,
            'includes_crm' => false,
            'includes_calculations' => false,
            'includes_dispo_orders' => false,
            'includes_online_audio' => true,
            'includes_social_events_barter' => true,
            'price_status_on_apply' => MasterDataTransferContractV2::PRICE_STATUS_ON_APPLY,
            'excluded_tables' => MasterDataTransferContractV2::excludedTablesSorted(),
            'counts' => $payload['counts'],
            'source_price_status_summary' => $payload['source_price_status_summary'],
            'files' => [],
        ];

        $this->packages->write($packagePath, $manifest, $payload['files']);
        $loaded = $this->packages->load($packagePath);

        return [
            'package_path' => $packagePath,
            'manifest' => $loaded['manifest'],
            'counts' => $payload['counts'],
            'source_price_status_summary' => $payload['source_price_status_summary'],
        ];
    }

    /**
     * @return array{
     *     files: array<string, mixed>,
     *     counts: array<string, int>,
     *     source_price_status_summary: array<string, array{active: int, archived: int, draft: int}>
     * }
     */
    private function buildPayload(): array
    {
        // Lesereihenfolge bewusst nicht kanonisch: finale Sortierung erfolgt über Fachschlüssel.
        $organizations = Organization::query()->get();
        if ($organizations->count() !== 1) {
            throw new MasterDataTransferException(
                'Export 2.0.0 erwartet genau eine Organisation.',
                ['organizations='.$organizations->count()],
            );
        }
        /** @var Organization $organization */
        $organization = $organizations->first();

        $methods = CalculationMethod::query()->get();
        $methodById = $methods->keyBy('id');

        $categories = AdvertisingCategory::query()->get();
        $categoryById = $categories->keyBy('id');

        $media = AdvertisingMedium::query()->get();
        $mediaById = $media->keyBy('id');

        $inventories = Inventory::query()->get();
        $inventoryById = $inventories->keyBy('id');

        $categoryMethods = AdvertisingCategoryCalculationMethod::query()->get();
        $mediumMethods = AdvertisingMediumCalculationMethod::query()->get();
        $rules = InventoryMediumRule::query()->get();
        $priceLists = PriceList::query()->with('items')->get();
        $productionLists = ProductionPriceList::query()->get();

        $oaPlatforms = OaPlatform::query()->get();
        $oaPlatformById = $oaPlatforms->keyBy('id');
        $oaPlatformRules = OaPlatformMediumRule::query()->get();
        $oaTargeting = OaTargetingDefinition::query()->get();
        $oaTkpLists = OaTkpPriceList::query()->with('items')->get();
        $oaAdserver = OaAdserverSurcharge::query()->get();

        $fieldDefinitions = FieldDefinition::query()->get();
        $fieldDefinitionById = $fieldDefinitions->keyBy('id');

        $fieldRevisions = FieldDefinitionRevision::query()->with('options')->get();
        $fieldRevisionById = $fieldRevisions->keyBy('id');

        $fieldSets = FieldSet::query()->get();
        $fieldSetById = $fieldSets->keyBy('id');

        $fieldSetVersions = FieldSetVersion::query()->get();
        $fieldSetVersionById = $fieldSetVersions->keyBy('id');

        $fieldSetVersionFields = FieldSetVersionField::query()->get();
        $fieldRules = FieldRule::query()->get();
        $fieldAssignments = FieldSetAssignment::query()->get();

        $files = [
            'data/organization.json' => [
                'name' => $organization->name,
            ],
            'data/calculation_methods.json' => $methods->map(fn (CalculationMethod $m) => [
                'key' => $m->key,
                'name' => $m->name,
                'help_text' => $m->help_text,
                'is_active' => (bool) $m->is_active,
                'sort' => (int) $m->sort,
            ])->values()->all(),
            'data/advertising_categories.json' => $categories->map(function (AdvertisingCategory $c) use ($methodById) {
                $defaultKey = null;
                if ($c->default_calculation_method_id !== null) {
                    $method = $methodById->get($c->default_calculation_method_id);
                    if ($method === null) {
                        throw new MasterDataTransferException(
                            'default_calculation_method_key unauflösbar für Kategorie '.$c->key,
                        );
                    }
                    $defaultKey = $method->key;
                }

                return [
                    'key' => $c->key,
                    'name' => $c->name,
                    'is_active' => (bool) $c->is_active,
                    'sort' => (int) $c->sort,
                    'default_calculation_method_key' => $defaultKey,
                ];
            })->values()->all(),
            'data/advertising_media.json' => $media->map(function (AdvertisingMedium $m) use ($categoryById, $methodById) {
                $category = $categoryById->get($m->category_id);
                if ($category === null) {
                    throw new MasterDataTransferException('Kategorie fehlt für Medium '.$m->code);
                }
                $defaultKey = null;
                if ($m->default_calculation_method_id !== null) {
                    $method = $methodById->get($m->default_calculation_method_id);
                    if ($method === null) {
                        throw new MasterDataTransferException(
                            'default_calculation_method_key unauflösbar für Medium '.$m->code,
                        );
                    }
                    $defaultKey = $method->key;
                }

                return [
                    'code' => $m->code,
                    'name' => $m->name,
                    'category_key' => $category->key,
                    'kind' => $m->kind?->value,
                    'component_profile' => $m->component_profile?->value,
                    'calculation_method_mode' => $m->calculation_method_mode->value,
                    'default_calculation_method_key' => $defaultKey,
                    'default_length_seconds' => (int) $m->default_length_seconds,
                    'is_discountable' => (bool) $m->is_discountable,
                    'is_ae_eligible' => (bool) $m->is_ae_eligible,
                    'is_active' => (bool) $m->is_active,
                    'sort' => (int) $m->sort,
                ];
            })->values()->all(),
            'data/advertising_category_calculation_methods.json' => $categoryMethods->map(
                function (AdvertisingCategoryCalculationMethod $row) use ($categoryById, $methodById) {
                    $category = $categoryById->get($row->advertising_category_id);
                    $method = $methodById->get($row->calculation_method_id);
                    if ($category === null || $method === null) {
                        throw new MasterDataTransferException('Kategorie-Methodenzuordnung unauflösbar.');
                    }

                    return [
                        'category_key' => $category->key,
                        'method_key' => $method->key,
                        'engine_profile_key' => $row->engine_profile_key,
                        'is_active' => (bool) $row->is_active,
                        'sort' => (int) $row->sort,
                    ];
                },
            )->values()->all(),
            'data/advertising_medium_calculation_methods.json' => $mediumMethods->map(
                function (AdvertisingMediumCalculationMethod $row) use ($mediaById, $methodById) {
                    $medium = $mediaById->get($row->advertising_medium_id);
                    $method = $methodById->get($row->calculation_method_id);
                    if ($medium === null || $method === null) {
                        throw new MasterDataTransferException('Medium-Methodenzuordnung unauflösbar.');
                    }

                    return [
                        'medium_code' => $medium->code,
                        'method_key' => $method->key,
                        'engine_profile_key' => $row->engine_profile_key,
                        'is_active' => (bool) $row->is_active,
                        'sort' => (int) $row->sort,
                    ];
                },
            )->values()->all(),
            'data/inventories.json' => $inventories->map(fn (Inventory $i) => [
                'code' => $i->code,
                'name' => $i->name,
                'type' => $i->type->value,
                'is_active' => (bool) $i->is_active,
                'sort' => (int) $i->sort,
                'logo_path' => $i->logo_path,
            ])->values()->all(),
            'data/inventory_medium_rules.json' => $rules->map(
                function (InventoryMediumRule $rule) use ($inventoryById, $mediaById) {
                    $inventory = $inventoryById->get($rule->inventory_id);
                    $medium = $mediaById->get($rule->advertising_medium_id);
                    if ($inventory === null || $medium === null) {
                        throw new MasterDataTransferException('Inventar-Medien-Regel unauflösbar.');
                    }

                    return [
                        'inventory_code' => $inventory->code,
                        'medium_code' => $medium->code,
                        'is_active' => (bool) $rule->is_active,
                        'booking_code' => $rule->booking_code,
                        'planning_responsibility_key' => $rule->planning_responsibility_key,
                        'hint_text' => $rule->hint_text,
                        'sort' => (int) $rule->sort,
                        'default_length_seconds' => $rule->default_length_seconds === null
                            ? null
                            : (int) $rule->default_length_seconds,
                        'surcharge_percent' => MasterDataExportDecimal::canonicalizeNullable(
                            $this->rawDecimalAttribute($rule, 'surcharge_percent'),
                            MasterDataExportDecimal::PRICE_SCALE,
                        ),
                        'is_discountable' => (bool) $rule->is_discountable,
                        'is_ae_eligible' => (bool) $rule->is_ae_eligible,
                        'component_calculation_strategy' => $rule->component_calculation_strategy->value,
                        'swf_allonge_confirmed_at' => $rule->swf_allonge_confirmed_at?->utc()->toIso8601String(),
                    ];
                },
            )->values()->all(),
            'data/price_lists.json' => $priceLists->map(function (PriceList $list) use ($inventoryById) {
                $inventory = $inventoryById->get($list->inventory_id);
                if ($inventory === null) {
                    throw new MasterDataTransferException('Spotpreisliste ohne Inventar.');
                }
                $items = $this->sortByBusinessKeys(
                    $list->items->map(function ($item): array {
                        /** @var DayGroup $dayGroup */
                        $dayGroup = $item->day_group;

                        return [
                            'hour' => (int) $item->hour,
                            'day_group' => $dayGroup->value,
                            'second_price' => MasterDataExportDecimal::canonicalize(
                                $this->rawDecimalAttribute($item, 'second_price'),
                                MasterDataExportDecimal::PRICE_SCALE,
                            ),
                        ];
                    })->all(),
                    ['day_group', 'hour'],
                );

                return [
                    'inventory_code' => $inventory->code,
                    'year' => (int) $list->year,
                    'revision_number' => (int) $list->revision_number,
                    'name' => $list->name,
                    'version' => (string) $list->version,
                    'valid_from' => $list->valid_from?->toDateString(),
                    'source_status' => $list->status->value,
                    'import_status' => 'draft',
                    'items' => $items,
                ];
            })->values()->all(),
            'data/production_price_lists.json' => $productionLists->map(
                function (ProductionPriceList $list) use ($inventoryById) {
                    $inventory = $inventoryById->get($list->inventory_id);
                    if ($inventory === null) {
                        throw new MasterDataTransferException('Produktionspreisliste ohne Inventar.');
                    }

                    return [
                        'inventory_code' => $inventory->code,
                        'production_type' => $list->production_type->value,
                        'year' => (int) $list->year,
                        'revision_number' => (int) $list->revision_number,
                        'name' => $list->name,
                        'version' => (string) $list->version,
                        'source_status' => $list->status->value,
                        'import_status' => 'draft',
                        'unit_price' => MasterDataExportDecimal::canonicalize(
                            $this->rawDecimalAttribute($list, 'unit_price'),
                            MasterDataExportDecimal::MONEY_SCALE,
                        ),
                        'is_discountable' => (bool) $list->is_discountable,
                        'is_ae_eligible' => (bool) $list->is_ae_eligible,
                    ];
                },
            )->values()->all(),
            'data/oa_platforms.json' => $oaPlatforms->map(fn (OaPlatform $p) => [
                'code' => $p->code,
                'name' => $p->name,
                'is_active' => (bool) $p->is_active,
                'sort' => (int) $p->sort,
                'is_adserver_relevant' => (bool) $p->is_adserver_relevant,
            ])->values()->all(),
            'data/oa_platform_medium_rules.json' => $oaPlatformRules->map(
                function (OaPlatformMediumRule $rule) use ($oaPlatformById, $mediaById) {
                    $platform = $oaPlatformById->get($rule->oa_platform_id);
                    $medium = $mediaById->get($rule->advertising_medium_id);
                    if ($platform === null || $medium === null) {
                        throw new MasterDataTransferException('OA-Plattform-Medien-Regel unauflösbar.');
                    }

                    return [
                        'platform_code' => $platform->code,
                        'medium_code' => $medium->code,
                        'is_active' => (bool) $rule->is_active,
                    ];
                },
            )->values()->all(),
            'data/oa_targeting_definitions.json' => $oaTargeting->map(fn (OaTargetingDefinition $row) => [
                'code' => $row->code,
                'revision_number' => (int) $row->revision_number,
                'name' => $row->name,
                'category' => $row->category->value,
                'tkp_surcharge' => MasterDataExportDecimal::canonicalize(
                    $this->rawDecimalAttribute($row, 'tkp_surcharge'),
                    MasterDataExportDecimal::PRICE_SCALE,
                ),
                'minimum_tkp_surcharge' => MasterDataExportDecimal::canonicalize(
                    $this->rawDecimalAttribute($row, 'minimum_tkp_surcharge'),
                    MasterDataExportDecimal::PRICE_SCALE,
                ),
                'is_active' => (bool) $row->is_active,
                'valid_from' => $row->valid_from?->toDateString(),
                'valid_to' => $row->valid_to?->toDateString(),
            ])->values()->all(),
            'data/oa_tkp_price_lists.json' => $oaTkpLists->map(function (OaTkpPriceList $list) use ($inventoryById, $mediaById) {
                $items = $list->items
                    ->map(function ($item) use ($inventoryById, $mediaById): array {
                        $inventory = $inventoryById->get($item->inventory_id);
                        $medium = $mediaById->get($item->advertising_medium_id);
                        if ($inventory === null || $medium === null) {
                            throw new MasterDataTransferException('OA-TKP-Item-Referenz unauflösbar.');
                        }

                        return [
                            'inventory_code' => $inventory->code,
                            'medium_code' => $medium->code,
                            'list_tkp' => MasterDataExportDecimal::canonicalize(
                                $this->rawDecimalAttribute($item, 'list_tkp'),
                                MasterDataExportDecimal::PRICE_SCALE,
                            ),
                            'minimum_tkp' => MasterDataExportDecimal::canonicalize(
                                $this->rawDecimalAttribute($item, 'minimum_tkp'),
                                MasterDataExportDecimal::PRICE_SCALE,
                            ),
                        ];
                    })
                    ->all();
                $items = $this->sortByBusinessKeys($items, ['inventory_code', 'medium_code']);

                return [
                    'year' => (int) $list->year,
                    'revision_number' => (int) $list->revision_number,
                    'name' => $list->name,
                    'version' => (string) $list->version,
                    'valid_from' => $list->valid_from?->toDateString(),
                    'source_status' => $list->status->value,
                    'import_status' => 'draft',
                    'items' => $items,
                ];
            })->values()->all(),
            'data/oa_adserver_surcharges.json' => $oaAdserver->map(fn (OaAdserverSurcharge $row) => [
                'code' => $row->code,
                'revision_number' => (int) $row->revision_number,
                'name' => $row->name,
                'tkp_amount' => MasterDataExportDecimal::canonicalize(
                    $this->rawDecimalAttribute($row, 'tkp_amount'),
                    MasterDataExportDecimal::PRICE_SCALE,
                ),
                'is_active' => (bool) $row->is_active,
                'scope_json' => $row->scope_json,
                'valid_from' => $row->valid_from?->toDateString(),
                'valid_to' => $row->valid_to?->toDateString(),
            ])->values()->all(),
            'data/field_definitions.json' => $fieldDefinitions->map(
                function (FieldDefinition $definition) use ($fieldRevisionById) {
                    $currentRevision = null;
                    if ($definition->current_revision_id !== null) {
                        $revision = $fieldRevisionById->get($definition->current_revision_id);
                        if ($revision === null || (int) $revision->field_definition_id !== (int) $definition->id) {
                            throw new MasterDataTransferException(
                                'current_revision unauflösbar für Feld '.$definition->key,
                            );
                        }
                        $currentRevision = (int) $revision->revision;
                    }

                    return [
                        'key' => $definition->key,
                        'field_type' => $definition->field_type->value,
                        'is_system' => (bool) $definition->is_system,
                        'is_key_protected' => (bool) $definition->is_key_protected,
                        'scope' => $definition->scope->value,
                        'applies_to' => $definition->applies_to->value,
                        'is_active' => (bool) $definition->is_active,
                        'current_revision' => $currentRevision,
                    ];
                },
            )->values()->all(),
            'data/field_definition_revisions.json' => $fieldRevisions->map(
                function (FieldDefinitionRevision $revision) use ($fieldDefinitionById) {
                    $definition = $fieldDefinitionById->get($revision->field_definition_id);
                    if ($definition === null) {
                        throw new MasterDataTransferException('Felddefinitions-Revision ohne Definition.');
                    }

                    return [
                        'field_definition_key' => $definition->key,
                        'revision' => (int) $revision->revision,
                        'label' => $revision->label,
                        'help_text' => $revision->help_text,
                        'validation_json' => $revision->validation_json,
                        'group_key' => $revision->group_key,
                        'sort_default' => (int) $revision->sort_default,
                        'reportable' => (bool) $revision->reportable,
                    ];
                },
            )->values()->all(),
            'data/field_definition_revision_options.json' => $this->exportFieldRevisionOptions(
                $fieldRevisions,
                $fieldDefinitionById,
            ),
            'data/field_rules.json' => $this->exportFieldRules($fieldRules, $fieldSetVersionById, $fieldSetById),
            'data/field_sets.json' => $fieldSets->map(function (FieldSet $set) use ($fieldSetVersionById) {
                $activeVersion = null;
                if ($set->active_version_id !== null) {
                    $version = $fieldSetVersionById->get($set->active_version_id);
                    if ($version === null || (int) $version->field_set_id !== (int) $set->id) {
                        throw new MasterDataTransferException(
                            'active_version unauflösbar für Feldset '.$set->key,
                        );
                    }
                    $activeVersion = (int) $version->version;
                }

                return [
                    'key' => $set->key,
                    'name' => $set->name,
                    'is_system' => (bool) $set->is_system,
                    'applies_to' => $set->applies_to->value,
                    'is_assignable' => (bool) $set->is_assignable,
                    'active_version' => $activeVersion,
                ];
            })->values()->all(),
            'data/field_set_versions.json' => $fieldSetVersions->map(
                function (FieldSetVersion $version) use ($fieldSetById) {
                    $set = $fieldSetById->get($version->field_set_id);
                    if ($set === null) {
                        throw new MasterDataTransferException('Feldset-Version ohne Feldset.');
                    }

                    return [
                        'field_set_key' => $set->key,
                        'version' => (int) $version->version,
                        'status' => $version->status->value,
                    ];
                },
            )->values()->all(),
            'data/field_set_version_fields.json' => $fieldSetVersionFields->map(
                function (FieldSetVersionField $row) use ($fieldSetVersionById, $fieldSetById, $fieldDefinitionById, $fieldRevisionById) {
                    $version = $fieldSetVersionById->get($row->field_set_version_id);
                    $definition = $fieldDefinitionById->get($row->field_definition_id);
                    $revision = $fieldRevisionById->get($row->field_definition_revision_id);
                    if ($version === null || $definition === null || $revision === null) {
                        throw new MasterDataTransferException('Feldset-Version-Feld unauflösbar.');
                    }
                    if ((int) $revision->field_definition_id !== (int) $definition->id) {
                        throw new MasterDataTransferException(
                            'Feldset-Version-Feld Revisionsreferenz passt nicht zur Definition.',
                        );
                    }
                    $set = $fieldSetById->get($version->field_set_id);
                    if ($set === null) {
                        throw new MasterDataTransferException('Feldset-Version-Feld ohne Feldset.');
                    }

                    return [
                        'field_set_key' => $set->key,
                        'version' => (int) $version->version,
                        'field_definition_key' => $definition->key,
                        'revision' => (int) $revision->revision,
                        'sort' => (int) $row->sort,
                        'required_override' => $row->required_override,
                        'visible_override' => $row->visible_override,
                    ];
                },
            )->values()->all(),
            'data/field_set_assignments.json' => $fieldAssignments->map(
                function (FieldSetAssignment $row) use ($fieldSetById, $categoryById, $mediaById) {
                    $set = $fieldSetById->get($row->field_set_id);
                    if ($set === null) {
                        throw new MasterDataTransferException('Feldset-Zuordnung ohne Feldset.');
                    }

                    $payload = [
                        'field_set_key' => $set->key,
                        'applies_to_process' => $row->applies_to_process->value,
                        'target_layer' => $row->target_layer->value,
                        'is_active' => (bool) $row->is_active,
                        'sort' => (int) $row->sort,
                    ];

                    if ($row->target_layer === FieldSetAssignmentTargetLayer::Global) {
                        return $payload;
                    }

                    if ($row->target_layer === FieldSetAssignmentTargetLayer::AdvertisingCategory) {
                        $category = $categoryById->get($row->advertising_category_id);
                        if ($category === null) {
                            throw new MasterDataTransferException(
                                'Feldset-Zuordnung Kategorie-Ziel unauflösbar.',
                            );
                        }
                        $payload['category_key'] = $category->key;

                        return $payload;
                    }

                    $medium = $mediaById->get($row->advertising_medium_id);
                    if ($medium === null) {
                        throw new MasterDataTransferException(
                            'Feldset-Zuordnung Medium-Ziel unauflösbar.',
                        );
                    }
                    $payload['medium_code'] = $medium->code;

                    return $payload;
                },
            )->values()->all(),
        ];

        $files = $this->sortExportFilesByBusinessKeys($files);

        $counts = [];
        foreach (MasterDataTransferContractV2::DATA_FILES as $relative) {
            $payload = $files[$relative];
            $counts[$relative] = $relative === 'data/organization.json'
                ? 1
                : count($payload);
        }

        return [
            'files' => $files,
            'counts' => $counts,
            'source_price_status_summary' => [
                'spot' => $this->statusSummary($priceLists),
                'production' => $this->statusSummary($productionLists),
                'oa_tkp' => $this->statusSummary($oaTkpLists),
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $files
     * @return array<string, mixed>
     */
    private function sortExportFilesByBusinessKeys(array $files): array
    {
        $files['data/calculation_methods.json'] = $this->sortByBusinessKeys(
            $files['data/calculation_methods.json'],
            ['key'],
        );
        $files['data/advertising_categories.json'] = $this->sortByBusinessKeys(
            $files['data/advertising_categories.json'],
            ['key'],
        );
        $files['data/advertising_media.json'] = $this->sortByBusinessKeys(
            $files['data/advertising_media.json'],
            ['code'],
        );
        $files['data/advertising_category_calculation_methods.json'] = $this->sortByBusinessKeys(
            $files['data/advertising_category_calculation_methods.json'],
            ['category_key', 'method_key'],
        );
        $files['data/advertising_medium_calculation_methods.json'] = $this->sortByBusinessKeys(
            $files['data/advertising_medium_calculation_methods.json'],
            ['medium_code', 'method_key'],
        );
        $files['data/inventories.json'] = $this->sortByBusinessKeys(
            $files['data/inventories.json'],
            ['code'],
        );
        $files['data/inventory_medium_rules.json'] = $this->sortByBusinessKeys(
            $files['data/inventory_medium_rules.json'],
            ['inventory_code', 'medium_code'],
        );
        $files['data/price_lists.json'] = $this->sortByBusinessKeys(
            $files['data/price_lists.json'],
            ['inventory_code', 'year', 'revision_number'],
        );
        $files['data/production_price_lists.json'] = $this->sortByBusinessKeys(
            $files['data/production_price_lists.json'],
            ['inventory_code', 'production_type', 'year', 'revision_number'],
        );
        $files['data/oa_platforms.json'] = $this->sortByBusinessKeys(
            $files['data/oa_platforms.json'],
            ['code'],
        );
        $files['data/oa_platform_medium_rules.json'] = $this->sortByBusinessKeys(
            $files['data/oa_platform_medium_rules.json'],
            ['platform_code', 'medium_code'],
        );
        $files['data/oa_targeting_definitions.json'] = $this->sortByBusinessKeys(
            $files['data/oa_targeting_definitions.json'],
            ['code', 'revision_number'],
        );
        $files['data/oa_tkp_price_lists.json'] = $this->sortByBusinessKeys(
            $files['data/oa_tkp_price_lists.json'],
            ['year', 'revision_number'],
        );
        $files['data/oa_adserver_surcharges.json'] = $this->sortByBusinessKeys(
            $files['data/oa_adserver_surcharges.json'],
            ['code', 'revision_number'],
        );
        $files['data/field_definitions.json'] = $this->sortByBusinessKeys(
            $files['data/field_definitions.json'],
            ['key'],
        );
        $files['data/field_definition_revisions.json'] = $this->sortByBusinessKeys(
            $files['data/field_definition_revisions.json'],
            ['field_definition_key', 'revision'],
        );
        $files['data/field_definition_revision_options.json'] = $this->sortByBusinessKeys(
            $files['data/field_definition_revision_options.json'],
            ['field_definition_key', 'revision', 'sort', 'key'],
        );
        $files['data/field_sets.json'] = $this->sortByBusinessKeys(
            $files['data/field_sets.json'],
            ['key'],
        );
        $files['data/field_set_versions.json'] = $this->sortByBusinessKeys(
            $files['data/field_set_versions.json'],
            ['field_set_key', 'version'],
        );
        $files['data/field_set_version_fields.json'] = $this->sortByBusinessKeys(
            $files['data/field_set_version_fields.json'],
            ['field_set_key', 'version', 'sort', 'field_definition_key', 'revision'],
        );
        // field_rules: bereits in exportFieldRules nach Fachschlüsseln + kanonischem JSON sortiert.
        $files['data/field_set_assignments.json'] = $this->sortByBusinessKeys(
            $files['data/field_set_assignments.json'],
            ['field_set_key', 'applies_to_process', 'target_layer', 'category_key', 'medium_code', 'sort'],
        );

        return $files;
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     * @param  list<string>  $keys
     * @return array<int, array<string, mixed>>
     */
    private function sortByBusinessKeys(array $rows, array $keys): array
    {
        $rows = array_values($rows);
        usort($rows, function (array $left, array $right) use ($keys): int {
            $a = [];
            $b = [];
            foreach ($keys as $key) {
                $a[] = $left[$key] ?? null;
                $b[] = $right[$key] ?? null;
            }

            return $a <=> $b;
        });

        return $rows;
    }

    /**
     * @param  Collection<int, FieldDefinitionRevision>  $revisions
     * @param  Collection<int|string, FieldDefinition>  $fieldDefinitionById
     * @return list<array<string, mixed>>
     */
    private function exportFieldRevisionOptions(Collection $revisions, Collection $fieldDefinitionById): array
    {
        $rows = [];
        foreach ($revisions as $revision) {
            $definition = $fieldDefinitionById->get($revision->field_definition_id);
            if ($definition === null) {
                throw new MasterDataTransferException('Feldoptions-Revision ohne Definition.');
            }
            foreach ($revision->options as $option) {
                /** @var FieldDefinitionRevisionOption $option */
                $rows[] = [
                    'field_definition_key' => $definition->key,
                    'revision' => (int) $revision->revision,
                    'key' => $option->key,
                    'label' => $option->label,
                    'sort' => (int) $option->sort,
                    'is_active' => (bool) $option->is_active,
                ];
            }
        }

        usort($rows, function (array $a, array $b): int {
            return [$a['field_definition_key'], $a['revision'], $a['sort'], $a['key']]
                <=> [$b['field_definition_key'], $b['revision'], $b['sort'], $b['key']];
        });

        return $rows;
    }

    /**
     * @param  Collection<int, FieldRule>  $rules
     * @param  Collection<int|string, FieldSetVersion>  $fieldSetVersionById
     * @param  Collection<int|string, FieldSet>  $fieldSetById
     * @return list<array<string, mixed>>
     */
    private function exportFieldRules(Collection $rules, Collection $fieldSetVersionById, Collection $fieldSetById): array
    {
        $rows = [];
        foreach ($rules as $rule) {
            $version = $fieldSetVersionById->get($rule->field_set_version_id);
            if ($version === null) {
                throw new MasterDataTransferException('Feldregel ohne Feldset-Version.');
            }
            $set = $fieldSetById->get($version->field_set_id);
            if ($set === null) {
                throw new MasterDataTransferException('Feldregel ohne Feldset.');
            }
            $rows[] = [
                'field_set_key' => $set->key,
                'version' => (int) $version->version,
                'sort' => (int) $rule->sort,
                'condition_json' => $rule->condition_json,
                'action_json' => $rule->action_json,
            ];
        }

        usort($rows, function (array $a, array $b): int {
            $condA = $this->canonicalJson($a['condition_json']);
            $condB = $this->canonicalJson($b['condition_json']);
            $actA = $this->canonicalJson($a['action_json']);
            $actB = $this->canonicalJson($b['action_json']);

            return [$a['field_set_key'], $a['version'], $a['sort'], $condA, $actA]
                <=> [$b['field_set_key'], $b['version'], $b['sort'], $condB, $actB];
        });

        return $rows;
    }

    /**
     * @param  iterable<mixed>  $lists
     * @return array{active: int, archived: int, draft: int}
     */
    private function statusSummary(iterable $lists): array
    {
        $summary = [
            PriceListStatus::Active->value => 0,
            PriceListStatus::Archived->value => 0,
            PriceListStatus::Draft->value => 0,
        ];
        foreach ($lists as $list) {
            $statusEnum = is_object($list) ? ($list->status ?? null) : null;
            if (! $statusEnum instanceof PriceListStatus) {
                throw new MasterDataTransferException('Preisobjekt ohne gültigen Status.');
            }
            $status = $statusEnum->value;
            if (! array_key_exists($status, $summary)) {
                throw new MasterDataTransferException('Unbekannter Preisstatus: '.$status);
            }
            $summary[$status]++;
        }

        return [
            'active' => $summary[PriceListStatus::Active->value],
            'archived' => $summary[PriceListStatus::Archived->value],
            'draft' => $summary[PriceListStatus::Draft->value],
        ];
    }

    /**
     * Liest Dezimal-Rohwerte ohne Eloquent-decimal-Cast (vermeidet PHP-Float).
     */
    private function rawDecimalAttribute(object $model, string $attribute): mixed
    {
        if (method_exists($model, 'getAttributes')) {
            /** @var array<string, mixed> $attributes */
            $attributes = $model->getAttributes();

            return $attributes[$attribute] ?? null;
        }

        return $model->{$attribute} ?? null;
    }

    /**
     * @param  array<string, mixed>|list<mixed>|null  $payload
     */
    private function canonicalJson(mixed $payload): string
    {
        $normalized = is_array($payload) ? $this->canonicalize($payload) : $payload;
        $json = json_encode($normalized, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            throw new MasterDataTransferException('JSON-Kanonisierung fehlgeschlagen.');
        }

        return $json;
    }

    /**
     * @param  array<string, mixed>|list<mixed>  $payload
     * @return array<string, mixed>|list<mixed>
     */
    private function canonicalize(array $payload): array
    {
        if (! array_is_list($payload)) {
            ksort($payload);
        }
        foreach ($payload as $key => $value) {
            if (is_array($value)) {
                $payload[$key] = $this->canonicalize($value);
            }
        }

        return $payload;
    }
}
