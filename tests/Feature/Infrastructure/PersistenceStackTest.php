<?php

namespace Tests\Feature\Infrastructure;

use App\Enums\Role;
use App\Models\User;
use App\Support\PrivateFileStorage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class PersistenceStackTest extends TestCase
{
    use RefreshDatabase;

    public function test_users_can_authenticate_and_role_gate_is_enforced(): void
    {
        $admin = User::factory()->role(Role::Admin)->create();
        $sales = User::factory()->role(Role::Sales)->create();

        $this->actingAs($admin)->get(route('admin.access'))->assertOk();
        $this->actingAs($sales)->get(route('admin.access'))->assertForbidden();
    }

    public function test_database_queue_persists_jobs_when_configured(): void
    {
        if (config('queue.default') !== 'database') {
            $this->markTestSkipped('Queue-Treiber ist nicht database.');
        }

        Queue::push(function (): void {});

        $this->assertGreaterThan(0, DB::table('jobs')->count());
    }

    public function test_database_cache_roundtrip_when_configured(): void
    {
        if (config('cache.default') !== 'database') {
            $this->markTestSkipped('Cache-Treiber ist nicht database.');
        }

        Cache::put('stack-check', 'ok', 60);

        $this->assertSame('ok', Cache::get('stack-check'));
    }

    public function test_database_sessions_table_exists_when_configured(): void
    {
        if (config('session.driver') !== 'database') {
            $this->markTestSkipped('Session-Treiber ist nicht database.');
        }

        $this->assertTrue(Schema::hasTable('sessions'));
    }

    public function test_private_file_storage_works_on_configured_disk(): void
    {
        Storage::fake((string) config('dispo.files_disk'));

        $storage = app(PrivateFileStorage::class);
        $path = PrivateFileStorage::TEMPORARY_PREFIX.'ci.txt';

        $this->assertNotFalse($storage->put($path, 'ci'));
        $this->assertSame('ci', $storage->get($path));
        $this->assertTrue($storage->deleteTemporary($path));
    }
}
