<?php

namespace Tests\Feature\Support;

use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * Statische und isolierte Checks für scripts/start-dev.sh – ohne Dev-DB dispo.
 */
class StartDevScriptSafetyTest extends TestCase
{
    public function test_start_dev_script_does_not_auto_seed_e2e_or_dev_users(): void
    {
        $script = file_get_contents(base_path('scripts/start-dev.sh'));
        $this->assertNotFalse($script);

        $this->assertStringContainsString('artisan migrate --force', $script);
        $this->assertStringContainsString('DISPO_SETUP_ONLY', $script);
        $this->assertStringContainsString('DISPO_ENV_FILE', $script);
        $this->assertStringContainsString('DISPO_SKIP_FRONTEND_BUILD', $script);
        $this->assertStringContainsString('Keine automatischen Seeder', $script);
        $this->assertStringContainsString('DevUserSeeder --force', $script);

        $this->assertDoesNotMatchRegularExpression(
            '/"\$PHP_BIN"\s+artisan\s+db:seed\s+--class=E2ECalculationSeeder/',
            $script,
        );
        $this->assertDoesNotMatchRegularExpression(
            '/"\$PHP_BIN"\s+artisan\s+db:seed\s+--class=DevUserSeeder/',
            $script,
        );
    }

    public function test_setup_only_succeeds_after_migrate_noop_on_isolated_sqlite(): void
    {
        $tmp = sys_get_temp_dir().'/dispo-start-dev-'.bin2hex(random_bytes(8));
        mkdir($tmp.'/database', 0700, true);
        mkdir($tmp.'/public/build', 0700, true);
        file_put_contents($tmp.'/public/build/.keep', '');
        $dbFile = $tmp.'/database/setup-only.sqlite';
        touch($dbFile);

        $appKey = 'base64:'.base64_encode(random_bytes(32));
        $envFile = $tmp.'/.env';
        file_put_contents($envFile, implode("\n", [
            'APP_NAME=Dispo',
            'APP_ENV=local',
            'APP_KEY='.$appKey,
            'APP_DEBUG=true',
            'APP_URL=http://127.0.0.1:18099',
            'DB_CONNECTION=sqlite',
            'DB_DATABASE='.$dbFile,
            'DB_URL=',
            'SESSION_DRIVER=array',
            'CACHE_STORE=array',
            'QUEUE_CONNECTION=sync',
        ])."\n");

        $sharedEnv = [
            'APP_ENV' => 'local',
            'APP_KEY' => $appKey,
            'DB_CONNECTION' => 'sqlite',
            'DB_DATABASE' => $dbFile,
            'DB_URL' => '',
            'CACHE_STORE' => 'array',
            'SESSION_DRIVER' => 'array',
            'QUEUE_CONNECTION' => 'sync',
            'PATH' => getenv('PATH') ?: '/usr/bin:/bin',
            'HOME' => getenv('HOME') ?: sys_get_temp_dir(),
        ];

        $migrate = new Process(
            [PHP_BINARY, base_path('artisan'), 'migrate', '--force'],
            base_path(),
            $sharedEnv,
        );
        $migrate->setTimeout(120);
        $migrate->run();
        $this->assertTrue(
            $migrate->isSuccessful(),
            $migrate->getOutput()."\n".$migrate->getErrorOutput(),
        );

        // No-op-Lauf über Startskript; isolierte ENV-Datei, keine Projekt-.env.
        $process = new Process(
            ['bash', base_path('scripts/start-dev.sh')],
            base_path(),
            array_merge($sharedEnv, [
                'DISPO_ENV_FILE' => $envFile,
                'DISPO_SETUP_ONLY' => '1',
                'DISPO_SKIP_FRONTEND_BUILD' => '1',
                'DISPO_PORT' => '18099',
                'DISPO_HOST' => '127.0.0.1',
                'PHP_BIN' => PHP_BINARY,
            ]),
        );
        $process->setTimeout(120);
        $process->run();

        $output = $process->getOutput()."\n".$process->getErrorOutput();
        $this->assertTrue($process->isSuccessful(), $output);
        $this->assertStringContainsString('Nothing to migrate', $output);
        $this->assertStringContainsString('DISPO_SETUP_ONLY=1', $output);
        $this->assertStringContainsString('Keine automatischen Seeder', $output);
        $this->assertStringNotContainsString('db:seed --class=E2ECalculationSeeder', $output);
        $this->assertStringNotContainsString('Testbenutzer …', $output);
    }

    public function test_migrate_failure_stops_start_dev_before_serve(): void
    {
        $tmp = sys_get_temp_dir().'/dispo-start-dev-fail-'.bin2hex(random_bytes(8));
        mkdir($tmp, 0700, true);
        $fakePhp = $tmp.'/fake-php';
        $fakePhpScript = implode("\n", [
            '#!/usr/bin/env bash',
            'set -euo pipefail',
            'if [[ "${1:-}" == "-r" && "${2:-}" == "echo PHP_MAJOR_VERSION;" ]]; then',
            '  echo 8',
            '  exit 0',
            'fi',
            'if [[ "${1:-}" == "-r" && "${2:-}" == "echo PHP_MINOR_VERSION;" ]]; then',
            '  echo 4',
            '  exit 0',
            'fi',
            'if [[ "${1:-}" == "-r" && "${2:-}" == "echo PHP_VERSION;" ]]; then',
            '  echo 8.4.0',
            '  exit 0',
            'fi',
            'if [[ "$*" == *"artisan migrate"* ]]; then',
            '  echo "Simulierter Migrationsfehler" >&2',
            '  exit 1',
            'fi',
            'echo "unerwarteter fake-php Aufruf: $*" >&2',
            'exit 2',
            '',
        ]);
        file_put_contents($fakePhp, $fakePhpScript);
        chmod($fakePhp, 0755);

        $envFile = $tmp.'/.env';
        file_put_contents($envFile, implode("\n", [
            'APP_KEY=base64:'.base64_encode(random_bytes(32)),
            'DB_CONNECTION=sqlite',
            'DB_DATABASE='.$tmp.'/unused.sqlite',
        ])."\n");
        touch($tmp.'/unused.sqlite');

        $process = new Process(
            ['bash', base_path('scripts/start-dev.sh')],
            base_path(),
            [
                'PATH' => getenv('PATH') ?: '/usr/bin:/bin',
                'PHP_BIN' => $fakePhp,
                'DISPO_ENV_FILE' => $envFile,
                'DISPO_PORT' => '18101',
                'DISPO_HOST' => '127.0.0.1',
                'DISPO_SETUP_ONLY' => '1',
                'HOME' => getenv('HOME') ?: $tmp,
                'DB_CONNECTION' => 'sqlite',
                'DB_DATABASE' => $tmp.'/unused.sqlite',
            ],
        );
        $process->setTimeout(30);
        $process->run();

        $output = $process->getOutput()."\n".$process->getErrorOutput();
        $this->assertFalse($process->isSuccessful(), $output);
        $this->assertStringContainsString('Simulierter Migrationsfehler', $output);
        $this->assertStringNotContainsString('db:seed', $output);
        $this->assertSame(1, $process->getExitCode());
    }
}
