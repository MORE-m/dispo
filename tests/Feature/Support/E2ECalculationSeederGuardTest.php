<?php

namespace Tests\Feature\Support;

use App\Models\Organization;
use App\Models\User;
use Database\Seeders\E2ECalculationSeeder;
use Illuminate\Support\Facades\Config;
use RuntimeException;
use Tests\TestCase;

/**
 * E2ECalculationSeeder: fail-closed gegen Dev-DB, erlaubt isolierten E2E-Pfad.
 * Kein RefreshDatabase: isolierte Temp-SQLite, niemals Dev-DB „dispo“.
 */
class E2ECalculationSeederGuardTest extends TestCase
{
    public function test_seeder_rejects_when_e2e_server_is_disabled(): void
    {
        Config::set('app.e2e_server', false);
        $this->app['env'] = 'testing';
        Config::set('database.default', 'sqlite');
        Config::set('database.connections.sqlite.database', ':memory:');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('E2E-Seeder-Sicherheitsabbruch');

        (new E2ECalculationSeeder)->run();
    }

    public function test_seeder_rejects_configured_dev_database_dispo(): void
    {
        Config::set('app.e2e_server', true);
        $this->app['env'] = 'testing';
        Config::set('database.default', 'mysql');
        Config::set('database.connections.mysql.driver', 'mysql');
        Config::set('database.connections.mysql.database', 'dispo');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Entwicklungsdatenbank');

        (new E2ECalculationSeeder)->run();
    }

    public function test_seeder_runs_under_isolated_testing_e2e_sqlite(): void
    {
        $dbFile = sys_get_temp_dir().'/dispo-e2e-seed-'.bin2hex(random_bytes(8)).'.sqlite';
        touch($dbFile);

        try {
            Config::set('app.e2e_server', true);
            $this->app['env'] = 'testing';
            Config::set('database.default', 'sqlite');
            Config::set('database.connections.sqlite.driver', 'sqlite');
            Config::set('database.connections.sqlite.database', $dbFile);
            Config::set('database.connections.sqlite.prefix', '');

            $this->assertSame(
                0,
                \Illuminate\Support\Facades\Artisan::call('migrate', ['--force' => true]),
            );

            (new E2ECalculationSeeder)->run();

            $this->assertTrue(
                User::query()->where('email', 'sales@example.com')->exists(),
            );
            $this->assertTrue(
                Organization::query()->where('name', 'E2E Sendergruppe')->exists(),
            );
        } finally {
            @unlink($dbFile);
        }
    }
}
