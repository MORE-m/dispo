<?php

namespace Tests\Feature\MasterDataTransfer;

use App\Enums\FieldAppliesTo;
use App\Enums\FieldScope;
use App\Enums\FieldSetAssignmentTargetLayer;
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
use App\Models\FieldSetAssignment;
use App\Models\FieldSetVersion;
use App\Models\FieldSetVersionField;
use App\Models\Inventory;
use App\Models\OaPlatform;
use App\Models\OaTkpPriceList;
use App\Models\Organization;
use App\Models\PriceList;
use App\Models\User;
use App\Services\MasterDataTransfer\Import\MasterDataImporter;
use App\Services\MasterDataTransfer\Import\MasterDataImporterV2;
use App\Services\MasterDataTransfer\MasterDataTransferContract;
use App\Services\MasterDataTransfer\MasterDataTransferContractV2;
use App\Services\MasterDataTransfer\MasterDataTransferException;
use App\Services\MasterDataTransfer\Support\MasterDataPackage;
use Database\Seeders\InitialCatalogMatCoreSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\TestCase;

/**
 * EPIC-03-F Slice 4 Phase B + virtueller Dry-Run (nur isolierte Tests, CLI gesperrt).
 */
class MasterDataTransferImporterV2PhaseBTest extends TestCase
{
    use RefreshDatabase;

    private string $tmp;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tmp = sys_get_temp_dir().'/mdt-v2-phase-b-'.uniqid('', true);
        File::ensureDirectoryExists($this->tmp);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->tmp);
        parent::tearDown();
    }

    #[Test]
    public function phase_b_sets_current_revision_and_active_version_pointers(): void
    {
        $package = $this->writeRichV2Package();
        $report = app(MasterDataImporterV2::class)->executePhasesABForIsolatedTests($package, dryRun: false);

        $this->assertSame([], $report['conflicts']);
        $definition = FieldDefinition::query()->where('key', 'campaign_title')->firstOrFail();
        $revision = FieldDefinitionRevision::query()
            ->where('field_definition_id', $definition->id)
            ->where('revision', 1)
            ->firstOrFail();
        $this->assertSame((int) $revision->id, (int) $definition->current_revision_id);

        $set = FieldSet::query()->where('key', 'calc_header_v2')->firstOrFail();
        $version = FieldSetVersion::query()->where('field_set_id', $set->id)->where('version', 1)->firstOrFail();
        $this->assertSame((int) $version->id, (int) $set->active_version_id);
    }

    #[Test]
    public function correct_existing_pointers_are_unchanged_divergent_conflict(): void
    {
        $package = $this->writeRichV2Package();
        app(MasterDataImporterV2::class)->executePhasesABForIsolatedTests($package, dryRun: false);

        $again = app(MasterDataImporterV2::class)->executePhasesABForIsolatedTests($package, dryRun: false);
        $this->assertSame(0, $again['created']);
        $pointerRows = collect($again['rows'])->whereIn('entity', [
            'field_definition_current_revision',
            'field_set_active_version',
        ]);
        $this->assertTrue($pointerRows->every(fn (array $row): bool => $row['action'] === 'unchanged'));

        $definition = FieldDefinition::query()->where('key', 'campaign_title')->firstOrFail();
        $foreign = new FieldDefinition;
        $foreign->forceFill([
            'key' => 'foreign_field',
            'field_type' => FieldType::ShortText,
            'is_system' => false,
            'is_key_protected' => false,
            'scope' => FieldScope::Header,
            'applies_to' => FieldAppliesTo::Calculation,
            'is_active' => true,
            'current_revision_id' => null,
            'lock_version' => 1,
        ])->save();
        $foreignRev = new FieldDefinitionRevision;
        $foreignRev->forceFill([
            'field_definition_id' => $foreign->id,
            'revision' => 99,
            'label' => 'Foreign',
            'help_text' => null,
            'validation_json' => null,
            'group_key' => null,
            'sort_default' => 1,
            'reportable' => false,
        ])->save();
        $definition->forceFill(['current_revision_id' => $foreignRev->id])->save();

        try {
            app(MasterDataImporterV2::class)->executePhasesABForIsolatedTests($package, dryRun: false);
            $this->fail('Erwartete Konflikt wegen abweichendem current_revision_id');
        } catch (MasterDataTransferException $e) {
            $this->assertTrue(
                collect($e->errors)->contains(fn (string $m): bool => str_contains($m, 'current_revision')),
            );
        }
        $this->assertSame((int) $foreignRev->id, (int) $definition->fresh()->current_revision_id);
    }

    #[Test]
    public function field_set_assignments_remap_target_identity_for_all_layers(): void
    {
        $files = $this->richPayload();
        $files['data/field_set_assignments.json'] = [
            [
                'field_set_key' => 'calc_header_v2',
                'applies_to_process' => FieldAppliesTo::Calculation->value,
                'target_layer' => FieldSetAssignmentTargetLayer::Global->value,
                'is_active' => true,
                'sort' => 10,
            ],
            [
                'field_set_key' => 'calc_header_v2',
                'applies_to_process' => FieldAppliesTo::Calculation->value,
                'target_layer' => FieldSetAssignmentTargetLayer::AdvertisingCategory->value,
                'category_key' => 'spots_v2',
                'is_active' => true,
                'sort' => 20,
            ],
            [
                'field_set_key' => 'calc_header_v2',
                'applies_to_process' => FieldAppliesTo::Calculation->value,
                'target_layer' => FieldSetAssignmentTargetLayer::AdvertisingMedium->value,
                'medium_code' => 'spot_v2',
                'is_active' => false,
                'sort' => 30,
            ],
        ];
        $package = $this->writeRichV2Package($files);

        $report = app(MasterDataImporterV2::class)->executePhasesABForIsolatedTests($package, dryRun: false);
        $this->assertSame([], $report['conflicts']);

        $set = FieldSet::query()->where('key', 'calc_header_v2')->firstOrFail();
        $category = AdvertisingCategory::query()->where('key', 'spots_v2')->firstOrFail();
        $medium = AdvertisingMedium::query()->where('code', 'spot_v2')->firstOrFail();

        $global = FieldSetAssignment::query()
            ->where('field_set_id', $set->id)
            ->where('target_identity', 'g')
            ->firstOrFail();
        $this->assertSame(FieldSetAssignmentTargetLayer::Global, $global->target_layer);
        $this->assertNull($global->advertising_category_id);
        $this->assertNull($global->advertising_medium_id);

        $catAssignment = FieldSetAssignment::query()
            ->where('field_set_id', $set->id)
            ->where('target_identity', 'c:'.$category->id)
            ->firstOrFail();
        $this->assertSame((int) $category->id, (int) $catAssignment->advertising_category_id);

        $medAssignment = FieldSetAssignment::query()
            ->where('field_set_id', $set->id)
            ->where('target_identity', 'm:'.$medium->id)
            ->firstOrFail();
        $this->assertSame((int) $medium->id, (int) $medAssignment->advertising_medium_id);
        $this->assertFalse($medAssignment->is_active);

        // Quell-/Ziel-IDs differieren: Category/Medium/Set haben hohe Quell-IDs im Paketkontext nicht,
        // aber target_identity muss Ziel-IDs nutzen — zweiter Apply unverändert.
        $again = app(MasterDataImporterV2::class)->executePhasesABForIsolatedTests($package, dryRun: false);
        $this->assertSame(0, $again['created']);
        $this->assertSame(3, FieldSetAssignment::query()->count());
    }

    #[Test]
    public function new_parents_and_children_in_same_package_plan_and_apply(): void
    {
        $package = $this->writeRichV2Package();
        $report = app(MasterDataImporterV2::class)->executePhasesABForIsolatedTests($package, dryRun: false);

        $this->assertGreaterThan(0, $report['created']);
        $inventory = Inventory::query()->where('code', 'inv_v2_radio')->firstOrFail();
        $this->assertNotNull($inventory->organization_id);
        $this->assertSame(1, PriceList::query()->where('inventory_id', $inventory->id)->count());
        $this->assertNotNull(OaPlatform::query()->where('code', 'spotify_v2')->first());
        $link = FieldSetVersionField::query()->firstOrFail();
        $this->assertNotNull($link->field_definition_revision_id);
    }

    #[Test]
    public function virtual_dry_run_succeeds_on_empty_oa_and_inventory_target_without_writes(): void
    {
        $this->assertSame(0, Inventory::query()->where('code', 'inv_v2_radio')->count());
        $this->assertSame(0, OaPlatform::query()->where('code', 'spotify_v2')->count());
        $this->assertSame(0, OaTkpPriceList::query()->count());
        $beforeDefs = FieldDefinition::query()->count();
        $beforeSets = FieldSet::query()->count();
        $beforeAssignments = FieldSetAssignment::query()->count();
        $beforeOrgs = Organization::query()->count();

        $package = $this->writeRichV2Package();
        $report = app(MasterDataImporterV2::class)->executePhasesABForIsolatedTests($package, dryRun: true);

        $this->assertTrue($report['dry_run']);
        $this->assertSame([], $report['conflicts']);
        $this->assertGreaterThan(0, $report['created']);
        $this->assertSame(0, Inventory::query()->where('code', 'inv_v2_radio')->count());
        $this->assertSame(0, OaPlatform::query()->where('code', 'spotify_v2')->count());
        $this->assertSame(0, FieldDefinition::query()->where('key', 'campaign_title')->count());
        $this->assertSame($beforeDefs, FieldDefinition::query()->count());
        $this->assertSame($beforeSets, FieldSet::query()->count());
        $this->assertSame($beforeAssignments, FieldSetAssignment::query()->count());
        $this->assertSame($beforeOrgs, Organization::query()->count());
    }

    #[Test]
    public function missing_reference_is_conflict_and_phase_b_failure_rolls_back_phase_a(): void
    {
        $files = $this->richPayload();
        $files['data/field_definitions.json'][0]['current_revision'] = 99;
        $bad = $this->writeRichV2Package($files);
        try {
            app(MasterDataImporterV2::class)->executePhasesABForIsolatedTests($bad, dryRun: false);
            $this->fail('Fehlende Revision muss konfliktieren');
        } catch (MasterDataTransferException $e) {
            $this->assertNotSame([], $e->errors);
        }
        $this->assertSame(0, FieldDefinition::query()->where('key', 'campaign_title')->count());
        $this->assertSame(0, Inventory::query()->where('code', 'inv_v2_radio')->count());

        $package = $this->writeRichV2Package();
        $threw = false;
        DB::listen(function ($query) use (&$threw): void {
            $sql = strtolower((string) $query->sql);
            if (! $threw && (str_contains($sql, 'update `field_definitions`') || str_contains($sql, 'update "field_definitions"'))) {
                $threw = true;
                throw new RuntimeException('forced phase-b failure');
            }
        });

        try {
            app(MasterDataImporterV2::class)->executePhasesABForIsolatedTests($package, dryRun: false);
            $this->fail('Erwarteter Apply-Abbruch in Phase B');
        } catch (RuntimeException $e) {
            $this->assertSame('forced phase-b failure', $e->getMessage());
        }

        $this->assertSame(0, FieldDefinition::query()->where('key', 'campaign_title')->count());
        $this->assertSame(0, Inventory::query()->where('code', 'inv_v2_radio')->count());
        $this->assertSame(0, Organization::query()->where('name', 'Fixture Org V2')->count());
    }

    #[Test]
    public function repeated_apply_is_idempotent_and_content_matches(): void
    {
        User::factory()->create([
            'email' => 'v2-phase-b@example.test',
            'role' => Role::Admin,
            'is_active' => true,
        ]);
        $files = $this->richPayload();
        $files['data/field_set_assignments.json'] = [[
            'field_set_key' => 'calc_header_v2',
            'applies_to_process' => FieldAppliesTo::Both->value,
            'target_layer' => FieldSetAssignmentTargetLayer::Global->value,
            'is_active' => true,
            'sort' => 1,
        ]];
        $package = $this->writeRichV2Package($files);

        $first = app(MasterDataImporterV2::class)->executePhasesABForIsolatedTests($package, dryRun: false);
        $counts = [
            'inventories' => Inventory::query()->count(),
            'price_lists' => PriceList::query()->count(),
            'field_defs' => FieldDefinition::query()->count(),
            'assignments' => FieldSetAssignment::query()->count(),
            'users' => User::query()->count(),
        ];
        $spot = PriceList::query()->firstOrFail();
        $this->assertSame(PriceListStatus::Draft, $spot->status);

        $second = app(MasterDataImporterV2::class)->executePhasesABForIsolatedTests($package, dryRun: false);
        $this->assertSame(0, $second['created']);
        $this->assertGreaterThan(0, $second['unchanged']);
        $this->assertSame($counts['inventories'], Inventory::query()->count());
        $this->assertSame($counts['price_lists'], PriceList::query()->count());
        $this->assertSame($counts['field_defs'], FieldDefinition::query()->count());
        $this->assertSame($counts['assignments'], FieldSetAssignment::query()->count());
        $this->assertSame($counts['users'], User::query()->count());
        $this->assertGreaterThan(0, $first['created']);
    }

    #[Test]
    public function v1_compat_and_operational_v2_cli_remain_blocked(): void
    {
        $v1 = $this->writeMinimalV1PackageAlignedToSeed();
        $db = (string) config('database.connections.'.config('database.default').'.database');
        $report = app(MasterDataImporter::class)->import($v1, dryRun: true, confirmDatabase: $db);
        $this->assertTrue($report['dry_run']);

        $v2 = $this->writeRichV2Package();
        try {
            app(MasterDataImporterV2::class)->import($v2, dryRun: true, confirmDatabase: 'ignored');
            $this->fail('V2 import() muss gesperrt bleiben');
        } catch (MasterDataTransferException $e) {
            $this->assertStringContainsString('nicht freigegeben', $e->getMessage());
            $this->assertStringContainsString('Phase C', $e->getMessage());
        }

        $this->assertNotSame(0, Artisan::call('dispo:import-master-data', [
            'path' => $v2,
            '--dry-run' => true,
            '--confirm-database' => $db,
        ]));
        $this->assertStringContainsString('nicht freigegeben', Artisan::output());

        $this->assertNotSame(0, Artisan::call('dispo:import-master-data', [
            'path' => $v2,
            '--apply' => true,
            '--confirm-database' => $db,
        ]));
        $this->assertStringContainsString('nicht freigegeben', Artisan::output());
    }

    #[Test]
    public function isolation_guard_rejects_protected_databases_for_phases_ab(): void
    {
        $package = $this->writeRichV2Package();
        $importer = app(MasterDataImporterV2::class);
        $originalEnv = app()->environment();
        $connection = (string) config('database.default');
        $originalDb = config('database.connections.'.$connection.'.database');

        try {
            app()->detectEnvironment(fn () => 'local');
            try {
                $importer->executePhasesABForIsolatedTests($package, dryRun: true);
                $this->fail('Nicht-testing Env muss abgelehnt werden');
            } catch (MasterDataTransferException $e) {
                $this->assertStringContainsString('testing', $e->getMessage());
            }

            app()->detectEnvironment(fn () => 'testing');
            foreach (['dispo', 'dispo_mat_core', 'dispo_staging', 'production'] as $forbidden) {
                config(['database.connections.'.$connection.'.database' => $forbidden]);
                try {
                    $importer->executePhasesABForIsolatedTests($package, dryRun: false);
                    $this->fail('Geschützte DB muss abgelehnt werden: '.$forbidden);
                } catch (MasterDataTransferException $e) {
                    $this->assertTrue(
                        str_contains($e->getMessage(), 'gesperrt')
                        || str_contains($e->getMessage(), 'dispo_test'),
                    );
                }
            }
        } finally {
            app()->detectEnvironment(fn () => $originalEnv);
            config(['database.connections.'.$connection.'.database' => $originalDb]);
        }
    }

    #[Test]
    public function assignment_attribute_divergence_conflicts_without_partial_write(): void
    {
        $files = $this->richPayload();
        $files['data/field_set_assignments.json'] = [[
            'field_set_key' => 'calc_header_v2',
            'applies_to_process' => FieldAppliesTo::Calculation->value,
            'target_layer' => FieldSetAssignmentTargetLayer::Global->value,
            'is_active' => true,
            'sort' => 5,
        ]];
        $package = $this->writeRichV2Package($files);
        app(MasterDataImporterV2::class)->executePhasesABForIsolatedTests($package, dryRun: false);

        $files['data/field_set_assignments.json'][0]['sort'] = 99;
        $conflictPackage = $this->writeRichV2Package($files);
        $before = FieldSetAssignment::query()->count();
        try {
            app(MasterDataImporterV2::class)->executePhasesABForIsolatedTests($conflictPackage, dryRun: false);
            $this->fail('Abweichende Assignment-Attribute müssen konfliktieren');
        } catch (MasterDataTransferException $e) {
            $this->assertTrue(
                collect($e->errors)->contains(fn (string $m): bool => str_contains($m, 'field_set_assignment')),
            );
        }
        $this->assertSame($before, FieldSetAssignment::query()->count());
        $this->assertSame(5, (int) FieldSetAssignment::query()->value('sort'));
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
            'type' => InventoryType::Sender->value,
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
            'git_sha' => 'synthetic-phase-b',
            'exported_at' => '2026-11-24T18:00:00+00:00',
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
            'exported_at' => '2026-11-24T18:00:00+00:00',
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
