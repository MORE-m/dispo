<?php

namespace Tests\Feature;

use App\Support\PrivateFileStorage;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;
use Tests\TestCase;

class PrivateFileStorageTest extends TestCase
{
    public function test_private_disk_is_not_publicly_served(): void
    {
        $this->assertSame('local', config('dispo.files_disk'));
        $this->assertFalse((bool) config('filesystems.disks.local.serve'));
        $this->assertNull(config('filesystems.disks.local.url'));
    }

    public function test_files_can_be_stored_and_read_privately(): void
    {
        Storage::fake((string) config('dispo.files_disk'));

        $storage = app(PrivateFileStorage::class);
        $path = 'health-check/smoke.txt';

        $this->assertNotFalse($storage->put($path, 'ok'));
        $this->assertTrue($storage->exists($path));
        $this->assertSame('ok', $storage->get($path));
        $this->assertFalse(is_file(storage_path('app/private/health-check/smoke.txt')));
    }

    public function test_temporary_files_may_be_deleted_physically(): void
    {
        Storage::fake((string) config('dispo.files_disk'));

        $storage = app(PrivateFileStorage::class);
        $path = PrivateFileStorage::TEMPORARY_PREFIX.'upload.bin';

        $this->assertNotFalse($storage->put($path, 'tmp'));
        $this->assertTrue($storage->deleteTemporary($path));
        $this->assertFalse($storage->exists($path));
    }

    public function test_persisted_paths_cannot_be_deleted_physically(): void
    {
        Storage::fake((string) config('dispo.files_disk'));

        $this->expectException(InvalidArgumentException::class);

        app(PrivateFileStorage::class)->deleteTemporary('uploads/kept.txt');
    }

    public function test_move_relocates_file(): void
    {
        Storage::fake((string) config('dispo.files_disk'));

        $storage = app(PrivateFileStorage::class);
        $from = PrivateFileStorage::TEMPORARY_PREFIX.'src.bin';
        $to = 'price-list-imports/dst.bin';
        $storage->put($from, 'payload');

        $this->assertTrue($storage->move($from, $to));
        Storage::disk((string) config('dispo.files_disk'))->assertMissing($from);
        Storage::disk((string) config('dispo.files_disk'))->assertExists($to);
    }
}
