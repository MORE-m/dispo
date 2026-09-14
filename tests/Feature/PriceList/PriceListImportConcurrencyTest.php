<?php

namespace Tests\Feature\PriceList;

use App\Enums\PriceListImportStatus;
use App\Enums\Role;
use App\Models\AuditEvent;
use App\Models\PriceList;
use App\Models\PriceListImport;
use App\Models\User;
use App\Support\PriceList\PriceListCalendar;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use Tests\Concerns\CreatesSpotClassicCatalog;
use Tests\Support\PriceListImportWorkbookFactory;
use Tests\TestCase;

/**
 * Sequenzielle Idempotenz + echte MySQL-Nebenläufigkeit für Confirm.
 */
class PriceListImportConcurrencyTest extends TestCase
{
    use CreatesSpotClassicCatalog;
    use DatabaseMigrations;

    protected function tearDown(): void
    {
        if (Schema::hasTable('price_list_items')) {
            DB::table('price_list_items')->delete();
        }
        if (Schema::hasTable('price_lists')) {
            DB::table('price_lists')->delete();
        }
        if (Schema::hasTable('price_list_imports')) {
            DB::table('price_list_imports')->delete();
        }

        parent::tearDown();
    }

    public function test_second_confirm_does_not_create_duplicate_drafts(): void
    {
        $this->createSpotClassicCatalog();
        $admin = User::factory()->role(Role::Admin)->create();
        $year = PriceListCalendar::currentYear();
        $path = tempnam(sys_get_temp_dir(), 'pli').'.xlsx';
        PriceListImportWorkbookFactory::canonicalFlat($path, [
            ['RH', 10, 'mo_fr', '1.1000'],
            ['RAH', 11, 'so', '0.9000'],
        ]);

        $preview = $this->actingAs($admin)->post(route('administration.price-lists.import.upload'), [
            'year' => $year,
            'file' => new UploadedFile($path, 'c.xlsx', null, null, true),
        ])->assertOk()->json();

        $this->actingAs($admin)->postJson($preview['urls']['confirm'], [
            'fingerprint' => $preview['preview']['fingerprint'],
        ])->assertOk();

        $count = PriceList::query()->where('name', 'like', 'Import %')->count();
        $this->assertSame(2, $count);

        $this->actingAs($admin)->postJson($preview['urls']['confirm'], [
            'fingerprint' => $preview['preview']['fingerprint'],
        ])->assertStatus(422);

        $this->assertSame($count, PriceList::query()->where('name', 'like', 'Import %')->count());
    }

    public function test_parallel_confirm_creates_single_draft_set(): void
    {
        $this->requireMysql('BL-P4-01b Parallel-Confirm');
        $this->createSpotClassicCatalog();
        $admin = User::factory()->role(Role::Admin)->create();
        $year = PriceListCalendar::currentYear();
        $path = tempnam(sys_get_temp_dir(), 'pli').'.xlsx';
        PriceListImportWorkbookFactory::canonicalFlat($path, [
            ['RH', 10, 'mo_fr', '1.1000'],
            ['RAH', 11, 'so', '0.9000'],
        ]);

        $preview = $this->actingAs($admin)->post(route('administration.price-lists.import.upload'), [
            'year' => $year,
            'file' => new UploadedFile($path, 'parallel.xlsx', null, null, true),
        ])->assertOk()->json();

        $importId = (int) $preview['import']['id'];
        $fingerprint = (string) $preview['preview']['fingerprint'];

        $results = $this->runParallelWorkers(
            [
                'action' => 'confirm_price_list_import',
                'payload' => [
                    'actor_id' => $admin->id,
                    'import_id' => $importId,
                    'fingerprint' => $fingerprint,
                ],
            ],
            [
                'action' => 'confirm_price_list_import',
                'payload' => [
                    'actor_id' => $admin->id,
                    'import_id' => $importId,
                    'fingerprint' => $fingerprint,
                ],
            ],
        );

        $this->assertNoDeadlockOrServerError($results);

        $ok = array_values(array_filter($results, fn (string $line): bool => str_starts_with($line, 'OK:')));
        $err = array_values(array_filter($results, fn (string $line): bool => str_starts_with($line, 'ERROR:')));
        $this->assertCount(1, $ok, 'Genau ein Confirm muss gewinnen. Results='.implode(' || ', $results));
        $this->assertCount(1, $err, 'Der zweite Confirm muss kontrolliert scheitern. Results='.implode(' || ', $results));
        $this->assertTrue(
            str_contains($err[0], 'ValidationException')
            || str_contains($err[0], 'PriceListAdminConflictException')
            || str_contains($err[0], 'bereits übernommen')
            || str_contains($err[0], 'veraltet'),
            'Zweiter Confirm muss Conflict/Validation sein: '.$err[0],
        );

        $import = PriceListImport::query()->findOrFail($importId);
        $this->assertSame(PriceListImportStatus::Imported, $import->status);
        $this->assertSame(2, PriceList::query()->where('name', 'like', 'Import %')->count());
        $this->assertSame(1, AuditEvent::query()->where('action', 'price_list_import.confirmed')->count());
        $this->assertCount(2, $import->created_price_list_ids ?? []);
    }

    /**
     * @param  array{action: string, payload: array<string, mixed>}  $workerA
     * @param  array{action: string, payload: array<string, mixed>}  $workerB
     * @return list<string>
     */
    private function runParallelWorkers(array $workerA, array $workerB): array
    {
        $runDir = sys_get_temp_dir().'/dispo-pli-concurrency-'.Str::uuid();
        if (! mkdir($runDir, 0700, true) && ! is_dir($runDir)) {
            $this->fail('Temporäres Barrier-Verzeichnis konnte nicht erstellt werden.');
        }

        try {
            $script = base_path('tests/concurrency/price_list_admin_worker.php');
            $baseEnv = $this->workerEnvironment();
            $processA = $this->makeWorkerProcess($script, $runDir, '0', $workerA, $baseEnv);
            $processB = $this->makeWorkerProcess($script, $runDir, '1', $workerB, $baseEnv);
            $processA->setTimeout(60);
            $processB->setTimeout(60);
            $processA->start();
            $processB->start();
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
     * @param  array{action: string, payload: array<string, mixed>}  $worker
     * @param  array<string, string>  $baseEnv
     */
    private function makeWorkerProcess(
        string $script,
        string $runDir,
        string $workerId,
        array $worker,
        array $baseEnv,
    ): Process {
        return new Process(
            [
                PHP_BINARY,
                $script,
                $runDir,
                $workerId,
                $worker['action'],
                json_encode($worker['payload'], JSON_THROW_ON_ERROR),
            ],
            null,
            $baseEnv,
        );
    }

    /**
     * @param  list<string>  $results
     */
    private function assertNoDeadlockOrServerError(array $results): void
    {
        foreach ($results as $line) {
            $this->assertTrue(
                str_starts_with($line, 'OK:') || str_starts_with($line, 'ERROR:'),
                'Unerwartetes Worker-Resultat: '.$line,
            );
            foreach (['Deadlock', '1213', '1205', 'Lock wait timeout', 'lock wait timeout'] as $needle) {
                $this->assertStringNotContainsString($needle, $line);
            }
        }
    }

    private function requireMysql(string $label): void
    {
        if (DB::connection()->getDriverName() !== 'mysql') {
            $this->markTestSkipped($label.' erfordert MySQL (GitHub-Job mysql).');
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

        return $env;
    }
}
