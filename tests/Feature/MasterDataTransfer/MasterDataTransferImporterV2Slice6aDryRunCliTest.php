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
use App\Models\AdvertisingCategory;
use App\Models\AdvertisingMedium;
use App\Models\Calculation;
use App\Models\CalculationMethod;
use App\Models\CalculationPosition;
use App\Models\FieldDefinition;
use App\Models\Inventory;
use App\Models\Organization;
use App\Models\PriceList;
use App\Models\PriceListItem;
use App\Models\User;
use App\Services\MasterDataTransfer\Import\MasterDataImporter;
use App\Services\MasterDataTransfer\Import\MasterDataImporterV2;
use App\Services\MasterDataTransfer\MasterDataTransferContract;
use App\Services\MasterDataTransfer\MasterDataTransferContractV2;
use App\Services\MasterDataTransfer\MasterDataTransferException;
use App\Services\MasterDataTransfer\Support\MasterDataOperationalDatabaseGuardV2;
use App\Services\MasterDataTransfer\Support\MasterDataPackage;
use Database\Seeders\InitialCatalogMatCoreSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * EPIC-03-F Slice 6a: Operativer schreibfreier V2-Dry-Run über CLI.
 */
class MasterDataTransferImporterV2Slice6aDryRunCliTest extends TestCase
{
    use RefreshDatabase;

    private string $tmp;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tmp = sys_get_temp_dir().'/mdt-v2-slice6a-'.uniqid('', true);
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
    public function operational_dry_run_plans_new_inventory_media_oa_and_prices_without_writes(): void
    {
        User::factory()->create(['email' => 'slice6a@example.test', 'role' => Role::Admin, 'is_active' => true]);
        $package = $this->writeRichV2Package();
        $sha = app(MasterDataPackage::class)->computePackageSha256($package);
        $before = $this->captureWritableCounts();

        $exit = Artisan::call('dispo:import-master-data', [
            'path' => $package,
            '--dry-run' => true,
            '--confirm-database' => MasterDataTransferContractV2::OPERATIONAL_DATABASE,
            '--require-package-sha256' => $sha,
        ]);
        $output = Artisan::output();

        $this->assertSame(0, $exit, $output);
        $this->assertStringContainsString('Dry-Run OK', $output);
        $this->assertStringContainsString('security.result=PASS', $output);
        $this->assertStringContainsString('package_sha256='.$sha, $output);
        $this->assertSame($before, $this->captureWritableCounts());
        $this->assertSame(0, Inventory::query()->where('code', 'inv_v2_radio')->count());
        $this->assertSame(0, PriceList::query()->count());
    }

    #[Test]
    public function operational_dry_run_keeps_existing_rh_price_list_and_pins_unchanged(): void
    {
        $user = User::factory()->create(['email' => 'rh-pin@example.test', 'role' => Role::Admin, 'is_active' => true]);
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

        $pinBefore = [
            'list_id' => $list->id,
            'version' => $list->version,
            'status' => $list->status->value,
            'items' => PriceListItem::query()->where('price_list_id', $list->id)->count(),
        ];

        $report = app(MasterDataImporterV2::class)->dryRunOperational(
            $package,
            MasterDataTransferContractV2::OPERATIONAL_DATABASE,
            $sha,
        );

        $this->assertSame('PASS', $report['security']['result']);
        $this->assertSame('pass', $report['security']['price_list_pins']);
        $list->refresh();
        $this->assertSame($pinBefore['list_id'], $list->id);
        $this->assertSame($pinBefore['version'], $list->version);
        $this->assertSame($pinBefore['status'], $list->status->value);
        $this->assertSame($pinBefore['items'], PriceListItem::query()->where('price_list_id', $list->id)->count());
    }

    #[Test]
    public function fachkonflikt_stops_dry_run_without_writes(): void
    {
        User::factory()->create(['email' => 'conflict@example.test', 'role' => Role::Admin, 'is_active' => true]);
        $org = Organization::query()->first() ?? Organization::factory()->create(['name' => 'more Marketing']);
        Inventory::query()->create([
            'organization_id' => $org->id,
            'code' => 'inv_v2_radio',
            'name' => 'Andere Bezeichnung',
            'type' => InventoryType::Sender,
            'is_active' => true,
            'sort' => 1,
            'logo_path' => null,
        ]);
        $package = $this->writeRichV2Package();
        $sha = app(MasterDataPackage::class)->computePackageSha256($package);
        $before = Inventory::query()->count();

        $exit = Artisan::call('dispo:import-master-data', [
            'path' => $package,
            '--dry-run' => true,
            '--confirm-database' => MasterDataTransferContractV2::OPERATIONAL_DATABASE,
            '--require-package-sha256' => $sha,
        ]);

        $this->assertNotSame(0, $exit);
        $this->assertStringContainsString('Konflikt', Artisan::output());
        $this->assertSame($before, Inventory::query()->count());
    }

    #[Test]
    public function wrong_database_and_confirm_and_digest_fail_closed(): void
    {
        User::factory()->create(['email' => 'gates@example.test', 'role' => Role::Admin, 'is_active' => true]);
        $package = $this->writeRichV2Package();
        $sha = app(MasterDataPackage::class)->computePackageSha256($package);

        config(['dispo.master_data_transfer_testing_operational_database' => 'dispo_mat_core']);
        $this->assertNotSame(0, Artisan::call('dispo:import-master-data', [
            'path' => $package,
            '--dry-run' => true,
            '--confirm-database' => 'dispo_mat_core',
            '--require-package-sha256' => $sha,
        ]));
        $this->assertTrue(
            str_contains(Artisan::output(), 'Test-Override muss exakt')
            || str_contains(Artisan::output(), 'nur gegen '.MasterDataTransferContractV2::OPERATIONAL_DATABASE),
            Artisan::output(),
        );

        config(['dispo.master_data_transfer_testing_operational_database' => MasterDataTransferContractV2::OPERATIONAL_DATABASE]);
        $this->assertNotSame(0, Artisan::call('dispo:import-master-data', [
            'path' => $package,
            '--dry-run' => true,
            '--confirm-database' => 'wrong_db',
            '--require-package-sha256' => $sha,
        ]));
        $this->assertStringContainsString('--confirm-database muss exakt', Artisan::output());

        $this->assertNotSame(0, Artisan::call('dispo:import-master-data', [
            'path' => $package,
            '--dry-run' => true,
            '--confirm-database' => MasterDataTransferContractV2::OPERATIONAL_DATABASE,
            '--require-package-sha256' => str_repeat('a', 64),
        ]));
        $this->assertStringContainsString('Paket-SHA256 stimmt nicht', Artisan::output());

        $this->assertNotSame(0, Artisan::call('dispo:import-master-data', [
            'path' => $package,
            '--dry-run' => true,
            '--confirm-database' => MasterDataTransferContractV2::OPERATIONAL_DATABASE,
        ]));
        $this->assertStringContainsString('--require-package-sha256 ist Pflicht', Artisan::output());
    }

    #[Test]
    public function config_staging_without_matching_live_connection_fails_closed(): void
    {
        User::factory()->create(['email' => 'cfg-mismatch@example.test', 'role' => Role::Admin, 'is_active' => true]);
        $package = $this->writeRichV2Package();
        $sha = app(MasterDataPackage::class)->computePackageSha256($package);

        config(['dispo.master_data_transfer_testing_operational_database' => null]);
        $connection = (string) config('database.default');
        $original = config('database.connections.'.$connection.'.database');
        config([
            'database.connections.'.$connection.'.database' => MasterDataTransferContractV2::OPERATIONAL_DATABASE,
        ]);

        try {
            $this->assertNotSame(0, Artisan::call('dispo:import-master-data', [
                'path' => $package,
                '--dry-run' => true,
                '--confirm-database' => MasterDataTransferContractV2::OPERATIONAL_DATABASE,
                '--require-package-sha256' => $sha,
            ]));
            $out = Artisan::output();
            $this->assertTrue(
                str_contains($out, 'MySQL/MariaDB')
                || str_contains($out, 'weicht von tatsächlicher')
                || str_contains($out, 'SELECT DATABASE()'),
                $out,
            );
        } finally {
            config(['database.connections.'.$connection.'.database' => $original]);
        }
    }

    #[Test]
    public function testing_override_on_non_isolated_database_is_rejected(): void
    {
        $guard = app(MasterDataOperationalDatabaseGuardV2::class);
        $connection = (string) config('database.default');
        $original = config('database.connections.'.$connection.'.database');
        config([
            'dispo.master_data_transfer_testing_operational_database' => MasterDataTransferContractV2::OPERATIONAL_DATABASE,
            'database.connections.'.$connection.'.database' => 'dispo_mat_core',
        ]);

        try {
            $guard->assertDryRunAllowed(MasterDataTransferContractV2::OPERATIONAL_DATABASE);
            $this->fail('Override auf nicht isolierter DB muss STOP auslösen');
        } catch (MasterDataTransferException $e) {
            $this->assertStringContainsString('isolierter Testdatenbank', $e->getMessage());
        } finally {
            config([
                'database.connections.'.$connection.'.database' => $original,
                'dispo.master_data_transfer_testing_operational_database' => MasterDataTransferContractV2::OPERATIONAL_DATABASE,
            ]);
        }
    }

    #[Test]
    public function intentional_write_during_operational_dry_run_is_blocked_without_durable_change(): void
    {
        $user = User::factory()->create([
            'email' => 'write-block@example.test',
            'name' => 'Original',
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
            DB::table('users')->where('id', $user->id)->update(['name' => 'Mutated By DryRun']);
        });

        try {
            app(MasterDataImporterV2::class)->dryRunOperational(
                $package,
                MasterDataTransferContractV2::OPERATIONAL_DATABASE,
                $sha,
            );
            $this->fail('Schreibversuch im Dry-Run muss abbrechen');
        } catch (MasterDataTransferException|\Throwable $e) {
            $blob = $e->getMessage();
            $this->assertTrue(
                str_contains($blob, 'readonly')
                || str_contains($blob, 'read-only')
                || str_contains($blob, 'Read-only')
                || str_contains($blob, 'Schreib')
                || str_contains($blob, 'query_only')
                || str_contains($blob, 'attempt to write'),
                $blob,
            );
        } finally {
            User::flushEventListeners();
        }

        $this->assertSame('Original', $user->fresh()->name);
    }

    #[Test]
    public function report_persist_failure_returns_nonzero_without_dry_run_ok(): void
    {
        User::factory()->create(['email' => 'report-fail@example.test', 'role' => Role::Admin, 'is_active' => true]);
        $package = $this->writeRichV2Package();
        $sha = app(MasterDataPackage::class)->computePackageSha256($package);

        $logs = storage_path('logs');
        File::ensureDirectoryExists($logs);
        $previous = fileperms($logs);
        chmod($logs, 0555);

        try {
            $exit = Artisan::call('dispo:import-master-data', [
                'path' => $package,
                '--dry-run' => true,
                '--confirm-database' => MasterDataTransferContractV2::OPERATIONAL_DATABASE,
                '--require-package-sha256' => $sha,
            ]);
            $output = Artisan::output();
            $this->assertNotSame(0, $exit);
            $this->assertStringNotContainsString('Dry-Run OK', $output);
            $this->assertTrue(
                str_contains($output, 'nicht beschreibbar')
                || str_contains($output, 'nicht geschrieben')
                || str_contains($output, 'Report'),
                $output,
            );
        } finally {
            chmod($logs, $previous ?: 0755);
        }
    }

    #[Test]
    public function fachkonflikt_writes_sanitized_stop_report(): void
    {
        User::factory()->create(['email' => 'conflict-report@example.test', 'role' => Role::Admin, 'is_active' => true]);
        $org = Organization::query()->create(['name' => 'more Marketing']);
        Inventory::query()->create([
            'organization_id' => $org->id,
            'code' => 'inv_v2_radio',
            'name' => 'Andere Bezeichnung',
            'type' => InventoryType::Sender,
            'is_active' => true,
            'sort' => 1,
            'logo_path' => null,
            'lock_version' => 1,
        ]);
        $package = $this->writeRichV2Package();
        $sha = app(MasterDataPackage::class)->computePackageSha256($package);

        $exit = Artisan::call('dispo:import-master-data', [
            'path' => $package,
            '--dry-run' => true,
            '--confirm-database' => MasterDataTransferContractV2::OPERATIONAL_DATABASE,
            '--require-package-sha256' => $sha,
        ]);
        $this->assertNotSame(0, $exit);
        $output = Artisan::output();
        $this->assertStringContainsString('Report:', $output);
        $this->assertMatchesRegularExpression('/Report: (.+master-data-import-report-.+\.json)/', $output, $output);
        preg_match('/Report: (.+master-data-import-report-.+\.json)/', $output, $m);
        $reportPath = trim($m[1]);
        $this->assertFileExists($reportPath);

        /** @var array<string, mixed> $payload */
        $payload = json_decode((string) File::get($reportPath), true);
        $this->assertSame('STOP', $payload['security']['result'] ?? null);
        $this->assertIsArray($payload['conflicts'] ?? null);
        $this->assertArrayNotHasKey('password', $payload);
        $this->assertSame([], $payload['rows'] ?? null);
    }

    #[Test]
    public function package_change_during_dry_run_is_detected(): void
    {
        User::factory()->create(['email' => 'drift@example.test', 'role' => Role::Admin, 'is_active' => true]);
        $package = $this->writeRichV2Package();
        $sha = app(MasterDataPackage::class)->computePackageSha256($package);

        User::retrieved(function () use ($package): void {
            static $tampered = false;
            if ($tampered) {
                return;
            }
            $tampered = true;

            $payload = [[
                'code' => 'inv_v2_radio',
                'name' => 'Radio V2 TAMPERED',
                'type' => InventoryType::Sender->value,
                'is_active' => true,
                'sort' => 1,
                'logo_path' => null,
            ]];
            $json = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)."\n";
            File::put($package.'/data/inventories.json', $json);
            $hash = hash('sha256', $json);

            $checksumPath = $package.'/checksums.sha256';
            $lines = file($checksumPath, FILE_IGNORE_NEW_LINES) ?: [];
            $rewritten = [];
            foreach ($lines as $line) {
                if (str_ends_with($line, '  data/inventories.json')) {
                    $rewritten[] = $hash.'  data/inventories.json';
                } else {
                    $rewritten[] = $line;
                }
            }
            File::put($checksumPath, implode("\n", $rewritten)."\n");

            $manifestPath = $package.'/manifest.json';
            /** @var array<string, mixed> $manifest */
            $manifest = json_decode((string) File::get($manifestPath), true);
            $manifest['files']['data/inventories.json'] = [
                'sha256' => $hash,
                'bytes' => strlen($json),
            ];
            File::put(
                $manifestPath,
                json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)."\n",
            );
        });

        try {
            app(MasterDataImporterV2::class)->dryRunOperational(
                $package,
                MasterDataTransferContractV2::OPERATIONAL_DATABASE,
                $sha,
            );
            $this->fail('SHA256-Drift muss STOP auslösen');
        } catch (MasterDataTransferException $e) {
            $this->assertTrue(
                str_contains($e->getMessage(), 'SHA256-Drift')
                || str_contains($e->getMessage(), 'Paketintegrität')
                || str_contains($e->getMessage(), 'Paket-SHA256'),
                $e->getMessage(),
            );
        } finally {
            User::flushEventListeners();
        }
    }

    #[Test]
    public function v2_apply_and_price_status_remain_blocked(): void
    {
        $package = $this->writeRichV2Package();
        $sha = app(MasterDataPackage::class)->computePackageSha256($package);

        $this->assertNotSame(0, Artisan::call('dispo:import-master-data', [
            'path' => $package,
            '--apply' => true,
            '--confirm-database' => MasterDataTransferContractV2::OPERATIONAL_DATABASE,
            '--require-package-sha256' => $sha,
        ]));
        $this->assertStringContainsString('Slice 6b fehlt', Artisan::output());

        $this->assertNotSame(0, Artisan::call('dispo:import-master-data', [
            'path' => $package,
            '--apply-price-status' => true,
            '--confirm-database' => MasterDataTransferContractV2::OPERATIONAL_DATABASE,
            '--require-package-sha256' => $sha,
        ]));
        $this->assertStringContainsString('Slice 7 fehlt', Artisan::output());
    }

    #[Test]
    public function isolated_test_guard_still_blocks_non_testing_style_apply_path(): void
    {
        User::factory()->create(['email' => 'iso@example.test', 'role' => Role::Admin, 'is_active' => true]);
        $package = $this->writeRichV2Package();

        // Operational dry-run must not call isolated apply.
        try {
            app(MasterDataImporterV2::class)->import($package, dryRun: false, confirmDatabase: MasterDataTransferContractV2::OPERATIONAL_DATABASE);
            $this->fail('Apply muss gesperrt bleiben');
        } catch (MasterDataTransferException $e) {
            $this->assertStringContainsString('Slice 6b fehlt', $e->getMessage());
        }
    }

    #[Test]
    public function v1_regression_dry_run_and_cli_still_work(): void
    {
        $this->seed(InitialCatalogMatCoreSeeder::class);
        $path = $this->tmp.'/v1-'.uniqid('', true);
        $method = CalculationMethod::query()->where('key', 'average')->firstOrFail();
        $category = AdvertisingCategory::query()->where('key', 'spots')->firstOrFail();
        $medium = AdvertisingMedium::query()->where('code', 'spot_classic')->firstOrFail();
        $inventory = Inventory::query()->where('code', 'inv_radio_hamburg')->firstOrFail();
        $files = [
            'data/organization.json' => ['name' => Organization::query()->orderBy('id')->value('name') ?? 'more Marketing'],
            'data/advertising_categories.json' => [[
                'key' => 'spots',
                'name' => $category->name,
                'is_active' => (bool) $category->is_active,
                'sort' => (int) $category->sort,
                'default_calculation_method_key' => 'average',
            ]],
            'data/calculation_methods.json' => [[
                'key' => 'average',
                'name' => $method->name,
                'help_text' => $method->help_text,
                'is_active' => (bool) $method->is_active,
                'sort' => (int) $method->sort,
            ]],
            'data/inventories.json' => [[
                'code' => 'inv_radio_hamburg',
                'name' => $inventory->name,
                'type' => $inventory->type->value,
                'is_active' => (bool) $inventory->is_active,
                'sort' => (int) $inventory->sort,
            ]],
            'data/advertising_media.json' => [[
                'code' => 'spot_classic',
                'name' => $medium->name,
                'category_key' => 'spots',
                'kind' => $medium->kind?->value,
                'component_profile' => $medium->component_profile?->value,
                'calculation_method_mode' => $medium->calculation_method_mode->value,
                'default_calculation_method_key' => null,
                'default_length_seconds' => (int) $medium->default_length_seconds,
                'is_discountable' => (bool) $medium->is_discountable,
                'is_ae_eligible' => (bool) $medium->is_ae_eligible,
                'is_active' => (bool) $medium->is_active,
                'sort' => (int) $medium->sort,
            ]],
            'data/inventory_medium_rules.json' => [],
            'data/advertising_category_calculation_methods.json' => [],
            'data/advertising_medium_calculation_methods.json' => [],
            'data/price_lists.json' => [],
            'data/production_price_lists.json' => [],
        ];
        $manifest = [
            'format' => MasterDataTransferContract::FORMAT,
            'schema_version' => MasterDataTransferContract::SCHEMA_VERSION,
            'epic' => MasterDataTransferContract::EPIC,
            'exported_at' => '2026-11-25T08:00:00+00:00',
            'source' => ['database' => 'fixture', 'connection' => 'sqlite', 'git_sha' => null],
            'scope' => [
                'inventory_codes' => ['inv_radio_hamburg'],
                'price_years' => MasterDataTransferContract::PRICE_YEARS,
                'includes_online_audio' => false,
                'price_lists_export_as_draft' => true,
            ],
            'files' => [],
        ];
        app(MasterDataPackage::class)->write($path, $manifest, $files);
        $db = (string) config('database.connections.'.config('database.default').'.database');

        $report = app(MasterDataImporter::class)->import($path, dryRun: true, confirmDatabase: $db);
        $this->assertTrue($report['dry_run']);

        $this->assertSame(0, Artisan::call('dispo:import-master-data', [
            'path' => $path,
            '--dry-run' => true,
            '--confirm-database' => $db,
        ]));
        $this->assertStringContainsString('Dry-Run OK', Artisan::output());
    }

    #[Test]
    public function allowlist_cannot_be_overridden_via_cli_option(): void
    {
        $definition = (new ImportMasterDataCommand)->getDefinition();
        $this->assertFalse($definition->hasOption('allow-database'));
        $this->assertFalse($definition->hasOption('operational-database'));
        $this->assertTrue($definition->hasOption('require-package-sha256'));
    }

    /**
     * @return array<string, int>
     */
    private function captureWritableCounts(): array
    {
        $tables = MasterDataTransferContractV2::WRITE_WHITELIST_TABLES;
        $counts = [];
        foreach ($tables as $table) {
            $counts[$table] = (int) DB::table($table)->count();
        }
        $counts['users'] = (int) DB::table('users')->count();

        return $counts;
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
            'git_sha' => 'synthetic-slice6a',
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
    }
}
