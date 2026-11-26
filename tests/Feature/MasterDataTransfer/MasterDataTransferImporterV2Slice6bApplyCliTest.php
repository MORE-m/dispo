<?php

namespace Tests\Feature\MasterDataTransfer;

use App\Console\Commands\MasterDataTransfer\ImportMasterDataCommand;
use App\Enums\FieldAppliesTo;
use App\Enums\FieldScope;
use App\Enums\FieldSetVersionStatus;
use App\Enums\FieldType;
use App\Enums\InventoryType;
use App\Enums\PriceListStatus;
use App\Enums\Role;
use App\Models\AdvertisingMedium;
use App\Models\Calculation;
use App\Models\CalculationPosition;
use App\Models\FieldDefinition;
use App\Models\Inventory;
use App\Models\Organization;
use App\Models\PriceList;
use App\Models\PriceListItem;
use App\Models\User;
use App\Services\MasterDataTransfer\Import\MasterDataImporterV2;
use App\Services\MasterDataTransfer\MasterDataTransferContractV2;
use App\Services\MasterDataTransfer\MasterDataTransferException;
use App\Services\MasterDataTransfer\Support\MasterDataBackupProofV2;
use App\Services\MasterDataTransfer\Support\MasterDataPackage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * EPIC-03-F Slice 6b: Operativer V2-Inhalts-Apply über CLI inkl. Backup-/SHA-/Allowlist-Gates.
 */
class MasterDataTransferImporterV2Slice6bApplyCliTest extends TestCase
{
    use RefreshDatabase;

    private string $tmp;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tmp = sys_get_temp_dir().'/mdt-v2-slice6b-'.uniqid('', true);
        File::ensureDirectoryExists($this->tmp);
        config([
            'dispo.master_data_transfer_testing_operational_database' => MasterDataTransferContractV2::OPERATIONAL_DATABASE,
        ]);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->tmp);
        FieldDefinition::flushEventListeners();
        Organization::flushEventListeners();
        config(['dispo.master_data_transfer_testing_operational_database' => null]);
        parent::tearDown();
    }

    #[Test]
    public function operational_apply_creates_inventory_media_oa_and_draft_prices(): void
    {
        User::factory()->create(['email' => 'slice6b@example.test', 'role' => Role::Admin, 'is_active' => true]);
        $package = $this->writeRichV2Package();
        $sha = app(MasterDataPackage::class)->computePackageSha256($package);
        $proof = $this->writeValidBackupProof();
        $usersBefore = (int) DB::table('users')->count();

        $exit = Artisan::call('dispo:import-master-data', [
            'path' => $package,
            '--apply' => true,
            '--confirm-database' => MasterDataTransferContractV2::OPERATIONAL_DATABASE,
            '--require-package-sha256' => $sha,
            '--require-backup-proof' => $proof,
        ]);
        $output = Artisan::output();

        $this->assertSame(0, $exit, $output);
        $this->assertStringContainsString('Import Apply OK', $output);
        $this->assertStringContainsString('security.result=PASS', $output);
        $this->assertStringContainsString('backup_proof=pass', $output);
        $this->assertSame(1, Inventory::query()->where('code', 'inv_v2_radio')->count());
        $this->assertSame(1, AdvertisingMedium::query()->where('code', 'spot_v2')->count());
        $list = PriceList::query()->where('year', 2026)->where('revision_number', 1)->first();
        $this->assertNotNull($list);
        $this->assertSame(PriceListStatus::Draft, $list->status);
        $this->assertSame($usersBefore, (int) DB::table('users')->count());
    }

    #[Test]
    public function repeated_operational_apply_is_idempotent(): void
    {
        User::factory()->create(['email' => 'idem6b@example.test', 'role' => Role::Admin, 'is_active' => true]);
        $package = $this->writeRichV2Package();
        $sha = app(MasterDataPackage::class)->computePackageSha256($package);
        $proof = $this->writeValidBackupProof();

        $firstExit = Artisan::call('dispo:import-master-data', [
            'path' => $package,
            '--apply' => true,
            '--confirm-database' => MasterDataTransferContractV2::OPERATIONAL_DATABASE,
            '--require-package-sha256' => $sha,
            '--require-backup-proof' => $proof,
        ]);
        $this->assertSame(0, $firstExit, Artisan::output());
        $afterFirst = $this->captureWritableCounts();

        $secondExit = Artisan::call('dispo:import-master-data', [
            'path' => $package,
            '--apply' => true,
            '--confirm-database' => MasterDataTransferContractV2::OPERATIONAL_DATABASE,
            '--require-package-sha256' => $sha,
            '--require-backup-proof' => $this->writeValidBackupProof('second'),
        ]);
        $output = Artisan::output();
        $this->assertSame(0, $secondExit, $output);
        $this->assertStringContainsString('created=0', $output);
        $this->assertSame($afterFirst, $this->captureWritableCounts());
    }

    #[Test]
    public function dry_run_plan_matches_apply_created_entities(): void
    {
        User::factory()->create(['email' => 'plan6b@example.test', 'role' => Role::Admin, 'is_active' => true]);
        $package = $this->writeRichV2Package();
        $sha = app(MasterDataPackage::class)->computePackageSha256($package);

        $dry = app(MasterDataImporterV2::class)->dryRunOperational(
            $package,
            MasterDataTransferContractV2::OPERATIONAL_DATABASE,
            $sha,
        );
        $this->assertTrue($dry['dry_run']);
        $this->assertSame('PASS', $dry['security']['result']);
        $this->assertGreaterThan(0, $dry['created']);

        $applyExit = Artisan::call('dispo:import-master-data', [
            'path' => $package,
            '--apply' => true,
            '--confirm-database' => MasterDataTransferContractV2::OPERATIONAL_DATABASE,
            '--require-package-sha256' => $sha,
            '--require-backup-proof' => $this->writeValidBackupProof(),
        ]);
        $this->assertSame(0, $applyExit, Artisan::output());

        $this->assertSame(1, Inventory::query()->where('code', 'inv_v2_radio')->count());
        $plannedCreates = collect($dry['rows'])->where('action', 'create')->count();
        $this->assertGreaterThan(0, $plannedCreates);
        $this->assertSame(0, Inventory::query()->where('code', 'inv_v2_radio')->whereNull('created_at')->count());
    }

    #[Test]
    public function wrong_package_hash_stops_before_writes(): void
    {
        User::factory()->create(['email' => 'hash6b@example.test', 'role' => Role::Admin, 'is_active' => true]);
        $package = $this->writeRichV2Package();
        $before = $this->captureWritableCounts();

        $exit = Artisan::call('dispo:import-master-data', [
            'path' => $package,
            '--apply' => true,
            '--confirm-database' => MasterDataTransferContractV2::OPERATIONAL_DATABASE,
            '--require-package-sha256' => str_repeat('a', 64),
            '--require-backup-proof' => $this->writeValidBackupProof(),
        ]);
        $this->assertNotSame(0, $exit);
        $this->assertStringContainsString('Paket-SHA256 stimmt nicht', Artisan::output());
        $this->assertSame($before, $this->captureWritableCounts());
    }

    #[Test]
    public function missing_confirm_database_stops(): void
    {
        $package = $this->writeRichV2Package();
        $sha = app(MasterDataPackage::class)->computePackageSha256($package);
        $exit = Artisan::call('dispo:import-master-data', [
            'path' => $package,
            '--apply' => true,
            '--require-package-sha256' => $sha,
            '--require-backup-proof' => $this->writeValidBackupProof(),
        ]);
        $this->assertNotSame(0, $exit);
        $this->assertStringContainsString('--confirm-database', Artisan::output());
    }

    #[Test]
    public function wrong_confirm_database_stops(): void
    {
        $package = $this->writeRichV2Package();
        $sha = app(MasterDataPackage::class)->computePackageSha256($package);
        $exit = Artisan::call('dispo:import-master-data', [
            'path' => $package,
            '--apply' => true,
            '--confirm-database' => 'dispo_mat_core',
            '--require-package-sha256' => $sha,
            '--require-backup-proof' => $this->writeValidBackupProof(),
        ]);
        $this->assertNotSame(0, $exit);
        $output = Artisan::output();
        $this->assertTrue(
            str_contains($output, '--confirm-database')
            || str_contains($output, 'nur gegen')
            || str_contains($output, 'gesperrt'),
            $output,
        );
    }

    #[Test]
    public function missing_backup_proof_stops_before_writes(): void
    {
        User::factory()->create(['email' => 'nobackup@example.test', 'role' => Role::Admin, 'is_active' => true]);
        $package = $this->writeRichV2Package();
        $sha = app(MasterDataPackage::class)->computePackageSha256($package);
        $before = $this->captureWritableCounts();

        $exit = Artisan::call('dispo:import-master-data', [
            'path' => $package,
            '--apply' => true,
            '--confirm-database' => MasterDataTransferContractV2::OPERATIONAL_DATABASE,
            '--require-package-sha256' => $sha,
        ]);
        $this->assertNotSame(0, $exit);
        $this->assertStringContainsString('--require-backup-proof', Artisan::output());
        $this->assertSame($before, $this->captureWritableCounts());
    }

    #[Test]
    public function invalid_backup_gate_stops(): void
    {
        $package = $this->writeRichV2Package();
        $sha = app(MasterDataPackage::class)->computePackageSha256($package);
        $proof = $this->writeValidBackupProof('fail-gate');
        File::put($proof.DIRECTORY_SEPARATOR.MasterDataBackupProofV2::GATE_FILE, "FAIL\n");

        $exit = Artisan::call('dispo:import-master-data', [
            'path' => $package,
            '--apply' => true,
            '--confirm-database' => MasterDataTransferContractV2::OPERATIONAL_DATABASE,
            '--require-package-sha256' => $sha,
            '--require-backup-proof' => $proof,
        ]);
        $this->assertNotSame(0, $exit);
        $this->assertStringContainsString('BACKUP_GATE.txt muss PASS zeigen', Artisan::output());
    }

    #[Test]
    public function protected_users_crm_and_calculations_remain_unchanged(): void
    {
        $user = User::factory()->create(['email' => 'protect6b@example.test', 'role' => Role::Admin, 'is_active' => true]);
        $org = Organization::query()->create(['name' => 'more Marketing']);
        $inventory = Inventory::query()->create([
            'organization_id' => $org->id,
            'code' => 'inv_radio_hamburg',
            'name' => 'Radio Hamburg',
            'type' => InventoryType::Sender,
            'is_active' => true,
            'sort' => 1,
            'logo_path' => null,
            'lock_version' => 1,
        ]);
        $list = PriceList::query()->create([
            'inventory_id' => $inventory->id,
            'year' => 2026,
            'revision_number' => 1,
            'name' => 'RH 2026',
            'version' => '1',
            'valid_from' => '2026-01-01',
            'status' => PriceListStatus::Active,
            'lock_version' => 1,
        ]);
        PriceListItem::query()->create([
            'price_list_id' => $list->id,
            'hour' => 8,
            'day_group' => 'mo_fr',
            'second_price' => '56.0000',
        ]);
        $calc = Calculation::factory()->create(['advisor_id' => $user->id]);
        $medium = AdvertisingMedium::query()->first() ?? AdvertisingMedium::factory()->create();
        CalculationPosition::factory()->create([
            'calculation_id' => $calc->id,
            'inventory_id' => $inventory->id,
            'advertising_medium_id' => $medium->id,
            'price_list_id' => $list->id,
        ]);

        $usersDigest = $this->tableDigest('users');
        $crmCount = (int) DB::table('crm_accounts')->count();
        $crmDigest = $this->tableDigest('crm_accounts');
        $calcDigest = $this->tableDigest('calculations');
        $posDigest = $this->tableDigest('calculation_positions');
        $listStatus = $list->fresh()->status;

        $package = $this->writeRichV2Package([
            'data/organization.json' => ['name' => $org->name],
            'data/inventories.json' => [
                [
                    'code' => 'inv_radio_hamburg',
                    'name' => 'Radio Hamburg',
                    'type' => InventoryType::Sender->value,
                    'is_active' => true,
                    'sort' => 1,
                    'logo_path' => null,
                ],
                [
                    'code' => 'inv_v2_radio',
                    'name' => 'Radio V2',
                    'type' => InventoryType::Sender->value,
                    'is_active' => true,
                    'sort' => 2,
                    'logo_path' => null,
                ],
            ],
            'data/price_lists.json' => [
                [
                    'inventory_code' => 'inv_radio_hamburg',
                    'year' => 2026,
                    'revision_number' => 1,
                    'name' => 'RH 2026',
                    'version' => '1',
                    'valid_from' => '2026-01-01',
                    'source_status' => 'active',
                    'import_status' => 'draft',
                    'items' => [['hour' => 8, 'day_group' => 'mo_fr', 'second_price' => '56.0000']],
                ],
                [
                    'inventory_code' => 'inv_v2_radio',
                    'year' => 2026,
                    'revision_number' => 1,
                    'name' => 'Spot 2026',
                    'version' => '1',
                    'valid_from' => '2026-01-01',
                    'source_status' => 'active',
                    'import_status' => 'draft',
                    'items' => [['hour' => 8, 'day_group' => 'mo_fr', 'second_price' => '12.5000']],
                ],
            ],
            'data/inventory_medium_rules.json' => [
                [
                    'inventory_code' => 'inv_radio_hamburg',
                    'medium_code' => 'spot_v2',
                    'is_active' => true,
                    'booking_code' => 'RH1',
                    'planning_responsibility_key' => null,
                    'hint_text' => null,
                    'sort' => 1,
                    'default_length_seconds' => 30,
                    'surcharge_percent' => null,
                    'is_discountable' => true,
                    'is_ae_eligible' => true,
                    'component_calculation_strategy' => 'shared_total_length',
                    'swf_allonge_confirmed_at' => null,
                ],
                [
                    'inventory_code' => 'inv_v2_radio',
                    'medium_code' => 'spot_v2',
                    'is_active' => true,
                    'booking_code' => 'BK1',
                    'planning_responsibility_key' => null,
                    'hint_text' => null,
                    'sort' => 2,
                    'default_length_seconds' => 30,
                    'surcharge_percent' => null,
                    'is_discountable' => true,
                    'is_ae_eligible' => true,
                    'component_calculation_strategy' => 'shared_total_length',
                    'swf_allonge_confirmed_at' => null,
                ],
            ],
        ]);
        $sha = app(MasterDataPackage::class)->computePackageSha256($package);

        $exit = Artisan::call('dispo:import-master-data', [
            'path' => $package,
            '--apply' => true,
            '--confirm-database' => MasterDataTransferContractV2::OPERATIONAL_DATABASE,
            '--require-package-sha256' => $sha,
            '--require-backup-proof' => $this->writeValidBackupProof(),
        ]);
        $this->assertSame(0, $exit, Artisan::output());

        $this->assertSame($usersDigest, $this->tableDigest('users'));
        $this->assertSame($crmCount, (int) DB::table('crm_accounts')->count());
        $this->assertSame($crmDigest, $this->tableDigest('crm_accounts'));
        $this->assertSame($calcDigest, $this->tableDigest('calculations'));
        $this->assertSame($posDigest, $this->tableDigest('calculation_positions'));
        $this->assertSame($listStatus, $list->fresh()->status);
    }

    #[Test]
    public function apply_does_not_activate_price_status(): void
    {
        User::factory()->create(['email' => 'draft6b@example.test', 'role' => Role::Admin, 'is_active' => true]);
        $package = $this->writeRichV2Package();
        $sha = app(MasterDataPackage::class)->computePackageSha256($package);

        $this->assertSame(0, Artisan::call('dispo:import-master-data', [
            'path' => $package,
            '--apply' => true,
            '--confirm-database' => MasterDataTransferContractV2::OPERATIONAL_DATABASE,
            '--require-package-sha256' => $sha,
            '--require-backup-proof' => $this->writeValidBackupProof(),
        ]));

        $this->assertSame(0, PriceList::query()->where('status', PriceListStatus::Active)->count());
        $this->assertGreaterThan(0, PriceList::query()->where('status', PriceListStatus::Draft)->count());

        $this->assertNotSame(0, Artisan::call('dispo:import-master-data', [
            'path' => $package,
            '--apply-price-status' => true,
            '--confirm-database' => MasterDataTransferContractV2::OPERATIONAL_DATABASE,
            '--require-package-sha256' => $sha,
            '--require-backup-proof' => $this->writeValidBackupProof(),
        ]));
        $this->assertStringContainsString('Slice 7 fehlt', Artisan::output());
    }

    #[Test]
    public function cli_exposes_backup_proof_option_and_rejects_allowlist_aliases(): void
    {
        $definition = (new ImportMasterDataCommand)->getDefinition();
        $this->assertTrue($definition->hasOption('require-backup-proof'));
        $this->assertFalse($definition->hasOption('allow-database'));

        $package = $this->writeRichV2Package();
        $sha = app(MasterDataPackage::class)->computePackageSha256($package);
        foreach (['dispo_mat_core', 'dispo', 'production', 'staging', 'dispo_staging'] as $forbidden) {
            $exit = Artisan::call('dispo:import-master-data', [
                'path' => $package,
                '--apply' => true,
                '--confirm-database' => $forbidden,
                '--require-package-sha256' => $sha,
                '--require-backup-proof' => $this->writeValidBackupProof($forbidden),
            ]);
            $this->assertNotSame(0, $exit, $forbidden);
        }
    }

    #[Test]
    public function apply_operational_rejects_without_testing_override_on_forbidden_db_name(): void
    {
        config(['dispo.master_data_transfer_testing_operational_database' => null]);
        $package = $this->writeRichV2Package();
        $sha = app(MasterDataPackage::class)->computePackageSha256($package);

        try {
            app(MasterDataImporterV2::class)->applyOperational(
                $package,
                MasterDataTransferContractV2::OPERATIONAL_DATABASE,
                $sha,
                $this->writeValidBackupProof(),
            );
            $this->fail('Apply ohne Testing-Override gegen Nicht-Staging muss STOP');
        } catch (MasterDataTransferException $e) {
            $this->assertTrue(
                str_contains($e->getMessage(), 'nur gegen')
                || str_contains($e->getMessage(), '--confirm-database')
                || str_contains($e->getMessage(), 'MySQL/MariaDB'),
                $e->getMessage(),
            );
        }
    }

    #[Test]
    public function invalid_backup_dump_sha_stops_before_writes(): void
    {
        User::factory()->create(['email' => 'badsha6b@example.test', 'role' => Role::Admin, 'is_active' => true]);
        $package = $this->writeRichV2Package();
        $sha = app(MasterDataPackage::class)->computePackageSha256($package);
        $proof = $this->writeValidBackupProof('bad-dump-sha');
        $metaPath = $proof.DIRECTORY_SEPARATOR.MasterDataBackupProofV2::META_FILE;
        $meta = json_decode((string) File::get($metaPath), true, 32, JSON_THROW_ON_ERROR);
        $meta['dump_sha256'] = str_repeat('b', 64);
        File::put($metaPath, json_encode($meta, JSON_THROW_ON_ERROR));
        $before = $this->captureWritableCounts();

        $exit = Artisan::call('dispo:import-master-data', [
            'path' => $package,
            '--apply' => true,
            '--confirm-database' => MasterDataTransferContractV2::OPERATIONAL_DATABASE,
            '--require-package-sha256' => $sha,
            '--require-backup-proof' => $proof,
        ]);
        $this->assertNotSame(0, $exit);
        $this->assertStringContainsString('Dump-SHA256 stimmt nicht', Artisan::output());
        $this->assertSame($before, $this->captureWritableCounts());
    }

    #[Test]
    public function package_with_excluded_users_payload_is_rejected(): void
    {
        User::factory()->create(['email' => 'excl6b@example.test', 'role' => Role::Admin, 'is_active' => true]);
        $package = $this->writeRichV2Package();
        $validSha = app(MasterDataPackage::class)->computePackageSha256($package);
        File::put($package.'/data/users.json', json_encode([
            ['email' => 'evil@example.test', 'role' => 'admin'],
        ], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));
        $before = $this->captureWritableCounts();

        $rejectedAtPackageLoad = false;
        try {
            app(MasterDataPackage::class)->computePackageSha256($package);
        } catch (MasterDataTransferException $e) {
            $rejectedAtPackageLoad = true;
            $this->assertStringContainsString('unzulässige', $e->getMessage());
        }

        if (! $rejectedAtPackageLoad) {
            $exit = Artisan::call('dispo:import-master-data', [
                'path' => $package,
                '--apply' => true,
                '--confirm-database' => MasterDataTransferContractV2::OPERATIONAL_DATABASE,
                '--require-package-sha256' => $validSha,
                '--require-backup-proof' => $this->writeValidBackupProof('excl'),
            ]);
            $this->assertNotSame(0, $exit);
            $output = Artisan::output();
            $this->assertTrue(
                str_contains($output, 'unzulässige')
                || str_contains($output, 'SHA256')
                || str_contains($output, 'users'),
                $output,
            );
        }

        $this->assertSame($before, $this->captureWritableCounts());
    }

    private function writeValidBackupProof(string $suffix = 'ok'): string
    {
        $dir = $this->tmp.'/proof-'.$suffix.'-'.uniqid('', true);
        File::ensureDirectoryExists($dir);
        $dumpName = 'pre-apply-dump.sql';
        $dumpPath = $dir.DIRECTORY_SEPARATOR.$dumpName;
        $payload = "-- synthetic staging backup proof dump\n".$suffix."\n".bin2hex(random_bytes(16))."\n";
        File::put($dumpPath, $payload);
        $sha = hash_file('sha256', $dumpPath);
        $bytes = filesize($dumpPath);
        File::put($dir.DIRECTORY_SEPARATOR.MasterDataBackupProofV2::GATE_FILE, "PASS\n");
        File::put(
            $dir.DIRECTORY_SEPARATOR.MasterDataBackupProofV2::META_FILE,
            json_encode([
                'dump_filename' => $dumpName,
                'dump_sha256' => $sha,
                'dump_bytes' => $bytes,
                'restore_path' => 'same_db_import_verified',
            ], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT)."\n",
        );

        return $dir;
    }

    /**
     * @return array<string, int>
     */
    private function captureWritableCounts(): array
    {
        $counts = [];
        foreach (MasterDataTransferContractV2::WRITE_WHITELIST_TABLES as $table) {
            $counts[$table] = (int) DB::table($table)->count();
        }
        $counts['users'] = (int) DB::table('users')->count();
        $counts['crm_accounts'] = (int) DB::table('crm_accounts')->count();
        $counts['calculations'] = (int) DB::table('calculations')->count();

        return $counts;
    }

    private function tableDigest(string $table): string
    {
        $rows = DB::table($table)->orderBy('id')->get()->map(fn ($row) => (array) $row)->all();

        return hash('sha256', json_encode($rows, JSON_THROW_ON_ERROR));
    }

    /**
     * @param  array<string, array<string, mixed>|list<mixed>>  $overrides
     */
    private function writeRichV2Package(array $overrides = []): string
    {
        $path = $this->tmp.'/v2-rich-'.uniqid('', true);
        $files = array_replace($this->richPayload(), $overrides);
        app(MasterDataPackage::class)->write($path, $this->v2Manifest($files), $files);

        return $path;
    }

    /**
     * @return array<string, array<string, mixed>|list<mixed>>
     */
    private function richPayload(): array
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
        $files['data/field_definition_revision_options.json'] = [];
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
        $files['data/field_set_assignments.json'] = [];

        return $files;
    }

    /**
     * @param  array<string, array<string, mixed>|list<mixed>>  $files
     * @return array<string, mixed>
     */
    private function v2Manifest(array $files): array
    {
        $counts = [];
        foreach ($files as $relative => $payload) {
            $counts[$relative] = $relative === 'data/organization.json' ? 1 : count($payload);
        }

        return [
            'format' => MasterDataTransferContractV2::FORMAT,
            'schema_version' => MasterDataTransferContractV2::SCHEMA_VERSION,
            'epic' => MasterDataTransferContractV2::EPIC,
            'git_sha' => 'synthetic-slice6b',
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
                'production' => ['active' => 0, 'archived' => 0, 'draft' => 1],
                'oa_tkp' => ['active' => 1, 'archived' => 0, 'draft' => 0],
            ],
            'files' => [],
        ];
    }
}
