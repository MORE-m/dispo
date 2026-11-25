<?php

namespace App\Services\MasterDataTransfer;

/**
 * EPIC-03-F: verbindlicher Scope und Formatvertrag für den vollständigen Fachdaten-Transfer (Schema 2.0.0).
 *
 * Slice 1: Paketvertrag und Validierung. Inhaltlicher Import folgt in späteren Slices.
 */
final class MasterDataTransferContractV2
{
    public const FORMAT = 'dispo-master-data-transfer';

    public const SCHEMA_VERSION = '2.0.0';

    public const EPIC = 'EPIC-03-F';

    public const MANIFEST_FILE = 'manifest.json';

    public const CHECKSUMS_FILE = 'checksums.sha256';

    public const PRICE_STATUS_ON_APPLY = 'draft';

    /**
     * Einzige erlaubte operative Zieldatenbank (Slice 6a Dry-Run / spätere 6b/7).
     * Nicht per CLI überschreibbar.
     */
    public const OPERATIONAL_DATABASE = 'spn26jz7_dispo_staging';

    /**
     * Pflicht-Datendateien laut Vertrag 2.0.0 (Reihenfolge ist kanonisch).
     *
     * @var list<string>
     */
    public const DATA_FILES = [
        'data/organization.json',
        'data/calculation_methods.json',
        'data/advertising_categories.json',
        'data/advertising_media.json',
        'data/advertising_category_calculation_methods.json',
        'data/advertising_medium_calculation_methods.json',
        'data/inventories.json',
        'data/inventory_medium_rules.json',
        'data/price_lists.json',
        'data/production_price_lists.json',
        'data/oa_platforms.json',
        'data/oa_platform_medium_rules.json',
        'data/oa_targeting_definitions.json',
        'data/oa_tkp_price_lists.json',
        'data/oa_adserver_surcharges.json',
        'data/field_definitions.json',
        'data/field_definition_revisions.json',
        'data/field_definition_revision_options.json',
        'data/field_rules.json',
        'data/field_sets.json',
        'data/field_set_versions.json',
        'data/field_set_version_fields.json',
        'data/field_set_assignments.json',
    ];

    /**
     * Geschlossene Schreib-Whitelist (Tabellennamen).
     *
     * @var list<string>
     */
    public const WRITE_WHITELIST_TABLES = [
        'organizations',
        'calculation_methods',
        'advertising_categories',
        'advertising_media',
        'advertising_category_calculation_methods',
        'advertising_medium_calculation_methods',
        'inventories',
        'inventory_medium_rules',
        'price_lists',
        'price_list_items',
        'production_price_lists',
        'oa_platforms',
        'oa_platform_medium_rules',
        'oa_targeting_definitions',
        'oa_tkp_price_lists',
        'oa_tkp_price_list_items',
        'oa_adserver_surcharges',
        'field_definitions',
        'field_definition_revisions',
        'field_definition_revision_options',
        'field_sets',
        'field_set_versions',
        'field_set_version_fields',
        'field_rules',
        'field_set_assignments',
    ];

    /**
     * Ausgeschlossene Geschäfts- und Betriebsdaten (Manifest excluded_tables).
     *
     * @var list<string>
     */
    public const EXCLUDED_TABLES = [
        'users',
        'crm_accounts',
        'crm_account_versions',
        'crm_imports',
        'crm_conflicts',
        'crm_shared_email_domains',
        'calculations',
        'calculation_field_values',
        'calculation_order_discounts',
        'calculation_positions',
        'calculation_position_components',
        'calculation_position_discounts',
        'calculation_position_field_values',
        'calculation_position_oa_platform_allocations',
        'calculation_position_oa_targeting_selections',
        'calculation_position_planner_entries',
        'calculation_position_production_lines',
        'calculation_position_time_ranges',
        'calculation_number_sequences',
        'dispo_orders',
        'dispo_order_positions',
        'dispo_order_position_field_values',
        'dispo_order_field_values',
        'dispo_order_comments',
        'dispo_order_uploads',
        'dispo_order_status_events',
        'dispo_order_approval_requests',
        'dispo_order_number_sequences',
        'configuration_snapshots',
        'configuration_snapshot_sources',
        'configuration_snapshot_source_fields',
        'configuration_snapshot_source_rules',
        'snapshot_field_definitions',
        'snapshot_field_rules',
        'spot_classic_plan_rows',
        'budget_proposals',
        'standard_offers',
        'standard_offer_versions',
        'standard_offer_number_sequences',
        'price_list_imports',
        'audit_events',
        'notification_outbox',
        'sessions',
        'password_reset_tokens',
        'cache',
        'cache_locks',
        'jobs',
        'job_batches',
        'failed_jobs',
        'migrations',
    ];

    /**
     * Datendateien, die als JSON-Array vorliegen müssen (auch leer).
     *
     * @var list<string>
     */
    public const ARRAY_DATA_FILES = [
        'data/calculation_methods.json',
        'data/advertising_categories.json',
        'data/advertising_media.json',
        'data/advertising_category_calculation_methods.json',
        'data/advertising_medium_calculation_methods.json',
        'data/inventories.json',
        'data/inventory_medium_rules.json',
        'data/price_lists.json',
        'data/production_price_lists.json',
        'data/oa_platforms.json',
        'data/oa_platform_medium_rules.json',
        'data/oa_targeting_definitions.json',
        'data/oa_tkp_price_lists.json',
        'data/oa_adserver_surcharges.json',
        'data/field_definitions.json',
        'data/field_definition_revisions.json',
        'data/field_definition_revision_options.json',
        'data/field_rules.json',
        'data/field_sets.json',
        'data/field_set_versions.json',
        'data/field_set_version_fields.json',
        'data/field_set_assignments.json',
    ];

    public static function isWriteWhitelistedTable(string $table): bool
    {
        return in_array($table, self::WRITE_WHITELIST_TABLES, true);
    }

    public static function isAllowedDataFile(string $relative): bool
    {
        return in_array($relative, self::DATA_FILES, true);
    }

    /**
     * @return list<string>
     */
    public static function excludedTablesSorted(): array
    {
        $tables = self::EXCLUDED_TABLES;
        sort($tables);

        return $tables;
    }
}
