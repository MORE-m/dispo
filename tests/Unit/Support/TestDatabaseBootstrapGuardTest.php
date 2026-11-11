<?php

namespace Tests\Unit\Support;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\Process\Process;
use Tests\Support\TestDatabaseBootstrapGuard;

/**
 * Fail-closed Bootstrap-Guard ohne Laravel – reine Environment-Matrix.
 */
class TestDatabaseBootstrapGuardTest extends TestCase
{
    public function test_allows_sqlite_memory_under_testing(): void
    {
        $this->expectNotToPerformAssertions();

        TestDatabaseBootstrapGuard::assertSafe([
            'APP_ENV' => 'testing',
            'DB_CONNECTION' => 'sqlite',
            'DB_DATABASE' => ':memory:',
            'DB_URL' => '',
        ]);
    }

    public function test_allows_mysql_dispo_test_under_testing(): void
    {
        $this->expectNotToPerformAssertions();

        TestDatabaseBootstrapGuard::assertSafe([
            'APP_ENV' => 'testing',
            'DB_CONNECTION' => 'mysql',
            'DB_DATABASE' => TestDatabaseBootstrapGuard::ALLOWED_MYSQL_DATABASE,
            'DB_URL' => '',
        ]);
    }

    public function test_allows_isolated_sqlite_file(): void
    {
        $this->expectNotToPerformAssertions();

        TestDatabaseBootstrapGuard::assertSafe([
            'APP_ENV' => 'testing',
            'DB_CONNECTION' => 'sqlite',
            'DB_DATABASE' => '/tmp/dispo-e2e-isolated-probe.sqlite',
            'DB_URL' => '',
        ]);
    }

    public function test_rejects_mysql_dispo_mat_core(): void
    {
        $this->assertGuardRejects([
            'APP_ENV' => 'testing',
            'DB_CONNECTION' => 'mysql',
            'DB_DATABASE' => 'dispo_mat_core',
            'DB_URL' => '',
        ], 'dispo_mat_core');
    }

    public function test_rejects_mysql_dispo(): void
    {
        $this->assertGuardRejects([
            'APP_ENV' => 'testing',
            'DB_CONNECTION' => 'mysql',
            'DB_DATABASE' => 'dispo',
            'DB_URL' => '',
        ], 'dispo');
    }

    public function test_rejects_mysql_arbitrary_database(): void
    {
        $this->assertGuardRejects([
            'APP_ENV' => 'testing',
            'DB_CONNECTION' => 'mysql',
            'DB_DATABASE' => 'irgendeine_andere_db',
            'DB_URL' => '',
        ], 'irgendeine_andere_db');
    }

    public function test_rejects_mysql_empty_database(): void
    {
        $this->assertGuardRejects([
            'APP_ENV' => 'testing',
            'DB_CONNECTION' => 'mysql',
            'DB_DATABASE' => '',
            'DB_URL' => '',
        ]);
    }

    public function test_rejects_non_testing_app_env(): void
    {
        $this->assertGuardRejects([
            'APP_ENV' => 'local',
            'DB_CONNECTION' => 'sqlite',
            'DB_DATABASE' => ':memory:',
            'DB_URL' => '',
        ], 'local');
    }

    public function test_rejects_pgsql_connection(): void
    {
        $this->assertGuardRejects([
            'APP_ENV' => 'testing',
            'DB_CONNECTION' => 'pgsql',
            'DB_DATABASE' => 'dispo_test',
            'DB_URL' => '',
        ], 'pgsql');
    }

    public function test_rejects_mysql_url_pointing_at_dispo_mat_core(): void
    {
        $this->assertGuardRejects([
            'APP_ENV' => 'testing',
            'DB_CONNECTION' => 'mysql',
            'DB_DATABASE' => 'dispo_test',
            'DB_URL' => 'mysql://root:secret@127.0.0.1:3306/dispo_mat_core',
        ], 'dispo_mat_core');
    }

    public function test_rejects_sqlite_path_with_dispo_mat_core_filename(): void
    {
        $this->assertGuardRejects([
            'APP_ENV' => 'testing',
            'DB_CONNECTION' => 'sqlite',
            'DB_DATABASE' => '/database/dispo_mat_core.sqlite',
            'DB_URL' => '',
        ], 'dispo_mat_core');
    }

    public function test_rejects_sqlite_path_with_dispo_filename(): void
    {
        $this->assertGuardRejects([
            'APP_ENV' => 'testing',
            'DB_CONNECTION' => 'sqlite',
            'DB_DATABASE' => '/database/dispo.sqlite',
            'DB_URL' => '',
        ], 'dispo');
    }

    public function test_mysql_url_error_does_not_leak_credentials(): void
    {
        try {
            TestDatabaseBootstrapGuard::assertSafe([
                'APP_ENV' => 'testing',
                'DB_CONNECTION' => 'mysql',
                'DB_DATABASE' => 'dispo_test',
                'DB_URL' => 'mysql://root:super-secret-password@127.0.0.1:3306/dispo',
            ]);
            $this->fail('Guard muss unsichere DB_URL ablehnen.');
        } catch (RuntimeException $exception) {
            $message = strtolower($exception->getMessage());
            $this->assertStringContainsString('testdatenbank-sicherheitsabbruch', $message);
            $this->assertStringNotContainsString('super-secret-password', $message);
            $this->assertStringNotContainsString('password=', $message);
            $this->assertStringNotContainsString('root:', $message);
        }
    }

    public function test_process_bootstrap_aborts_before_autoload_on_unsafe_mysql(): void
    {
        $bootstrap = dirname(__DIR__, 2).'/bootstrap.php';
        $this->assertFileExists($bootstrap);
        $bootstrapExport = var_export($bootstrap, true);

        $script = <<<PHP
fwrite(STDOUT, "MARKER_BEFORE_BOOTSTRAP\\n");
try {
    require {$bootstrapExport};
    fwrite(STDOUT, "MARKER_AFTER_BOOTSTRAP_UNEXPECTED\\n");
    exit(0);
} catch (Throwable \$e) {
    fwrite(STDERR, \$e->getMessage()."\\n");
    exit(2);
}
PHP;

        $process = new Process(
            [PHP_BINARY, '-d', 'display_errors=0', '-r', $script],
            dirname(__DIR__, 3),
            array_merge($this->inheritMinimalEnv(), [
                'APP_ENV' => 'testing',
                'DB_CONNECTION' => 'mysql',
                'DB_DATABASE' => 'dispo_mat_core',
                'DB_URL' => '',
            ]),
        );
        $process->setTimeout(15);
        $process->run();

        $stdout = $process->getOutput();
        $stderr = $process->getErrorOutput();
        $combined = $stdout."\n".$stderr;

        $this->assertNotSame(0, $process->getExitCode());
        $this->assertStringContainsString('MARKER_BEFORE_BOOTSTRAP', $stdout);
        $this->assertStringNotContainsString('MARKER_AFTER_BOOTSTRAP_UNEXPECTED', $stdout);
        $this->assertStringContainsString('Testdatenbank-Sicherheitsabbruch', $combined);
        $this->assertStringContainsString('dispo_mat_core', $combined);
        $this->assertStringNotContainsString('Composer\\Autoload', $combined);
        $this->assertStringNotContainsString('Illuminate\\Foundation', $combined);
        $this->assertStringNotContainsString('Migration', $combined);
    }

    public function test_process_bootstrap_aborts_for_legacy_dispo_mysql(): void
    {
        $bootstrap = dirname(__DIR__, 2).'/bootstrap.php';
        $bootstrapExport = var_export($bootstrap, true);

        $script = <<<PHP
try {
    require {$bootstrapExport};
    exit(0);
} catch (Throwable \$e) {
    fwrite(STDERR, \$e->getMessage()."\\n");
    exit(2);
}
PHP;

        $process = new Process(
            [PHP_BINARY, '-d', 'display_errors=0', '-r', $script],
            dirname(__DIR__, 3),
            array_merge($this->inheritMinimalEnv(), [
                'APP_ENV' => 'testing',
                'DB_CONNECTION' => 'mysql',
                'DB_DATABASE' => 'dispo',
                'DB_URL' => '',
            ]),
        );
        $process->setTimeout(15);
        $process->run();

        $combined = $process->getErrorOutput()."\n".$process->getOutput();
        $this->assertNotSame(0, $process->getExitCode());
        $this->assertStringContainsString('Testdatenbank-Sicherheitsabbruch', $combined);
        $this->assertStringContainsString('dispo', $combined);
    }

    /**
     * @param  array<string, mixed>  $environment
     */
    private function assertGuardRejects(array $environment, ?string $mustMention = null): void
    {
        try {
            TestDatabaseBootstrapGuard::assertSafe($environment);
            $this->fail('Guard muss unsichere Konfiguration ablehnen: '.json_encode($environment));
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('Testdatenbank-Sicherheitsabbruch', $exception->getMessage());
            if ($mustMention !== null) {
                $this->assertStringContainsString($mustMention, $exception->getMessage());
            }
        }
    }

    /**
     * @return array<string, string>
     */
    private function inheritMinimalEnv(): array
    {
        $env = [];
        foreach (['PATH', 'HOME', 'USER', 'TMPDIR', 'TMP', 'TEMP', 'SYSTEMROOT'] as $key) {
            $value = getenv($key);
            if ($value !== false) {
                $env[$key] = $value;
            }
        }

        return $env;
    }
}
