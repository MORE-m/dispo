<?php

namespace App\Support;

use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;

/**
 * Kapselt den privaten Dateispeicher. Der Disk-Name ist konfigurierbar,
 * damit später ohne Fachlogikänderung auf S3 gewechselt werden kann (ADR-001/002).
 *
 * Persistierte V1-Uploads werden archiviert, nicht physisch gelöscht (UPL-005).
 */
class PrivateFileStorage
{
    public const TEMPORARY_PREFIX = 'temporary/';

    public function disk(): Filesystem
    {
        return Storage::disk((string) config('dispo.files_disk'));
    }

    public function put(string $path, string $contents): bool|string
    {
        return $this->disk()->put($path, $contents);
    }

    public function move(string $from, string $to): bool
    {
        return $this->disk()->move($from, $to);
    }

    public function get(string $path): ?string
    {
        return $this->disk()->get($path);
    }

    public function exists(string $path): bool
    {
        return $this->disk()->exists($path);
    }

    /**
     * Physisches Löschen nur für noch nicht persistierte temporäre Dateien.
     */
    public function deleteTemporary(string $path): bool
    {
        if (! str_starts_with($path, self::TEMPORARY_PREFIX)) {
            throw new InvalidArgumentException(
                'Physisches Löschen ist nur für temporäre Pfade unter '.self::TEMPORARY_PREFIX.' erlaubt.',
            );
        }

        return $this->disk()->delete($path);
    }
}
