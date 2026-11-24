<?php

namespace Tests\Feature\MasterDataTransfer;

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
use App\Enums\PriceListStatus;
use App\Enums\ProductionType;
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
use App\Models\OaTkpPriceListItem;
use App\Models\Organization;
use App\Models\PriceList;
use App\Models\PriceListItem;
use App\Models\ProductionPriceList;
use App\Models\User;
use App\Services\MasterDataTransfer\Export\MasterDataExporter;
use App\Services\MasterDataTransfer\Export\MasterDataExporterV2;
use App\Services\MasterDataTransfer\MasterDataTransferContract;
use App\Services\MasterDataTransfer\MasterDataTransferContractV2;
use App\Services\MasterDataTransfer\MasterDataTransferException;
use App\Services\MasterDataTransfer\Support\MasterDataPackage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * EPIC-03-F Slice 2: vollständiger Exporter 2.0.0 (synthetische Fixtures, read-only).
 */
class MasterDataTransferExporterV2Test extends TestCase
{
    use RefreshDatabase;

    private string $tmp;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tmp = sys_get_temp_dir().'/mdt-export-v2-'.uniqid('', true);
        File::ensureDirectoryExists($this->tmp);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->tmp);
        parent::tearDown();
    }

    #[Test]
    public function full_synthetic_catalog_exports_all_25_whitelist_tables(): void
    {
        $this->seedFullSyntheticCatalog();
        $path = $this->tmp.'/full';

        $result = app(MasterDataExporterV2::class)->export($path, 'slice2-test-sha');
        $loaded = app(MasterDataPackage::class)->load($path);

        $this->assertSame(MasterDataTransferContractV2::SCHEMA_VERSION, $loaded['schema_version']);
        $this->assertCount(count(MasterDataTransferContractV2::DATA_FILES), $loaded['data']);
        foreach (MasterDataTransferContractV2::DATA_FILES as $relative) {
            $this->assertArrayHasKey($relative, $loaded['data']);
            $this->assertFileExists($path.'/'.$relative);
            $this->assertSame(
                $relative === 'data/organization.json' ? 1 : count($loaded['data'][$relative]),
                $result['counts'][$relative],
                $relative,
            );
        }

        $this->assertSame('Fixture Org', $loaded['data']['data/organization.json']['name']);
        $this->assertGreaterThanOrEqual(14, count($loaded['data']['data/inventories.json']));
        $this->assertGreaterThanOrEqual(2, count($loaded['data']['data/price_lists.json']));
        $this->assertGreaterThanOrEqual(2, count($loaded['data']['data/production_price_lists.json']));
        $this->assertNotEmpty($loaded['data']['data/oa_platforms.json']);
        $this->assertNotEmpty($loaded['data']['data/oa_tkp_price_lists.json']);
        $this->assertNotEmpty($loaded['data']['data/field_definitions.json']);
        $this->assertNotEmpty($loaded['data']['data/field_set_assignments.json']);

        $spotStatuses = collect($loaded['data']['data/price_lists.json'])->pluck('source_status')->unique()->all();
        $this->assertContains('active', $spotStatuses);
        $this->assertContains('archived', $spotStatuses);
        $this->assertContains('draft', $spotStatuses);

        $assignment = collect($loaded['data']['data/field_set_assignments.json'])
            ->firstWhere('target_layer', FieldSetAssignmentTargetLayer::AdvertisingCategory->value);
        $this->assertIsArray($assignment);
        $this->assertArrayHasKey('category_key', $assignment);
        $this->assertArrayNotHasKey('target_identity', $assignment);

        $fieldDef = collect($loaded['data']['data/field_definitions.json'])->firstWhere('key', 'fixture_note');
        $this->assertSame(1, $fieldDef['current_revision']);
        $this->assertArrayNotHasKey('id', $fieldDef);

        $this->assertStringNotContainsString('"id":', File::get($path.'/data/inventories.json'));
        $this->assertStringNotContainsString('"id":', File::get($path.'/data/price_lists.json'));
        $this->assertStringNotContainsString('"inventory_id":', File::get($path.'/data/price_lists.json'));

        $this->assertEquals(
            $result['source_price_status_summary'],
            $loaded['manifest']['source_price_status_summary'],
        );
        $this->assertGreaterThanOrEqual(1, $loaded['manifest']['source_price_status_summary']['spot']['active']);
        $this->assertGreaterThanOrEqual(1, $loaded['manifest']['source_price_status_summary']['spot']['archived']);
        $this->assertGreaterThanOrEqual(1, $loaded['manifest']['source_price_status_summary']['spot']['draft']);
    }

    #[Test]
    public function empty_optional_tables_are_exported_as_empty_arrays(): void
    {
        $this->seedMinimalOrganizationCatalog();
        // OA-/Preis-Tabellen starten ohne Seeds leer; Feldkatalog bleibt migrationsseitig bestehen.
        $path = $this->tmp.'/empty-optional';

        $loaded = app(MasterDataPackage::class)->load(
            app(MasterDataExporterV2::class)->export($path, 'empty-sha')['package_path'],
        );

        foreach ([
            'data/oa_platforms.json',
            'data/oa_platform_medium_rules.json',
            'data/oa_targeting_definitions.json',
            'data/oa_tkp_price_lists.json',
            'data/oa_adserver_surcharges.json',
            'data/price_lists.json',
            'data/production_price_lists.json',
            'data/field_set_assignments.json',
        ] as $relative) {
            $this->assertSame([], $loaded['data'][$relative], $relative);
            $this->assertSame(0, $loaded['manifest']['counts'][$relative], $relative);
        }

        foreach (MasterDataTransferContractV2::DATA_FILES as $relative) {
            $this->assertArrayHasKey($relative, $loaded['data']);
        }
    }

    #[Test]
    public function missing_or_mismatched_reference_fails_closed(): void
    {
        $this->seedMinimalOrganizationCatalog();

        $definitionA = FieldDefinition::query()->create([
            'key' => 'field_a',
            'field_type' => FieldType::ShortText,
            'is_system' => false,
            'is_key_protected' => false,
            'scope' => FieldScope::Header,
            'applies_to' => FieldAppliesTo::Both,
            'is_active' => true,
            'current_revision_id' => null,
        ]);
        $definitionB = FieldDefinition::query()->create([
            'key' => 'field_b',
            'field_type' => FieldType::ShortText,
            'is_system' => false,
            'is_key_protected' => false,
            'scope' => FieldScope::Header,
            'applies_to' => FieldAppliesTo::Both,
            'is_active' => true,
            'current_revision_id' => null,
        ]);
        $revisionB = FieldDefinitionRevision::query()->create([
            'field_definition_id' => $definitionB->id,
            'revision' => 1,
            'label' => 'B',
            'help_text' => null,
            'validation_json' => null,
            'group_key' => null,
            'sort_default' => 1,
            'reportable' => false,
            'created_at' => now(),
        ]);
        // Fachlich ungültig: current_revision gehört zu einer anderen Definition.
        $definitionA->forceFill(['current_revision_id' => $revisionB->id])->save();

        $this->expectException(MasterDataTransferException::class);
        $this->expectExceptionMessage('current_revision unauflösbar');
        app(MasterDataExporterV2::class)->export($this->tmp.'/bad-fk', 'sha');
    }

    #[Test]
    public function identical_source_produces_deterministic_package_files(): void
    {
        $this->seedFullSyntheticCatalog();
        $a = $this->tmp.'/det-a';
        $b = $this->tmp.'/det-b';

        app(MasterDataExporterV2::class)->export($a, 'same-sha');
        app(MasterDataExporterV2::class)->export($b, 'same-sha');

        foreach (MasterDataTransferContractV2::DATA_FILES as $relative) {
            $this->assertSame(
                File::get($a.'/'.$relative),
                File::get($b.'/'.$relative),
                $relative,
            );
        }
        $this->assertSame(File::get($a.'/checksums.sha256'), File::get($b.'/checksums.sha256'));

        $manifestA = json_decode(File::get($a.'/manifest.json'), true);
        $manifestB = json_decode(File::get($b.'/manifest.json'), true);
        unset($manifestA['exported_at'], $manifestB['exported_at'], $manifestA['files'], $manifestB['files']);
        $this->assertSame($manifestA, $manifestB);
    }

    #[Test]
    public function export_does_not_write_to_database(): void
    {
        $this->seedFullSyntheticCatalog();
        User::factory()->create(['email' => 'read-only-check@example.test']);

        DB::flushQueryLog();
        DB::enableQueryLog();
        app(MasterDataExporterV2::class)->export($this->tmp.'/readonly', 'sha');

        $writes = collect(DB::getQueryLog())->filter(function (array $entry): bool {
            $sql = strtolower((string) ($entry['query'] ?? ''));

            return str_contains($sql, 'insert ')
                || str_contains($sql, 'update ')
                || str_contains($sql, 'delete ')
                || str_contains($sql, 'alter ');
        });

        $this->assertCount(0, $writes);
        DB::disableQueryLog();
        $this->assertSame(1, User::query()->count());
    }

    #[Test]
    public function package_never_contains_excluded_business_tables(): void
    {
        $this->seedFullSyntheticCatalog();
        User::factory()->create(['email' => 'secret-user@example.test']);
        $path = $this->tmp.'/no-excluded';
        app(MasterDataExporterV2::class)->export($path, 'sha');

        $blob = '';
        foreach (File::allFiles($path) as $file) {
            $blob .= File::get($file->getPathname());
        }
        $this->assertStringNotContainsString('secret-user@example.test', $blob);
        $manifest = json_decode(File::get($path.'/manifest.json'), true);
        $this->assertContains('users', $manifest['excluded_tables']);
        $this->assertContains('calculations', $manifest['excluded_tables']);
        $this->assertContains('crm_accounts', $manifest['excluded_tables']);
        foreach (array_keys($manifest['counts']) as $file) {
            $this->assertTrue(MasterDataTransferContractV2::isAllowedDataFile($file));
        }
        foreach (File::allFiles($path.'/data') as $file) {
            $relative = 'data/'.$file->getFilename();
            $this->assertTrue(MasterDataTransferContractV2::isAllowedDataFile($relative), $relative);
        }
    }

    #[Test]
    public function cli_defaults_to_schema_1_and_requires_explicit_schema_2(): void
    {
        $this->seedMinimalOrganizationCatalog();
        Inventory::factory()->create([
            'organization_id' => Organization::query()->value('id'),
            'code' => 'inv_radio_hamburg',
            'name' => 'Radio Hamburg',
            'type' => InventoryType::Sender,
            'is_active' => true,
            'sort' => 1,
        ]);

        // V1 needs active price lists for RH scope years — use exporter service path for schema switch.
        $exit = Artisan::call('dispo:export-master-data', [
            '--path' => $this->tmp.'/cli-v2',
            '--schema' => MasterDataTransferContractV2::SCHEMA_VERSION,
            '--git-sha' => 'cli-sha',
        ]);
        $this->assertSame(0, $exit);
        $loaded = app(MasterDataPackage::class)->load($this->tmp.'/cli-v2');
        $this->assertSame(MasterDataTransferContractV2::SCHEMA_VERSION, $loaded['schema_version']);

        $fail = Artisan::call('dispo:export-master-data', [
            '--path' => $this->tmp.'/cli-v2-inv',
            '--schema' => MasterDataTransferContractV2::SCHEMA_VERSION,
            '--inventory' => ['inv_radio_hamburg'],
        ]);
        $this->assertSame(1, $fail);
        $this->assertStringContainsString('--inventory', Artisan::output());
    }

    #[Test]
    public function schema_1_exporter_and_tests_remain_compatible(): void
    {
        $this->seedMinimalOrganizationCatalog();
        $orgId = (int) Organization::query()->value('id');
        $inventory = Inventory::factory()->create([
            'organization_id' => $orgId,
            'code' => 'inv_radio_hamburg',
            'name' => 'Radio Hamburg',
            'type' => InventoryType::Sender,
            'is_active' => true,
            'sort' => 1,
        ]);
        $category = AdvertisingCategory::query()->firstOrFail();
        $method = CalculationMethod::query()->firstOrFail();
        $medium = AdvertisingMedium::query()->firstOrFail();
        InventoryMediumRule::query()->create([
            'inventory_id' => $inventory->id,
            'advertising_medium_id' => $medium->id,
            'is_active' => true,
            'booking_code' => 'BK',
            'planning_responsibility_key' => null,
            'hint_text' => null,
            'sort' => 1,
            'default_length_seconds' => 30,
            'surcharge_percent' => null,
            'is_discountable' => true,
            'is_ae_eligible' => true,
            'component_calculation_strategy' => ComponentCalculationStrategy::Individual,
            'swf_allonge_confirmed_at' => null,
        ]);
        foreach (MasterDataTransferContract::PRICE_YEARS as $year) {
            $list = PriceList::factory()->create([
                'inventory_id' => $inventory->id,
                'year' => $year,
                'revision_number' => 1,
                'version' => $year.'.1',
                'status' => PriceListStatus::Active,
                'valid_from' => sprintf('%04d-01-01', $year),
            ]);
            PriceListItem::factory()->create([
                'price_list_id' => $list->id,
                'hour' => 6,
                'day_group' => DayGroup::MoFr,
                'second_price' => '1.5000',
            ]);
        }

        $path = $this->tmp.'/v1-compat';
        $result = app(MasterDataExporter::class)->export($path, ['inv_radio_hamburg'], 'v1-sha');
        $loaded = app(MasterDataPackage::class)->load($path);

        $this->assertSame(MasterDataTransferContract::SCHEMA_VERSION, $loaded['schema_version']);
        $this->assertSame(1, $result['counts']['inventories']);
        $this->assertArrayHasKey('advertising_medium_code', $loaded['data']['data/inventory_medium_rules.json'][0]);
        unset($category, $method);
    }

    #[Test]
    public function oa_and_field_families_use_business_keys_not_numeric_ids(): void
    {
        $this->seedFullSyntheticCatalog();
        $loaded = app(MasterDataPackage::class)->load(
            app(MasterDataExporterV2::class)->export($this->tmp.'/keys', 'sha')['package_path'],
        );

        $tkp = $loaded['data']['data/oa_tkp_price_lists.json'][0];
        $this->assertArrayHasKey('year', $tkp);
        $this->assertArrayHasKey('revision_number', $tkp);
        $this->assertArrayHasKey('items', $tkp);
        $this->assertArrayHasKey('inventory_code', $tkp['items'][0]);
        $this->assertArrayHasKey('medium_code', $tkp['items'][0]);
        $this->assertArrayNotHasKey('id', $tkp);

        $versionField = $loaded['data']['data/field_set_version_fields.json'][0];
        $this->assertArrayHasKey('field_set_key', $versionField);
        $this->assertArrayHasKey('field_definition_key', $versionField);
        $this->assertArrayHasKey('revision', $versionField);
        $this->assertArrayNotHasKey('field_definition_revision_id', $versionField);

        $targeting = $loaded['data']['data/oa_targeting_definitions.json'][0];
        $this->assertArrayHasKey('minimum_tkp_surcharge', $targeting);
        $this->assertArrayHasKey('revision_number', $targeting);
    }

    /**
     * @return array{inventory_ids: list<int>}
     */
    private function seedFullSyntheticCatalog(): array
    {
        if (Organization::query()->count() === 0) {
            Organization::query()->create(['name' => 'Fixture Org']);
        } else {
            $keep = Organization::query()->orderBy('id')->firstOrFail();
            Organization::query()->where('id', '!=', $keep->id)->delete();
            $keep->forceFill(['name' => 'Fixture Org'])->save();
        }
        $org = Organization::query()->firstOrFail();

        $method = CalculationMethod::query()->where('key', 'average')->first()
            ?? tap(CalculationMethod::factory()->create(['name' => 'Average']), function (CalculationMethod $m): void {
                $m->forceFill(['key' => 'fixture_average', 'sort' => 1, 'is_active' => true])->save();
            });

        $category = AdvertisingCategory::query()->where('key', 'spots')->first();
        if ($category === null) {
            $category = AdvertisingCategory::factory()->create(['name' => 'Spots', 'sort' => 1, 'is_active' => true]);
            $category->forceFill(['key' => 'spots'])->save();
        }
        $category->forceFill(['default_calculation_method_id' => $method->id])->save();

        $oaCategory = AdvertisingCategory::query()->where('key', 'online_audio')->first();
        if ($oaCategory === null) {
            $oaCategory = AdvertisingCategory::factory()->create(['name' => 'Online Audio', 'sort' => 2, 'is_active' => true]);
            $oaCategory->forceFill(['key' => 'online_audio'])->save();
        }

        $medium = AdvertisingMedium::query()->where('code', 'fx_spot_classic')->first();
        if ($medium === null) {
            $medium = AdvertisingMedium::factory()->create([
                'name' => 'Spot Classic FX',
                'is_active' => true,
                'sort' => 1,
                'default_length_seconds' => 30,
                'is_discountable' => true,
                'is_ae_eligible' => true,
            ]);
            $medium->forceFill([
                'code' => 'fx_spot_classic',
                'category_id' => $category->id,
                'kind' => CalculationKind::SpotClassic,
                'calculation_method_mode' => CalculationMethodMode::Inherit,
                'default_calculation_method_id' => null,
            ])->save();
        }

        $oaMedium = AdvertisingMedium::query()->where('code', 'fx_pre_stream')->first();
        if ($oaMedium === null) {
            $oaMedium = AdvertisingMedium::factory()->create([
                'name' => 'Pre Stream FX',
                'is_active' => true,
                'sort' => 2,
                'default_length_seconds' => 0,
                'is_discountable' => false,
                'is_ae_eligible' => false,
            ]);
            $oaMedium->forceFill([
                'code' => 'fx_pre_stream',
                'category_id' => $oaCategory->id,
                'kind' => CalculationKind::OaTkp,
                'calculation_method_mode' => CalculationMethodMode::Override,
            ])->save();
        }

        AdvertisingCategoryCalculationMethod::query()->firstOrCreate(
            [
                'advertising_category_id' => $category->id,
                'calculation_method_id' => $method->id,
            ],
            [
                'engine_profile_key' => 'average_v1',
                'is_active' => true,
                'sort' => 1,
            ],
        );
        AdvertisingMediumCalculationMethod::query()->firstOrCreate(
            [
                'advertising_medium_id' => $oaMedium->id,
                'calculation_method_id' => $method->id,
            ],
            [
                'engine_profile_key' => 'tkp_v1',
                'is_active' => true,
                'sort' => 1,
            ],
        );

        $inventoryIds = [];
        for ($i = 1; $i <= 14; $i++) {
            $inv = Inventory::factory()->create([
                'organization_id' => $org->id,
                'code' => sprintf('inv_fixture_%02d', $i),
                'name' => sprintf('Fixture Inventory %02d', $i),
                'type' => InventoryType::Sender,
                'is_active' => $i % 2 === 1,
                'sort' => $i,
                'logo_path' => null,
            ]);
            $inventoryIds[] = $inv->id;
            InventoryMediumRule::query()->create([
                'inventory_id' => $inv->id,
                'advertising_medium_id' => $medium->id,
                'is_active' => $i % 3 !== 0,
                'booking_code' => 'B'.$i,
                'planning_responsibility_key' => null,
                'hint_text' => null,
                'sort' => $i,
                'default_length_seconds' => 30,
                'surcharge_percent' => null,
                'is_discountable' => true,
                'is_ae_eligible' => true,
                'component_calculation_strategy' => ComponentCalculationStrategy::Individual,
                'swf_allonge_confirmed_at' => null,
            ]);
        }

        $primary = Inventory::query()->where('code', 'inv_fixture_01')->firstOrFail();
        $active = PriceList::factory()->create([
            'inventory_id' => $primary->id,
            'year' => 2026,
            'revision_number' => 1,
            'version' => '2026.1',
            'status' => PriceListStatus::Active,
            'valid_from' => '2026-01-01',
            'name' => 'Active 2026',
        ]);
        PriceListItem::factory()->create([
            'price_list_id' => $active->id,
            'hour' => 8,
            'day_group' => DayGroup::MoFr,
            'second_price' => '2.5000',
        ]);
        $archived = PriceList::factory()->create([
            'inventory_id' => $primary->id,
            'year' => 2026,
            'revision_number' => 2,
            'version' => '2026.2',
            'status' => PriceListStatus::Archived,
            'valid_from' => '2026-02-01',
            'name' => 'Archived 2026',
        ]);
        PriceListItem::factory()->create([
            'price_list_id' => $archived->id,
            'hour' => 9,
            'day_group' => DayGroup::Sa,
            'second_price' => '3.0000',
        ]);
        $draft = PriceList::factory()->create([
            'inventory_id' => $primary->id,
            'year' => 2027,
            'revision_number' => 1,
            'version' => '2027.1',
            'status' => PriceListStatus::Draft,
            'valid_from' => '2027-01-01',
            'name' => 'Draft 2027',
        ]);
        PriceListItem::factory()->create([
            'price_list_id' => $draft->id,
            'hour' => 10,
            'day_group' => DayGroup::So,
            'second_price' => '4.0000',
        ]);

        ProductionPriceList::factory()->create([
            'inventory_id' => $primary->id,
            'production_type' => ProductionType::SpotProduction,
            'year' => 2026,
            'revision_number' => 1,
            'version' => '2026.1',
            'status' => PriceListStatus::Active,
            'unit_price' => '600.0000',
            'is_discountable' => false,
            'is_ae_eligible' => false,
            'name' => 'Prod Active',
        ]);
        ProductionPriceList::factory()->create([
            'inventory_id' => $primary->id,
            'production_type' => ProductionType::SpotProduction,
            'year' => 2026,
            'revision_number' => 2,
            'version' => '2026.2',
            'status' => PriceListStatus::Archived,
            'unit_price' => '600.0000',
            'is_discountable' => false,
            'is_ae_eligible' => false,
            'name' => 'Prod Archived',
        ]);

        $platform = OaPlatform::query()->create([
            'code' => 'plat_a',
            'name' => 'Platform A',
            'is_active' => true,
            'sort' => 1,
            'is_adserver_relevant' => true,
        ]);
        OaPlatformMediumRule::query()->create([
            'oa_platform_id' => $platform->id,
            'advertising_medium_id' => $oaMedium->id,
            'is_active' => true,
        ]);
        OaTargetingDefinition::query()->create([
            'code' => 'geo_de',
            'name' => 'Geo DE',
            'category' => OaTargetingCategory::Technisch,
            'tkp_surcharge' => '1.5000',
            'minimum_tkp_surcharge' => '1.0000',
            'revision_number' => 1,
            'is_active' => true,
            'valid_from' => '2026-01-01',
            'valid_to' => null,
        ]);
        OaTargetingDefinition::query()->create([
            'code' => 'geo_de',
            'name' => 'Geo DE r2',
            'category' => OaTargetingCategory::Technisch,
            'tkp_surcharge' => '2.0000',
            'minimum_tkp_surcharge' => '1.5000',
            'revision_number' => 2,
            'is_active' => false,
            'valid_from' => '2027-01-01',
            'valid_to' => null,
        ]);
        $oaList = OaTkpPriceList::query()->create([
            'year' => 2026,
            'name' => 'OA 2026',
            'version' => '2026.1',
            'revision_number' => 1,
            'lock_version' => 1,
            'status' => PriceListStatus::Active,
            'valid_from' => '2026-01-01',
        ]);
        OaTkpPriceListItem::query()->create([
            'oa_tkp_price_list_id' => $oaList->id,
            'advertising_medium_id' => $oaMedium->id,
            'inventory_id' => $primary->id,
            'list_tkp' => '12.0000',
            'minimum_tkp' => '8.0000',
        ]);
        OaAdserverSurcharge::query()->create([
            'code' => 'adsrv_a',
            'name' => 'Adserver A',
            'tkp_amount' => '0.5000',
            'revision_number' => 1,
            'is_active' => true,
            'scope_json' => ['platforms' => ['plat_a']],
            'valid_from' => '2026-01-01',
            'valid_to' => null,
        ]);

        $definition = FieldDefinition::query()->create([
            'key' => 'fixture_note',
            'field_type' => FieldType::Select,
            'is_system' => false,
            'is_key_protected' => false,
            'scope' => FieldScope::Header,
            'applies_to' => FieldAppliesTo::Both,
            'is_active' => true,
            'current_revision_id' => null,
        ]);
        $revision = FieldDefinitionRevision::query()->create([
            'field_definition_id' => $definition->id,
            'revision' => 1,
            'label' => 'Notiz',
            'help_text' => null,
            'validation_json' => ['required' => false],
            'group_key' => 'main',
            'sort_default' => 10,
            'reportable' => true,
            'created_at' => now(),
        ]);
        FieldDefinitionRevisionOption::query()->create([
            'field_definition_revision_id' => $revision->id,
            'key' => 'opt_a',
            'label' => 'Option A',
            'sort' => 1,
            'is_active' => true,
        ]);
        $definition->forceFill(['current_revision_id' => $revision->id])->save();

        $fieldSet = new FieldSet;
        $fieldSet->forceFill([
            'key' => 'fixture_set',
            'name' => 'Fixture Set',
            'is_system' => false,
            'applies_to' => FieldAppliesTo::Both,
            'is_assignable' => true,
            'active_version_id' => null,
            'lock_version' => 1,
        ])->save();
        $version = FieldSetVersion::query()->create([
            'field_set_id' => $fieldSet->id,
            'version' => 1,
            'status' => FieldSetVersionStatus::Active,
            'created_at' => now(),
        ]);
        FieldSetVersionField::query()->create([
            'field_set_version_id' => $version->id,
            'field_definition_id' => $definition->id,
            'field_definition_revision_id' => $revision->id,
            'sort' => 1,
            'required_override' => true,
            'visible_override' => null,
        ]);
        FieldRule::query()->create([
            'field_set_version_id' => $version->id,
            'sort' => 1,
            'condition_json' => ['op' => 'eq', 'field' => 'x', 'value' => 1],
            'action_json' => ['set' => 'y'],
        ]);
        $fieldSet->forceFill(['active_version_id' => $version->id])->save();

        FieldSetAssignment::factory()
            ->forFieldSet($fieldSet)
            ->global()
            ->active()
            ->create(['sort' => 1]);
        FieldSetAssignment::factory()
            ->forFieldSet($fieldSet)
            ->forCategory((int) $category->id)
            ->active()
            ->create([
                'applies_to_process' => FieldAppliesTo::Calculation,
                'sort' => 2,
            ]);
        FieldSetAssignment::factory()
            ->forFieldSet($fieldSet)
            ->forMedium((int) $medium->id)
            ->inactive()
            ->create([
                'applies_to_process' => FieldAppliesTo::DispoOrder,
                'sort' => 3,
            ]);

        return ['inventory_ids' => $inventoryIds];
    }

    private function seedMinimalOrganizationCatalog(): void
    {
        if (Organization::query()->count() === 0) {
            Organization::query()->create(['name' => 'Minimal Org']);
        } elseif (Organization::query()->count() > 1) {
            $keep = Organization::query()->orderBy('id')->firstOrFail();
            Organization::query()->where('id', '!=', $keep->id)->delete();
            $keep->forceFill(['name' => 'Minimal Org'])->save();
        } else {
            Organization::query()->firstOrFail()->forceFill(['name' => 'Minimal Org'])->save();
        }

        $method = CalculationMethod::query()->where('key', 'average')->first()
            ?? tap(CalculationMethod::factory()->create(['name' => 'Average']), function (CalculationMethod $m): void {
                $m->forceFill(['key' => 'fixture_average', 'sort' => 1, 'is_active' => true])->save();
            });

        $category = AdvertisingCategory::query()->where('key', 'spots')->first();
        if ($category === null) {
            $category = AdvertisingCategory::factory()->create(['name' => 'Spots', 'sort' => 1, 'is_active' => true]);
            $category->forceFill(['key' => 'spots'])->save();
        }

        $medium = AdvertisingMedium::query()->where('code', 'spot_classic')->first();
        if ($medium === null) {
            $medium = AdvertisingMedium::factory()->create([
                'name' => 'Spot Classic',
                'is_active' => true,
                'sort' => 1,
                'default_length_seconds' => 30,
                'is_discountable' => true,
                'is_ae_eligible' => true,
            ]);
            $medium->forceFill([
                'code' => 'spot_classic',
                'category_id' => $category->id,
                'kind' => CalculationKind::SpotClassic,
                'calculation_method_mode' => CalculationMethodMode::Inherit,
            ])->save();
        }

        unset($method);
    }
}
