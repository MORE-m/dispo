<?php

namespace Tests\Feature\MasterDataTransfer;

use App\Enums\ComponentCalculationStrategy;
use App\Enums\InventoryType;
use App\Enums\PriceListStatus;
use App\Enums\ProductionType;
use App\Enums\Role;
use App\Models\AdvertisingCategory;
use App\Models\AdvertisingMedium;
use App\Models\AdvertisingMediumCalculationMethod;
use App\Models\Calculation;
use App\Models\CalculationMethod;
use App\Models\Inventory;
use App\Models\InventoryMediumRule;
use App\Models\Organization;
use App\Models\PriceList;
use App\Models\PriceListItem;
use App\Models\ProductionPriceList;
use App\Models\User;
use App\Services\MasterDataTransfer\Export\MasterDataExporter;
use App\Services\MasterDataTransfer\Import\MasterDataImporter;
use App\Services\MasterDataTransfer\MasterDataTransferContract;
use App\Services\MasterDataTransfer\MasterDataTransferException;
use App\Services\MasterDataTransfer\Support\MasterDataPackage;
use App\Support\OnlineAudio\OaTkpCoreMedia;
use Database\Seeders\InitialCatalogMatCoreSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class MasterDataTransferTest extends TestCase
{
    use RefreshDatabase;

    private string $tmp;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tmp = sys_get_temp_dir().'/mdt-'.uniqid('', true);
        File::ensureDirectoryExists($this->tmp);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->tmp);
        parent::tearDown();
    }

    #[Test]
    public function export_and_import_roundtrip_on_empty_database(): void
    {
        $this->seed(InitialCatalogMatCoreSeeder::class);
        $this->seedSpotPricesForCodes([
            'inv_radio_hamburg',
            'inv_more_hamburg_kombi',
        ]);

        $exportPath = $this->tmp.'/export';
        $result = app(MasterDataExporter::class)->export(
            $exportPath,
            ['inv_radio_hamburg', 'inv_more_hamburg_kombi'],
            'testsha',
        );
        $this->assertSame(2, $result['counts']['inventories']);
        $this->assertGreaterThan(0, $result['counts']['price_lists']);
        $this->assertFileExists($exportPath.'/manifest.json');
        $this->assertFileExists($exportPath.'/checksums.sha256');

        // Ziel: leere operative Tabellen, Migration-Saat bleibt
        InventoryMediumRule::query()->delete();
        PriceListItem::query()->delete();
        PriceList::query()->delete();
        ProductionPriceList::query()->delete();
        AdvertisingMediumCalculationMethod::query()->delete();
        AdvertisingMedium::query()->delete();
        Inventory::query()->delete();
        Organization::query()->delete();

        $admin = User::factory()->create([
            'email' => 'staging-admin@example.test',
            'role' => Role::Admin,
            'is_active' => true,
        ]);

        $db = (string) config('database.connections.'.config('database.default').'.database');
        $report = app(MasterDataImporter::class)->import($exportPath, dryRun: true, confirmDatabase: $db);
        $this->assertTrue($report['dry_run']);
        $this->assertSame(0, Inventory::query()->count());

        $report = app(MasterDataImporter::class)->import($exportPath, dryRun: false, confirmDatabase: $db);
        $this->assertFalse($report['dry_run']);
        $this->assertGreaterThan(0, $report['created']);
        $this->assertSame(2, Inventory::query()->count());
        $this->assertNotNull(Inventory::query()->where('code', 'inv_radio_hamburg')->first());
        $this->assertTrue(
            PriceList::query()->where('status', PriceListStatus::Draft)->exists(),
        );
        $this->assertFalse(
            PriceList::query()->where('status', PriceListStatus::Active)->exists(),
        );
        $this->assertSame(1, User::query()->count());
        $this->assertSame($admin->email, User::query()->firstOrFail()->email);

        // Idempotenz
        $again = app(MasterDataImporter::class)->import($exportPath, dryRun: false, confirmDatabase: $db);
        $this->assertSame(0, $again['created']);
        $this->assertGreaterThan(0, $again['unchanged']);
    }

    #[Test]
    public function checksum_tamper_fails_closed(): void
    {
        $package = $this->writeMinimalPackage();
        File::put($package.'/data/inventories.json', "{\"tampered\":true}\n");

        $db = (string) config('database.connections.'.config('database.default').'.database');
        $this->expectException(MasterDataTransferException::class);
        app(MasterDataImporter::class)->import($package, dryRun: true, confirmDatabase: $db);
    }

    #[Test]
    public function confirm_database_guard_and_mat_core_apply_blocked(): void
    {
        $package = $this->writeMinimalPackage();
        $this->expectException(MasterDataTransferException::class);
        app(MasterDataImporter::class)->import($package, dryRun: true, confirmDatabase: 'wrong-db');
    }

    #[Test]
    public function apply_against_dispo_mat_core_is_blocked(): void
    {
        config(['database.connections.'.config('database.default').'.database' => 'dispo_mat_core']);
        $package = $this->writeMinimalPackage();
        $this->expectException(MasterDataTransferException::class);
        app(MasterDataImporter::class)->import($package, dryRun: false, confirmDatabase: 'dispo_mat_core');
    }

    #[Test]
    public function active_price_list_is_not_overwritten(): void
    {
        $this->seed(InitialCatalogMatCoreSeeder::class);
        $inventory = Inventory::query()->where('code', 'inv_radio_hamburg')->firstOrFail();
        $active = PriceList::factory()->create([
            'inventory_id' => $inventory->id,
            'year' => 2026,
            'revision_number' => 1,
            'version' => '1',
            'status' => PriceListStatus::Active,
        ]);
        if (Schema::hasColumn('price_lists', 'active_inventory_id')) {
            $active->forceFill([
                'active_inventory_id' => $inventory->id,
                'active_year' => 2026,
            ])->save();
        }
        PriceListItem::factory()->create([
            'price_list_id' => $active->id,
            'hour' => 0,
            'day_group' => 'mo_fr',
            'second_price' => '9.0000',
        ]);

        $package = $this->writeMinimalPackage(inventoryCode: 'inv_radio_hamburg', year: 2026, revision: 1);
        $db = (string) config('database.connections.'.config('database.default').'.database');
        $report = app(MasterDataImporter::class)->import($package, dryRun: false, confirmDatabase: $db);

        $active->refresh();
        $this->assertSame(PriceListStatus::Active, $active->status);
        $this->assertSame('9.0000', (string) $active->items()->firstOrFail()->second_price);
        $this->assertTrue(
            PriceList::query()
                ->where('inventory_id', $inventory->id)
                ->where('year', 2026)
                ->where('status', PriceListStatus::Draft)
                ->where('revision_number', '>', 1)
                ->exists(),
        );
        $this->assertNotEmpty(collect($report['rows'])->first(
            fn ($r) => $r['entity'] === 'price_list' && $r['action'] === 'create',
        ));
    }

    #[Test]
    public function artisan_cli_dry_run_and_apply_flags(): void
    {
        $package = $this->writeMinimalPackage();
        $db = (string) config('database.connections.'.config('database.default').'.database');

        $this->assertSame(1, Artisan::call('dispo:import-master-data', [
            'path' => $package,
        ]));

        $this->assertSame(0, Artisan::call('dispo:import-master-data', [
            'path' => $package,
            '--dry-run' => true,
            '--confirm-database' => $db,
        ]));
    }

    #[Test]
    public function package_excludes_users_and_movement_tables_from_contract_files(): void
    {
        $joined = implode('|', MasterDataTransferContract::DATA_FILES);
        $this->assertStringNotContainsString('users', $joined);
        $this->assertStringNotContainsString('dispo_order', $joined);
        $this->assertStringNotContainsString('crm_', $joined);
        $this->assertStringNotContainsString('oa_', $joined);
        $this->assertStringNotContainsString('notification_outbox', $joined);
        $this->assertCount(8, MasterDataTransferContract::SPOT_INVENTORY_CODES);
    }

    #[Test]
    public function repeated_import_after_price_list_activation_stays_idempotent(): void
    {
        $package = $this->writeMinimalPackage(inventoryCode: 'inv_radio_hamburg', year: 2026, revision: 1);
        $db = (string) config('database.connections.'.config('database.default').'.database');

        app(MasterDataImporter::class)->import($package, dryRun: false, confirmDatabase: $db);

        $inventory = Inventory::query()->where('code', 'inv_radio_hamburg')->firstOrFail();
        $draft = PriceList::query()
            ->where('inventory_id', $inventory->id)
            ->where('year', 2026)
            ->where('status', PriceListStatus::Draft)
            ->firstOrFail();

        $draft->forceFill(['status' => PriceListStatus::Active])->save();
        if (Schema::hasColumn('price_lists', 'active_inventory_id')) {
            $draft->forceFill([
                'active_inventory_id' => $inventory->id,
                'active_year' => 2026,
            ])->save();
        }

        $before = PriceList::query()->where('inventory_id', $inventory->id)->where('year', 2026)->count();
        $again = app(MasterDataImporter::class)->import($package, dryRun: false, confirmDatabase: $db);

        $this->assertSame(0, $again['created']);
        $this->assertSame(
            $before,
            PriceList::query()->where('inventory_id', $inventory->id)->where('year', 2026)->count(),
        );
        $this->assertTrue(
            collect($again['rows'])->contains(
                fn ($r) => $r['entity'] === 'price_list' && $r['action'] === 'unchanged',
            ),
        );
        $draft->refresh();
        $this->assertSame(PriceListStatus::Active, $draft->status);
    }

    #[Test]
    public function repeated_production_price_list_import_after_activation_stays_idempotent(): void
    {
        $package = $this->writeMinimalPackage(inventoryCode: 'inv_radio_hamburg', year: 2026, revision: 1);
        $db = (string) config('database.connections.'.config('database.default').'.database');
        app(MasterDataImporter::class)->import($package, dryRun: false, confirmDatabase: $db);

        $inventory = Inventory::query()->where('code', 'inv_radio_hamburg')->firstOrFail();
        $draft = ProductionPriceList::query()
            ->where('inventory_id', $inventory->id)
            ->where('production_type', ProductionType::SpotProduction)
            ->where('year', 2026)
            ->where('status', PriceListStatus::Draft)
            ->firstOrFail();
        $draft->forceFill(['status' => PriceListStatus::Active])->save();

        $before = ProductionPriceList::query()
            ->where('inventory_id', $inventory->id)
            ->where('production_type', ProductionType::SpotProduction)
            ->where('year', 2026)
            ->count();

        $again = app(MasterDataImporter::class)->import($package, dryRun: false, confirmDatabase: $db);

        $this->assertSame(
            $before,
            ProductionPriceList::query()
                ->where('inventory_id', $inventory->id)
                ->where('production_type', ProductionType::SpotProduction)
                ->where('year', 2026)
                ->count(),
        );
        $this->assertTrue(
            collect($again['rows'])->contains(
                fn ($r) => $r['entity'] === 'production_price_list' && $r['action'] === 'unchanged',
            ),
        );
        $draft->refresh();
        $this->assertSame(PriceListStatus::Active, $draft->status);
    }

    #[Test]
    public function dry_run_has_no_persistent_writes_or_side_effects(): void
    {
        $package = $this->writeMinimalPackage();
        $db = (string) config('database.connections.'.config('database.default').'.database');

        $beforeInventories = Inventory::query()->count();
        $beforePriceLists = PriceList::query()->count();
        $beforeMaxPriceListId = (int) (PriceList::query()->max('id') ?? 0);
        $beforeMaxInventoryId = (int) (Inventory::query()->max('id') ?? 0);

        $writes = [];
        DB::listen(function ($query) use (&$writes): void {
            $sql = strtolower($query->sql);
            if (preg_match('/\b(insert|update|delete)\b/', $sql) === 1) {
                $writes[] = $sql;
            }
        });

        $report = app(MasterDataImporter::class)->import($package, dryRun: true, confirmDatabase: $db);

        $this->assertTrue($report['dry_run']);
        $this->assertGreaterThan(0, $report['created']);
        $this->assertSame([], $writes);
        $this->assertSame($beforeInventories, Inventory::query()->count());
        $this->assertSame($beforePriceLists, PriceList::query()->count());
        $this->assertSame($beforeMaxPriceListId, (int) (PriceList::query()->max('id') ?? 0));
        $this->assertSame($beforeMaxInventoryId, (int) (Inventory::query()->max('id') ?? 0));
    }

    #[Test]
    public function export_does_not_use_exclusive_row_locks(): void
    {
        $this->seed(InitialCatalogMatCoreSeeder::class);
        $this->seedSpotPricesForCodes(['inv_radio_hamburg']);

        $queries = [];
        DB::listen(function ($query) use (&$queries): void {
            $queries[] = strtolower($query->sql);
        });

        app(MasterDataExporter::class)->export(
            $this->tmp.'/export-nolock',
            ['inv_radio_hamburg'],
            'testsha',
        );

        foreach ($queries as $sql) {
            $this->assertStringNotContainsString('for update', $sql);
            $this->assertStringNotContainsString('lock in share mode', $sql);
        }
    }

    #[Test]
    public function export_excludes_online_audio_even_when_assigned_to_spot_inventory(): void
    {
        $this->seed(InitialCatalogMatCoreSeeder::class);
        $this->seedSpotPricesForCodes(['inv_radio_hamburg']);

        $inventory = Inventory::query()->where('code', 'inv_radio_hamburg')->firstOrFail();
        $spots = AdvertisingCategory::query()->where('key', 'spots')->firstOrFail();
        $oaCode = OaTkpCoreMedia::CODES[0];

        $oaMedium = AdvertisingMedium::query()->where('code', $oaCode)->first();
        if ($oaMedium === null) {
            $oaMedium = new AdvertisingMedium;
            $oaMedium->forceFill([
                'code' => $oaCode,
                'category_id' => $spots->id,
                'name' => 'OA Pre-Stream (test)',
                'calculation_method_mode' => 'inherit',
                'default_length_seconds' => 30,
                'is_discountable' => true,
                'is_ae_eligible' => true,
                'is_active' => true,
                'sort' => 99,
                'lock_version' => 1,
            ])->save();
        }

        $ruleExists = InventoryMediumRule::query()
            ->where('inventory_id', $inventory->id)
            ->where('advertising_medium_id', $oaMedium->id)
            ->exists();
        if (! $ruleExists) {
            $rule = new InventoryMediumRule;
            $rule->forceFill([
                'inventory_id' => $inventory->id,
                'advertising_medium_id' => $oaMedium->id,
                'is_active' => true,
                'sort' => 0,
                'is_discountable' => true,
                'is_ae_eligible' => true,
                'component_calculation_strategy' => ComponentCalculationStrategy::SharedTotalLength,
                'lock_version' => 1,
            ])->save();
        }

        $exportPath = $this->tmp.'/export-no-oa';
        app(MasterDataExporter::class)->export($exportPath, ['inv_radio_hamburg'], 'testsha');

        $media = json_decode(File::get($exportPath.'/data/advertising_media.json'), true);
        $rules = json_decode(File::get($exportPath.'/data/inventory_medium_rules.json'), true);
        $mediaCodes = collect($media)->pluck('code')->all();
        $ruleMedia = collect($rules)->pluck('advertising_medium_code')->all();

        $this->assertNotContains($oaCode, $mediaCodes);
        $this->assertNotContains($oaCode, $ruleMedia);
        $this->assertNotContains('native_ad', $mediaCodes);
    }

    #[Test]
    public function manipulated_package_outside_spot_allowlist_is_rejected(): void
    {
        $package = $this->writeMinimalPackage();
        $mediaPath = $package.'/data/advertising_media.json';
        $media = json_decode(File::get($mediaPath), true);
        $media[] = [
            'code' => OaTkpCoreMedia::CODES[0],
            'name' => 'OA eingeschmuggelt',
            'category_key' => 'spots',
            'kind' => null,
            'component_profile' => null,
            'calculation_method_mode' => 'inherit',
            'default_calculation_method_key' => null,
            'default_length_seconds' => 30,
            'is_discountable' => true,
            'is_ae_eligible' => true,
            'is_active' => true,
            'sort' => 99,
        ];
        $json = json_encode($media, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)."\n";
        File::put($mediaPath, $json);

        $checksums = [];
        foreach (MasterDataTransferContract::DATA_FILES as $relative) {
            $checksums[] = hash_file('sha256', $package.'/'.$relative).'  '.$relative;
        }
        File::put($package.'/checksums.sha256', implode("\n", $checksums)."\n");

        $manifest = json_decode(File::get($package.'/manifest.json'), true);
        foreach (MasterDataTransferContract::DATA_FILES as $relative) {
            $absolute = $package.'/'.$relative;
            $manifest['files'][$relative] = [
                'sha256' => hash_file('sha256', $absolute),
                'bytes' => filesize($absolute),
            ];
        }
        // Manifest behält includes_online_audio=false — Scope-Guard muss trotzdem ablehnen.
        $manifest['scope']['includes_online_audio'] = false;
        File::put(
            $package.'/manifest.json',
            json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)."\n",
        );

        $db = (string) config('database.connections.'.config('database.default').'.database');
        $this->expectException(MasterDataTransferException::class);
        $this->expectExceptionMessage('Spot-Scope');
        app(MasterDataImporter::class)->import($package, dryRun: true, confirmDatabase: $db);
    }

    #[Test]
    public function existing_users_calculations_remain_unchanged_on_import(): void
    {
        $package = $this->writeMinimalPackage();
        $db = (string) config('database.connections.'.config('database.default').'.database');

        $user = User::factory()->create([
            'email' => 'keep-me@example.test',
            'role' => Role::Admin,
            'is_active' => true,
        ]);
        $calc = Calculation::factory()->create([
            'advisor_id' => $user->id,
            'number' => 'K-2026-42421',
            'number_year' => 2026,
            'number_seq' => 42421,
        ]);
        $userEmail = $user->email;
        $userRole = $user->role;
        $calcNumber = $calc->number;
        $calcAdvisorId = $calc->advisor_id;

        app(MasterDataImporter::class)->import($package, dryRun: false, confirmDatabase: $db);

        $user->refresh();
        $calc->refresh();
        $this->assertSame($userEmail, $user->email);
        $this->assertSame($userRole, $user->role);
        $this->assertSame($calcNumber, $calc->number);
        $this->assertSame($calcAdvisorId, $calc->advisor_id);
        $this->assertSame(1, User::query()->where('email', 'keep-me@example.test')->count());
        $this->assertSame(1, Calculation::query()->where('number', 'K-2026-42421')->count());
    }

    /**
     * @param  list<string>  $codes
     */
    private function seedSpotPricesForCodes(array $codes): void
    {
        foreach ($codes as $code) {
            $inventory = Inventory::query()->where('code', $code)->firstOrFail();
            foreach ([2026, 2027] as $year) {
                $list = PriceList::factory()->create([
                    'inventory_id' => $inventory->id,
                    'name' => "Import {$year} {$code}",
                    'year' => $year,
                    'revision_number' => 1,
                    'version' => '1',
                    'status' => PriceListStatus::Active,
                ]);
                if (Schema::hasColumn('price_lists', 'active_inventory_id')) {
                    $list->forceFill([
                        'active_inventory_id' => $inventory->id,
                        'active_year' => $year,
                    ])->save();
                }
                PriceListItem::factory()->create([
                    'price_list_id' => $list->id,
                    'hour' => 8,
                    'day_group' => 'mo_fr',
                    'second_price' => '1.2500',
                ]);
            }
            ProductionPriceList::factory()->create([
                'inventory_id' => $inventory->id,
                'production_type' => ProductionType::SpotProduction,
                'year' => 2026,
                'revision_number' => 1,
                'version' => '1',
                'status' => PriceListStatus::Active,
                'unit_price' => '600.00',
            ]);
        }
    }

    private function writeMinimalPackage(
        string $inventoryCode = 'inv_radio_hamburg',
        int $year = 2026,
        int $revision = 1,
    ): string {
        $path = $this->tmp.'/pkg-'.uniqid('', true);
        $category = AdvertisingCategory::query()->where('key', 'spots')->firstOrFail();
        $method = CalculationMethod::query()->where('key', 'average')->firstOrFail();

        // Ensure medium exists in package even if DB empty for media
        $files = [
            'data/organization.json' => ['name' => 'more Marketing'],
            'data/advertising_categories.json' => [[
                'key' => 'spots',
                'name' => $category->name,
                'is_active' => true,
                'sort' => (int) $category->sort,
                'default_calculation_method_key' => 'average',
            ]],
            'data/calculation_methods.json' => [[
                'key' => 'average',
                'name' => $method->name,
                'help_text' => $method->help_text,
                'is_active' => true,
                'sort' => (int) $method->sort,
            ]],
            'data/inventories.json' => [[
                'code' => $inventoryCode,
                'name' => 'Radio Hamburg',
                'type' => InventoryType::Sender->value,
                'is_active' => true,
                'sort' => 1,
            ]],
            'data/advertising_media.json' => [[
                'code' => 'spot_classic',
                'name' => 'Werbespot',
                'category_key' => 'spots',
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
            'data/inventory_medium_rules.json' => [[
                'inventory_code' => $inventoryCode,
                'advertising_medium_code' => 'spot_classic',
                'is_active' => true,
                'booking_code' => 'Spots (L)',
                'planning_responsibility_key' => 'disposition',
                'hint_text' => null,
                'sort' => 0,
                'default_length_seconds' => 30,
                'surcharge_percent' => '0.0000',
                'is_discountable' => true,
                'is_ae_eligible' => true,
                'component_calculation_strategy' => 'shared_total_length',
                'swf_allonge_confirmed_at' => null,
            ]],
            'data/advertising_category_calculation_methods.json' => [[
                'category_key' => 'spots',
                'calculation_method_key' => 'average',
                'engine_profile_key' => 'spot_classic',
                'is_active' => true,
                'sort' => 10,
            ]],
            'data/advertising_medium_calculation_methods.json' => [],
            'data/price_lists.json' => [[
                'inventory_code' => $inventoryCode,
                'name' => "Import {$year} {$inventoryCode}",
                'year' => $year,
                'version' => (string) $revision,
                'revision_number' => $revision,
                'source_status' => 'active',
                'import_status' => 'draft',
                'valid_from' => null,
                'items' => [[
                    'hour' => 0,
                    'day_group' => 'mo_fr',
                    'second_price' => '1.0000',
                ]],
            ]],
            'data/production_price_lists.json' => [[
                'inventory_code' => $inventoryCode,
                'production_type' => ProductionType::SpotProduction->value,
                'year' => $year,
                'name' => 'Spotproduktion',
                'version' => '1',
                'revision_number' => 1,
                'source_status' => 'active',
                'import_status' => 'draft',
                'unit_price' => '600.00',
                'is_discountable' => false,
                'is_ae_eligible' => false,
            ]],
        ];

        $manifest = [
            'format' => MasterDataTransferContract::FORMAT,
            'schema_version' => MasterDataTransferContract::SCHEMA_VERSION,
            'epic' => MasterDataTransferContract::EPIC,
            'exported_at' => now()->utc()->toIso8601String(),
            'source' => ['database' => 'fixture', 'connection' => 'sqlite', 'git_sha' => null],
            'scope' => [
                'inventory_codes' => [$inventoryCode],
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
