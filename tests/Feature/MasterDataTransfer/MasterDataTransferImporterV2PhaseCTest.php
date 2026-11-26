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
use App\Services\MasterDataTransfer\Support\MasterDataPackage;
use App\Services\MasterDataTransfer\Support\MasterDataProtectedStateSnapshotV2;
use Database\Seeders\InitialCatalogMatCoreSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * EPIC-03-F Slice 5 Phase C: Schutz-Snapshots, Pins, Whitelist (CLI gesperrt).
 */
class MasterDataTransferImporterV2PhaseCTest extends TestCase
{
    use RefreshDatabase;

    private string $tmp;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tmp = sys_get_temp_dir().'/mdt-v2-phase-c-'.uniqid('', true);
        File::ensureDirectoryExists($this->tmp);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->tmp);
        FieldDefinition::flushEventListeners();
        Organization::flushEventListeners();
        parent::tearDown();
    }

    #[Test]
    public function phase_c_dry_run_passes_without_writes_and_reports_security(): void
    {
        User::factory()->create(['email' => 'phase-c@example.test', 'role' => Role::Admin, 'is_active' => true]);
        $package = $this->writeRichV2Package();
        $beforeInv = Inventory::query()->count();

        $report = app(MasterDataImporterV2::class)->executePhasesABCForIsolatedTests($package, dryRun: true);

        $this->assertTrue($report['dry_run']);
        $this->assertSame('PASS', $report['security']['result']);
        $this->assertSame('pass', $report['security']['protection_snapshots']);
        $this->assertSame('pass', $report['security']['whitelist']);
        $this->assertGreaterThan(0, $report['security']['protected_table_count']);
        $this->assertSame($beforeInv, Inventory::query()->count());
        $this->assertSame(0, Inventory::query()->where('code', 'inv_v2_radio')->count());
    }

    #[Test]
    public function phase_c_apply_creates_within_whitelist_and_keeps_protection(): void
    {
        User::factory()->create(['email' => 'phase-c-apply@example.test', 'role' => Role::Admin, 'is_active' => true]);
        $package = $this->writeRichV2Package();
        $report = app(MasterDataImporterV2::class)->executePhasesABCForIsolatedTests($package, dryRun: false);

        $this->assertSame('PASS', $report['security']['result']);
        $this->assertGreaterThan(0, $report['created']);
        $this->assertNotNull(Inventory::query()->where('code', 'inv_v2_radio')->first());
        $this->assertSame(1, User::query()->count());
    }

    #[Test]
    public function user_content_drift_same_count_stops_and_rolls_back(): void
    {
        $user = User::factory()->create([
            'email' => 'drift-user@example.test',
            'name' => 'Original',
            'role' => Role::Admin,
            'is_active' => true,
        ]);
        $package = $this->writeRichV2Package();

        Organization::saved(function () use ($user): void {
            DB::table('users')->where('id', $user->id)->update(['name' => 'Mutated']);
        });

        try {
            app(MasterDataImporterV2::class)->executePhasesABCForIsolatedTests($package, dryRun: false);
            $this->fail('User-Drift muss STOP auslösen');
        } catch (MasterDataTransferException $e) {
            $blob = $e->getMessage().' '.implode(' ', $e->errors);
            $this->assertTrue(
                str_contains($blob, 'users') || str_contains($blob, 'Schutz') || str_contains($blob, 'Whitelist'),
                $blob,
            );
        } finally {
            Organization::flushEventListeners();
        }

        $this->assertSame(0, Inventory::query()->where('code', 'inv_v2_radio')->count());
        $this->assertSame('Original', $user->fresh()->name);
    }

    #[Test]
    public function crm_and_sequence_and_excluded_insert_drift_stop(): void
    {
        User::factory()->create(['email' => 'crm@example.test', 'role' => Role::Admin, 'is_active' => true]);
        $accountId = DB::table('crm_accounts')->insertGetId([
            'type' => 'account',
            'is_provisional' => true,
            'lock_version' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('calculation_number_sequences')->insertOrIgnore([
            'year' => 2026,
            'last_seq' => 10,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $package = $this->writeRichV2Package();
        Organization::saved(function () use ($accountId): void {
            DB::table('crm_accounts')->where('id', $accountId)->update(['matching_domain' => 'mutated.example']);
            DB::table('calculation_number_sequences')->where('year', 2026)->update(['last_seq' => 99]);
            DB::table('crm_accounts')->insert([
                'type' => 'account',
                'is_provisional' => true,
                'lock_version' => 1,
                'matching_domain' => 'new.example',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        });

        try {
            app(MasterDataImporterV2::class)->executePhasesABCForIsolatedTests($package, dryRun: false);
            $this->fail('CRM/Sequenz-Drift muss STOP auslösen');
        } catch (MasterDataTransferException $e) {
            $blob = $e->getMessage().' '.implode(' ', $e->errors);
            $this->assertTrue(
                str_contains($blob, 'crm') || str_contains($blob, 'calculation_number') || str_contains($blob, 'Whitelist') || str_contains($blob, 'Schutz'),
                $blob,
            );
        } finally {
            Organization::flushEventListeners();
        }

        $this->assertSame(0, Inventory::query()->where('code', 'inv_v2_radio')->count());
        $this->assertSame(1, DB::table('crm_accounts')->count());
        $this->assertSame(10, (int) DB::table('calculation_number_sequences')->where('year', 2026)->value('last_seq'));
    }

    #[Test]
    public function calculation_pin_rebind_and_content_change_stop(): void
    {
        User::factory()->create(['email' => 'pin@example.test', 'role' => Role::Admin, 'is_active' => true]);
        $org = Organization::query()->create(['name' => 'Pin Org']);
        $inventory = Inventory::query()->create([
            'organization_id' => $org->id,
            'code' => 'pin_inv',
            'name' => 'Pin Inv',
            'type' => InventoryType::Sender,
            'is_active' => true,
            'sort' => 1,
            'lock_version' => 1,
        ]);
        $listA = PriceList::query()->create([
            'inventory_id' => $inventory->id,
            'year' => 2026,
            'revision_number' => 1,
            'name' => 'Pinned A',
            'version' => '1',
            'valid_from' => '2026-01-01',
            'status' => PriceListStatus::Active,
            'lock_version' => 1,
        ]);
        PriceListItem::query()->create([
            'price_list_id' => $listA->id,
            'hour' => 8,
            'day_group' => 'mo_fr',
            'second_price' => '10.0000',
        ]);
        $listB = PriceList::query()->create([
            'inventory_id' => $inventory->id,
            'year' => 2026,
            'revision_number' => 2,
            'name' => 'Pinned B',
            'version' => '2',
            'valid_from' => '2026-01-01',
            'status' => PriceListStatus::Draft,
            'lock_version' => 1,
        ]);
        $calc = Calculation::factory()->create(['advisor_id' => User::query()->firstOrFail()->id]);
        $medium = AdvertisingMedium::query()->first() ?? AdvertisingMedium::factory()->create();
        $position = CalculationPosition::factory()->create([
            'calculation_id' => $calc->id,
            'inventory_id' => $inventory->id,
            'advertising_medium_id' => $medium->id,
            'price_list_id' => $listA->id,
        ]);

        $package = $this->writeRichV2Package();

        // 1) Umbindung einer gepinnten Position (außerhalb Whitelist) → sofort STOP.
        Organization::saved(function () use ($position, $listB): void {
            DB::table('calculation_positions')->where('id', $position->id)->update([
                'price_list_id' => $listB->id,
            ]);
        });
        try {
            app(MasterDataImporterV2::class)->executePhasesABCForIsolatedTests($package, dryRun: false);
            $this->fail('Pin-Umbindung muss STOP auslösen');
        } catch (MasterDataTransferException $e) {
            $blob = $e->getMessage().' '.implode(' ', $e->errors);
            $this->assertTrue(
                str_contains($blob, 'Whitelist') || str_contains($blob, 'calculation_positions') || str_contains($blob, 'Pin'),
                $blob,
            );
        } finally {
            Organization::flushEventListeners();
        }
        $this->assertSame((int) $listA->id, (int) $position->fresh()->price_list_id);

        // 2) Inhaltsänderung einer gepinnten Liste (Whitelist-Tabelle) → Pin-Fingerprint STOP.
        Organization::saved(function () use ($listA): void {
            DB::table('price_list_items')->where('price_list_id', $listA->id)->update([
                'second_price' => '99.0000',
            ]);
        });
        try {
            app(MasterDataImporterV2::class)->executePhasesABCForIsolatedTests($package, dryRun: false);
            $this->fail('Pin-Inhaltsdrift muss STOP auslösen');
        } catch (MasterDataTransferException $e) {
            $blob = $e->getMessage().' '.implode(' ', $e->errors);
            $this->assertTrue(
                str_contains($blob, 'Pin') || str_contains($blob, 'Preislisten') || str_contains($blob, 'Fingerprint'),
                $blob,
            );
        } finally {
            Organization::flushEventListeners();
        }

        $price = (string) DB::table('price_list_items')->where('price_list_id', $listA->id)->value('second_price');
        $this->assertTrue(str_starts_with($price, '10'), $price);
        $this->assertSame(0, Inventory::query()->where('code', 'inv_v2_radio')->count());
    }

    #[Test]
    public function unauthorized_write_via_observer_stops_and_rolls_back(): void
    {
        User::factory()->create(['email' => 'wl@example.test', 'role' => Role::Admin, 'is_active' => true]);
        $package = $this->writeRichV2Package();

        Organization::saved(function (): void {
            DB::table('audit_events')->insert([
                'auditable_type' => 'phase_c_forbidden',
                'auditable_id' => 1,
                'action' => 'phase_c_forbidden',
                'old_values' => null,
                'new_values' => json_encode(['x' => 1]),
                'user_id' => null,
                'correlation_id' => null,
                'created_at' => now(),
            ]);
        });

        try {
            app(MasterDataImporterV2::class)->executePhasesABCForIsolatedTests($package, dryRun: false);
            $this->fail('Unerlaubter Write muss STOP auslösen');
        } catch (MasterDataTransferException $e) {
            $blob = $e->getMessage().' '.implode(' ', $e->errors);
            $this->assertTrue(
                str_contains($blob, 'Whitelist') || str_contains($blob, 'audit_events'),
                $blob,
            );
        } finally {
            Organization::flushEventListeners();
        }

        $this->assertSame(0, Inventory::query()->where('code', 'inv_v2_radio')->count());
        $this->assertSame(0, DB::table('audit_events')->where('action', 'phase_c_forbidden')->count());
    }

    #[Test]
    public function identical_existing_price_list_is_unchanged_and_keeps_status(): void
    {
        User::factory()->create(['email' => 'rh@example.test', 'role' => Role::Admin, 'is_active' => true]);
        $packagePath = $this->writeRichV2Package();
        app(MasterDataImporterV2::class)->executePhasesABCForIsolatedTests($packagePath, dryRun: false);

        $inventory = Inventory::query()->where('code', 'inv_v2_radio')->firstOrFail();
        $list = PriceList::query()
            ->where('inventory_id', $inventory->id)
            ->where('year', 2026)
            ->where('revision_number', 1)
            ->firstOrFail();
        $list->forceFill(['status' => PriceListStatus::Active])->save();

        $again = app(MasterDataImporterV2::class)->executePhasesABCForIsolatedTests($packagePath, dryRun: false);
        $this->assertSame(0, $again['created']);
        $this->assertSame('PASS', $again['security']['result']);
        $this->assertSame(PriceListStatus::Active, $list->fresh()->status);
        $row = collect($again['rows'])->first(
            fn (array $r): bool => $r['entity'] === 'price_list' && str_contains($r['key'], 'inv_v2_radio'),
        );
        $this->assertNotNull($row);
        $this->assertSame('unchanged', $row['action']);
    }

    #[Test]
    public function repeated_abc_import_is_idempotent_and_generic_apply_entry_stays_blocked(): void
    {
        User::factory()->create(['email' => 'idem@example.test', 'role' => Role::Admin, 'is_active' => true]);
        $package = $this->writeRichV2Package();
        $first = app(MasterDataImporterV2::class)->executePhasesABCForIsolatedTests($package, dryRun: false);
        $second = app(MasterDataImporterV2::class)->executePhasesABCForIsolatedTests($package, dryRun: false);

        $this->assertGreaterThan(0, $first['created']);
        $this->assertSame(0, $second['created']);
        $this->assertSame('PASS', $second['security']['result']);

        try {
            app(MasterDataImporterV2::class)->import($package, dryRun: false, confirmDatabase: 'ignored');
            $this->fail('Generischer Apply-Einstieg muss gesperrt bleiben');
        } catch (MasterDataTransferException $e) {
            $this->assertStringContainsString('applyOperational()', $e->getMessage());
        }

        $db = (string) config('database.connections.'.config('database.default').'.database');
        $this->assertNotSame(0, Artisan::call('dispo:import-master-data', [
            'path' => $package,
            '--apply' => true,
            '--confirm-database' => $db,
        ]));
        $output = Artisan::output();
        $this->assertTrue(
            str_contains($output, '--require-backup-proof')
            || str_contains($output, '--require-package-sha256'),
            $output,
        );
    }

    #[Test]
    public function protected_snapshot_detects_missing_table_fail_closed(): void
    {
        $snapshot = new MasterDataProtectedStateSnapshotV2;
        $captured = $snapshot->capture();
        $this->assertArrayHasKey('users', $captured['tables']);

        $mutated = $captured;
        $mutated['tables']['users']['digest'] = hash('sha256', 'changed');
        $diff = $snapshot->diff($captured, $mutated);
        $this->assertNotSame([], $diff);
    }

    #[Test]
    public function v1_regression_dry_run_still_works(): void
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
        $report = app(MasterDataImporter::class)
            ->import($path, dryRun: true, confirmDatabase: $db);
        $this->assertTrue($report['dry_run']);
    }

    /**
     * @param  array<string, array<string, mixed>|list<mixed>>  $overrides
     */
    private function writeRichV2Package(array $overrides = []): string
    {
        $path = $this->tmp.'/v2-rich-'.uniqid('', true);
        $files = $overrides === [] ? $this->richPayload() : $overrides;
        if ($overrides !== [] && ! array_key_exists('data/organization.json', $overrides)) {
            $files = array_replace($this->richPayload(), $overrides);
        }
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
            'git_sha' => 'synthetic-phase-c',
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
