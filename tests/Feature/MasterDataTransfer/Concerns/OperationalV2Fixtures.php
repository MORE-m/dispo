<?php

namespace Tests\Feature\MasterDataTransfer\Concerns;

use App\Enums\FieldAppliesTo;
use App\Enums\FieldScope;
use App\Enums\FieldSetVersionStatus;
use App\Enums\FieldType;
use App\Enums\InventoryType;
use App\Enums\Role;
use App\Models\FieldDefinition;
use App\Models\Organization;
use App\Models\User;
use App\Services\MasterDataTransfer\MasterDataTransferContractV2;
use App\Services\MasterDataTransfer\Support\MasterDataBackupProofBuilderV2;
use App\Services\MasterDataTransfer\Support\MasterDataBackupProofV2;
use App\Services\MasterDataTransfer\Support\MasterDataPackage;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;

/**
 * Gemeinsame synthetische Fixtures für Slice 6b-H / Slice 7 (nur isolierte Test-DB, nie Staging).
 * Proofs werden ausschließlich über den Produktions-Builder erzeugt.
 */
trait OperationalV2Fixtures
{
    private string $tmp;

    protected function bootOperationalFixtures(string $label): void
    {
        $this->tmp = sys_get_temp_dir().'/mdt-v2-'.$label.'-'.uniqid('', true);
        File::ensureDirectoryExists($this->tmp);
        config([
            'dispo.master_data_transfer_testing_operational_database' => MasterDataTransferContractV2::OPERATIONAL_DATABASE,
            'dispo.master_data_transfer_receipt_dir' => $this->tmp.'/receipts',
            'dispo.master_data_transfer_instance_id' => null,
        ]);
        User::factory()->create(['email' => $label.'@example.test', 'role' => Role::Admin, 'is_active' => true]);
    }

    protected function teardownOperationalFixtures(): void
    {
        File::deleteDirectory($this->tmp);
        FieldDefinition::flushEventListeners();
        Organization::flushEventListeners();
        config([
            'dispo.master_data_transfer_testing_operational_database' => null,
            'dispo.master_data_transfer_receipt_dir' => null,
        ]);
    }

    protected function packageSha(string $package): string
    {
        return app(MasterDataPackage::class)->computePackageSha256($package);
    }

    /**
     * @param  array{receipt_id: string, signature: string}|null  $receipt
     */
    protected function proofFor(
        string $packageSha,
        string $phase = MasterDataBackupProofV2::PHASE_PRE_CONTENT_APPLY,
        ?array $receipt = null,
        ?string $createdAt = null,
        string $database = MasterDataTransferContractV2::OPERATIONAL_DATABASE,
    ): string {
        $dump = $this->tmp.'/dump-'.uniqid('', true).'.sql';
        File::put($dump, "-- synthetic staging dump\n".bin2hex(random_bytes(12))."\n");
        $built = (new MasterDataBackupProofBuilderV2)->build([
            'dump_path' => $dump,
            'output_dir' => $this->tmp.'/proof-'.uniqid('', true),
            'phase' => $phase,
            'package_sha256' => $packageSha,
            'database_name' => $database,
            'restore_path' => 'same_db_import_verified',
            'content_receipt' => $receipt,
            'created_at' => $createdAt,
            'dump_filename' => 'dump.sql',
        ]);

        return $built['proof_dir'];
    }

    /**
     * Inhalts-Apply per CLI mit frischem Backup A; liefert Receipt-Pfad.
     *
     * @return array{exit: int, output: string, receipt_path: ?string}
     */
    protected function applyContent(string $package, ?string $proof = null): array
    {
        $sha = $this->packageSha($package);
        $exit = Artisan::call('dispo:import-master-data', [
            'path' => $package,
            '--apply' => true,
            '--confirm-database' => MasterDataTransferContractV2::OPERATIONAL_DATABASE,
            '--require-package-sha256' => $sha,
            '--require-backup-proof' => $proof ?? $this->proofFor($sha),
        ]);
        $output = Artisan::output();
        $receiptPath = null;
        if (preg_match('/^receipt=(.+)$/m', $output, $m) === 1) {
            $receiptPath = trim($m[1]);
        }

        return ['exit' => $exit, 'output' => $output, 'receipt_path' => $receiptPath];
    }

    /**
     * @return list<string>
     */
    protected function receiptFiles(): array
    {
        $dir = $this->tmp.'/receipts';
        if (! is_dir($dir)) {
            return [];
        }

        return array_values(array_map(fn (string $f): string => $dir.'/'.$f, array_diff(scandir($dir) ?: [], ['.', '..'])));
    }

    /**
     * @param  array<string, array<string, mixed>|list<mixed>>  $overrides
     */
    protected function writePackage(array $overrides = []): string
    {
        $path = $this->tmp.'/pkg-'.uniqid('', true);
        $files = array_replace($this->basePayload(), $overrides);
        app(MasterDataPackage::class)->write($path, $this->manifestFor($files), $files);

        return $path;
    }

    /**
     * @return array<string, mixed>
     */
    protected function spotList(string $inventory, int $year, int $revision, string $sourceStatus, string $price = '12.5000'): array
    {
        return [
            'inventory_code' => $inventory, 'year' => $year, 'revision_number' => $revision,
            'name' => 'Spot '.$year.' r'.$revision, 'version' => (string) $revision,
            'valid_from' => $year.'-01-01', 'source_status' => $sourceStatus, 'import_status' => 'draft',
            'items' => [['hour' => 8, 'day_group' => 'mo_fr', 'second_price' => $price]],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function productionList(string $inventory, int $year, int $revision, string $sourceStatus, string $price = '150.00'): array
    {
        return [
            'inventory_code' => $inventory, 'production_type' => 'spot_production', 'year' => $year,
            'revision_number' => $revision, 'name' => 'Prod '.$year.' r'.$revision, 'version' => (string) $revision,
            'source_status' => $sourceStatus, 'import_status' => 'draft', 'unit_price' => $price,
            'is_discountable' => false, 'is_ae_eligible' => false,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function oaList(int $year, int $revision, string $sourceStatus, bool $complete = true): array
    {
        $items = [[
            'inventory_code' => 'inv_v2_radio', 'medium_code' => 'pre_stream',
            'list_tkp' => '8.0000', 'minimum_tkp' => '6.0000',
        ]];
        if (! $complete) {
            $items = [];
        }

        return [
            'year' => $year, 'revision_number' => $revision, 'name' => 'OA TKP '.$year.' r'.$revision,
            'version' => (string) $revision, 'valid_from' => $year.'-01-01',
            'source_status' => $sourceStatus, 'import_status' => 'draft', 'items' => $items,
        ];
    }

    /**
     * @return array<string, array<string, mixed>|list<mixed>>
     */
    protected function basePayload(): array
    {
        $files = [];
        foreach (MasterDataTransferContractV2::DATA_FILES as $relative) {
            $files[$relative] = $relative === 'data/organization.json'
                ? ['name' => 'Fixture Org V2']
                : [];
        }
        $files['data/calculation_methods.json'] = [[
            'key' => 'avg_v2', 'name' => 'Durchschnitt V2', 'help_text' => null, 'is_active' => true, 'sort' => 1,
        ]];
        $files['data/advertising_categories.json'] = [[
            'key' => 'spots_v2', 'name' => 'Spots V2', 'is_active' => true, 'sort' => 10,
            'default_calculation_method_key' => 'avg_v2',
        ]];
        $files['data/advertising_media.json'] = [
            [
                'code' => 'spot_v2', 'name' => 'Spot V2', 'category_key' => 'spots_v2', 'kind' => 'spot_classic',
                'component_profile' => null, 'calculation_method_mode' => 'inherit',
                'default_calculation_method_key' => null, 'default_length_seconds' => 30,
                'is_discountable' => true, 'is_ae_eligible' => true, 'is_active' => true, 'sort' => 10,
            ],
            [
                'code' => 'pre_stream', 'name' => 'Pre Stream', 'category_key' => 'spots_v2', 'kind' => 'oa_tkp',
                'component_profile' => null, 'calculation_method_mode' => 'inherit',
                'default_calculation_method_key' => null, 'default_length_seconds' => 0,
                'is_discountable' => false, 'is_ae_eligible' => false, 'is_active' => true, 'sort' => 20,
            ],
        ];
        $files['data/advertising_category_calculation_methods.json'] = [[
            'category_key' => 'spots_v2', 'method_key' => 'avg_v2', 'engine_profile_key' => 'default',
            'is_active' => true, 'sort' => 1,
        ]];
        $files['data/advertising_medium_calculation_methods.json'] = [[
            'medium_code' => 'spot_v2', 'method_key' => 'avg_v2', 'engine_profile_key' => 'default',
            'is_active' => true, 'sort' => 1,
        ]];
        $files['data/inventories.json'] = [
            ['code' => 'inv_v2_radio', 'name' => 'Radio V2', 'type' => InventoryType::Sender->value, 'is_active' => true, 'sort' => 1, 'logo_path' => null],
            ['code' => 'inv_v2_other', 'name' => 'Radio Other', 'type' => InventoryType::Sender->value, 'is_active' => true, 'sort' => 2, 'logo_path' => null],
        ];
        $rule = fn (string $inv, string $medium, string $booking, int $sort): array => [
            'inventory_code' => $inv, 'medium_code' => $medium, 'is_active' => true,
            'booking_code' => $booking, 'planning_responsibility_key' => null, 'hint_text' => null, 'sort' => $sort,
            'default_length_seconds' => 30, 'surcharge_percent' => null, 'is_discountable' => true,
            'is_ae_eligible' => true, 'component_calculation_strategy' => 'shared_total_length',
            'swf_allonge_confirmed_at' => null,
        ];
        $files['data/inventory_medium_rules.json'] = [
            $rule('inv_v2_radio', 'spot_v2', 'BK1', 1),
            $rule('inv_v2_radio', 'pre_stream', 'BK2', 2),
        ];
        $files['data/price_lists.json'] = [$this->spotList('inv_v2_radio', 2026, 1, 'active')];
        $files['data/production_price_lists.json'] = [$this->productionList('inv_v2_radio', 2026, 1, 'active')];
        $files['data/oa_platforms.json'] = [[
            'code' => 'spotify_v2', 'name' => 'Spotify V2', 'is_active' => true, 'sort' => 1, 'is_adserver_relevant' => true,
        ]];
        $files['data/oa_platform_medium_rules.json'] = [[
            'platform_code' => 'spotify_v2', 'medium_code' => 'spot_v2', 'is_active' => true,
        ]];
        $files['data/oa_targeting_definitions.json'] = [[
            'code' => 'geo_de', 'revision_number' => 1, 'name' => 'Deutschland', 'category' => 'technisch',
            'tkp_surcharge' => '1.2500', 'minimum_tkp_surcharge' => '0.5000', 'is_active' => true,
            'valid_from' => '2026-01-01', 'valid_to' => null,
        ]];
        $files['data/oa_tkp_price_lists.json'] = [$this->oaList(2026, 1, 'active')];
        $files['data/oa_adserver_surcharges.json'] = [[
            'code' => 'ads_v2', 'revision_number' => 1, 'name' => 'Adserver V2', 'tkp_amount' => '0.2500',
            'is_active' => true, 'scope_json' => ['platforms' => ['spotify_v2']],
            'valid_from' => '2026-01-01', 'valid_to' => null,
        ]];
        $files['data/field_definitions.json'] = [[
            'key' => 'campaign_title', 'field_type' => FieldType::ShortText->value, 'is_system' => false,
            'is_key_protected' => false, 'scope' => FieldScope::Header->value,
            'applies_to' => FieldAppliesTo::Calculation->value, 'is_active' => true, 'current_revision' => 1,
        ]];
        $files['data/field_definition_revisions.json'] = [[
            'field_definition_key' => 'campaign_title', 'revision' => 1, 'label' => 'Kampagnentitel',
            'help_text' => null, 'validation_json' => ['max' => 120], 'group_key' => 'base',
            'sort_default' => 10, 'reportable' => true,
        ]];
        $files['data/field_sets.json'] = [[
            'key' => 'calc_header_v2', 'name' => 'Calc Header V2', 'is_system' => false,
            'applies_to' => FieldAppliesTo::Calculation->value, 'is_assignable' => true, 'active_version' => 1,
        ]];
        $files['data/field_set_versions.json'] = [[
            'field_set_key' => 'calc_header_v2', 'version' => 1, 'status' => FieldSetVersionStatus::Draft->value,
        ]];
        $files['data/field_set_version_fields.json'] = [[
            'field_set_key' => 'calc_header_v2', 'version' => 1, 'field_definition_key' => 'campaign_title',
            'revision' => 1, 'sort' => 10, 'required_override' => true, 'visible_override' => null,
        ]];
        $files['data/field_rules.json'] = [[
            'field_set_key' => 'calc_header_v2', 'version' => 1, 'sort' => 1,
            'condition_json' => ['op' => 'always'], 'action_json' => ['set' => 'visible'],
        ]];

        return $files;
    }

    /**
     * @param  array<string, array<string, mixed>|list<mixed>>  $files
     * @return array<string, mixed>
     */
    protected function manifestFor(array $files): array
    {
        $counts = [];
        foreach ($files as $relative => $payload) {
            $counts[$relative] = $relative === 'data/organization.json' ? 1 : count($payload);
        }

        return [
            'format' => MasterDataTransferContractV2::FORMAT,
            'schema_version' => MasterDataTransferContractV2::SCHEMA_VERSION,
            'epic' => MasterDataTransferContractV2::EPIC,
            'git_sha' => 'synthetic-slice7',
            'exported_at' => '2026-11-26T09:00:00+00:00',
            'source_database' => 'fixture',
            'includes_users' => false,
            'includes_crm' => false,
            'includes_calculations' => false,
            'includes_dispo_orders' => false,
            'includes_online_audio' => true,
            'includes_social_events_barter' => true,
            'price_status_on_apply' => MasterDataTransferContractV2::PRICE_STATUS_ON_APPLY,
            'excluded_tables' => MasterDataTransferContractV2::excludedTablesSorted(),
            'counts' => $counts,
            'source_price_status_summary' => [
                'spot' => ['active' => 1, 'archived' => 0, 'draft' => 0],
                'production' => ['active' => 1, 'archived' => 0, 'draft' => 0],
                'oa_tkp' => ['active' => 1, 'archived' => 0, 'draft' => 0],
            ],
            'files' => [],
        ];
    }
}
