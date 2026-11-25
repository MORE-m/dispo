<?php

namespace Tests\Feature\MasterDataTransfer;

use App\Enums\FieldAppliesTo;
use App\Enums\FieldScope;
use App\Enums\FieldSetVersionStatus;
use App\Enums\FieldType;
use App\Enums\InventoryType;
use App\Enums\PriceListStatus;
use App\Enums\Role;
use App\Models\AdvertisingCategory;
use App\Models\AdvertisingMedium;
use App\Models\CalculationMethod;
use App\Models\FieldDefinition;
use App\Models\FieldDefinitionRevision;
use App\Models\FieldSet;
use App\Models\FieldSetVersion;
use App\Models\FieldSetVersionField;
use App\Models\Inventory;
use App\Models\OaAdserverSurcharge;
use App\Models\OaPlatform;
use App\Models\OaTargetingDefinition;
use App\Models\OaTkpPriceList;
use App\Models\Organization;
use App\Models\PriceList;
use App\Models\ProductionPriceList;
use App\Models\User;
use App\Services\MasterDataTransfer\Import\MasterDataImporter;
use App\Services\MasterDataTransfer\Import\MasterDataImporterV2;
use App\Services\MasterDataTransfer\MasterDataTransferContract;
use App\Services\MasterDataTransfer\MasterDataTransferContractV2;
use App\Services\MasterDataTransfer\MasterDataTransferException;
use App\Services\MasterDataTransfer\Support\MasterDataImportRecordValidatorV2;
use App\Services\MasterDataTransfer\Support\MasterDataPackage;
use Database\Seeders\InitialCatalogMatCoreSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * EPIC-03-F Slice 3 Phase A: Importer 2.0.0 (nur isolierte Tests, CLI gesperrt).
 */
class MasterDataTransferImporterV2PhaseATest extends TestCase
{
    use RefreshDatabase;

    private string $tmp;

    /** @var list<string> */
    private const ALLOWED_WRITE_TABLES = [
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

    protected function setUp(): void
    {
        parent::setUp();
        $this->tmp = sys_get_temp_dir().'/mdt-v2-phase-a-'.uniqid('', true);
        File::ensureDirectoryExists($this->tmp);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->tmp);
        parent::tearDown();
    }

    #[Test]
    public function phase_a_creates_full_synthetic_family_and_keeps_prices_draft(): void
    {
        $admin = User::factory()->create([
            'email' => 'v2-phase-a@example.test',
            'role' => Role::Admin,
            'is_active' => true,
        ]);
        $package = $this->writeRichV2Package();

        $report = app(MasterDataImporterV2::class)->executePhaseAForIsolatedTests($package, dryRun: false);

        $this->assertFalse($report['dry_run']);
        $this->assertGreaterThan(0, $report['created']);
        $this->assertSame([], $report['conflicts']);

        $this->assertSame('Fixture Org V2', Organization::query()->value('name'));
        $this->assertNotNull(CalculationMethod::query()->where('key', 'avg_v2')->first());
        $this->assertNotNull(AdvertisingCategory::query()->where('key', 'spots_v2')->first());
        $this->assertNotNull(AdvertisingMedium::query()->where('code', 'spot_v2')->first());
        $inventory = Inventory::query()->where('code', 'inv_v2_radio')->firstOrFail();
        $this->assertSame(InventoryType::Sender, $inventory->type);

        $spot = PriceList::query()
            ->where('inventory_id', $inventory->id)
            ->where('year', 2026)
            ->where('revision_number', 1)
            ->firstOrFail();
        $this->assertSame(PriceListStatus::Draft, $spot->status);
        $this->assertSame(1, $spot->items()->count());

        $prod = ProductionPriceList::query()
            ->where('inventory_id', $inventory->id)
            ->where('year', 2026)
            ->where('revision_number', 1)
            ->firstOrFail();
        $this->assertSame(PriceListStatus::Draft, $prod->status);

        $oa = OaTkpPriceList::query()->where('year', 2026)->where('revision_number', 1)->firstOrFail();
        $this->assertSame(PriceListStatus::Draft, $oa->status);
        $this->assertSame(1, $oa->items()->count());

        $this->assertNotNull(OaPlatform::query()->where('code', 'spotify_v2')->first());
        $this->assertNotNull(OaTargetingDefinition::query()->where('code', 'geo_de')->where('revision_number', 1)->first());
        $this->assertNotNull(OaAdserverSurcharge::query()->where('code', 'ads_v2')->where('revision_number', 1)->first());

        $definition = FieldDefinition::query()->where('key', 'campaign_title')->firstOrFail();
        $this->assertNull($definition->current_revision_id);
        $revision = FieldDefinitionRevision::query()
            ->where('field_definition_id', $definition->id)
            ->where('revision', 1)
            ->firstOrFail();
        $set = FieldSet::query()->where('key', 'calc_header_v2')->firstOrFail();
        $this->assertNull($set->active_version_id);
        $version = FieldSetVersion::query()->where('field_set_id', $set->id)->where('version', 1)->firstOrFail();
        $link = FieldSetVersionField::query()
            ->where('field_set_version_id', $version->id)
            ->where('field_definition_id', $definition->id)
            ->firstOrFail();
        $this->assertSame((int) $revision->id, (int) $link->field_definition_revision_id);

        $this->assertSame(1, User::query()->count());
        $this->assertSame($admin->email, User::query()->firstOrFail()->email);
        $this->assertFalse(PriceList::query()->where('status', PriceListStatus::Active)->exists());
        $this->assertFalse(OaTkpPriceList::query()->where('status', PriceListStatus::Active)->exists());
    }

    #[Test]
    public function second_import_of_identical_package_is_unchanged(): void
    {
        $package = $this->writeRichV2Package();
        app(MasterDataImporterV2::class)->executePhaseAForIsolatedTests($package, dryRun: false);

        try {
            $again = app(MasterDataImporterV2::class)->executePhaseAForIsolatedTests($package, dryRun: false);
        } catch (MasterDataTransferException $e) {
            $this->fail('Unerwartete Konflikte: '.json_encode($e->errors, JSON_UNESCAPED_UNICODE));
        }

        $this->assertSame(0, $again['created']);
        $this->assertGreaterThan(0, $again['unchanged']);
        $this->assertSame([], $again['conflicts']);
        $this->assertArrayNotHasKey('update', $again['counts_by_action']);
    }

    #[Test]
    public function category_metadata_update_is_allowed_name_conflict_is_not(): void
    {
        $package = $this->writeRichV2Package();
        app(MasterDataImporterV2::class)->executePhaseAForIsolatedTests($package, dryRun: false);

        $category = AdvertisingCategory::query()->where('key', 'spots_v2')->firstOrFail();
        $this->assertTrue((bool) $category->is_active);
        $this->assertSame(10, (int) $category->sort);

        $metaPackage = $this->writeRichV2Package(overrides: [
            'data/advertising_categories.json' => [[
                'key' => 'spots_v2',
                'name' => 'Spots V2',
                'is_active' => false,
                'sort' => 99,
                'default_calculation_method_key' => 'avg_v2',
            ]],
        ]);
        $metaReport = app(MasterDataImporterV2::class)->executePhaseAForIsolatedTests($metaPackage, dryRun: false);
        $this->assertSame([], $metaReport['conflicts']);
        $this->assertGreaterThan(0, $metaReport['counts_by_action']['update'] ?? 0);
        $category->refresh();
        $this->assertFalse((bool) $category->is_active);
        $this->assertSame(99, (int) $category->sort);

        $namePackage = $this->writeRichV2Package(overrides: [
            'data/advertising_categories.json' => [[
                'key' => 'spots_v2',
                'name' => 'Anderer Name',
                'is_active' => false,
                'sort' => 99,
                'default_calculation_method_key' => 'avg_v2',
            ]],
        ]);
        try {
            app(MasterDataImporterV2::class)->executePhaseAForIsolatedTests($namePackage, dryRun: false);
            $this->fail('Namenskonflikt muss fail-closed abgewiesen werden.');
        } catch (MasterDataTransferException $e) {
            $this->assertStringContainsString('Konflikten', $e->getMessage());
            $this->assertTrue(collect($e->errors)->contains(
                fn (string $msg): bool => str_contains($msg, 'Namenskonflikt'),
            ));
        }
        $this->assertSame('Spots V2', AdvertisingCategory::query()->where('key', 'spots_v2')->value('name'));
    }

    #[Test]
    public function divergent_price_lists_conflict_without_new_revision_or_activation(): void
    {
        $package = $this->writeRichV2Package();
        app(MasterDataImporterV2::class)->executePhaseAForIsolatedTests($package, dryRun: false);

        $conflictPackage = $this->writeRichV2Package(overrides: [
            'data/price_lists.json' => [[
                'inventory_code' => 'inv_v2_radio',
                'year' => 2026,
                'revision_number' => 1,
                'name' => 'Spot 2026',
                'version' => '1',
                'valid_from' => '2026-01-01',
                'source_status' => 'active',
                'import_status' => 'draft',
                'items' => [[
                    'hour' => 8,
                    'day_group' => 'mo_fr',
                    'second_price' => '99.0000',
                ]],
            ]],
        ]);

        try {
            app(MasterDataImporterV2::class)->executePhaseAForIsolatedTests($conflictPackage, dryRun: false);
            $this->fail('Abweichende Spotpreisliste muss Konflikt erzeugen.');
        } catch (MasterDataTransferException $e) {
            $messages = array_merge([$e->getMessage()], $e->errors);
            $this->assertTrue(
                collect($messages)->contains(fn (string $msg): bool => str_contains($msg, 'price_list')),
                'Erwarteter price_list-Konflikt, erhalten: '.json_encode($messages),
            );
        }

        $inventory = Inventory::query()->where('code', 'inv_v2_radio')->firstOrFail();
        $this->assertSame(1, PriceList::query()->where('inventory_id', $inventory->id)->count());
        $list = PriceList::query()->where('inventory_id', $inventory->id)->firstOrFail();
        $this->assertSame(PriceListStatus::Draft, $list->status);
        $item = $list->items()->firstOrFail();
        $this->assertSame(PriceListStatus::Draft, $list->status);
        $this->assertNotSame('99.0000', (string) $item->second_price);
        $this->assertTrue(
            in_array((string) $item->getAttributes()['second_price'], ['12.5', '12.5000', '12.50'], true)
            || abs((float) $item->getAttributes()['second_price'] - 12.5) < 0.00001,
        );
    }

    #[Test]
    public function record_schema_rejects_extra_keys_bad_types_and_duplicate_keys(): void
    {
        $base = $this->richPayload();

        try {
            $bad = $base;
            $bad['data/calculation_methods.json'][0]['extra_field'] = 'nope';
            MasterDataImportRecordValidatorV2::validate($bad);
            $this->fail('Zusätzliche Schlüssel müssen abgewiesen werden.');
        } catch (MasterDataTransferException $e) {
            $this->assertTrue(collect($e->errors)->contains(
                fn (string $msg): bool => str_contains($msg, 'unzulässige Schlüssel'),
            ));
        }

        try {
            $bad = $base;
            $bad['data/inventories.json'][0]['is_active'] = 'yes';
            MasterDataImportRecordValidatorV2::validate($bad);
            $this->fail('Falsche Datentypen müssen abgewiesen werden.');
        } catch (MasterDataTransferException $e) {
            $this->assertTrue(collect($e->errors)->contains(
                fn (string $msg): bool => str_contains($msg, 'muss bool sein'),
            ));
        }

        try {
            $bad = $base;
            $bad['data/inventories.json'][] = $bad['data/inventories.json'][0];
            MasterDataImportRecordValidatorV2::validate($bad);
            $this->fail('Doppelte Fachschlüssel müssen abgewiesen werden.');
        } catch (MasterDataTransferException $e) {
            $this->assertTrue(collect($e->errors)->contains(
                fn (string $msg): bool => str_contains($msg, 'Doppelter inventory.code'),
            ));
        }

        try {
            $bad = $base;
            $bad['data/price_lists.json'][0]['import_status'] = 'active';
            MasterDataImportRecordValidatorV2::validate($bad);
            $this->fail('import_status != draft muss abgewiesen werden.');
        } catch (MasterDataTransferException $e) {
            $this->assertTrue(collect($e->errors)->contains(
                fn (string $msg): bool => str_contains($msg, 'import_status muss draft sein'),
            ));
        }
    }

    #[Test]
    public function source_and_target_ids_differ_while_business_keys_match(): void
    {
        Organization::query()->create(['name' => 'Fixture Org V2']);
        $method = new CalculationMethod;
        $method->forceFill([
            'key' => 'avg_v2',
            'name' => 'Durchschnitt V2',
            'help_text' => null,
            'is_active' => true,
            'sort' => 1,
            'lock_version' => 1,
        ])->save();
        $this->assertGreaterThan(1, (int) $method->id);

        $package = $this->writeRichV2Package();
        $report = app(MasterDataImporterV2::class)->executePhaseAForIsolatedTests($package, dryRun: false);

        $this->assertSame([], $report['conflicts']);
        $this->assertSame(
            (int) $method->id,
            (int) CalculationMethod::query()->where('key', 'avg_v2')->value('id'),
        );
        $this->assertNotNull(Inventory::query()->where('code', 'inv_v2_radio')->first());
    }

    #[Test]
    public function dry_run_writes_nothing_and_operational_paths_stay_blocked(): void
    {
        $package = $this->writeRichV2Package();
        $beforeUsers = User::query()->count();

        $report = app(MasterDataImporterV2::class)->executePhaseAForIsolatedTests($package, dryRun: true);
        $this->assertTrue($report['dry_run']);
        $this->assertSame(0, Organization::query()->count());
        $this->assertSame(0, Inventory::query()->count());
        $this->assertSame(0, PriceList::query()->count());
        $this->assertSame($beforeUsers, User::query()->count());

        try {
            app(MasterDataImporterV2::class)->import($package, dryRun: true, confirmDatabase: 'ignored');
            $this->fail('ImporterV2::import() darf nicht als operativer Dry-Run dienen.');
        } catch (MasterDataTransferException $e) {
            $this->assertStringContainsString('dryRunOperational()', $e->getMessage());
        }

        $db = (string) config('database.connections.'.config('database.default').'.database');
        $this->assertSame(1, Artisan::call('dispo:import-master-data', [
            'path' => $package,
            '--dry-run' => true,
            '--confirm-database' => $db,
        ]));
        $this->assertStringContainsString('--require-package-sha256 ist Pflicht', Artisan::output());

        $this->assertSame(1, Artisan::call('dispo:import-master-data', [
            'path' => $package,
            '--apply' => true,
            '--confirm-database' => $db,
        ]));
        $this->assertStringContainsString('Slice 6b fehlt', Artisan::output());
        $this->assertSame(0, Organization::query()->count());
    }

    #[Test]
    public function v1_importer_remains_compatible_and_rejects_v2_packages(): void
    {
        $this->seed(InitialCatalogMatCoreSeeder::class);
        $v1 = $this->writeMinimalV1PackageAlignedToSeed();
        $db = (string) config('database.connections.'.config('database.default').'.database');
        $report = app(MasterDataImporter::class)->import($v1, dryRun: true, confirmDatabase: $db);
        $this->assertTrue($report['dry_run']);

        $v2 = $this->writeRichV2Package();
        try {
            app(MasterDataImporter::class)->import($v2, dryRun: true, confirmDatabase: $db);
            $this->fail('V1-Importer darf V2-Pakete nicht akzeptieren.');
        } catch (MasterDataTransferException $e) {
            $this->assertStringContainsString('nicht freigegeben', $e->getMessage());
            $this->assertStringContainsString(MasterDataTransferContractV2::SCHEMA_VERSION, $e->getMessage());
        }
    }

    #[Test]
    public function apply_rolls_back_completely_on_failure_and_stays_in_whitelist(): void
    {
        $package = $this->writeRichV2Package();
        $importer = app(MasterDataImporterV2::class);

        DB::listen(function ($query): void {
            $sql = strtolower((string) $query->sql);
            if (! str_contains($sql, 'insert ') && ! str_contains($sql, 'update ') && ! str_contains($sql, 'delete ')) {
                return;
            }
            foreach (self::ALLOWED_WRITE_TABLES as $table) {
                if (preg_match('/\b'.preg_quote($table, '/').'\b/', $sql) === 1) {
                    return;
                }
            }
            if (str_contains($sql, 'sqlite_sequence') || str_contains($sql, 'migrations')) {
                return;
            }
            $this->fail('Schreibzugriff außerhalb Whitelist: '.$query->sql);
        });

        $report = $importer->executePhaseAForIsolatedTests($package, dryRun: false);
        $this->assertGreaterThan(0, $report['created']);

        $methodBefore = CalculationMethod::query()->where('key', 'avg_v2')->firstOrFail();
        $nameBefore = $methodBefore->name;
        $orgCount = Organization::query()->count();
        $inventoryCount = Inventory::query()->count();
        $priceCount = PriceList::query()->count();
        $userCount = User::query()->count();

        $conflict = $this->writeRichV2Package(overrides: [
            'data/calculation_methods.json' => [[
                'key' => 'avg_v2',
                'name' => 'ANDERS',
                'help_text' => null,
                'is_active' => true,
                'sort' => 1,
            ]],
        ]);

        try {
            $importer->executePhaseAForIsolatedTests($conflict, dryRun: false);
            $this->fail('Konflikt muss Exception auslösen.');
        } catch (MasterDataTransferException $e) {
            $this->assertStringContainsString('Konflikten', $e->getMessage());
        }

        $this->assertSame($nameBefore, CalculationMethod::query()->where('key', 'avg_v2')->value('name'));
        $this->assertSame($orgCount, Organization::query()->count());
        $this->assertSame($inventoryCount, Inventory::query()->count());
        $this->assertSame($priceCount, PriceList::query()->count());
        $this->assertSame($userCount, User::query()->count());
    }

    #[Test]
    public function missing_package_reference_is_rejected_before_mutation(): void
    {
        $bad = $this->richPayload();
        $bad['data/advertising_media.json'][0]['category_key'] = 'missing_cat';

        try {
            MasterDataImportRecordValidatorV2::validate($bad);
            $this->fail('Unbekannte category_key muss abgewiesen werden.');
        } catch (MasterDataTransferException $e) {
            $this->assertTrue(collect($e->errors)->contains(
                fn (string $msg): bool => str_contains($msg, 'category_key unbekannt'),
            ));
        }

        $this->assertSame(0, Organization::query()->count());
    }

    #[Test]
    public function isolated_execution_rejects_protected_databases_and_non_testing_env(): void
    {
        $package = $this->writeRichV2Package();
        $importer = app(MasterDataImporterV2::class);
        $connection = (string) config('database.default');
        $originalDb = config('database.connections.'.$connection.'.database');

        foreach (['dispo_mat_core', 'dispo', 'staging', 'production'] as $blocked) {
            config(['database.connections.'.$connection.'.database' => $blocked]);
            try {
                $importer->executePhaseAForIsolatedTests($package, dryRun: true);
                $this->fail('Geschützte DB muss auch im Dry-Run abgelehnt werden: '.$blocked);
            } catch (MasterDataTransferException $e) {
                $this->assertStringContainsString('gesperrt', $e->getMessage());
            }

            try {
                $importer->executePhaseAForIsolatedTests($package, dryRun: false);
                $this->fail('Geschützte DB muss im Apply abgelehnt werden: '.$blocked);
            } catch (MasterDataTransferException $e) {
                $this->assertStringContainsString('gesperrt', $e->getMessage());
            }
        }

        config(['database.connections.'.$connection.'.database' => $originalDb]);

        $originalEnv = app()->environment();
        app()->detectEnvironment(fn (): string => 'production');
        try {
            try {
                $importer->executePhaseAForIsolatedTests($package, dryRun: true);
                $this->fail('Nicht-Testumgebung muss abgelehnt werden.');
            } catch (MasterDataTransferException $e) {
                $this->assertStringContainsString('APP_ENV=testing', $e->getMessage());
            }
        } finally {
            app()->detectEnvironment(fn (): string => $originalEnv);
            config(['database.connections.'.$connection.'.database' => $originalDb]);
        }

        $this->assertSame(0, Organization::query()->count());
    }

    #[Test]
    public function duplicate_price_positions_and_version_collisions_are_rejected_without_partial_write(): void
    {
        $base = $this->richPayload();

        try {
            $bad = $base;
            $bad['data/price_lists.json'][0]['items'][] = [
                'hour' => 8,
                'day_group' => 'mo_fr',
                'second_price' => '1.0000',
            ];
            MasterDataImportRecordValidatorV2::validate($bad);
            $this->fail('Doppelte Spotpreispositionen müssen abgewiesen werden.');
        } catch (MasterDataTransferException $e) {
            $this->assertTrue(collect($e->errors)->contains(
                fn (string $msg): bool => str_contains($msg, 'Doppelte Spotpreisposition'),
            ));
        }

        try {
            $bad = $base;
            $bad['data/oa_tkp_price_lists.json'][0]['items'][] = [
                'inventory_code' => 'inv_v2_radio',
                'medium_code' => 'spot_v2',
                'list_tkp' => '1.0000',
                'minimum_tkp' => '1.0000',
            ];
            MasterDataImportRecordValidatorV2::validate($bad);
            $this->fail('Doppelte OA-TKP-Positionen müssen abgewiesen werden.');
        } catch (MasterDataTransferException $e) {
            $this->assertTrue(collect($e->errors)->contains(
                fn (string $msg): bool => str_contains($msg, 'Doppelte OA-TKP-Position'),
            ));
        }

        try {
            $bad = $base;
            $bad['data/price_lists.json'][] = [
                'inventory_code' => 'inv_v2_radio',
                'year' => 2026,
                'revision_number' => 2,
                'name' => 'Andere Revision gleiche Version',
                'version' => '1',
                'valid_from' => '2026-01-01',
                'source_status' => 'draft',
                'import_status' => 'draft',
                'items' => [[
                    'hour' => 9,
                    'day_group' => 'mo_fr',
                    'second_price' => '2.0000',
                ]],
            ];
            MasterDataImportRecordValidatorV2::validate($bad);
            $this->fail('Doppelte Spotpreislisten-Version muss abgewiesen werden.');
        } catch (MasterDataTransferException $e) {
            $this->assertTrue(collect($e->errors)->contains(
                fn (string $msg): bool => str_contains($msg, 'Doppelte Spotpreislisten-Version'),
            ));
        }

        app(MasterDataImporterV2::class)->executePhaseAForIsolatedTests($this->writeRichV2Package(), dryRun: false);
        $inventory = Inventory::query()->where('code', 'inv_v2_radio')->firstOrFail();
        $beforeCount = PriceList::query()->where('inventory_id', $inventory->id)->count();

        $collisionPackage = $this->writeRichV2Package(overrides: [
            'data/price_lists.json' => [[
                'inventory_code' => 'inv_v2_radio',
                'year' => 2026,
                'revision_number' => 2,
                'name' => 'Kollidierende Version',
                'version' => '1',
                'valid_from' => '2026-06-01',
                'source_status' => 'draft',
                'import_status' => 'draft',
                'items' => [[
                    'hour' => 10,
                    'day_group' => 'sa',
                    'second_price' => '3.0000',
                ]],
            ]],
        ]);

        try {
            app(MasterDataImporterV2::class)->executePhaseAForIsolatedTests($collisionPackage, dryRun: false);
            $this->fail('Version-Kollision gegen bestehende Liste muss Konflikt erzeugen.');
        } catch (MasterDataTransferException $e) {
            $this->assertTrue(collect($e->errors)->contains(
                fn (string $msg): bool => str_contains($msg, 'Version-Kollision'),
            ));
        }

        $this->assertSame($beforeCount, PriceList::query()->where('inventory_id', $inventory->id)->count());
        $this->assertFalse(
            PriceList::query()->where('inventory_id', $inventory->id)->where('revision_number', 2)->exists(),
        );
    }

    #[Test]
    public function nullable_keys_assignments_default_method_and_multi_org_are_validated(): void
    {
        $base = $this->richPayload();

        try {
            $bad = $base;
            unset($bad['data/calculation_methods.json'][0]['help_text']);
            MasterDataImportRecordValidatorV2::validate($bad);
            $this->fail('Fehlender nullable Schlüssel muss abgewiesen werden.');
        } catch (MasterDataTransferException $e) {
            $this->assertTrue(collect($e->errors)->contains(
                fn (string $msg): bool => str_contains($msg, 'help_text fehlt'),
            ));
        }

        try {
            $bad = $base;
            $bad['data/field_sets.json'] = [[
                'key' => 'calc_header_v2',
                'name' => 'Calc Header V2',
                'is_system' => false,
                'applies_to' => FieldAppliesTo::Calculation->value,
                'is_assignable' => true,
                'active_version' => 1,
            ]];
            $bad['data/field_set_assignments.json'] = [[
                'field_set_key' => 'calc_header_v2',
                'applies_to_process' => FieldAppliesTo::Calculation->value,
                'target_layer' => 'global',
                'is_active' => true,
                'sort' => 1,
                'category_key' => 'spots_v2',
                'medium_code' => null,
            ]];
            MasterDataImportRecordValidatorV2::validate($bad);
            $this->fail('global mit category_key muss abgewiesen werden.');
        } catch (MasterDataTransferException $e) {
            $this->assertTrue(collect($e->errors)->contains(
                fn (string $msg): bool => str_contains($msg, 'target_layer=global darf category_key nicht setzen'),
            ));
        }

        try {
            $bad = $base;
            $bad['data/field_set_assignments.json'] = [
                [
                    'field_set_key' => 'calc_header_v2',
                    'applies_to_process' => FieldAppliesTo::Calculation->value,
                    'target_layer' => 'advertising_category',
                    'is_active' => true,
                    'sort' => 1,
                    'category_key' => 'spots_v2',
                    'medium_code' => null,
                ],
                [
                    'field_set_key' => 'calc_header_v2',
                    'applies_to_process' => FieldAppliesTo::Calculation->value,
                    'target_layer' => 'advertising_category',
                    'is_active' => false,
                    'sort' => 2,
                    'category_key' => 'spots_v2',
                    'medium_code' => null,
                ],
            ];
            MasterDataImportRecordValidatorV2::validate($bad);
            $this->fail('Doppelte Feldset-Zuordnung muss abgewiesen werden.');
        } catch (MasterDataTransferException $e) {
            $this->assertTrue(collect($e->errors)->contains(
                fn (string $msg): bool => str_contains($msg, 'Doppelte Feldset-Zuordnung'),
            ));
        }

        Organization::query()->create(['name' => 'Org A']);
        Organization::query()->create(['name' => 'Org B']);
        try {
            app(MasterDataImporterV2::class)->executePhaseAForIsolatedTests($this->writeRichV2Package(), dryRun: false);
            $this->fail('Mehrere Organisationen müssen Konflikt erzeugen.');
        } catch (MasterDataTransferException $e) {
            $this->assertTrue(collect($e->errors)->contains(
                fn (string $msg): bool => str_contains($msg, 'Mehr als eine Organisation'),
            ));
        }
        $this->assertSame(2, Organization::query()->count());
        $this->assertSame(0, Inventory::query()->where('code', 'inv_v2_radio')->count());

        Organization::query()->delete();
        Organization::query()->create(['name' => 'Fixture Org V2']);
        $existingCategory = new AdvertisingCategory;
        $existingCategory->forceFill([
            'key' => 'spots_v2',
            'name' => 'Spots V2',
            'is_active' => true,
            'sort' => 10,
            'default_calculation_method_id' => null,
            'lock_version' => 1,
        ])->save();

        $package = $this->writeRichV2Package(overrides: [
            'data/calculation_methods.json' => [[
                'key' => 'brand_new_method',
                'name' => 'Brand New',
                'help_text' => null,
                'is_active' => true,
                'sort' => 50,
            ]],
            'data/advertising_categories.json' => [[
                'key' => 'spots_v2',
                'name' => 'Spots V2',
                'is_active' => true,
                'sort' => 10,
                'default_calculation_method_key' => 'brand_new_method',
            ]],
            'data/advertising_category_calculation_methods.json' => [[
                'category_key' => 'spots_v2',
                'method_key' => 'brand_new_method',
                'engine_profile_key' => 'default',
                'is_active' => true,
                'sort' => 1,
            ]],
            'data/advertising_medium_calculation_methods.json' => [[
                'medium_code' => 'spot_v2',
                'method_key' => 'brand_new_method',
                'engine_profile_key' => 'default',
                'is_active' => true,
                'sort' => 1,
            ]],
            'data/advertising_media.json' => [[
                'code' => 'spot_v2',
                'name' => 'Spot V2',
                'category_key' => 'spots_v2',
                'kind' => 'spot_classic',
                'component_profile' => null,
                'calculation_method_mode' => 'inherit',
                'default_calculation_method_key' => null,
                'default_length_seconds' => 30,
                'is_discountable' => true,
                'is_ae_eligible' => true,
                'is_active' => true,
                'sort' => 10,
            ]],
        ]);

        $report = app(MasterDataImporterV2::class)->executePhaseAForIsolatedTests($package, dryRun: false);
        $this->assertSame([], $report['conflicts']);
        $methodId = (int) CalculationMethod::query()->where('key', 'brand_new_method')->value('id');
        $this->assertGreaterThan(0, $methodId);
        $existingCategory->refresh();
        $this->assertSame($methodId, (int) $existingCategory->default_calculation_method_id);
    }

    /**
     * @param  array<string, mixed>  $overrides
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
            'key' => 'avg_v2',
            'name' => 'Durchschnitt V2',
            'help_text' => null,
            'is_active' => true,
            'sort' => 1,
        ]];
        $files['data/advertising_categories.json'] = [[
            'key' => 'spots_v2',
            'name' => 'Spots V2',
            'is_active' => true,
            'sort' => 10,
            'default_calculation_method_key' => 'avg_v2',
        ]];
        $files['data/advertising_media.json'] = [[
            'code' => 'spot_v2',
            'name' => 'Spot V2',
            'category_key' => 'spots_v2',
            'kind' => 'spot_classic',
            'component_profile' => null,
            'calculation_method_mode' => 'inherit',
            'default_calculation_method_key' => null,
            'default_length_seconds' => 30,
            'is_discountable' => true,
            'is_ae_eligible' => true,
            'is_active' => true,
            'sort' => 10,
        ]];
        $files['data/advertising_category_calculation_methods.json'] = [[
            'category_key' => 'spots_v2',
            'method_key' => 'avg_v2',
            'engine_profile_key' => 'default',
            'is_active' => true,
            'sort' => 1,
        ]];
        $files['data/advertising_medium_calculation_methods.json'] = [[
            'medium_code' => 'spot_v2',
            'method_key' => 'avg_v2',
            'engine_profile_key' => 'default',
            'is_active' => true,
            'sort' => 1,
        ]];
        $files['data/inventories.json'] = [[
            'code' => 'inv_v2_radio',
            'name' => 'Radio V2',
            'type' => 'sender',
            'is_active' => true,
            'sort' => 1,
            'logo_path' => null,
        ]];
        $files['data/inventory_medium_rules.json'] = [[
            'inventory_code' => 'inv_v2_radio',
            'medium_code' => 'spot_v2',
            'is_active' => true,
            'booking_code' => 'BK1',
            'planning_responsibility_key' => null,
            'hint_text' => null,
            'sort' => 1,
            'default_length_seconds' => 30,
            'surcharge_percent' => null,
            'is_discountable' => true,
            'is_ae_eligible' => true,
            'component_calculation_strategy' => 'shared_total_length',
            'swf_allonge_confirmed_at' => null,
        ]];
        $files['data/price_lists.json'] = [[
            'inventory_code' => 'inv_v2_radio',
            'year' => 2026,
            'revision_number' => 1,
            'name' => 'Spot 2026',
            'version' => '1',
            'valid_from' => '2026-01-01',
            'source_status' => 'active',
            'import_status' => 'draft',
            'items' => [[
                'hour' => 8,
                'day_group' => 'mo_fr',
                'second_price' => '12.5000',
            ]],
        ]];
        $files['data/production_price_lists.json'] = [[
            'inventory_code' => 'inv_v2_radio',
            'production_type' => 'spot_production',
            'year' => 2026,
            'revision_number' => 1,
            'name' => 'Prod 2026',
            'version' => '1',
            'source_status' => 'draft',
            'import_status' => 'draft',
            'unit_price' => '150.00',
            'is_discountable' => false,
            'is_ae_eligible' => false,
        ]];
        $files['data/oa_platforms.json'] = [[
            'code' => 'spotify_v2',
            'name' => 'Spotify V2',
            'is_active' => true,
            'sort' => 1,
            'is_adserver_relevant' => true,
        ]];
        $files['data/oa_platform_medium_rules.json'] = [[
            'platform_code' => 'spotify_v2',
            'medium_code' => 'spot_v2',
            'is_active' => true,
        ]];
        $files['data/oa_targeting_definitions.json'] = [[
            'code' => 'geo_de',
            'revision_number' => 1,
            'name' => 'Deutschland',
            'category' => 'technisch',
            'tkp_surcharge' => '1.2500',
            'minimum_tkp_surcharge' => '0.5000',
            'is_active' => true,
            'valid_from' => '2026-01-01',
            'valid_to' => null,
        ]];
        $files['data/oa_tkp_price_lists.json'] = [[
            'year' => 2026,
            'revision_number' => 1,
            'name' => 'OA TKP 2026',
            'version' => '1',
            'valid_from' => '2026-01-01',
            'source_status' => 'active',
            'import_status' => 'draft',
            'items' => [[
                'inventory_code' => 'inv_v2_radio',
                'medium_code' => 'spot_v2',
                'list_tkp' => '8.0000',
                'minimum_tkp' => '6.0000',
            ]],
        ]];
        $files['data/oa_adserver_surcharges.json'] = [[
            'code' => 'ads_v2',
            'revision_number' => 1,
            'name' => 'Adserver V2',
            'tkp_amount' => '0.2500',
            'is_active' => true,
            'scope_json' => ['platforms' => ['spotify_v2']],
            'valid_from' => '2026-01-01',
            'valid_to' => null,
        ]];
        $files['data/field_definitions.json'] = [[
            'key' => 'campaign_title',
            'field_type' => FieldType::ShortText->value,
            'is_system' => false,
            'is_key_protected' => false,
            'scope' => FieldScope::Header->value,
            'applies_to' => FieldAppliesTo::Calculation->value,
            'is_active' => true,
            'current_revision' => 1,
        ]];
        $files['data/field_definition_revisions.json'] = [[
            'field_definition_key' => 'campaign_title',
            'revision' => 1,
            'label' => 'Kampagnentitel',
            'help_text' => null,
            'validation_json' => ['max' => 120],
            'group_key' => 'base',
            'sort_default' => 10,
            'reportable' => true,
        ]];
        $files['data/field_definition_revision_options.json'] = [];
        $files['data/field_sets.json'] = [[
            'key' => 'calc_header_v2',
            'name' => 'Calc Header V2',
            'is_system' => false,
            'applies_to' => FieldAppliesTo::Calculation->value,
            'is_assignable' => true,
            'active_version' => 1,
        ]];
        $files['data/field_set_versions.json'] = [[
            'field_set_key' => 'calc_header_v2',
            'version' => 1,
            'status' => FieldSetVersionStatus::Draft->value,
        ]];
        $files['data/field_set_version_fields.json'] = [[
            'field_set_key' => 'calc_header_v2',
            'version' => 1,
            'field_definition_key' => 'campaign_title',
            'revision' => 1,
            'sort' => 10,
            'required_override' => true,
            'visible_override' => null,
        ]];
        $files['data/field_rules.json'] = [[
            'field_set_key' => 'calc_header_v2',
            'version' => 1,
            'sort' => 1,
            'condition_json' => ['op' => 'always'],
            'action_json' => ['set' => 'visible'],
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
            $counts[$relative] = $relative === 'data/organization.json'
                ? 1
                : count($payload);
        }

        return [
            'format' => MasterDataTransferContractV2::FORMAT,
            'schema_version' => MasterDataTransferContractV2::SCHEMA_VERSION,
            'epic' => MasterDataTransferContractV2::EPIC,
            'git_sha' => 'synthetic-phase-a',
            'exported_at' => '2026-11-24T10:00:00+00:00',
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

    private function writeMinimalV1PackageAlignedToSeed(): string
    {
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
            'exported_at' => '2026-11-24T10:00:00+00:00',
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

        return $path;
    }
}
