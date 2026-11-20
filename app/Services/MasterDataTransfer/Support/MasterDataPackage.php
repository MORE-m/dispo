<?php

namespace App\Services\MasterDataTransfer\Support;

use App\Services\MasterDataTransfer\MasterDataTransferContract;
use App\Services\MasterDataTransfer\MasterDataTransferException;
use Illuminate\Support\Facades\File;

/**
 * Lädt und prüft ein EPIC-03-B Exportpaket (Manifest + SHA256).
 */
final class MasterDataPackage
{
    /**
     * @return array{
     *     root: string,
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

        if (! is_file($manifestPath) || ! is_file($checksumsPath)) {
            throw new MasterDataTransferException('manifest.json oder checksums.sha256 fehlt.');
        }

        $manifest = $this->decodeJsonFile($manifestPath);
        $this->assertManifestShape($manifest);
        $this->assertChecksums($root, $checksumsPath, $manifest);

        $data = [];
        foreach (MasterDataTransferContract::DATA_FILES as $relative) {
            $data[$relative] = $this->decodeJsonFile($root.DIRECTORY_SEPARATOR.$relative);
        }

        return [
            'root' => $root,
            'manifest' => $manifest,
            'data' => $data,
        ];
    }

    /**
     * @param  array<string, mixed>  $manifest
     * @param  array<string, array<string, mixed>>  $filePayloads  relative path => decoded JSON-serializable
     */
    public function write(string $packagePath, array $manifest, array $filePayloads): void
    {
        File::ensureDirectoryExists($packagePath.DIRECTORY_SEPARATOR.'data');

        $checksumLines = [];
        foreach (MasterDataTransferContract::DATA_FILES as $relative) {
            if (! array_key_exists($relative, $filePayloads)) {
                throw new MasterDataTransferException('Export unvollständig: '.$relative);
            }
            $absolute = $packagePath.DIRECTORY_SEPARATOR.$relative;
            $json = $this->encodeJson($filePayloads[$relative]);
            File::ensureDirectoryExists(dirname($absolute));
            File::put($absolute, $json);
            $hash = hash('sha256', $json);
            $checksumLines[] = $hash.'  '.$relative;
            $manifest['files'][$relative] = [
                'sha256' => $hash,
                'bytes' => strlen($json),
            ];
        }

        $manifestJson = $this->encodeJson($manifest);
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
    private function encodeJson(array $payload): string
    {
        $json = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            throw new MasterDataTransferException('JSON-Encode fehlgeschlagen.');
        }

        return $json."\n";
    }

    /**
     * @param  array<string, mixed>  $manifest
     */
    private function assertManifestShape(array $manifest): void
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
        if (! is_array($manifest['scope'] ?? null)) {
            $errors[] = 'scope fehlt.';
        } elseif (($manifest['scope']['includes_online_audio'] ?? null) !== false) {
            $errors[] = 'includes_online_audio muss false sein (EPIC-03-B).';
        }
        if ($errors !== []) {
            throw new MasterDataTransferException('Manifest ungültig.', $errors);
        }
    }

    /**
     * @param  array<string, mixed>  $manifest
     */
    private function assertChecksums(string $root, string $checksumsPath, array $manifest): void
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
            $fromFile[$m[2]] = $m[1];
        }

        foreach (MasterDataTransferContract::DATA_FILES as $relative) {
            $absolute = $root.DIRECTORY_SEPARATOR.$relative;
            if (! is_file($absolute)) {
                $errors[] = 'Datei fehlt: '.$relative;

                continue;
            }
            $actual = hash_file('sha256', $absolute);
            $expectedFile = $fromFile[$relative] ?? null;
            $expectedManifest = $manifest['files'][$relative]['sha256'] ?? null;
            if ($expectedFile === null || $expectedManifest === null) {
                $errors[] = 'Checksum-Eintrag fehlt: '.$relative;

                continue;
            }
            if (! hash_equals($expectedFile, $actual) || ! hash_equals($expectedManifest, $actual)) {
                $errors[] = 'SHA256-Mismatch: '.$relative;
            }
        }

        if ($errors !== []) {
            throw new MasterDataTransferException('Paketintegrität fehlgeschlagen.', $errors);
        }
    }
}
