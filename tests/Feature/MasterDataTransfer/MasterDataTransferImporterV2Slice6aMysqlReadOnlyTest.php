<?php

namespace Tests\Feature\MasterDataTransfer;

use App\Enums\FieldAppliesTo;
use App\Enums\FieldScope;
use App\Enums\FieldSetVersionStatus;
use App\Enums\FieldType;
use App\Enums\InventoryType;
use App\Enums\Role;
use App\Models\User;
use App\Services\MasterDataTransfer\Import\MasterDataImporterV2;
use App\Services\MasterDataTransfer\MasterDataTransferContractV2;
use App\Services\MasterDataTransfer\MasterDataTransferException;
use App\Services\MasterDataTransfer\Support\MasterDataOperationalDatabaseGuardV2;
use App\Services\MasterDataTransfer\Support\MasterDataOperationalDryRunReadGuardV2;
use App\Services\MasterDataTransfer\Support\MasterDataPackage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * EPIC-03-F Slice 6a: MySQL-spezifische Read-only- und Verbindungsnachweise.
 *
 * @group mysql
 */
class MasterDataTransferImporterV2Slice6aMysqlReadOnlyTest extends TestCase
{
    use RefreshDatabase;

    private string $tmp;

    protected function setUp(): void
    {
        parent::setUp();
        if (DB::connection()->getDriverName() !== 'mysql') {
            $this->markTestSkipped('MySQL-only Slice-6a Read-only-Nachweis');
        }

        $this->tmp = sys_get_temp_dir().'/mdt-v2-slice6a-mysql-'.uniqid('', true);
        File::ensureDirectoryExists($this->tmp);
        config([
            'dispo.master_data_transfer_testing_operational_database' => MasterDataTransferContractV2::OPERATIONAL_DATABASE,
        ]);
    }

    protected function tearDown(): void
    {
        if (isset($this->tmp)) {
            File::deleteDirectory($this->tmp);
        }
        config(['dispo.master_data_transfer_testing_operational_database' => null]);
        parent::tearDown();
    }

    #[Test]
    public function mysql_select_database_must_match_config_when_override_disabled(): void
    {
        config(['dispo.master_data_transfer_testing_operational_database' => null]);
        $guard = new MasterDataOperationalDatabaseGuardV2;
        $connection = (string) config('database.default');
        $original = config('database.connections.'.$connection.'.database');

        // Config behauptet Staging, echte Verbindung bleibt dispo_test.
        config([
            'database.connections.'.$connection.'.database' => MasterDataTransferContractV2::OPERATIONAL_DATABASE,
        ]);

        try {
            $guard->assertDryRunAllowed(MasterDataTransferContractV2::OPERATIONAL_DATABASE);
            $this->fail('Config/Actual-Mismatch muss STOP auslösen');
        } catch (MasterDataTransferException $e) {
            $this->assertTrue(
                str_contains($e->getMessage(), 'weicht von tatsächlicher')
                || str_contains($e->getMessage(), 'nur gegen '),
                $e->getMessage(),
            );
        } finally {
            config(['database.connections.'.$connection.'.database' => $original]);
        }
    }

    #[Test]
    public function intentional_mysql_write_during_dry_run_is_blocked(): void
    {
        $user = User::factory()->create([
            'email' => 'mysql-write@example.test',
            'name' => 'Original Mysql',
            'role' => Role::Admin,
            'is_active' => true,
        ]);
        $package = $this->writeRichV2Package();
        $sha = app(MasterDataPackage::class)->computePackageSha256($package);

        User::retrieved(function () use ($user): void {
            static $attempted = false;
            if ($attempted) {
                return;
            }
            $attempted = true;
            DB::table('users')->where('id', $user->id)->update(['name' => 'Mutated Mysql']);
        });

        try {
            app(MasterDataImporterV2::class)->dryRunOperational(
                $package,
                MasterDataTransferContractV2::OPERATIONAL_DATABASE,
                $sha,
            );
            $this->fail('MySQL-Schreibversuch im Dry-Run muss abbrechen');
        } catch (MasterDataTransferException|\Throwable $e) {
            $blob = $e->getMessage();
            $this->assertTrue(
                str_contains($blob, 'Read-only')
                || str_contains($blob, 'read only')
                || str_contains($blob, 'READONLY')
                || str_contains($blob, 'Schreibversuch')
                || str_contains($blob, 'Cannot execute'),
                $blob,
            );
        } finally {
            User::flushEventListeners();
        }

        $this->assertSame('Original Mysql', $user->fresh()->name);
    }

    #[Test]
    public function read_guard_cleans_up_after_success_and_failure(): void
    {
        $guard = new MasterDataOperationalDryRunReadGuardV2;
        $guard->enter();
        $this->assertTrue($guard->isActive());
        $guard->leave();
        $this->assertFalse($guard->isActive());

        $guard->enter();
        try {
            throw new MasterDataTransferException('simulierter Fehler');
        } catch (MasterDataTransferException) {
            $guard->leave();
        }
        $this->assertFalse($guard->isActive());
    }

    /**
     * @return array<string, array<string, mixed>|list<mixed>>
     */
    private function richPayload(): array
    {
        $files = [];
        foreach (MasterDataTransferContractV2::DATA_FILES as $relative) {
            $files[$relative] = $relative === 'data/organization.json'
                ? ['name' => 'Fixture Org V2 Mysql']
                : [];
        }
        $files['data/calculation_methods.json'] = [[
            'key' => 'avg_v2', 'name' => 'Durchschnitt V2', 'help_text' => null, 'is_active' => true, 'sort' => 1,
        ]];
        $files['data/advertising_categories.json'] = [[
            'key' => 'spots_v2', 'name' => 'Spots V2', 'is_active' => true, 'sort' => 10,
            'default_calculation_method_key' => 'avg_v2',
        ]];
        $files['data/advertising_media.json'] = [[
            'code' => 'spot_v2', 'name' => 'Spot V2', 'category_key' => 'spots_v2', 'kind' => 'spot_classic',
            'component_profile' => null, 'calculation_method_mode' => 'inherit',
            'default_calculation_method_key' => null, 'default_length_seconds' => 30,
            'is_discountable' => true, 'is_ae_eligible' => true, 'is_active' => true, 'sort' => 10,
        ]];
        $files['data/advertising_category_calculation_methods.json'] = [[
            'category_key' => 'spots_v2', 'method_key' => 'avg_v2', 'engine_profile_key' => 'default',
            'is_active' => true, 'sort' => 1,
        ]];
        $files['data/advertising_medium_calculation_methods.json'] = [[
            'medium_code' => 'spot_v2', 'method_key' => 'avg_v2', 'engine_profile_key' => 'default',
            'is_active' => true, 'sort' => 1,
        ]];
        $files['data/inventories.json'] = [[
            'code' => 'inv_v2_radio', 'name' => 'Radio V2', 'type' => InventoryType::Sender->value,
            'is_active' => true, 'sort' => 1, 'logo_path' => null,
        ]];
        $files['data/inventory_medium_rules.json'] = [[
            'inventory_code' => 'inv_v2_radio', 'medium_code' => 'spot_v2', 'is_active' => true,
            'booking_code' => 'BK1', 'planning_responsibility_key' => null, 'hint_text' => null, 'sort' => 1,
            'default_length_seconds' => 30, 'surcharge_percent' => null, 'is_discountable' => true,
            'is_ae_eligible' => true, 'component_calculation_strategy' => 'shared_total_length',
            'swf_allonge_confirmed_at' => null,
        ]];
        $files['data/price_lists.json'] = [[
            'inventory_code' => 'inv_v2_radio', 'year' => 2026, 'revision_number' => 1, 'name' => 'Spot 2026',
            'version' => '1', 'valid_from' => '2026-01-01', 'source_status' => 'active', 'import_status' => 'draft',
            'items' => [['hour' => 8, 'day_group' => 'mo_fr', 'second_price' => '12.5000']],
        ]];
        $files['data/production_price_lists.json'] = [[
            'inventory_code' => 'inv_v2_radio', 'production_type' => 'spot_production', 'year' => 2026,
            'revision_number' => 1, 'name' => 'Prod 2026', 'version' => '1', 'source_status' => 'draft',
            'import_status' => 'draft', 'unit_price' => '150.00', 'is_discountable' => false, 'is_ae_eligible' => false,
        ]];
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
        $files['data/oa_tkp_price_lists.json'] = [[
            'year' => 2026, 'revision_number' => 1, 'name' => 'OA TKP 2026', 'version' => '1',
            'valid_from' => '2026-01-01', 'source_status' => 'active', 'import_status' => 'draft',
            'items' => [[
                'inventory_code' => 'inv_v2_radio', 'medium_code' => 'spot_v2',
                'list_tkp' => '8.0000', 'minimum_tkp' => '6.0000',
            ]],
        ]];
        $files['data/oa_adserver_surcharges.json'] = [];
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

    private function writeRichV2Package(): string
    {
        $path = $this->tmp.'/v2-rich-'.uniqid('', true);
        $files = $this->richPayload();
        $counts = [];
        foreach ($files as $relative => $payload) {
            $counts[$relative] = $relative === 'data/organization.json' ? 1 : count($payload);
        }
        $manifest = [
            'format' => MasterDataTransferContractV2::FORMAT,
            'schema_version' => MasterDataTransferContractV2::SCHEMA_VERSION,
            'epic' => MasterDataTransferContractV2::EPIC,
            'git_sha' => 'synthetic-slice6a-mysql',
            'exported_at' => '2026-11-25T08:00:00+00:00',
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
                'production' => ['active' => 0, 'archived' => 0, 'draft' => 1],
                'oa_tkp' => ['active' => 1, 'archived' => 0, 'draft' => 0],
            ],
            'files' => [],
        ];
        app(MasterDataPackage::class)->write($path, $manifest, $files);

        return $path;
    }
}
