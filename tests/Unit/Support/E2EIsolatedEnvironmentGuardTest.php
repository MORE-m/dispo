<?php

namespace Tests\Unit\Support;

use App\Support\E2E\E2EIsolatedEnvironmentGuard;
use Illuminate\Support\Facades\Config;
use RuntimeException;
use Tests\TestCase;

/**
 * Fail-closed Guard für E2E-Seeder (testing + E2E_SERVER, nie Dev-DB dispo).
 */
class E2EIsolatedEnvironmentGuardTest extends TestCase
{
    public function test_rejects_without_testing_environment(): void
    {
        Config::set('app.e2e_server', true);
        $this->app['env'] = 'local';

        try {
            E2EIsolatedEnvironmentGuard::assertSafeForE2ESeeding();
            $this->fail('Guard muss local ablehnen.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('E2E-Seeder-Sicherheitsabbruch', $exception->getMessage());
            $this->assertStringContainsString('APP_ENV=testing', $exception->getMessage());
        }
    }

    public function test_rejects_without_e2e_server_flag(): void
    {
        Config::set('app.e2e_server', false);
        $this->app['env'] = 'testing';

        try {
            E2EIsolatedEnvironmentGuard::assertSafeForE2ESeeding();
            $this->fail('Guard muss fehlendes E2E_SERVER ablehnen.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('E2E_SERVER=1', $exception->getMessage());
        }
    }

    public function test_rejects_mysql_dev_database_dispo(): void
    {
        Config::set('app.e2e_server', true);
        $this->app['env'] = 'testing';
        Config::set('database.default', 'mysql');
        Config::set('database.connections.mysql.driver', 'mysql');
        Config::set('database.connections.mysql.database', 'dispo');

        try {
            E2EIsolatedEnvironmentGuard::assertSafeForE2ESeeding();
            $this->fail('Guard muss Dev-DB dispo ablehnen.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('Entwicklungsdatenbank', $exception->getMessage());
            $this->assertStringContainsString('dispo', $exception->getMessage());
            $this->assertStringNotContainsString('password', strtolower($exception->getMessage()));
        }
    }

    public function test_rejects_sqlite_path_named_dispo(): void
    {
        $this->assertTrue(
            E2EIsolatedEnvironmentGuard::targetsForbiddenDevDatabase(
                '/tmp/dispo.sqlite',
                'sqlite',
            ),
        );
        $this->assertFalse(
            E2EIsolatedEnvironmentGuard::targetsForbiddenDevDatabase(
                '/tmp/e2e.sqlite',
                'sqlite',
            ),
        );
        $this->assertFalse(
            E2EIsolatedEnvironmentGuard::targetsForbiddenDevDatabase(':memory:', 'sqlite'),
        );
    }

    public function test_allows_testing_e2e_server_and_sqlite_memory(): void
    {
        Config::set('app.e2e_server', true);
        $this->app['env'] = 'testing';
        Config::set('database.default', 'sqlite');
        Config::set('database.connections.sqlite.driver', 'sqlite');
        Config::set('database.connections.sqlite.database', ':memory:');

        E2EIsolatedEnvironmentGuard::assertSafeForE2ESeeding();

        $this->assertTrue((bool) config('app.e2e_server'));
        $this->assertTrue(app()->environment('testing'));
    }
}
