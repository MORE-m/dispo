<?php

namespace Tests\Feature\OnlineAudio;

use App\Enums\PriceListStatus;
use App\Models\OaTkpPriceList;
use App\Services\OnlineAudio\Import\OaTkpPriceListDraftImportGuard;
use Database\Seeders\InitialCatalogMatCoreSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Process\Process;
use Tests\Support\MysqlTestDatabaseGuard;
use Tests\TestCase;

/**
 * PO-CALC-TKP-1D4c-2: MySQL-Concurrency für OA-TKP-Aktivierung (GET_LOCK).
 *
 * RefreshDatabase ohne Parent-TX (`connectionsToTransact=[]`), damit Worker
 * committed Drafts sehen. Unter SQLite: früh skip ohne Migration (kein
 * RefreshDatabaseState-Poisoning der SQLite-Suite).
 *
 * @group mysql
 */
class PoCalcTkp1d4c2ActivateConcurrencyMysqlTest extends TestCase
{
    use RefreshDatabase;

    private bool $skipBecauseNotMysql = false;

    /**
     * @return list<string>
     */
    protected function connectionsToTransact(): array
    {
        return [];
    }

    /**
     * @return array<int, string>
     */
    protected function setUpTraits(): array
    {
        MysqlTestDatabaseGuard::assertSafeBeforeDestructiveTraits();

        $driver = (string) config('database.connections.'.config('database.default').'.driver');
        if ($driver !== 'mysql') {
            $this->skipBecauseNotMysql = true;

            return [];
        }

        return parent::setUpTraits();
    }

    protected function setUp(): void
    {
        parent::setUp();

        if ($this->skipBecauseNotMysql) {
            $this->markTestSkipped('OA-TKP Activate-Concurrency erfordert MySQL (GET_LOCK).');
        }

        $this->purgeOaPriceLists();
    }

    protected function tearDown(): void
    {
        if (! $this->skipBecauseNotMysql) {
            $this->purgeOaPriceLists();
        }

        parent::tearDown();
    }

    #[Test]
    public function concurrent_activate_same_year_leaves_exactly_one_active(): void
    {
        $this->seed(InitialCatalogMatCoreSeeder::class);
        $guard = app(OaTkpPriceListDraftImportGuard::class);
        $guard->importDraftOnly([
            'year' => 2026,
            'revision_number' => 1,
            'status' => PriceListStatus::Draft->value,
            'name' => 'Concurrency A',
            'version' => '2026.1',
            'items' => [[
                'medium_code' => 'pre_stream',
                'inventory_code' => 'inv_more_kombi_online_audio',
                'list_tkp' => '12.0000',
                'minimum_tkp' => '8.0000',
            ]],
        ]);
        $guard->importDraftOnly([
            'year' => 2026,
            'revision_number' => 2,
            'status' => PriceListStatus::Draft->value,
            'name' => 'Concurrency B',
            'version' => '2026.2',
            'items' => [[
                'medium_code' => 'pre_stream',
                'inventory_code' => 'inv_more_kombi_online_audio',
                'list_tkp' => '13.0000',
                'minimum_tkp' => '9.0000',
            ]],
        ]);

        $results = $this->runParallelActivate(2026, 1, 2026, 2, archivePrevious: true);
        $ok = array_values(array_filter($results, fn (string $line): bool => str_starts_with($line, 'OK:')));
        $this->assertGreaterThanOrEqual(1, count($ok), implode(' || ', $results));
        foreach ($results as $line) {
            $this->assertTrue(
                str_starts_with($line, 'OK:') || str_starts_with($line, 'ERROR:import'),
                'Unerwartetes Worker-Resultat: '.$line,
            );
            foreach (['Deadlock', '1213', '1205', 'Lock wait timeout', 'SQLSTATE[40'] as $needle) {
                $this->assertStringNotContainsString($needle, $line);
            }
        }

        $this->assertSame(
            1,
            OaTkpPriceList::query()->where('year', 2026)->where('status', PriceListStatus::Active)->count(),
            'Nach paralleler Aktivierung muss genau eine active Revision existieren. Results='.implode(' || ', $results),
        );
    }

    #[Test]
    public function concurrent_activate_same_revision_is_safe(): void
    {
        $this->seed(InitialCatalogMatCoreSeeder::class);
        app(OaTkpPriceListDraftImportGuard::class)->importDraftOnly([
            'year' => 2026,
            'revision_number' => 1,
            'status' => PriceListStatus::Draft->value,
            'name' => 'Concurrency Same',
            'version' => '2026.1',
            'items' => [[
                'medium_code' => 'pre_stream',
                'inventory_code' => 'inv_more_kombi_online_audio',
                'list_tkp' => '12.0000',
                'minimum_tkp' => '8.0000',
            ]],
        ]);

        $results = $this->runParallelActivate(2026, 1, 2026, 1, archivePrevious: false);
        $ok = array_values(array_filter($results, fn (string $line): bool => str_starts_with($line, 'OK:')));
        $this->assertCount(2, $ok, implode(' || ', $results));
        $this->assertSame(
            1,
            OaTkpPriceList::query()->where('year', 2026)->where('status', PriceListStatus::Active)->count(),
        );
    }

    private function purgeOaPriceLists(): void
    {
        if (Schema::hasTable('oa_tkp_price_list_items')) {
            DB::table('oa_tkp_price_list_items')->delete();
        }
        if (Schema::hasTable('oa_tkp_price_lists')) {
            DB::table('oa_tkp_price_lists')->delete();
        }
    }

    /**
     * @return list<string>
     */
    private function runParallelActivate(
        int $yearA,
        int $revisionA,
        int $yearB,
        int $revisionB,
        bool $archivePrevious,
    ): array {
        $runDir = sys_get_temp_dir().'/oa-tkp-activate-'.Str::uuid();
        if (! mkdir($runDir, 0700, true) && ! is_dir($runDir)) {
            $this->fail('Temporäres Barrier-Verzeichnis konnte nicht erstellt werden.');
        }

        try {
            $script = base_path('tests/concurrency/oa_tkp_activate_worker.php');
            $archive = $archivePrevious ? '1' : '0';
            $env = $this->workerEnvironment();
            $processA = new Process(
                [PHP_BINARY, $script, $runDir, '0', '2', (string) $yearA, (string) $revisionA, $archive],
                null,
                $env,
            );
            $processB = new Process(
                [PHP_BINARY, $script, $runDir, '1', '2', (string) $yearB, (string) $revisionB, $archive],
                null,
                $env,
            );
            $processA->setTimeout(90);
            $processB->setTimeout(90);
            $processA->start();
            $processB->start();

            $deadline = microtime(true) + 20;
            while (microtime(true) < $deadline) {
                $ready = glob($runDir.'/ready-*') ?: [];
                if (count($ready) >= 2) {
                    break;
                }
                usleep(10_000);
            }
            file_put_contents($runDir.'/go', '1');

            $processA->wait();
            $processB->wait();

            $results = [];
            foreach (glob($runDir.'/worker-*.result') ?: [] as $resultFile) {
                $results[] = trim((string) file_get_contents($resultFile));
            }

            $this->assertCount(2, $results, 'Beide Worker müssen ein Resultat schreiben. stderr='
                .$processA->getErrorOutput().' | '.$processB->getErrorOutput());

            return $results;
        } finally {
            foreach (glob($runDir.'/*') ?: [] as $file) {
                if (is_file($file)) {
                    unlink($file);
                }
            }
            if (is_dir($runDir)) {
                rmdir($runDir);
            }
        }
    }

    /**
     * @return array<string, string>
     */
    private function workerEnvironment(): array
    {
        $vars = [
            'APP_KEY', 'APP_ENV', 'DB_CONNECTION', 'DB_HOST', 'DB_PORT',
            'DB_DATABASE', 'DB_USERNAME', 'DB_PASSWORD', 'DB_URL',
        ];

        $env = [];
        foreach ($vars as $var) {
            $value = getenv($var);
            if ($value !== false) {
                $env[$var] = (string) $value;
            }
        }

        $env['APP_ENV'] = 'testing';
        $env['DB_CONNECTION'] = 'mysql';
        $env['DB_DATABASE'] = 'dispo_test';
        $env['DB_HOST'] = $env['DB_HOST'] ?? '127.0.0.1';
        $env['DB_PORT'] = $env['DB_PORT'] ?? '3306';
        $env['DB_USERNAME'] = $env['DB_USERNAME'] ?? 'root';
        $env['DB_PASSWORD'] = $env['DB_PASSWORD'] ?? '';
        $env['DB_URL'] = '';

        if (! isset($env['APP_KEY']) || $env['APP_KEY'] === '') {
            $env['APP_KEY'] = (string) config('app.key');
        }

        return $env;
    }
}
