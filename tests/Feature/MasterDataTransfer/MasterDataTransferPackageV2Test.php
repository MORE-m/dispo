<?php

namespace Tests\Feature\MasterDataTransfer;

use App\Services\MasterDataTransfer\Import\MasterDataImporter;
use App\Services\MasterDataTransfer\MasterDataTransferContract;
use App\Services\MasterDataTransfer\MasterDataTransferContractV2;
use App\Services\MasterDataTransfer\MasterDataTransferException;
use App\Services\MasterDataTransfer\Support\MasterDataPackage;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Slice 1: Schema-Erkennung und Paketintegrität 2.0.0 (schreibfrei, synthetische Fixtures).
 */
class MasterDataTransferPackageV2Test extends TestCase
{
    private string $tmp;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tmp = sys_get_temp_dir().'/mdt-v2-'.uniqid('', true);
        File::ensureDirectoryExists($this->tmp);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->tmp);
        parent::tearDown();
    }

    #[Test]
    public function valid_v1_package_remains_loadable(): void
    {
        $package = $this->writeMinimalV1Package();
        $loaded = app(MasterDataPackage::class)->load($package);

        $this->assertSame(MasterDataTransferContract::SCHEMA_VERSION, $loaded['schema_version']);
        $this->assertSame(MasterDataTransferContract::EPIC, $loaded['manifest']['epic']);
        $this->assertArrayHasKey('data/inventories.json', $loaded['data']);
    }

    #[Test]
    public function valid_synthetic_v2_package_is_detected(): void
    {
        $package = $this->writeMinimalV2Package();
        $packages = app(MasterDataPackage::class);

        $this->assertSame(
            MasterDataTransferContractV2::SCHEMA_VERSION,
            $packages->detectSchemaVersion($package),
        );

        $loaded = $packages->load($package);
        $this->assertSame(MasterDataTransferContractV2::SCHEMA_VERSION, $loaded['schema_version']);
        $this->assertSame(MasterDataTransferContractV2::EPIC, $loaded['manifest']['epic']);
        $this->assertCount(count(MasterDataTransferContractV2::DATA_FILES), $loaded['data']);
        $this->assertSame([], $loaded['data']['data/oa_platforms.json']);
    }

    #[Test]
    public function v1_and_v2_packages_cannot_be_cross_read_as_each_other(): void
    {
        $v1 = $this->writeMinimalV1Package();
        $v2 = $this->writeMinimalV2Package();
        $packages = app(MasterDataPackage::class);

        $this->assertSame(MasterDataTransferContract::SCHEMA_VERSION, $packages->detectSchemaVersion($v1));
        $this->assertSame(MasterDataTransferContractV2::SCHEMA_VERSION, $packages->detectSchemaVersion($v2));

        $loadedV1 = $packages->load($v1);
        $loadedV2 = $packages->load($v2);
        $this->assertSame(MasterDataTransferContract::SCHEMA_VERSION, $loadedV1['schema_version']);
        $this->assertSame(MasterDataTransferContractV2::SCHEMA_VERSION, $loadedV2['schema_version']);
        $this->assertNotSame($loadedV1['manifest']['epic'], $loadedV2['manifest']['epic']);

        try {
            app(MasterDataImporter::class)->import(
                $v2,
                dryRun: true,
                confirmDatabase: (string) config('database.connections.'.config('database.default').'.database'),
            );
            $this->fail('2.0.0-Paket darf vom 1.0.0-Importer nicht akzeptiert werden.');
        } catch (MasterDataTransferException $e) {
            $this->assertStringContainsString('nicht freigegeben', $e->getMessage());
            $this->assertStringContainsString(MasterDataTransferContractV2::SCHEMA_VERSION, $e->getMessage());
        }
    }

    #[Test]
    public function missing_manifest_is_rejected(): void
    {
        $package = $this->writeMinimalV2Package();
        File::delete($package.'/manifest.json');

        $this->expectException(MasterDataTransferException::class);
        $this->expectExceptionMessage('manifest.json fehlt');
        app(MasterDataPackage::class)->load($package);
    }

    #[Test]
    public function invalid_manifest_is_rejected(): void
    {
        $package = $this->writeMinimalV2Package();
        $this->rewriteV2Checksums($package, function (array $manifest): array {
            $manifest['includes_users'] = true;

            return $manifest;
        });

        $this->expectException(MasterDataTransferException::class);
        $this->expectExceptionMessage('Manifest ungültig');
        app(MasterDataPackage::class)->load($package);
    }

    #[Test]
    public function missing_required_data_file_is_detected(): void
    {
        $package = $this->writeMinimalV2Package();
        File::delete($package.'/data/oa_platforms.json');
        $this->rewriteV2Checksums($package);

        $this->expectException(MasterDataTransferException::class);
        app(MasterDataPackage::class)->load($package);
    }

    #[Test]
    public function wrong_sha256_is_detected(): void
    {
        $package = $this->writeMinimalV2Package();
        File::put($package.'/data/oa_platforms.json', "[{\"code\":\"spotify\"}]\n");

        $this->expectException(MasterDataTransferException::class);
        $this->expectExceptionMessage('Paketintegrität fehlgeschlagen');
        app(MasterDataPackage::class)->load($package);
    }

    #[Test]
    public function unexpected_or_excluded_file_is_rejected(): void
    {
        $package = $this->writeMinimalV2Package();
        File::put($package.'/data/users.json', "[]\n");

        $this->expectException(MasterDataTransferException::class);
        $this->expectExceptionMessage('unzulässige Dateien');
        app(MasterDataPackage::class)->load($package);
    }

    #[Test]
    public function unsafe_checksum_path_is_rejected(): void
    {
        $package = $this->writeMinimalV2Package();
        $lines = File::lines($package.'/checksums.sha256')->all();
        $lines[] = hash('sha256', 'x')."  ../escape.json\n";
        File::put($package.'/checksums.sha256', implode('', $lines));

        $this->expectException(MasterDataTransferException::class);
        app(MasterDataPackage::class)->load($package);
    }

    #[Test]
    public function empty_arrays_are_allowed_in_v2_data_files(): void
    {
        $package = $this->writeMinimalV2Package();
        $loaded = app(MasterDataPackage::class)->load($package);

        $this->assertSame([], $loaded['data']['data/oa_adserver_surcharges.json']);
        $this->assertSame([], $loaded['data']['data/field_set_assignments.json']);
        $this->assertSame([], $loaded['data']['data/inventories.json']);
    }

    #[Test]
    public function repeated_serialization_of_identical_content_is_deterministic(): void
    {
        $files = $this->v2FilePayloads();
        $manifest = $this->v2Manifest($files);

        $first = $this->tmp.'/det-a';
        $second = $this->tmp.'/det-b';
        app(MasterDataPackage::class)->write($first, $manifest, $files);
        app(MasterDataPackage::class)->write($second, $manifest, $files);

        foreach (MasterDataTransferContractV2::DATA_FILES as $relative) {
            $this->assertSame(
                File::get($first.'/'.$relative),
                File::get($second.'/'.$relative),
                $relative,
            );
        }
        $this->assertSame(
            File::get($first.'/checksums.sha256'),
            File::get($second.'/checksums.sha256'),
        );
        $this->assertSame(
            File::get($first.'/manifest.json'),
            File::get($second.'/manifest.json'),
        );
    }

    #[Test]
    public function package_validation_does_not_write_to_database(): void
    {
        $package = $this->writeMinimalV2Package();
        DB::flushQueryLog();
        DB::enableQueryLog();

        app(MasterDataPackage::class)->load($package);

        $writes = collect(DB::getQueryLog())->filter(function (array $entry): bool {
            $sql = strtolower((string) ($entry['query'] ?? ''));

            return str_contains($sql, 'insert ')
                || str_contains($sql, 'update ')
                || str_contains($sql, 'delete ')
                || str_contains($sql, 'alter ');
        });

        $this->assertCount(0, $writes);
        DB::disableQueryLog();
    }

    #[Test]
    public function unknown_schema_version_is_rejected(): void
    {
        $package = $this->writeMinimalV2Package();
        $this->rewriteV2Checksums($package, function (array $manifest): array {
            $manifest['schema_version'] = '9.9.9';

            return $manifest;
        });

        try {
            app(MasterDataPackage::class)->load($package);
            $this->fail('Unbekannte schema_version muss fail-closed abgewiesen werden.');
        } catch (MasterDataTransferException $e) {
            $this->assertSame('Manifest ungültig.', $e->getMessage());
            $this->assertNotEmpty(array_filter(
                $e->errors,
                fn (string $error): bool => str_contains($error, 'Unbekannte schema_version'),
            ));
        }
    }

    #[Test]
    public function v2_manifest_with_v1_epic_is_rejected(): void
    {
        $package = $this->writeMinimalV2Package();
        $this->rewriteV2Checksums($package, function (array $manifest): array {
            $manifest['epic'] = MasterDataTransferContract::EPIC;

            return $manifest;
        });

        $this->expectException(MasterDataTransferException::class);
        app(MasterDataPackage::class)->load($package);
    }

    /**
     * @return array<string, array<string, mixed>|list<mixed>>
     */
    private function v2FilePayloads(): array
    {
        $files = [];
        foreach (MasterDataTransferContractV2::DATA_FILES as $relative) {
            $files[$relative] = $relative === 'data/organization.json'
                ? ['name' => 'more Marketing']
                : [];
        }

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
            'git_sha' => 'synthetic-test-sha',
            'exported_at' => '2026-11-23T12:00:00+00:00',
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
                'spot' => ['active' => 0, 'archived' => 0, 'draft' => 0],
                'production' => ['active' => 0, 'archived' => 0, 'draft' => 0],
                'oa_tkp' => ['active' => 0, 'archived' => 0, 'draft' => 0],
            ],
            'files' => [],
        ];
    }

    private function writeMinimalV2Package(): string
    {
        $path = $this->tmp.'/v2-'.uniqid('', true);
        $files = $this->v2FilePayloads();
        app(MasterDataPackage::class)->write($path, $this->v2Manifest($files), $files);

        return $path;
    }

    private function writeMinimalV1Package(): string
    {
        $path = $this->tmp.'/v1-'.uniqid('', true);
        $files = [
            'data/organization.json' => ['name' => 'more Marketing'],
            'data/advertising_categories.json' => [[
                'key' => 'spots',
                'name' => 'Spots',
                'is_active' => true,
                'sort' => 1,
                'default_calculation_method_key' => 'average',
            ]],
            'data/calculation_methods.json' => [[
                'key' => 'average',
                'name' => 'Durchschnitt',
                'help_text' => null,
                'is_active' => true,
                'sort' => 1,
            ]],
            'data/inventories.json' => [[
                'code' => 'inv_radio_hamburg',
                'name' => 'Radio Hamburg',
                'type' => 'sender',
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
            'exported_at' => '2026-11-23T12:00:00+00:00',
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

    /**
     * @param  callable(array<string, mixed>): array<string, mixed>|null  $mutateManifest
     */
    private function rewriteV2Checksums(string $package, ?callable $mutateManifest = null): void
    {
        $manifest = json_decode(File::get($package.'/manifest.json'), true);
        $this->assertIsArray($manifest);

        if ($mutateManifest !== null) {
            $manifest = $mutateManifest($manifest);
        }

        $checksums = [];
        foreach (MasterDataTransferContractV2::DATA_FILES as $relative) {
            $absolute = $package.'/'.$relative;
            if (! is_file($absolute)) {
                continue;
            }
            $hash = hash_file('sha256', $absolute);
            $this->assertNotFalse($hash);
            $checksums[] = $hash.'  '.$relative;
            $manifest['files'][$relative] = [
                'sha256' => $hash,
                'bytes' => filesize($absolute),
            ];
        }

        File::put($package.'/checksums.sha256', implode("\n", $checksums)."\n");
        File::put(
            $package.'/manifest.json',
            json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)."\n",
        );
    }
}
