<?php

namespace Tests\Feature\MasterDataTransfer;

use App\Enums\InventoryType;
use App\Enums\PriceListStatus;
use App\Enums\ProductionType;
use App\Enums\Role;
use App\Models\AdvertisingCategory;
use App\Models\AdvertisingMedium;
use App\Models\AdvertisingMediumCalculationMethod;
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
use Database\Seeders\InitialCatalogMatCoreSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
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
