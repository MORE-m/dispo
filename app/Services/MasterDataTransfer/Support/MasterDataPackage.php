<?php

namespace App\Services\MasterDataTransfer\Support;

use App\Services\MasterDataTransfer\MasterDataTransferContract;
use App\Services\MasterDataTransfer\MasterDataTransferContractV2;
use App\Services\MasterDataTransfer\MasterDataTransferException;
use Illuminate\Support\Facades\File;

/**
 * Lädt und prüft EPIC-03 Transferpakete (Manifest + SHA256).
 *
 * Schema 1.0.0 (EPIC-03-B) und 2.0.0 (EPIC-03-F) sind strikt getrennt.
 */
final class MasterDataPackage
{
    /**
     * @return array{
     *     root: string,
     *     schema_version: string,
     *     manifest: array<string, mixed>,
     *     data: array<string, mixed>
     * }
     */
    public function load(string $packagePath): array
    {
        $root = rtrim($packagePath, DIRECTORY_SEPARATOR);
        if (! is_dir($root)) {
            throw new MasterDataTransferException('Paketverzeichnis fehlt: '.$root);
        }

        $manifestPath = $root.DIRECTORY_SEPARATOR.MasterDataTransferContract::MANIFEST_FILE;
        $checksumsPath = $root.DIRECTORY_SEPARATOR.MasterDataTransferContract::CHECKSUMS_FILE;

        if (! is_file($manifestPath)) {
            throw new MasterDataTransferException('manifest.json fehlt.');
        }
        if (! is_file($checksumsPath)) {
            throw new MasterDataTransferException('checksums.sha256 fehlt.');
        }

        $manifest = $this->decodeJsonFile($manifestPath);
        $schemaVersion = $this->resolveSchemaVersion($manifest);
        $dataFiles = $this->dataFilesForSchema($schemaVersion);

        if ($schemaVersion === MasterDataTransferContract::SCHEMA_VERSION) {
            $this->assertManifestShapeV1($manifest);
        } else {
            $this->assertManifestShapeV2($manifest);
        }

        $this->assertChecksums($root, $checksumsPath, $manifest, $dataFiles);
        $this->assertNoUnexpectedPackageFiles($root, $dataFiles);

        $data = [];
        foreach ($dataFiles as $relative) {
            $payload = $this->decodeJsonFile($root.DIRECTORY_SEPARATOR.$relative);
            if ($schemaVersion === MasterDataTransferContractV2::SCHEMA_VERSION) {
                $this->assertDataFileShapeV2($relative, $payload);
            }
            $data[$relative] = $payload;
        }

        if ($schemaVersion === MasterDataTransferContractV2::SCHEMA_VERSION) {
            $this->assertManifestCountsV2($manifest, $data);
        }

        return [
            'root' => $root,
            'schema_version' => $schemaVersion,
            'manifest' => $manifest,
            'data' => $data,
        ];
    }

    /**
     * Liest nur die Schemaversion aus dem Manifest (fail-closed).
     */
    public function detectSchemaVersion(string $packagePath): string
    {
        $root = rtrim($packagePath, DIRECTORY_SEPARATOR);
        $manifestPath = $root.DIRECTORY_SEPARATOR.MasterDataTransferContract::MANIFEST_FILE;
        if (! is_file($manifestPath)) {
            throw new MasterDataTransferException('manifest.json fehlt.');
        }

        return $this->resolveSchemaVersion($this->decodeJsonFile($manifestPath));
    }

    /**
     * @param  array<string, mixed>  $manifest
     * @param  array<string, array<string, mixed>|list<mixed>>  $filePayloads  relative path => decoded JSON-serializable
     */
    public function write(string $packagePath, array $manifest, array $filePayloads): void
    {
        $schemaVersion = $this->resolveSchemaVersion($manifest);
        $dataFiles = $this->dataFilesForSchema($schemaVersion);
        $sortKeys = $schemaVersion === MasterDataTransferContractV2::SCHEMA_VERSION;

        File::ensureDirectoryExists($packagePath.DIRECTORY_SEPARATOR.'data');

        $checksumLines = [];
        foreach ($dataFiles as $relative) {
            if (! array_key_exists($relative, $filePayloads)) {
                throw new MasterDataTransferException('Export unvollständig: '.$relative);
            }
            $absolute = $packagePath.DIRECTORY_SEPARATOR.$relative;
            $payload = $filePayloads[$relative];
            if ($schemaVersion === MasterDataTransferContractV2::SCHEMA_VERSION) {
                $this->assertDataFileShapeV2($relative, $payload);
            }
            $json = $this->encodeJson($payload, $sortKeys);
            File::ensureDirectoryExists(dirname($absolute));
            File::put($absolute, $json);
            $hash = hash('sha256', $json);
            $checksumLines[] = $hash.'  '.$relative;
            $manifest['files'][$relative] = [
                'sha256' => $hash,
                'bytes' => strlen($json),
            ];
        }

        $manifestJson = $this->encodeJson($manifest, $sortKeys);
        File::put($packagePath.DIRECTORY_SEPARATOR.MasterDataTransferContract::MANIFEST_FILE, $manifestJson);
        File::put(
            $packagePath.DIRECTORY_SEPARATOR.MasterDataTransferContract::CHECKSUMS_FILE,
            implode("\n", $checksumLines)."\n",
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeJsonFile(string $path): array
    {
        $raw = File::get($path);
        $decoded = json_decode($raw, true);
        if (! is_array($decoded)) {
            throw new MasterDataTransferException('Ungültiges JSON: '.$path);
        }

        return $decoded;
    }

    /**
     * @param  array<string, mixed>|list<mixed>  $payload
     */
    private function encodeJson(array $payload, bool $sortKeys = false): string
    {
        if ($sortKeys) {
            $payload = $this->canonicalize($payload);
        }

        $json = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            throw new MasterDataTransferException('JSON-Encode fehlgeschlagen.');
        }

        return $json."\n";
    }

    /**
     * @param  array<string, mixed>|list<mixed>  $payload
     * @return array<string, mixed>|list<mixed>
     */
    private function canonicalize(array $payload): array
    {
        $isList = array_is_list($payload);
        if (! $isList) {
            ksort($payload);
        }

        foreach ($payload as $key => $value) {
            if (is_array($value)) {
                $payload[$key] = $this->canonicalize($value);
            }
        }

        return $payload;
    }

    /**
     * @param  array<string, mixed>  $manifest
     */
    private function resolveSchemaVersion(array $manifest): string
    {
        if (($manifest['format'] ?? null) !== MasterDataTransferContract::FORMAT) {
            throw new MasterDataTransferException('Manifest ungültig.', ['Ungültiges format.']);
        }

        $version = $manifest['schema_version'] ?? null;
        if (! is_string($version) || $version === '') {
            throw new MasterDataTransferException('Manifest ungültig.', ['schema_version fehlt.']);
        }

        if ($version === MasterDataTransferContract::SCHEMA_VERSION) {
            return $version;
        }
        if ($version === MasterDataTransferContractV2::SCHEMA_VERSION) {
            return $version;
        }

        throw new MasterDataTransferException('Manifest ungültig.', [
            'Unbekannte schema_version: '.$version,
        ]);
    }

    /**
     * @return list<string>
     */
    private function dataFilesForSchema(string $schemaVersion): array
    {
        return $schemaVersion === MasterDataTransferContractV2::SCHEMA_VERSION
            ? MasterDataTransferContractV2::DATA_FILES
            : MasterDataTransferContract::DATA_FILES;
    }

    /**
     * @param  array<string, mixed>  $manifest
     */
    private function assertManifestShapeV1(array $manifest): void
    {
        $errors = [];
        if (($manifest['format'] ?? null) !== MasterDataTransferContract::FORMAT) {
            $errors[] = 'Ungültiges format.';
        }
        if (($manifest['schema_version'] ?? null) !== MasterDataTransferContract::SCHEMA_VERSION) {
            $errors[] = 'Inkompatible schema_version (erwartet '.MasterDataTransferContract::SCHEMA_VERSION.').';
        }
        if (($manifest['epic'] ?? null) !== MasterDataTransferContract::EPIC) {
            $errors[] = 'Ungültiges epic.';
        }
        $scope = $manifest['scope'] ?? null;
        if (! is_array($scope)) {
            $errors[] = 'scope fehlt.';
        } else {
            if (($scope['includes_online_audio'] ?? null) !== false) {
                $errors[] = 'includes_online_audio muss false sein (EPIC-03-B).';
            }
            if (($scope['price_lists_export_as_draft'] ?? null) !== true) {
                $errors[] = 'price_lists_export_as_draft muss true sein (EPIC-03-B).';
            }

            $rawYears = $scope['price_years'] ?? null;
            if (! is_array($rawYears)) {
                $errors[] = 'scope.price_years fehlt.';
            } else {
                $years = array_values(array_map('intval', $rawYears));
                sort($years);
                $expected = MasterDataTransferContract::PRICE_YEARS;
                $sortedExpected = $expected;
                sort($sortedExpected);
                if ($years !== $sortedExpected) {
                    $errors[] = 'scope.price_years muss exakt '
                        .implode('/', MasterDataTransferContract::PRICE_YEARS).' sein.';
                }
            }

            $rawInventories = $scope['inventory_codes'] ?? null;
            if (! is_array($rawInventories) || $rawInventories === []) {
                $errors[] = 'scope.inventory_codes fehlt oder ist leer.';
            } else {
                foreach ($rawInventories as $code) {
                    $normalized = (string) $code;
                    if ($normalized === '' || ! MasterDataTransferContract::isAllowedInventoryCode($normalized)) {
                        $errors[] = 'scope.inventory_codes enthält unzulässigen Code: '
                            .($normalized === '' ? '(leer)' : $normalized);
                    }
                }
            }
        }
        if ($errors !== []) {
            throw new MasterDataTransferException('Manifest ungültig.', $errors);
        }
    }

    /**
     * @param  array<string, mixed>  $manifest
     */
    private function assertManifestShapeV2(array $manifest): void
    {
        $errors = [];
        if (($manifest['format'] ?? null) !== MasterDataTransferContractV2::FORMAT) {
            $errors[] = 'Ungültiges format.';
        }
        if (($manifest['schema_version'] ?? null) !== MasterDataTransferContractV2::SCHEMA_VERSION) {
            $errors[] = 'Inkompatible schema_version (erwartet '.MasterDataTransferContractV2::SCHEMA_VERSION.').';
        }
        if (($manifest['epic'] ?? null) !== MasterDataTransferContractV2::EPIC) {
            $errors[] = 'Ungültiges epic (erwartet '.MasterDataTransferContractV2::EPIC.').';
        }
        if (! is_string($manifest['git_sha'] ?? null) || $manifest['git_sha'] === '') {
            $errors[] = 'git_sha fehlt.';
        }
        if (! is_string($manifest['exported_at'] ?? null) || $manifest['exported_at'] === '') {
            $errors[] = 'exported_at fehlt.';
        }
        if (! is_string($manifest['source_database'] ?? null) || $manifest['source_database'] === '') {
            $errors[] = 'source_database fehlt.';
        }
        if (($manifest['includes_users'] ?? null) !== false) {
            $errors[] = 'includes_users muss false sein.';
        }
        if (($manifest['includes_crm'] ?? null) !== false) {
            $errors[] = 'includes_crm muss false sein.';
        }
        if (($manifest['includes_calculations'] ?? null) !== false) {
            $errors[] = 'includes_calculations muss false sein.';
        }
        if (($manifest['includes_dispo_orders'] ?? null) !== false) {
            $errors[] = 'includes_dispo_orders muss false sein.';
        }
        if (($manifest['includes_online_audio'] ?? null) !== true) {
            $errors[] = 'includes_online_audio muss true sein.';
        }
        if (($manifest['includes_social_events_barter'] ?? null) !== true) {
            $errors[] = 'includes_social_events_barter muss true sein.';
        }
        if (($manifest['price_status_on_apply'] ?? null) !== MasterDataTransferContractV2::PRICE_STATUS_ON_APPLY) {
            $errors[] = 'price_status_on_apply muss draft sein.';
        }

        $excluded = $manifest['excluded_tables'] ?? null;
        if (! is_array($excluded)) {
            $errors[] = 'excluded_tables fehlt.';
        } else {
            $normalized = array_values(array_map('strval', $excluded));
            sort($normalized);
            if ($normalized !== MasterDataTransferContractV2::excludedTablesSorted()) {
                $errors[] = 'excluded_tables weicht vom Vertrag 2.0.0 ab.';
            }
        }

        $counts = $manifest['counts'] ?? null;
        if (! is_array($counts)) {
            $errors[] = 'counts fehlt.';
        } else {
            foreach (MasterDataTransferContractV2::DATA_FILES as $relative) {
                if (! array_key_exists($relative, $counts) || ! is_int($counts[$relative])) {
                    $errors[] = 'counts fehlt oder ist ungültig für: '.$relative;
                }
            }
            foreach (array_keys($counts) as $key) {
                if (! is_string($key) || ! MasterDataTransferContractV2::isAllowedDataFile($key)) {
                    $errors[] = 'counts enthält unzulässige Datei: '.(is_string($key) ? $key : '(ungültig)');
                }
            }
        }

        $summary = $manifest['source_price_status_summary'] ?? null;
        if (! is_array($summary)) {
            $errors[] = 'source_price_status_summary fehlt.';
        } else {
            foreach (['spot', 'production', 'oa_tkp'] as $family) {
                $row = $summary[$family] ?? null;
                if (! is_array($row)
                    || ! is_int($row['active'] ?? null)
                    || ! is_int($row['archived'] ?? null)
                    || ! is_int($row['draft'] ?? null)
                ) {
                    $errors[] = 'source_price_status_summary.'.$family.' ungültig.';
                }
            }
        }

        if ($errors !== []) {
            throw new MasterDataTransferException('Manifest ungültig.', $errors);
        }
    }

    /**
     * @param  array<string, mixed>|list<mixed>  $payload
     */
    private function assertDataFileShapeV2(string $relative, array $payload): void
    {
        if ($relative === 'data/organization.json') {
            if (array_is_list($payload)) {
                throw new MasterDataTransferException('Paket ungültig.', [
                    'data/organization.json muss ein Objekt sein.',
                ]);
            }

            return;
        }

        if (in_array($relative, MasterDataTransferContractV2::ARRAY_DATA_FILES, true) && ! array_is_list($payload)) {
            throw new MasterDataTransferException('Paket ungültig.', [
                $relative.' muss ein JSON-Array sein (auch leer).',
            ]);
        }
    }

    /**
     * @param  array<string, mixed>  $manifest
     * @param  array<string, mixed>  $data
     */
    private function assertManifestCountsV2(array $manifest, array $data): void
    {
        $errors = [];
        $counts = $manifest['counts'];
        if (! is_array($counts)) {
            return;
        }

        foreach (MasterDataTransferContractV2::DATA_FILES as $relative) {
            $payload = $data[$relative] ?? null;
            if (! is_array($payload)) {
                $errors[] = 'Datei fehlt nach Load: '.$relative;

                continue;
            }
            $expected = $relative === 'data/organization.json'
                ? 1
                : count($payload);
            $actual = $counts[$relative] ?? null;
            if ($actual !== $expected) {
                $errors[] = sprintf(
                    'counts für %s weicht ab (manifest=%s, datei=%d).',
                    $relative,
                    is_scalar($actual) ? (string) $actual : 'null',
                    $expected,
                );
            }
        }

        if ($errors !== []) {
            throw new MasterDataTransferException('Manifest counts ungültig.', $errors);
        }
    }

    /**
     * @param  array<string, mixed>  $manifest
     * @param  list<string>  $dataFiles
     */
    private function assertChecksums(string $root, string $checksumsPath, array $manifest, array $dataFiles): void
    {
        $errors = [];
        $lines = preg_split('/\R/', trim(File::get($checksumsPath))) ?: [];
        $fromFile = [];
        foreach ($lines as $line) {
            if ($line === '') {
                continue;
            }
            if (! preg_match('/^([a-f0-9]{64})  (.+)$/', $line, $m)) {
                $errors[] = 'Ungültige checksums-Zeile.';

                continue;
            }
            $relative = $m[2];
            if (! $this->isSafeRelativePath($relative)) {
                $errors[] = 'Unsicherer Dateipfad in checksums: '.$relative;

                continue;
            }
            if (isset($fromFile[$relative])) {
                $errors[] = 'Doppelter checksums-Eintrag: '.$relative;

                continue;
            }
            $fromFile[$relative] = $m[1];
        }

        $expectedSet = $dataFiles;
        sort($expectedSet);
        $actualSet = array_keys($fromFile);
        sort($actualSet);
        if ($actualSet !== $expectedSet) {
            foreach (array_diff($actualSet, $expectedSet) as $extra) {
                $errors[] = 'Unzulässige Datei in checksums: '.$extra;
            }
            foreach (array_diff($expectedSet, $actualSet) as $missing) {
                $errors[] = 'Checksum-Eintrag fehlt: '.$missing;
            }
        }

        foreach ($dataFiles as $relative) {
            $absolute = $root.DIRECTORY_SEPARATOR.$relative;
            if (! is_file($absolute)) {
                $errors[] = 'Datei fehlt: '.$relative;

                continue;
            }
            $actualHash = hash_file('sha256', $absolute);
            if ($actualHash === false) {
                $errors[] = 'SHA256 nicht lesbar: '.$relative;

                continue;
            }
            $expectedFile = $fromFile[$relative] ?? null;
            $expectedManifest = $manifest['files'][$relative]['sha256'] ?? null;
            if (! is_string($expectedFile) || ! is_string($expectedManifest)) {
                $errors[] = 'Checksum-Eintrag fehlt: '.$relative;

                continue;
            }
            if (! hash_equals($expectedFile, $actualHash) || ! hash_equals($expectedManifest, $actualHash)) {
                $errors[] = 'SHA256-Mismatch: '.$relative;
            }
        }

        $manifestFiles = $manifest['files'] ?? null;
        if (is_array($manifestFiles)) {
            foreach (array_keys($manifestFiles) as $key) {
                if (! is_string($key) || ! in_array($key, $dataFiles, true)) {
                    $errors[] = 'Manifest files enthält unzulässige Datei: '.(is_string($key) ? $key : '(ungültig)');
                }
            }
        }

        if ($errors !== []) {
            throw new MasterDataTransferException('Paketintegrität fehlgeschlagen.', $errors);
        }
    }

    /**
     * @param  list<string>  $dataFiles
     */
    private function assertNoUnexpectedPackageFiles(string $root, array $dataFiles): void
    {
        $errors = [];
        $allowed = array_fill_keys($dataFiles, true);
        $allowed[MasterDataTransferContract::MANIFEST_FILE] = true;
        $allowed[MasterDataTransferContract::CHECKSUMS_FILE] = true;

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS),
        );
        foreach ($iterator as $fileInfo) {
            if (! $fileInfo instanceof \SplFileInfo || ! $fileInfo->isFile()) {
                continue;
            }
            $absolute = $fileInfo->getPathname();
            $relative = ltrim(str_replace('\\', '/', substr($absolute, strlen($root))), '/');
            if ($relative === '' || isset($allowed[$relative])) {
                continue;
            }
            if (! $this->isSafeRelativePath($relative)) {
                $errors[] = 'Unsicherer Dateipfad im Paket: '.$relative;

                continue;
            }
            $errors[] = 'Unerwartete Paketdatei: '.$relative;
        }

        if ($errors !== []) {
            throw new MasterDataTransferException('Paket enthält unzulässige Dateien.', $errors);
        }
    }

    private function isSafeRelativePath(string $relative): bool
    {
        if ($relative === '' || str_starts_with($relative, '/') || str_contains($relative, '\\')) {
            return false;
        }
        if (str_contains($relative, "\0")) {
            return false;
        }
        foreach (explode('/', $relative) as $segment) {
            if ($segment === '' || $segment === '.' || $segment === '..') {
                return false;
            }
        }

        return true;
    }
}
