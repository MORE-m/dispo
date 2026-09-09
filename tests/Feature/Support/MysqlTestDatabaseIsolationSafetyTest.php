<?php

namespace Tests\Feature\Support;

use Illuminate\Support\Facades\DB;
use PDO;
use Symfony\Component\Process\Process;
use Tests\TestCase;
use Throwable;

/**
 * MySQL-Testisolation: phpunit.mysql.xml force + Worker-Guard.
 * Kein RefreshDatabase gegen die Entwicklungsdatenbank.
 */
class MysqlTestDatabaseIsolationSafetyTest extends TestCase
{
    public function test_phpunit_mysql_xml_forces_dispo_test_over_shell_dispo(): void
    {
        if (! $this->mysqlServerReachable()) {
            $this->markTestSkipped('MySQL 127.0.0.1 nicht erreichbar.');
        }

        $probeFile = base_path('tests/Feature/Support/MysqlTestDatabaseIsolationSafetyTest.php');
        $process = new Process(
            [
                PHP_BINARY,
                base_path('vendor/bin/pest'),
                '--configuration=phpunit.mysql.xml',
                '--filter=test_resolved_laravel_mysql_database_is_dispo_test$',
                $probeFile,
            ],
            base_path(),
            array_merge($this->inheritMinimalEnv(), [
                // Feindliche äußere Werte – dürfen die Dev-DB nicht wählen.
                'DB_DATABASE' => 'dispo',
                'DB_URL' => 'mysql://root@127.0.0.1:3306/dispo',
                'APP_ENV' => 'local',
            ]),
        );
        $process->setTimeout(60);
        $process->run();

        $output = $process->getOutput()."\n".$process->getErrorOutput();
        $this->assertTrue(
            $process->isSuccessful(),
            $output,
        );
        // Lokal (JSON-Reporter) oder CI (Text): beide Formate akzeptieren.
        $passedViaJson = str_contains($output, '"result":"passed"');
        $passedViaText = str_contains($output, 'resolved laravel mysql database is dispo test')
            && (str_contains($output, 'PASS') || str_contains($output, '1 passed'));
        $this->assertTrue(
            $passedViaJson || $passedViaText,
            "Subprozess-Schutzprobe ohne erkennbares PASS:\n{$output}",
        );
    }

    public function test_resolved_laravel_mysql_database_is_dispo_test(): void
    {
        if (config('database.default') !== 'mysql'
            && (string) config('database.connections.mysql.driver') !== 'mysql'
        ) {
            $this->markTestSkipped('Nur unter phpunit.mysql.xml relevant.');
        }

        if ((string) config('database.connections.'.config('database.default').'.driver') !== 'mysql') {
            $this->markTestSkipped('Aktueller Lauf ist kein MySQL-Testlauf.');
        }

        $configured = (string) config('database.connections.mysql.database');
        $this->assertSame('dispo_test', $configured);

        $selected = DB::selectOne('select database() as db_name');
        $selectedName = is_object($selected) ? (string) ($selected->db_name ?? '') : '';
        $this->assertSame('dispo_test', $selectedName);
        $this->assertTrue(app()->environment('testing'));
    }

    public function test_parallel_worker_rejects_dev_database_dispo(): void
    {
        $process = new Process(
            [
                PHP_BINARY,
                base_path('tests/concurrency/calculation_number_worker.php'),
                'safety-run',
                '0',
            ],
            base_path(),
            array_merge($this->inheritMinimalEnv(), [
                'APP_ENV' => 'testing',
                'DB_CONNECTION' => 'mysql',
                'DB_HOST' => '127.0.0.1',
                'DB_PORT' => '3306',
                'DB_DATABASE' => 'dispo',
                'DB_USERNAME' => 'root',
                'DB_PASSWORD' => '',
                'DB_URL' => '',
            ]),
        );
        $process->setTimeout(30);
        $process->run();

        $this->assertFalse($process->isSuccessful());
        $combined = $process->getErrorOutput()."\n".$process->getOutput();
        $this->assertStringContainsString('Testdatenbank-Sicherheitsabbruch', $combined);
        $this->assertStringNotContainsString('password=', strtolower($combined));
    }

    public function test_parallel_worker_accepts_dispo_test_bootstrap(): void
    {
        if (! $this->mysqlServerReachable()) {
            $this->markTestSkipped('MySQL 127.0.0.1 nicht erreichbar.');
        }

        $script = <<<'PHP'
try {
    $app = require 'tests/concurrency/bootstrap_mysql_worker.php';
    echo 'OK:'.config('database.connections.mysql.database');
} catch (Throwable $e) {
    fwrite(STDERR, $e->getMessage());
    exit(1);
}
PHP;

        $process = new Process(
            [PHP_BINARY, '-r', $script],
            base_path(),
            array_merge($this->inheritMinimalEnv(), [
                'APP_ENV' => 'testing',
                'APP_KEY' => 'base64:2fl+Ktvkfl+Fuz4Qp/Ej30N8mK8nwZuqqjHadrQtdZg=',
                'DB_CONNECTION' => 'mysql',
                'DB_HOST' => '127.0.0.1',
                'DB_PORT' => '3306',
                'DB_DATABASE' => 'dispo_test',
                'DB_USERNAME' => 'root',
                'DB_PASSWORD' => '',
                'DB_URL' => '',
            ]),
        );
        $process->setTimeout(30);
        $process->run();

        $this->assertTrue($process->isSuccessful(), $process->getErrorOutput());
        $this->assertSame('OK:dispo_test', trim($process->getOutput()));
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

    private function mysqlServerReachable(): bool
    {
        try {
            new PDO(
                'mysql:host=127.0.0.1;port=3306;dbname=dispo_test',
                'root',
                '',
                [PDO::ATTR_TIMEOUT => 2],
            );

            return true;
        } catch (Throwable) {
            return false;
        }
    }
}
