<?php

namespace Tests\Unit\Support;

use Illuminate\Support\Facades\Config;
use RuntimeException;
use Tests\Support\MysqlTestDatabaseGuard;
use Tests\TestCase;

/**
 * Fail-fast-Guard ohne destruktive Traits gegen die Entwicklungsdatenbank.
 */
class MysqlTestDatabaseGuardTest extends TestCase
{
    public function test_sqlite_memory_is_allowed(): void
    {
        Config::set('database.default', 'sqlite');
        Config::set('database.connections.sqlite.driver', 'sqlite');
        Config::set('database.connections.sqlite.database', ':memory:');

        MysqlTestDatabaseGuard::assertSafeBeforeDestructiveTraits();

        $this->assertSame('sqlite', config('database.default'));
    }

    public function test_guard_rejects_dev_database_dispo_before_destructive_work(): void
    {
        Config::set('database.default', 'mysql');
        Config::set('database.connections.mysql.driver', 'mysql');
        Config::set('database.connections.mysql.database', 'dispo');

        try {
            MysqlTestDatabaseGuard::assertMysqlDatabaseIsDispoTest(
                configuredDatabase: 'dispo',
                context: 'unit-probe',
                verifySelectedDatabase: false,
            );
            $this->fail('Guard muss dispo ablehnen.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('Testdatenbank-Sicherheitsabbruch', $exception->getMessage());
            $this->assertStringContainsString('dispo', $exception->getMessage());
            $this->assertStringNotContainsString('password', strtolower($exception->getMessage()));
        }
    }

    public function test_guard_rejects_empty_and_unknown_database_names(): void
    {
        foreach ([null, '', '   ', 'dispo_prod', 'other'] as $name) {
            try {
                MysqlTestDatabaseGuard::assertMysqlDatabaseIsDispoTest(
                    configuredDatabase: is_string($name) ? $name : null,
                    context: 'unit-probe',
                    verifySelectedDatabase: false,
                );
                $this->fail('Guard muss ungültigen Namen ablehnen: '.var_export($name, true));
            } catch (RuntimeException $exception) {
                $this->assertStringContainsString('Testdatenbank-Sicherheitsabbruch', $exception->getMessage());
            }
        }
    }

    public function test_guard_accepts_dispo_test_without_live_connection_check(): void
    {
        MysqlTestDatabaseGuard::assertMysqlDatabaseIsDispoTest(
            configuredDatabase: 'dispo_test',
            context: 'unit-probe',
            verifySelectedDatabase: false,
        );

        $this->assertSame('dispo_test', MysqlTestDatabaseGuard::ALLOWED_MYSQL_DATABASE);
    }

    public function test_set_up_traits_invokes_guard_before_parent_traits(): void
    {
        Config::set('database.default', 'mysql');
        Config::set('database.connections.mysql.driver', 'mysql');
        Config::set('database.connections.mysql.database', 'dispo');

        $parentTraitsReached = false;

        $run = function () use (&$parentTraitsReached): void {
            MysqlTestDatabaseGuard::assertSafeBeforeDestructiveTraits();
            $parentTraitsReached = true;
        };

        try {
            $run();
            $this->fail('Guard muss bei dispo abbrechen, bevor Traits weiterlaufen.');
        } catch (RuntimeException $exception) {
            $this->assertFalse($parentTraitsReached);
            $this->assertStringContainsString('Testdatenbank-Sicherheitsabbruch', $exception->getMessage());
        }
    }
}
