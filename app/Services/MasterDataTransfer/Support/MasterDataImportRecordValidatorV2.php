<?php

namespace App\Services\MasterDataTransfer\Support;

use App\Enums\CalculationKind;
use App\Enums\CalculationMethodMode;
use App\Enums\ComponentCalculationStrategy;
use App\Enums\DayGroup;
use App\Enums\FieldAppliesTo;
use App\Enums\FieldScope;
use App\Enums\FieldSetAssignmentTargetLayer;
use App\Enums\FieldSetVersionStatus;
use App\Enums\FieldType;
use App\Enums\InventoryType;
use App\Enums\OaTargetingCategory;
use App\Enums\ProductionType;
use App\Enums\SpotComponentProfile;
use App\Services\MasterDataTransfer\MasterDataTransferContractV2;
use App\Services\MasterDataTransfer\MasterDataTransferException;

/**
 * EPIC-03-F Slice 3: Paketdaten-Validierung nach Load (Schema 2.0.0).
 */
final class MasterDataImportRecordValidatorV2
{
    /** @var list<string> */
    private array $errors = [];

    /**
     * @param  array<string, mixed>  $data  Keys = relative DATA_FILES paths
     */
    public static function validate(array $data): void
    {
        $validator = new self;
        $validator->run($data);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function run(array $data): void
    {
        foreach (MasterDataTransferContractV2::DATA_FILES as $relative) {
            if (! array_key_exists($relative, $data)) {
                $this->errors[] = 'Pflichtdatei fehlt in geladenen Daten: '.$relative;
            }
        }

        if ($this->errors !== []) {
            throw new MasterDataTransferException('Paketdaten ungültig.', $this->errors);
        }

        $this->validateOrganization($data['data/organization.json']);
        $methods = $this->validateCalculationMethods($data['data/calculation_methods.json']);
        $categories = $this->validateAdvertisingCategories($data['data/advertising_categories.json'], $methods);
        $media = $this->validateAdvertisingMedia($data['data/advertising_media.json'], $categories, $methods);
        $this->validateCategoryMethods($data['data/advertising_category_calculation_methods.json'], $categories, $methods);
        $this->validateMediumMethods($data['data/advertising_medium_calculation_methods.json'], $media, $methods);
        $inventories = $this->validateInventories($data['data/inventories.json']);
        $this->validateInventoryMediumRules($data['data/inventory_medium_rules.json'], $inventories, $media);
        $definitions = $this->validateFieldDefinitions($data['data/field_definitions.json']);
        $revisions = $this->validateFieldDefinitionRevisions(
            $data['data/field_definition_revisions.json'],
            $definitions,
        );
        $this->validateFieldDefinitionRevisionOptions(
            $data['data/field_definition_revision_options.json'],
            $revisions,
        );
        $fieldSets = $this->validateFieldSets($data['data/field_sets.json']);
        $fieldSetVersions = $this->validateFieldSetVersions($data['data/field_set_versions.json'], $fieldSets);
        $this->validateFieldSetVersionFields(
            $data['data/field_set_version_fields.json'],
            $fieldSetVersions,
            $revisions,
        );
        $this->validateFieldRules($data['data/field_rules.json'], $fieldSetVersions);
        $platforms = $this->validateOaPlatforms($data['data/oa_platforms.json']);
        $this->validateOaPlatformMediumRules($data['data/oa_platform_medium_rules.json'], $platforms, $media);
        $this->validateOaTargetingDefinitions($data['data/oa_targeting_definitions.json']);
        $this->validatePriceLists($data['data/price_lists.json'], $inventories);
        $this->validateProductionPriceLists($data['data/production_price_lists.json'], $inventories);
        $this->validateOaTkpPriceLists(
            $data['data/oa_tkp_price_lists.json'],
            $inventories,
            $media,
        );
        $this->validateOaAdserverSurcharges($data['data/oa_adserver_surcharges.json']);
        $this->validateFieldSetAssignments(
            $data['data/field_set_assignments.json'],
            $fieldSets,
            $categories,
            $media,
        );

        if ($this->errors !== []) {
            throw new MasterDataTransferException('Paketdaten ungültig.', $this->errors);
        }
    }

    private function validateOrganization(mixed $row): void
    {
        if (! is_array($row) || array_is_list($row)) {
            $this->errors[] = 'data/organization.json muss ein Objekt sein.';

            return;
        }
        $this->assertAllowedKeys($row, ['name'], 'data/organization.json');
        if (! is_string($row['name'] ?? null) || trim($row['name']) === '') {
            $this->errors[] = 'organization.name fehlt oder ist leer.';
        }
    }

    /**
     * @return array<string, true>
     */
    private function validateCalculationMethods(mixed $rows): array
    {
        $keys = [];
        if (! is_array($rows) || ! array_is_list($rows)) {
            $this->errors[] = 'data/calculation_methods.json muss ein Array sein.';

            return $keys;
        }
        foreach ($rows as $i => $row) {
            if (! is_array($row)) {
                $this->errors[] = 'calculation_methods['.$i.'] ist kein Objekt.';

                continue;
            }
            $this->assertAllowedKeys(
                $row,
                ['key', 'name', 'help_text', 'is_active', 'sort'],
                'calculation_methods['.$i.']',
            );
            $key = $this->requireNonEmptyString($row, 'key', 'calculation_methods['.$i.']');
            if ($key === null) {
                continue;
            }
            if (isset($keys[$key])) {
                $this->errors[] = 'Doppelter calculation_method.key: '.$key;

                continue;
            }
            $keys[$key] = true;
            $this->requireString($row, 'name', 'calculation_methods['.$i.']');
            $this->requireBool($row, 'is_active', 'calculation_methods['.$i.']');
            $this->requireInt($row, 'sort', 'calculation_methods['.$i.']');
            $this->requirePresentNullableString($row, 'help_text', 'calculation_methods['.$i.']');
        }

        return $keys;
    }

    /**
     * @param  array<string, true>  $methods
     * @return array<string, true>
     */
    private function validateAdvertisingCategories(mixed $rows, array $methods): array
    {
        $keys = [];
        if (! is_array($rows) || ! array_is_list($rows)) {
            $this->errors[] = 'data/advertising_categories.json muss ein Array sein.';

            return $keys;
        }
        foreach ($rows as $i => $row) {
            if (! is_array($row)) {
                continue;
            }
            $this->assertAllowedKeys(
                $row,
                ['key', 'name', 'is_active', 'sort', 'default_calculation_method_key'],
                'advertising_categories['.$i.']',
            );
            $key = $this->requireNonEmptyString($row, 'key', 'advertising_categories['.$i.']');
            if ($key === null) {
                continue;
            }
            if (isset($keys[$key])) {
                $this->errors[] = 'Doppelter advertising_category.key: '.$key;

                continue;
            }
            $keys[$key] = true;
            $this->requireString($row, 'name', 'advertising_categories['.$i.']');
            $this->requireBool($row, 'is_active', 'advertising_categories['.$i.']');
            $this->requireInt($row, 'sort', 'advertising_categories['.$i.']');
            if (! array_key_exists('default_calculation_method_key', $row)) {
                $this->errors[] = 'advertising_categories['.$i.'].default_calculation_method_key fehlt (Schlüssel muss vorhanden sein, Wert darf null sein).';
            } else {
                $default = $row['default_calculation_method_key'];
                if ($default === null) {
                    // erlaubt
                } elseif (! is_string($default) || $default === '') {
                    $this->errors[] = 'default_calculation_method_key ungültig bei '.$key;
                } elseif (! isset($methods[$default])) {
                    $this->errors[] = 'default_calculation_method_key unbekannt: '.$key.' → '.$default;
                }
            }
        }

        return $keys;
    }

    /**
     * @param  array<string, true>  $categories
     * @param  array<string, true>  $methods
     * @return array<string, true>
     */
    private function validateAdvertisingMedia(mixed $rows, array $categories, array $methods): array
    {
        $codes = [];
        if (! is_array($rows) || ! array_is_list($rows)) {
            $this->errors[] = 'data/advertising_media.json muss ein Array sein.';

            return $codes;
        }
        foreach ($rows as $i => $row) {
            if (! is_array($row)) {
                continue;
            }
            $allowed = [
                'code', 'name', 'category_key', 'kind', 'component_profile',
                'calculation_method_mode', 'default_calculation_method_key',
                'default_length_seconds', 'is_discountable', 'is_ae_eligible', 'is_active', 'sort',
            ];
            $this->assertAllowedKeys($row, $allowed, 'advertising_media['.$i.']');
            $code = $this->requireNonEmptyString($row, 'code', 'advertising_media['.$i.']');
            if ($code === null) {
                continue;
            }
            if (isset($codes[$code])) {
                $this->errors[] = 'Doppelter advertising_medium.code: '.$code;

                continue;
            }
            $codes[$code] = true;
            $this->requireString($row, 'name', 'advertising_media['.$i.']');
            $categoryKey = $this->requireNonEmptyString($row, 'category_key', 'advertising_media['.$i.']');
            if ($categoryKey !== null && ! isset($categories[$categoryKey])) {
                $this->errors[] = 'category_key unbekannt für Medium '.$code.': '.$categoryKey;
            }
            $this->requireEnum($row, 'calculation_method_mode', CalculationMethodMode::class, 'advertising_media['.$i.']');
            $kind = $row['kind'] ?? null;
            if ($kind !== null && $kind !== '' && CalculationKind::tryFrom((string) $kind) === null) {
                $this->errors[] = 'kind ungültig: '.$code;
            }
            $profile = $row['component_profile'] ?? null;
            if ($profile !== null && $profile !== '' && SpotComponentProfile::tryFrom((string) $profile) === null) {
                $this->errors[] = 'component_profile ungültig: '.$code;
            }
            $default = $row['default_calculation_method_key'] ?? null;
            if (is_string($default) && $default !== '' && ! isset($methods[$default])) {
                $this->errors[] = 'default_calculation_method_key unbekannt für Medium '.$code;
            }
            $this->requireInt($row, 'default_length_seconds', 'advertising_media['.$i.']');
            $this->requireBool($row, 'is_discountable', 'advertising_media['.$i.']');
            $this->requireBool($row, 'is_ae_eligible', 'advertising_media['.$i.']');
            $this->requireBool($row, 'is_active', 'advertising_media['.$i.']');
            $this->requireInt($row, 'sort', 'advertising_media['.$i.']');
        }

        return $codes;
    }

    /**
     * @param  array<string, true>  $categories
     * @param  array<string, true>  $methods
     */
    private function validateCategoryMethods(mixed $rows, array $categories, array $methods): void
    {
        if (! is_array($rows) || ! array_is_list($rows)) {
            $this->errors[] = 'data/advertising_category_calculation_methods.json muss ein Array sein.';

            return;
        }
        $seen = [];
        foreach ($rows as $i => $row) {
            if (! is_array($row)) {
                continue;
            }
            $this->assertAllowedKeys(
                $row,
                ['category_key', 'method_key', 'engine_profile_key', 'is_active', 'sort'],
                'advertising_category_calculation_methods['.$i.']',
            );
            $categoryKey = $this->requireNonEmptyString($row, 'category_key', 'category_methods['.$i.']');
            $methodKey = $this->requireNonEmptyString($row, 'method_key', 'category_methods['.$i.']');
            if ($categoryKey === null || $methodKey === null) {
                continue;
            }
            if (! isset($categories[$categoryKey])) {
                $this->errors[] = 'category_key unbekannt: '.$categoryKey;
            }
            if (! isset($methods[$methodKey])) {
                $this->errors[] = 'method_key unbekannt: '.$methodKey;
            }
            $biz = $categoryKey.'|'.$methodKey;
            if (isset($seen[$biz])) {
                $this->errors[] = 'Doppeltes Kategorie-Methodenpaar: '.$biz;
            }
            $seen[$biz] = true;
            $this->requireNullableString($row, 'engine_profile_key', 'category_methods['.$i.']');
            $this->requireBool($row, 'is_active', 'category_methods['.$i.']');
            $this->requireInt($row, 'sort', 'category_methods['.$i.']');
        }
    }

    /**
     * @param  array<string, true>  $media
     * @param  array<string, true>  $methods
     */
    private function validateMediumMethods(mixed $rows, array $media, array $methods): void
    {
        if (! is_array($rows) || ! array_is_list($rows)) {
            $this->errors[] = 'data/advertising_medium_calculation_methods.json muss ein Array sein.';

            return;
        }
        $seen = [];
        foreach ($rows as $i => $row) {
            if (! is_array($row)) {
                continue;
            }
            $this->assertAllowedKeys(
                $row,
                ['medium_code', 'method_key', 'engine_profile_key', 'is_active', 'sort'],
                'advertising_medium_calculation_methods['.$i.']',
            );
            $mediumCode = $this->requireNonEmptyString($row, 'medium_code', 'medium_methods['.$i.']');
            $methodKey = $this->requireNonEmptyString($row, 'method_key', 'medium_methods['.$i.']');
            if ($mediumCode === null || $methodKey === null) {
                continue;
            }
            if (! isset($media[$mediumCode])) {
                $this->errors[] = 'medium_code unbekannt: '.$mediumCode;
            }
            if (! isset($methods[$methodKey])) {
                $this->errors[] = 'method_key unbekannt: '.$methodKey;
            }
            $biz = $mediumCode.'|'.$methodKey;
            if (isset($seen[$biz])) {
                $this->errors[] = 'Doppeltes Medium-Methodenpaar: '.$biz;
            }
            $seen[$biz] = true;
            $this->requireNullableString($row, 'engine_profile_key', 'medium_methods['.$i.']');
            $this->requireBool($row, 'is_active', 'medium_methods['.$i.']');
            $this->requireInt($row, 'sort', 'medium_methods['.$i.']');
        }
    }

    /**
     * @return array<string, true>
     */
    private function validateInventories(mixed $rows): array
    {
        $codes = [];
        if (! is_array($rows) || ! array_is_list($rows)) {
            $this->errors[] = 'data/inventories.json muss ein Array sein.';

            return $codes;
        }
        foreach ($rows as $i => $row) {
            if (! is_array($row)) {
                continue;
            }
            $this->assertAllowedKeys($row, ['code', 'name', 'type', 'is_active', 'sort', 'logo_path'], 'inventories['.$i.']');
            $code = $this->requireNonEmptyString($row, 'code', 'inventories['.$i.']');
            if ($code === null) {
                continue;
            }
            if (isset($codes[$code])) {
                $this->errors[] = 'Doppelter inventory.code: '.$code;

                continue;
            }
            $codes[$code] = true;
            $this->requireString($row, 'name', 'inventories['.$i.']');
            $this->requireEnum($row, 'type', InventoryType::class, 'inventories['.$i.']');
            $this->requireBool($row, 'is_active', 'inventories['.$i.']');
            $this->requireInt($row, 'sort', 'inventories['.$i.']');
            $this->requirePresentNullableString($row, 'logo_path', 'inventories['.$i.']');
        }

        return $codes;
    }

    /**
     * @param  array<string, true>  $inventories
     * @param  array<string, true>  $media
     */
    private function validateInventoryMediumRules(mixed $rows, array $inventories, array $media): void
    {
        if (! is_array($rows) || ! array_is_list($rows)) {
            $this->errors[] = 'data/inventory_medium_rules.json muss ein Array sein.';

            return;
        }
        $seen = [];
        foreach ($rows as $i => $row) {
            if (! is_array($row)) {
                continue;
            }
            $allowed = [
                'inventory_code', 'medium_code', 'is_active', 'booking_code',
                'planning_responsibility_key', 'hint_text', 'sort', 'default_length_seconds',
                'surcharge_percent', 'is_discountable', 'is_ae_eligible',
                'component_calculation_strategy', 'swf_allonge_confirmed_at',
            ];
            $this->assertAllowedKeys($row, $allowed, 'inventory_medium_rules['.$i.']');
            $inventoryCode = $this->requireNonEmptyString($row, 'inventory_code', 'rules['.$i.']');
            $mediumCode = $this->requireNonEmptyString($row, 'medium_code', 'rules['.$i.']');
            if ($inventoryCode === null || $mediumCode === null) {
                continue;
            }
            if (! isset($inventories[$inventoryCode])) {
                $this->errors[] = 'inventory_code unbekannt in Regel: '.$inventoryCode;
            }
            if (! isset($media[$mediumCode])) {
                $this->errors[] = 'medium_code unbekannt in Regel: '.$mediumCode;
            }
            $biz = $inventoryCode.'|'.$mediumCode;
            if (isset($seen[$biz])) {
                $this->errors[] = 'Doppelte Inventar-Medien-Regel: '.$biz;
            }
            $seen[$biz] = true;
            $this->requireEnum($row, 'component_calculation_strategy', ComponentCalculationStrategy::class, 'rules['.$i.']');
            $this->requireDecimalNullable($row, 'surcharge_percent', MasterDataExportDecimal::PRICE_SCALE, 'rules['.$i.']');
            $this->requireBool($row, 'is_active', 'rules['.$i.']');
            $this->requireBool($row, 'is_discountable', 'rules['.$i.']');
            $this->requireBool($row, 'is_ae_eligible', 'rules['.$i.']');
            $this->requireInt($row, 'sort', 'rules['.$i.']');
            if (array_key_exists('default_length_seconds', $row) && $row['default_length_seconds'] !== null) {
                $this->requireInt($row, 'default_length_seconds', 'rules['.$i.']');
            }
        }
    }

    /**
     * @return array<string, true>
     */
    private function validateFieldDefinitions(mixed $rows): array
    {
        $keys = [];
        if (! is_array($rows) || ! array_is_list($rows)) {
            $this->errors[] = 'data/field_definitions.json muss ein Array sein.';

            return $keys;
        }
        foreach ($rows as $i => $row) {
            if (! is_array($row)) {
                continue;
            }
            $allowed = [
                'key', 'field_type', 'is_system', 'is_key_protected', 'scope',
                'applies_to', 'is_active', 'current_revision',
            ];
            $this->assertAllowedKeys($row, $allowed, 'field_definitions['.$i.']');
            $key = $this->requireNonEmptyString($row, 'key', 'field_definitions['.$i.']');
            if ($key === null) {
                continue;
            }
            if (isset($keys[$key])) {
                $this->errors[] = 'Doppelter field_definition.key: '.$key;

                continue;
            }
            $keys[$key] = true;
            $this->requireEnum($row, 'field_type', FieldType::class, 'field_definitions['.$i.']');
            $this->requireEnum($row, 'scope', FieldScope::class, 'field_definitions['.$i.']');
            $this->requireEnum($row, 'applies_to', FieldAppliesTo::class, 'field_definitions['.$i.']');
            $this->requireBool($row, 'is_system', 'field_definitions['.$i.']');
            $this->requireBool($row, 'is_key_protected', 'field_definitions['.$i.']');
            $this->requireBool($row, 'is_active', 'field_definitions['.$i.']');
            if (array_key_exists('current_revision', $row) && $row['current_revision'] !== null) {
                $this->requireInt($row, 'current_revision', 'field_definitions['.$i.']');
            }
        }

        return $keys;
    }

    /**
     * @param  array<string, true>  $definitions
     * @return array<string, true> field_definition_key|revision
     */
    private function validateFieldDefinitionRevisions(mixed $rows, array $definitions): array
    {
        $keys = [];
        if (! is_array($rows) || ! array_is_list($rows)) {
            $this->errors[] = 'data/field_definition_revisions.json muss ein Array sein.';

            return $keys;
        }
        foreach ($rows as $i => $row) {
            if (! is_array($row)) {
                continue;
            }
            $allowed = [
                'field_definition_key', 'revision', 'label', 'help_text',
                'validation_json', 'group_key', 'sort_default', 'reportable',
            ];
            $this->assertAllowedKeys($row, $allowed, 'field_definition_revisions['.$i.']');
            $defKey = $this->requireNonEmptyString($row, 'field_definition_key', 'revisions['.$i.']');
            if ($defKey === null) {
                continue;
            }
            if (! isset($definitions[$defKey])) {
                $this->errors[] = 'field_definition_key unbekannt in Revision: '.$defKey;
            }
            $this->requireInt($row, 'revision', 'revisions['.$i.']');
            $revision = (int) $row['revision'];
            $biz = $defKey.'|'.$revision;
            if (isset($keys[$biz])) {
                $this->errors[] = 'Doppelte Felddefinitions-Revision: '.$biz;

                continue;
            }
            $keys[$biz] = true;
            $this->requireString($row, 'label', 'revisions['.$i.']');
            $this->requirePresentNullableString($row, 'help_text', 'revisions['.$i.']');
            $this->requirePresentNullableString($row, 'group_key', 'revisions['.$i.']');
            $this->requireInt($row, 'sort_default', 'revisions['.$i.']');
            $this->requireBool($row, 'reportable', 'revisions['.$i.']');
            if (! array_key_exists('validation_json', $row)) {
                $this->errors[] = 'validation_json fehlt in Revision '.$biz;
            }
        }

        return $keys;
    }

    /**
     * @param  array<string, true>  $revisions
     */
    private function validateFieldDefinitionRevisionOptions(mixed $rows, array $revisions): void
    {
        if (! is_array($rows) || ! array_is_list($rows)) {
            $this->errors[] = 'data/field_definition_revision_options.json muss ein Array sein.';

            return;
        }
        $seen = [];
        foreach ($rows as $i => $row) {
            if (! is_array($row)) {
                continue;
            }
            $this->assertAllowedKeys(
                $row,
                ['field_definition_key', 'revision', 'key', 'label', 'sort', 'is_active'],
                'field_definition_revision_options['.$i.']',
            );
            $defKey = $this->requireNonEmptyString($row, 'field_definition_key', 'options['.$i.']');
            $this->requireInt($row, 'revision', 'options['.$i.']');
            if ($defKey === null) {
                continue;
            }
            $revision = (int) ($row['revision'] ?? -1);
            $revKey = $defKey.'|'.$revision;
            if (! isset($revisions[$revKey])) {
                $this->errors[] = 'Revision unbekannt für Option: '.$revKey;
            }
            $optKey = $this->requireNonEmptyString($row, 'key', 'options['.$i.']');
            if ($optKey === null) {
                continue;
            }
            $biz = $revKey.'|'.$optKey;
            if (isset($seen[$biz])) {
                $this->errors[] = 'Doppelte Feldoptions-Revision: '.$biz;
            }
            $seen[$biz] = true;
            $this->requireString($row, 'label', 'options['.$i.']');
            $this->requireInt($row, 'sort', 'options['.$i.']');
            $this->requireBool($row, 'is_active', 'options['.$i.']');
        }
    }

    /**
     * @return array<string, true>
     */
    private function validateFieldSets(mixed $rows): array
    {
        $keys = [];
        if (! is_array($rows) || ! array_is_list($rows)) {
            $this->errors[] = 'data/field_sets.json muss ein Array sein.';

            return $keys;
        }
        foreach ($rows as $i => $row) {
            if (! is_array($row)) {
                continue;
            }
            $this->assertAllowedKeys(
                $row,
                ['key', 'name', 'is_system', 'applies_to', 'is_assignable', 'active_version'],
                'field_sets['.$i.']',
            );
            $key = $this->requireNonEmptyString($row, 'key', 'field_sets['.$i.']');
            if ($key === null) {
                continue;
            }
            if (isset($keys[$key])) {
                $this->errors[] = 'Doppelter field_set.key: '.$key;

                continue;
            }
            $keys[$key] = true;
            $this->requireString($row, 'name', 'field_sets['.$i.']');
            $this->requireBool($row, 'is_system', 'field_sets['.$i.']');
            $this->requireEnum($row, 'applies_to', FieldAppliesTo::class, 'field_sets['.$i.']');
            $this->requireBool($row, 'is_assignable', 'field_sets['.$i.']');
            if (array_key_exists('active_version', $row) && $row['active_version'] !== null) {
                $this->requireInt($row, 'active_version', 'field_sets['.$i.']');
            }
        }

        return $keys;
    }

    /**
     * @param  array<string, true>  $fieldSets
     * @return array<string, true>
     */
    private function validateFieldSetVersions(mixed $rows, array $fieldSets): array
    {
        $keys = [];
        if (! is_array($rows) || ! array_is_list($rows)) {
            $this->errors[] = 'data/field_set_versions.json muss ein Array sein.';

            return $keys;
        }
        foreach ($rows as $i => $row) {
            if (! is_array($row)) {
                continue;
            }
            $this->assertAllowedKeys($row, ['field_set_key', 'version', 'status'], 'field_set_versions['.$i.']');
            $setKey = $this->requireNonEmptyString($row, 'field_set_key', 'field_set_versions['.$i.']');
            if ($setKey === null) {
                continue;
            }
            if (! isset($fieldSets[$setKey])) {
                $this->errors[] = 'field_set_key unbekannt: '.$setKey;
            }
            $this->requireInt($row, 'version', 'field_set_versions['.$i.']');
            $version = (int) $row['version'];
            $biz = $setKey.'|'.$version;
            if (isset($keys[$biz])) {
                $this->errors[] = 'Doppelte Feldset-Version: '.$biz;

                continue;
            }
            $keys[$biz] = true;
            $this->requireEnum($row, 'status', FieldSetVersionStatus::class, 'field_set_versions['.$i.']');
        }

        return $keys;
    }

    /**
     * @param  array<string, true>  $fieldSetVersions
     * @param  array<string, true>  $revisions
     */
    private function validateFieldSetVersionFields(mixed $rows, array $fieldSetVersions, array $revisions): void
    {
        if (! is_array($rows) || ! array_is_list($rows)) {
            $this->errors[] = 'data/field_set_version_fields.json muss ein Array sein.';

            return;
        }
        $seen = [];
        foreach ($rows as $i => $row) {
            if (! is_array($row)) {
                continue;
            }
            $allowed = [
                'field_set_key', 'version', 'field_definition_key', 'revision',
                'sort', 'required_override', 'visible_override',
            ];
            $this->assertAllowedKeys($row, $allowed, 'field_set_version_fields['.$i.']');
            $setKey = $this->requireNonEmptyString($row, 'field_set_key', 'fsvf['.$i.']');
            $this->requireInt($row, 'version', 'fsvf['.$i.']');
            if ($setKey === null) {
                continue;
            }
            $version = (int) ($row['version'] ?? -1);
            if (! isset($fieldSetVersions[$setKey.'|'.$version])) {
                $this->errors[] = 'field_set_version unbekannt: '.$setKey.'|'.$version;
            }
            $defKey = $this->requireNonEmptyString($row, 'field_definition_key', 'fsvf['.$i.']');
            $this->requireInt($row, 'revision', 'fsvf['.$i.']');
            if ($defKey === null) {
                continue;
            }
            $revision = (int) ($row['revision'] ?? -1);
            if (! isset($revisions[$defKey.'|'.$revision])) {
                $this->errors[] = 'field_definition_revision unbekannt: '.$defKey.'|'.$revision;
            }
            $biz = $setKey.'|'.$version.'|'.$defKey;
            if (isset($seen[$biz])) {
                $this->errors[] = 'Doppeltes Feldset-Version-Feld: '.$biz;
            }
            $seen[$biz] = true;
            $this->requireInt($row, 'sort', 'fsvf['.$i.']');
            foreach (['required_override', 'visible_override'] as $nullableBool) {
                if (! array_key_exists($nullableBool, $row)) {
                    $this->errors[] = 'fsvf['.$i.'].'.$nullableBool.' fehlt (Schlüssel muss vorhanden sein, Wert darf null sein).';
                } elseif ($row[$nullableBool] !== null && ! is_bool($row[$nullableBool])) {
                    $this->errors[] = 'fsvf['.$i.'].'.$nullableBool.' muss bool oder null sein.';
                }
            }
        }
    }

    /**
     * @param  array<string, true>  $fieldSetVersions
     */
    private function validateFieldRules(mixed $rows, array $fieldSetVersions): void
    {
        if (! is_array($rows) || ! array_is_list($rows)) {
            $this->errors[] = 'data/field_rules.json muss ein Array sein.';

            return;
        }
        foreach ($rows as $i => $row) {
            if (! is_array($row)) {
                continue;
            }
            $this->assertAllowedKeys(
                $row,
                ['field_set_key', 'version', 'sort', 'condition_json', 'action_json'],
                'field_rules['.$i.']',
            );
            $setKey = $this->requireNonEmptyString($row, 'field_set_key', 'field_rules['.$i.']');
            $this->requireInt($row, 'version', 'field_rules['.$i.']');
            if ($setKey === null) {
                continue;
            }
            $version = (int) ($row['version'] ?? -1);
            if (! isset($fieldSetVersions[$setKey.'|'.$version])) {
                $this->errors[] = 'field_set_version unbekannt in Regel: '.$setKey.'|'.$version;
            }
            $this->requireInt($row, 'sort', 'field_rules['.$i.']');
            if (! array_key_exists('condition_json', $row) || ! array_key_exists('action_json', $row)) {
                $this->errors[] = 'condition_json/action_json fehlt in field_rules['.$i.']';
            }
        }
    }

    /**
     * @return array<string, true>
     */
    private function validateOaPlatforms(mixed $rows): array
    {
        $codes = [];
        if (! is_array($rows) || ! array_is_list($rows)) {
            $this->errors[] = 'data/oa_platforms.json muss ein Array sein.';

            return $codes;
        }
        foreach ($rows as $i => $row) {
            if (! is_array($row)) {
                continue;
            }
            $this->assertAllowedKeys(
                $row,
                ['code', 'name', 'is_active', 'sort', 'is_adserver_relevant'],
                'oa_platforms['.$i.']',
            );
            $code = $this->requireNonEmptyString($row, 'code', 'oa_platforms['.$i.']');
            if ($code === null) {
                continue;
            }
            if (isset($codes[$code])) {
                $this->errors[] = 'Doppelter oa_platform.code: '.$code;

                continue;
            }
            $codes[$code] = true;
            $this->requireString($row, 'name', 'oa_platforms['.$i.']');
            $this->requireBool($row, 'is_active', 'oa_platforms['.$i.']');
            $this->requireInt($row, 'sort', 'oa_platforms['.$i.']');
            $this->requireBool($row, 'is_adserver_relevant', 'oa_platforms['.$i.']');
        }

        return $codes;
    }

    /**
     * @param  array<string, true>  $platforms
     * @param  array<string, true>  $media
     */
    private function validateOaPlatformMediumRules(mixed $rows, array $platforms, array $media): void
    {
        if (! is_array($rows) || ! array_is_list($rows)) {
            $this->errors[] = 'data/oa_platform_medium_rules.json muss ein Array sein.';

            return;
        }
        $seen = [];
        foreach ($rows as $i => $row) {
            if (! is_array($row)) {
                continue;
            }
            $this->assertAllowedKeys($row, ['platform_code', 'medium_code', 'is_active'], 'oa_platform_medium_rules['.$i.']');
            $platform = $this->requireNonEmptyString($row, 'platform_code', 'oa_pmr['.$i.']');
            $medium = $this->requireNonEmptyString($row, 'medium_code', 'oa_pmr['.$i.']');
            if ($platform === null || $medium === null) {
                continue;
            }
            if (! isset($platforms[$platform])) {
                $this->errors[] = 'platform_code unbekannt: '.$platform;
            }
            if (! isset($media[$medium])) {
                $this->errors[] = 'medium_code unbekannt: '.$medium;
            }
            $biz = $platform.'|'.$medium;
            if (isset($seen[$biz])) {
                $this->errors[] = 'Doppelte OA-Plattform-Medien-Regel: '.$biz;
            }
            $seen[$biz] = true;
            $this->requireBool($row, 'is_active', 'oa_pmr['.$i.']');
        }
    }

    private function validateOaTargetingDefinitions(mixed $rows): void
    {
        if (! is_array($rows) || ! array_is_list($rows)) {
            $this->errors[] = 'data/oa_targeting_definitions.json muss ein Array sein.';

            return;
        }
        $seen = [];
        foreach ($rows as $i => $row) {
            if (! is_array($row)) {
                continue;
            }
            $allowed = [
                'code', 'revision_number', 'name', 'category', 'tkp_surcharge',
                'minimum_tkp_surcharge', 'is_active', 'valid_from', 'valid_to',
            ];
            $this->assertAllowedKeys($row, $allowed, 'oa_targeting_definitions['.$i.']');
            $code = $this->requireNonEmptyString($row, 'code', 'oa_targeting['.$i.']');
            $this->requireInt($row, 'revision_number', 'oa_targeting['.$i.']');
            if ($code === null) {
                continue;
            }
            $revision = (int) ($row['revision_number'] ?? -1);
            $biz = $code.'|'.$revision;
            if (isset($seen[$biz])) {
                $this->errors[] = 'Doppelte OA-Targeting-Definition: '.$biz;
            }
            $seen[$biz] = true;
            $this->requireEnum($row, 'category', OaTargetingCategory::class, 'oa_targeting['.$i.']');
            $this->requireDecimal($row, 'tkp_surcharge', MasterDataExportDecimal::PRICE_SCALE, 'oa_targeting['.$i.']');
            $this->requireDecimal($row, 'minimum_tkp_surcharge', MasterDataExportDecimal::PRICE_SCALE, 'oa_targeting['.$i.']');
            $this->requireBool($row, 'is_active', 'oa_targeting['.$i.']');
            $this->requireNullableDateString($row, 'valid_from', 'oa_targeting['.$i.']');
            $this->requireNullableDateString($row, 'valid_to', 'oa_targeting['.$i.']');
        }
    }

    /**
     * @param  array<string, true>  $inventories
     */
    private function validatePriceLists(mixed $rows, array $inventories): void
    {
        if (! is_array($rows) || ! array_is_list($rows)) {
            $this->errors[] = 'data/price_lists.json muss ein Array sein.';

            return;
        }
        $seen = [];
        $seenVersion = [];
        foreach ($rows as $i => $row) {
            if (! is_array($row)) {
                continue;
            }
            $allowed = [
                'inventory_code', 'year', 'revision_number', 'name', 'version',
                'valid_from', 'source_status', 'import_status', 'items',
            ];
            $this->assertAllowedKeys($row, $allowed, 'price_lists['.$i.']');
            $inventoryCode = $this->requireNonEmptyString($row, 'inventory_code', 'price_lists['.$i.']');
            $this->requireInt($row, 'year', 'price_lists['.$i.']');
            $this->requireInt($row, 'revision_number', 'price_lists['.$i.']');
            $this->requireString($row, 'version', 'price_lists['.$i.']');
            $this->requirePresentNullableDateString($row, 'valid_from', 'price_lists['.$i.']');
            if ($inventoryCode === null) {
                continue;
            }
            if (! isset($inventories[$inventoryCode])) {
                $this->errors[] = 'inventory_code unbekannt in Spotpreisliste: '.$inventoryCode;
            }
            $year = (int) ($row['year'] ?? 0);
            $revision = (int) ($row['revision_number'] ?? 0);
            $version = (string) ($row['version'] ?? '');
            $biz = $inventoryCode.'|'.$year.'|'.$revision;
            if (isset($seen[$biz])) {
                $this->errors[] = 'Doppelte Spotpreisliste: '.$biz;
            }
            $seen[$biz] = true;
            $versionBiz = $inventoryCode.'|'.$year.'|'.$version;
            if ($version !== '' && isset($seenVersion[$versionBiz])) {
                $this->errors[] = 'Doppelte Spotpreislisten-Version (inventory/year/version): '.$versionBiz;
            }
            if ($version !== '') {
                $seenVersion[$versionBiz] = true;
            }
            $this->validatePriceStatusFields($row, 'price_lists['.$i.']');
            $items = $row['items'] ?? null;
            if (! is_array($items)) {
                $this->errors[] = 'price_lists['.$i.'].items fehlt.';

                continue;
            }
            $seenItems = [];
            foreach ($items as $j => $item) {
                if (! is_array($item)) {
                    continue;
                }
                $this->assertAllowedKeys($item, ['hour', 'day_group', 'second_price'], 'price_list_item['.$i.'].'.$j.']');
                $this->requireInt($item, 'hour', 'price_list_item');
                $this->requireEnum($item, 'day_group', DayGroup::class, 'price_list_item');
                $this->requireDecimal($item, 'second_price', MasterDataExportDecimal::PRICE_SCALE, 'price_list_item');
                $itemBiz = (string) ($item['day_group'] ?? '').'|'.(string) ($item['hour'] ?? '');
                if (isset($seenItems[$itemBiz])) {
                    $this->errors[] = 'Doppelte Spotpreisposition (day_group, hour) in price_lists['.$i.']: '.$itemBiz;
                }
                $seenItems[$itemBiz] = true;
            }
        }
    }

    /**
     * @param  array<string, true>  $inventories
     */
    private function validateProductionPriceLists(mixed $rows, array $inventories): void
    {
        if (! is_array($rows) || ! array_is_list($rows)) {
            $this->errors[] = 'data/production_price_lists.json muss ein Array sein.';

            return;
        }
        $seen = [];
        foreach ($rows as $i => $row) {
            if (! is_array($row)) {
                continue;
            }
            $allowed = [
                'inventory_code', 'production_type', 'year', 'revision_number',
                'name', 'version', 'source_status', 'import_status',
                'unit_price', 'is_discountable', 'is_ae_eligible',
            ];
            $this->assertAllowedKeys($row, $allowed, 'production_price_lists['.$i.']');
            $inventoryCode = $this->requireNonEmptyString($row, 'inventory_code', 'production['.$i.']');
            $this->requireEnum($row, 'production_type', ProductionType::class, 'production['.$i.']');
            $this->requireInt($row, 'year', 'production['.$i.']');
            $this->requireInt($row, 'revision_number', 'production['.$i.']');
            if ($inventoryCode === null) {
                continue;
            }
            if (! isset($inventories[$inventoryCode])) {
                $this->errors[] = 'inventory_code unbekannt in Produktionspreisliste: '.$inventoryCode;
            }
            $ptype = (string) ($row['production_type'] ?? '');
            $year = (int) ($row['year'] ?? 0);
            $revision = (int) ($row['revision_number'] ?? 0);
            $biz = $inventoryCode.'|'.$ptype.'|'.$year.'|'.$revision;
            if (isset($seen[$biz])) {
                $this->errors[] = 'Doppelte Produktionspreisliste: '.$biz;
            }
            $seen[$biz] = true;
            $this->validatePriceStatusFields($row, 'production_price_lists['.$i.']');
            $this->requireDecimal($row, 'unit_price', MasterDataExportDecimal::MONEY_SCALE, 'production['.$i.']');
            $this->requireBool($row, 'is_discountable', 'production['.$i.']');
            $this->requireBool($row, 'is_ae_eligible', 'production['.$i.']');
        }
    }

    /**
     * @param  array<string, true>  $inventories
     * @param  array<string, true>  $media
     */
    private function validateOaTkpPriceLists(mixed $rows, array $inventories, array $media): void
    {
        if (! is_array($rows) || ! array_is_list($rows)) {
            $this->errors[] = 'data/oa_tkp_price_lists.json muss ein Array sein.';

            return;
        }
        $seen = [];
        foreach ($rows as $i => $row) {
            if (! is_array($row)) {
                continue;
            }
            $allowed = [
                'year', 'revision_number', 'name', 'version', 'valid_from',
                'source_status', 'import_status', 'items',
            ];
            $this->assertAllowedKeys($row, $allowed, 'oa_tkp_price_lists['.$i.']');
            $this->requireInt($row, 'year', 'oa_tkp['.$i.']');
            $this->requireInt($row, 'revision_number', 'oa_tkp['.$i.']');
            $year = (int) ($row['year'] ?? 0);
            $revision = (int) ($row['revision_number'] ?? 0);
            $biz = $year.'|'.$revision;
            if (isset($seen[$biz])) {
                $this->errors[] = 'Doppelte OA-TKP-Preisliste: '.$biz;
            }
            $seen[$biz] = true;
            $this->validatePriceStatusFields($row, 'oa_tkp_price_lists['.$i.']');
            $this->requirePresentNullableDateString($row, 'valid_from', 'oa_tkp_price_lists['.$i.']');
            $items = $row['items'] ?? null;
            if (! is_array($items)) {
                $this->errors[] = 'oa_tkp_price_lists['.$i.'].items fehlt.';

                continue;
            }
            $seenItems = [];
            foreach ($items as $j => $item) {
                if (! is_array($item)) {
                    continue;
                }
                $this->assertAllowedKeys(
                    $item,
                    ['inventory_code', 'medium_code', 'list_tkp', 'minimum_tkp'],
                    'oa_tkp_item['.$i.'].'.$j.']',
                );
                $inv = $this->requireNonEmptyString($item, 'inventory_code', 'oa_tkp_item');
                $med = $this->requireNonEmptyString($item, 'medium_code', 'oa_tkp_item');
                if ($inv !== null && ! isset($inventories[$inv])) {
                    $this->errors[] = 'inventory_code unbekannt in OA-TKP-Item: '.$inv;
                }
                if ($med !== null && ! isset($media[$med])) {
                    $this->errors[] = 'medium_code unbekannt in OA-TKP-Item: '.$med;
                }
                if ($inv !== null && $med !== null) {
                    $itemBiz = $inv.'|'.$med;
                    if (isset($seenItems[$itemBiz])) {
                        $this->errors[] = 'Doppelte OA-TKP-Position (inventory_code, medium_code) in oa_tkp_price_lists['.$i.']: '.$itemBiz;
                    }
                    $seenItems[$itemBiz] = true;
                }
                $this->requireDecimal($item, 'list_tkp', MasterDataExportDecimal::PRICE_SCALE, 'oa_tkp_item');
                $this->requireDecimal($item, 'minimum_tkp', MasterDataExportDecimal::PRICE_SCALE, 'oa_tkp_item');
            }
        }
    }

    private function validateOaAdserverSurcharges(mixed $rows): void
    {
        if (! is_array($rows) || ! array_is_list($rows)) {
            $this->errors[] = 'data/oa_adserver_surcharges.json muss ein Array sein.';

            return;
        }
        $seen = [];
        foreach ($rows as $i => $row) {
            if (! is_array($row)) {
                continue;
            }
            $allowed = [
                'code', 'revision_number', 'name', 'tkp_amount', 'is_active',
                'scope_json', 'valid_from', 'valid_to',
            ];
            $this->assertAllowedKeys($row, $allowed, 'oa_adserver_surcharges['.$i.']');
            $code = $this->requireNonEmptyString($row, 'code', 'oa_adserver['.$i.']');
            $this->requireInt($row, 'revision_number', 'oa_adserver['.$i.']');
            if ($code === null) {
                continue;
            }
            $revision = (int) ($row['revision_number'] ?? -1);
            $biz = $code.'|'.$revision;
            if (isset($seen[$biz])) {
                $this->errors[] = 'Doppelter OA-Adserver-Aufschlag: '.$biz;
            }
            $seen[$biz] = true;
            $this->requireDecimal($row, 'tkp_amount', MasterDataExportDecimal::PRICE_SCALE, 'oa_adserver['.$i.']');
            $this->requireBool($row, 'is_active', 'oa_adserver['.$i.']');
            $this->requireNullableDateString($row, 'valid_from', 'oa_adserver['.$i.']');
            $this->requireNullableDateString($row, 'valid_to', 'oa_adserver['.$i.']');
            if (! array_key_exists('scope_json', $row)) {
                $this->errors[] = 'scope_json fehlt in oa_adserver_surcharges['.$i.']';
            }
        }
    }

    /**
     * @param  array<string, true>  $fieldSets
     * @param  array<string, true>  $categories
     * @param  array<string, true>  $media
     */
    private function validateFieldSetAssignments(
        mixed $rows,
        array $fieldSets,
        array $categories,
        array $media,
    ): void {
        if (! is_array($rows) || ! array_is_list($rows)) {
            $this->errors[] = 'data/field_set_assignments.json muss ein Array sein.';

            return;
        }
        $seen = [];
        foreach ($rows as $i => $row) {
            if (! is_array($row)) {
                continue;
            }
            $allowed = [
                'field_set_key', 'applies_to_process', 'target_layer', 'is_active', 'sort',
                'category_key', 'medium_code',
            ];
            $this->assertAllowedKeys($row, $allowed, 'field_set_assignments['.$i.']');
            $setKey = $this->requireNonEmptyString($row, 'field_set_key', 'assignments['.$i.']');
            if ($setKey !== null && ! isset($fieldSets[$setKey])) {
                $this->errors[] = 'field_set_key unbekannt in Zuordnung: '.$setKey;
            }
            $this->requireEnum($row, 'applies_to_process', FieldAppliesTo::class, 'assignments['.$i.']');
            $process = is_string($row['applies_to_process'] ?? null) ? (string) $row['applies_to_process'] : '';
            $layer = $row['target_layer'] ?? null;
            if (! is_string($layer) || FieldSetAssignmentTargetLayer::tryFrom($layer) === null) {
                $this->errors[] = 'target_layer ungültig in assignments['.$i.']';

                continue;
            }
            $targetLayer = FieldSetAssignmentTargetLayer::from($layer);

            if ($targetLayer === FieldSetAssignmentTargetLayer::Global) {
                if (array_key_exists('category_key', $row) && $row['category_key'] !== null) {
                    $this->errors[] = 'assignments['.$i.']: target_layer=global darf category_key nicht setzen.';
                }
                if (array_key_exists('medium_code', $row) && $row['medium_code'] !== null) {
                    $this->errors[] = 'assignments['.$i.']: target_layer=global darf medium_code nicht setzen.';
                }
                $semantic = ($setKey ?? '').'|'.$process.'|global';
            } elseif ($targetLayer === FieldSetAssignmentTargetLayer::AdvertisingCategory) {
                $cat = $this->requireNonEmptyString($row, 'category_key', 'assignments['.$i.']');
                if ($cat !== null && ! isset($categories[$cat])) {
                    $this->errors[] = 'category_key unbekannt in Zuordnung: '.$cat;
                }
                if (array_key_exists('medium_code', $row) && $row['medium_code'] !== null) {
                    $this->errors[] = 'assignments['.$i.']: target_layer=advertising_category darf medium_code nicht setzen.';
                }
                $semantic = ($setKey ?? '').'|'.$process.'|category|'.($cat ?? '');
            } else {
                $med = $this->requireNonEmptyString($row, 'medium_code', 'assignments['.$i.']');
                if ($med !== null && ! isset($media[$med])) {
                    $this->errors[] = 'medium_code unbekannt in Zuordnung: '.$med;
                }
                if (array_key_exists('category_key', $row) && $row['category_key'] !== null) {
                    $this->errors[] = 'assignments['.$i.']: target_layer=advertising_medium darf category_key nicht setzen.';
                }
                $semantic = ($setKey ?? '').'|'.$process.'|medium|'.($med ?? '');
            }

            if (isset($seen[$semantic])) {
                $this->errors[] = 'Doppelte Feldset-Zuordnung: '.$semantic;
            }
            $seen[$semantic] = true;

            $this->requireBool($row, 'is_active', 'assignments['.$i.']');
            $this->requireInt($row, 'sort', 'assignments['.$i.']');
        }
    }

    /**
     * @param  array<string, mixed>  $row
     * @param  list<string>  $allowed
     */
    private function assertAllowedKeys(array $row, array $allowed, string $context): void
    {
        $extra = array_diff(array_keys($row), $allowed);
        if ($extra !== []) {
            sort($extra);
            $this->errors[] = $context.': unzulässige Schlüssel: '.implode(', ', $extra);
        }
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function requireNonEmptyString(array $row, string $field, string $context): ?string
    {
        $value = $row[$field] ?? null;
        if (! is_string($value) || trim($value) === '') {
            $this->errors[] = $context.'.'.$field.' fehlt oder ist leer.';

            return null;
        }

        return $value;
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function requireString(array $row, string $field, string $context): void
    {
        if (! is_string($row[$field] ?? null)) {
            $this->errors[] = $context.'.'.$field.' muss ein String sein.';
        }
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function requireNullableString(array $row, string $field, string $context): void
    {
        $value = $row[$field] ?? null;
        if ($value !== null && ! is_string($value)) {
            $this->errors[] = $context.'.'.$field.' muss String oder null sein.';
        }
    }

    /**
     * Pflichtschlüssel mit erlaubtem null: fehlender Schlüssel ≠ null-Wert.
     *
     * @param  array<string, mixed>  $row
     */
    private function requirePresentNullableString(array $row, string $field, string $context): void
    {
        if (! array_key_exists($field, $row)) {
            $this->errors[] = $context.'.'.$field.' fehlt (Schlüssel muss vorhanden sein, Wert darf null sein).';

            return;
        }
        $value = $row[$field];
        if ($value !== null && ! is_string($value)) {
            $this->errors[] = $context.'.'.$field.' muss String oder null sein.';
        }
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function requirePresentNullableDateString(array $row, string $field, string $context): void
    {
        if (! array_key_exists($field, $row)) {
            $this->errors[] = $context.'.'.$field.' fehlt (Schlüssel muss vorhanden sein, Wert darf null sein).';

            return;
        }
        $this->requireNullableDateString($row, $field, $context);
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function requireBool(array $row, string $field, string $context): void
    {
        if (! is_bool($row[$field] ?? null)) {
            $this->errors[] = $context.'.'.$field.' muss bool sein.';
        }
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function requireInt(array $row, string $field, string $context): void
    {
        if (! is_int($row[$field] ?? null)) {
            $this->errors[] = $context.'.'.$field.' muss int sein.';
        }
    }

    /**
     * @param  array<string, mixed>  $row
     * @param  class-string<\BackedEnum>  $enumClass
     */
    private function requireEnum(array $row, string $field, string $enumClass, string $context): void
    {
        $value = $row[$field] ?? null;
        if (! is_string($value) || $enumClass::tryFrom($value) === null) {
            $this->errors[] = $context.'.'.$field.' ist kein gültiger Enum-Wert.';
        }
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function requireDecimal(array $row, string $field, int $scale, string $context): void
    {
        try {
            MasterDataExportDecimal::canonicalize($row[$field] ?? null, $scale);
        } catch (MasterDataTransferException $e) {
            $this->errors[] = $context.'.'.$field.': '.$e->getMessage();
        }
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function requireDecimalNullable(array $row, string $field, int $scale, string $context): void
    {
        $value = $row[$field] ?? null;
        if ($value === null || $value === '') {
            return;
        }
        try {
            MasterDataExportDecimal::canonicalizeNullable($value, $scale);
        } catch (MasterDataTransferException $e) {
            $this->errors[] = $context.'.'.$field.': '.$e->getMessage();
        }
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function requireNullableDateString(array $row, string $field, string $context): void
    {
        $value = $row[$field] ?? null;
        if ($value === null || $value === '') {
            return;
        }
        if (! is_string($value) || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            $this->errors[] = $context.'.'.$field.' muss ISO-Datum (YYYY-MM-DD) oder null sein.';
        }
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function validatePriceStatusFields(array $row, string $context): void
    {
        $source = $row['source_status'] ?? null;
        if (! in_array($source, ['active', 'archived', 'draft'], true)) {
            $this->errors[] = $context.'.source_status ungültig.';
        }
        if (($row['import_status'] ?? null) !== 'draft') {
            $this->errors[] = $context.'.import_status muss draft sein.';
        }
    }
}
