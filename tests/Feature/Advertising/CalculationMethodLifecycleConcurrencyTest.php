<?php

namespace Tests\Feature\Advertising;

use App\Enums\Role;
use App\Exceptions\CatalogAdminConflictException;
use App\Models\AdvertisingCategory;
use App\Models\AdvertisingCategoryCalculationMethod;
use App\Models\AdvertisingMedium;
use App\Models\CalculationMethod;
use App\Models\User;
use App\Services\Advertising\Admin\CalculationMethodImpactPreviewService;
use App\Support\Advertising\CanonicalAdvertisingCategories;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * ADV-001c3b1: echte parallele MySQL-Transaktionen für Methoden-Lifecycle.
 *
 * Orchestrierung nur in tests/: Worker nutzen Domain-Writer bzw. den
 * dokumentierten Assignment-Activation-Guard – keine rohen Inserts als
 * Sicherheitsbeweis.
 */
class CalculationMethodLifecycleConcurrencyTest extends TestCase
{
    use DatabaseMigrations;

    protected function tearDown(): void
    {
        // c2-down ist fail-closed bei Null-kind und abweichendem Spot-Methodenkatalog.
        // Mutation nur über MysqlTestDatabaseGuard (dispo_test) / DatabaseMigrations.
        try {
            if (DB::connection()->getDriverName() === 'mysql') {
                DB::statement('SET SESSION innodb_lock_wait_timeout = 3');
                if (Schema::hasTable('advertising_media')) {
                    DB::table('advertising_media')
                        ->whereNull('kind')
                        ->update(['kind' => 'spot_classic']);
                }
                if (Schema::hasTable('advertising_category_calculation_methods')
                    && Schema::hasTable('calculation_methods')
                    && Schema::hasTable('advertising_categories')
                ) {
                    $spotsId = DB::table('advertising_categories')->where('key', 'spots')->value('id');
                    $allowedMethodIds = DB::table('calculation_methods')
                        ->whereIn('key', ['average', 'calendar', 'fixed_price'])
                        ->pluck('id')
                        ->all();
                    if ($spotsId !== null && $allowedMethodIds !== []) {
                        DB::table('advertising_category_calculation_methods')
                            ->where('advertising_category_id', $spotsId)
                            ->whereNotIn('calculation_method_id', $allowedMethodIds)
                            ->delete();
                    }
                }
            }
        } catch (\Throwable) {
            // Neutralisierung fehlgeschlagen → c2-down scheitert explizit im parent::tearDown.
        }

        parent::tearDown();
    }

    public function test_c_race_method_update_versus_deactivate(): void
    {
        $this->requireMysql('C-RACE-METHOD-01');

        $admin = User::factory()->role(Role::Admin)->create();
        $method = CalculationMethod::query()->where('key', 'free_position')->firstOrFail();
        $this->assertTrue($method->is_active);

        $preview = app(CalculationMethodImpactPreviewService::class)->previewDeactivate($method);

        $results = $this->runParallelWorkers(
            [
                'action' => 'update_method_metadata',
                'payload' => [
                    'actor_id' => $admin->id,
                    'method_id' => $method->id,
                    'name' => 'Free Position Race',
                    'help_text' => 'race',
                    'sort' => 99,
                    'lock_version' => $method->lock_version,
                    'orchestration' => [
                        'outer_transaction' => true,
                        'prelock' => ['method_id' => $method->id],
                        'signal_after_prelock' => 'holder_method_locked',
                        'wait_after_prelock' => ['waiter_entered'],
                    ],
                ],
            ],
            [
                'action' => 'deactivate_method',
                'payload' => [
                    'actor_id' => $admin->id,
                    'method_id' => $method->id,
                    'lock_version' => $method->lock_version,
                    'fingerprint' => $preview['fingerprint'],
                    'orchestration' => [
                        'wait_before' => ['holder_method_locked'],
                        'signal_before' => 'waiter_entered',
                    ],
                ],
            ],
        );

        $this->assertCount(2, $results);
        $this->assertNoDeadlockOrLockTimeout($results);
        $this->assertValidSerialOutcomes($results, ['update_method_metadata', 'deactivate_method']);

        $method->refresh();
        $this->assertContains($method->lock_version, [2, 3]);
        if ($method->is_active) {
            $this->assertSame('Free Position Race', $method->name);
        } else {
            $this->assertFalse($method->is_active);
        }
    }

    public function test_c_race_deactivate_versus_guarded_assignment_activation(): void
    {
        $this->requireMysql('C-RACE-METHOD-02');

        $admin = User::factory()->role(Role::Admin)->create();
        $method = CalculationMethod::query()->where('key', 'tkp')->firstOrFail();
        $spots = AdvertisingCategory::query()
            ->where('key', CanonicalAdvertisingCategories::SPOTS)
            ->firstOrFail();

        $assignment = AdvertisingCategoryCalculationMethod::factory()->create([
            'advertising_category_id' => $spots->id,
            'calculation_method_id' => $method->id,
            'is_active' => false,
            'engine_profile_key' => null,
            'sort' => 90,
        ]);

        $preview = app(CalculationMethodImpactPreviewService::class)->previewDeactivate($method->fresh());
        $this->assertTrue($preview['can_proceed']);

        $results = $this->runParallelWorkers(
            [
                'action' => 'deactivate_method',
                'payload' => [
                    'actor_id' => $admin->id,
                    'method_id' => $method->id,
                    'lock_version' => $method->lock_version,
                    'fingerprint' => $preview['fingerprint'],
                    'orchestration' => [
                        'outer_transaction' => true,
                        'prelock' => ['method_id' => $method->id],
                        'signal_after_prelock' => 'holder_method_locked',
                        'wait_after_prelock' => ['waiter_entered'],
                    ],
                ],
            ],
            [
                'action' => 'activate_category_assignment_guarded',
                'payload' => [
                    'actor_id' => $admin->id,
                    'assignment_id' => $assignment->id,
                    'method_id' => $method->id,
                    'orchestration' => [
                        'wait_before' => ['holder_method_locked'],
                        'signal_before' => 'waiter_entered',
                    ],
                ],
            ],
        );

        $this->assertCount(2, $results);
        $this->assertNoDeadlockOrLockTimeout($results);
        $this->assertValidSerialOutcomes($results, [
            'deactivate_method',
            'activate_category_assignment_guarded',
        ]);

        $method->refresh();
        $assignment->refresh();

        // Fachlich gültige Endzustände: deaktiviert ohne aktive Zuordnung,
        // oder aktiv mit genau der reaktivierten Zuordnung.
        if (! $method->is_active) {
            $this->assertFalse($assignment->is_active);
        } else {
            $this->assertTrue($assignment->is_active);
            $this->assertTrue($method->is_active);
        }

        $activeCount = AdvertisingCategoryCalculationMethod::query()
            ->where('calculation_method_id', $method->id)
            ->where('is_active', true)
            ->count();
        if (! $method->is_active) {
            $this->assertSame(0, $activeCount);
        }
    }

    public function test_c_race_method_update_versus_medium_update_no_deadlock(): void
    {
        $this->requireMysql('C-RACE-METHOD-03');

        $admin = User::factory()->role(Role::Admin)->create();
        $method = CalculationMethod::query()->where('key', 'calendar')->firstOrFail();
        $spots = AdvertisingCategory::query()
            ->where('key', CanonicalAdvertisingCategories::SPOTS)
            ->firstOrFail();
        $medium = AdvertisingMedium::factory()->create([
            'category_id' => $spots->id,
            'code' => 'race_method_media_'.bin2hex(random_bytes(2)),
            'kind' => null,
            'is_active' => true,
        ]);

        $results = $this->runParallelWorkers(
            [
                'action' => 'update_method_metadata',
                'payload' => [
                    'actor_id' => $admin->id,
                    'method_id' => $method->id,
                    'name' => $method->name.' X',
                    'help_text' => $method->help_text,
                    'sort' => $method->sort,
                    'lock_version' => $method->lock_version,
                ],
            ],
            [
                'action' => 'update_medium_metadata',
                'payload' => [
                    'actor_id' => $admin->id,
                    'medium_id' => $medium->id,
                    'name' => $medium->name.' Y',
                    'lock_version' => $medium->lock_version,
                ],
            ],
        );

        $this->assertCount(2, $results);
        $this->assertNoDeadlockOrLockTimeout($results);
        $ok = array_values(array_filter($results, fn (string $line): bool => str_starts_with($line, 'OK:')));
        $this->assertCount(2, $ok, 'Beide unabhängigen Updates müssen erfolgreich sein: '.implode(' || ', $results));
    }

    /**
     * @param  array{action: string, payload: array<string, mixed>}  ...$workers
     * @return list<string>
     */
    private function runParallelWorkers(array ...$workers): array
    {
        $runDir = sys_get_temp_dir().'/c3b1-race-'.bin2hex(random_bytes(8));
        mkdir($runDir, 0700, true);

        $script = base_path('tests/concurrency/catalog_lifecycle_worker.php');
        $processes = [];
        foreach (array_values($workers) as $index => $worker) {
            $processes[] = $this->startWorker($runDir, $index, $script, $worker);
        }

        try {
            foreach ($processes as $process) {
                $process->start();
            }
            foreach ($processes as $process) {
                $process->wait();
            }

            $results = [];
            foreach (array_keys($workers) as $index) {
                $file = $runDir.'/worker-'.$index.'.result';
                $this->assertFileExists($file);
                $results[] = trim((string) file_get_contents($file));
            }

            return $results;
        } finally {
            foreach ($processes as $process) {
                if ($process->isRunning()) {
                    $process->stop(0);
                }
            }
            foreach (glob($runDir.'/*') ?: [] as $file) {
                @unlink($file);
            }
            @rmdir($runDir);
        }
    }

    /**
     * @param  array{action: string, payload: array<string, mixed>}  $worker
     */
    private function startWorker(string $runDir, int $workerId, string $script, array $worker): Process
    {
        $baseEnv = $this->workerEnvironment();

        return new Process(
            [
                PHP_BINARY,
                $script,
                $runDir,
                (string) $workerId,
                $worker['action'],
                json_encode($worker['payload'], JSON_THROW_ON_ERROR),
            ],
            null,
            $baseEnv,
        );
    }

    /**
     * @param  list<string>  $results
     * @param  list<string>  $allowedActions
     */
    private function assertValidSerialOutcomes(array $results, array $allowedActions): void
    {
        $ok = array_values(array_filter($results, fn (string $line): bool => str_starts_with($line, 'OK:')));
        $errors = array_values(array_filter($results, fn (string $line): bool => str_starts_with($line, 'ERROR:')));

        $this->assertGreaterThanOrEqual(1, count($ok), 'Mindestens ein OK erwartet: '.implode(' || ', $results));
        $this->assertSame(2, count($ok) + count($errors), 'Nur OK/ERROR: '.implode(' || ', $results));

        foreach ($ok as $line) {
            $matched = false;
            foreach ($allowedActions as $action) {
                if (str_starts_with($line, 'OK:'.$action)) {
                    $matched = true;
                    break;
                }
            }
            $this->assertTrue($matched, 'Unerlaubter OK-Action: '.$line);
        }

        foreach ($errors as $error) {
            $this->assertTrue(
                str_contains($error, ValidationException::class)
                || str_contains($error, CatalogAdminConflictException::class),
                'Verlierer muss fachlich kontrolliert scheitern: '.$error,
            );
        }
    }

    /**
     * @param  list<string>  $results
     */
    private function assertNoDeadlockOrLockTimeout(array $results): void
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
                $env[$var] = $value;
            }
        }

        return $env;
    }
}
