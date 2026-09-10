<?php

namespace Tests\Feature\Advertising;

use App\Enums\CalculationKind;
use App\Enums\FieldAppliesTo;
use App\Enums\Role;
use App\Exceptions\CatalogAdminConflictException;
use App\Models\AdvertisingCategory;
use App\Models\AdvertisingMedium;
use App\Models\FieldSet;
use App\Models\FieldSetAssignment;
use App\Models\User;
use App\Services\Advertising\Admin\AdvertisingCategoryAdminWriter;
use App\Services\Advertising\Admin\AdvertisingMediumAdminWriter;
use App\Services\Advertising\Admin\CatalogImpactPreviewService;
use App\Support\Advertising\CanonicalAdvertisingCategories;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * ADV-001b C-RACE: echte parallele MySQL-Transaktionen.
 *
 * Orchestrierung nur in tests/: äußere Worker-Transaktionen, explizite
 * Zeilenlocks und Dateibarrieren – keine Test-Hooks unter app/.
 */
class CatalogLifecycleConcurrencyTest extends TestCase
{
    use DatabaseMigrations;

    protected function tearDown(): void
    {
        // ADV-001c3a: Create erzeugt kind=NULL. DatabaseMigrations ruft c2-down auf,
        // das bei Null-kind bewusst fail-closed ist – vor Rollback aufräumen.
        if (Schema::hasTable('advertising_media')) {
            DB::table('advertising_media')->whereNull('kind')->delete();
        }

        parent::tearDown();
    }

    public function test_c_race_01_category_deactivate_versus_medium_create(): void
    {
        $this->requireMysql('C-RACE-01');

        $admin = User::factory()->role(Role::Admin)->create();
        $spots = $this->spotsCategory();
        $this->deactivateAllMediaInCategory($spots->id);

        $code = 'race_create_'.bin2hex(random_bytes(3));
        $preview = app(CatalogImpactPreviewService::class)->previewCategoryDeactivate($spots->fresh());

        $results = $this->runParallelWorkers(
            [
                'action' => 'deactivate_category',
                'payload' => [
                    'actor_id' => $admin->id,
                    'category_id' => $spots->id,
                    'lock_version' => $spots->lock_version,
                    'fingerprint' => $preview['fingerprint'],
                    'orchestration' => [
                        'outer_transaction' => true,
                        'prelock' => ['category_id' => $spots->id],
                        'signal_after_prelock' => 'holder_category_locked',
                        'wait_after_prelock' => ['waiter_entered'],
                    ],
                ],
            ],
            [
                'action' => 'create_medium',
                'payload' => [
                    'actor_id' => $admin->id,
                    'code' => $code,
                    'name' => 'Race Create',
                    'category_id' => $spots->id,
                    'orchestration' => [
                        'wait_before' => ['holder_category_locked'],
                        'signal_before' => 'waiter_entered',
                    ],
                ],
            ],
        );

        $this->assertExclusiveWinner($results, 'deactivate_category');
        $this->assertInvariantNoActiveMediumUnderInactiveCategory();

        $spots->refresh();
        $this->assertFalse($spots->is_active);
        $this->assertNull(AdvertisingMedium::query()->where('code', $code)->first());
    }

    public function test_c_race_01b_create_holds_category_before_deactivate(): void
    {
        $this->requireMysql('C-RACE-01b');

        $admin = User::factory()->role(Role::Admin)->create();
        $spots = $this->spotsCategory();
        $this->deactivateAllMediaInCategory($spots->id);

        $code = 'race_create_b_'.bin2hex(random_bytes(3));
        $preview = app(CatalogImpactPreviewService::class)->previewCategoryDeactivate($spots->fresh());

        $results = $this->runParallelWorkers(
            [
                'action' => 'create_medium',
                'payload' => [
                    'actor_id' => $admin->id,
                    'code' => $code,
                    'name' => 'Race Create Holder',
                    'category_id' => $spots->id,
                    'orchestration' => [
                        'outer_transaction' => true,
                        'prelock' => ['category_id' => $spots->id],
                        'signal_after_prelock' => 'holder_category_locked',
                        'wait_after_prelock' => ['waiter_entered'],
                    ],
                ],
            ],
            [
                'action' => 'deactivate_category',
                'payload' => [
                    'actor_id' => $admin->id,
                    'category_id' => $spots->id,
                    'lock_version' => $spots->lock_version,
                    'fingerprint' => $preview['fingerprint'],
                    'orchestration' => [
                        'wait_before' => ['holder_category_locked'],
                        'signal_before' => 'waiter_entered',
                    ],
                ],
            ],
        );

        $this->assertExclusiveWinner($results, 'create_medium');
        $this->assertInvariantNoActiveMediumUnderInactiveCategory();

        $spots->refresh();
        $created = AdvertisingMedium::query()->where('code', $code)->first();
        $this->assertNotNull($created);
        $this->assertTrue($created->is_active);
        $this->assertTrue($spots->is_active);
    }

    public function test_c_race_02_category_deactivate_versus_medium_reactivate(): void
    {
        $this->requireMysql('C-RACE-02');

        $admin = User::factory()->role(Role::Admin)->create();
        $spots = $this->spotsCategory();
        $this->deactivateAllMediaInCategory($spots->id);

        $medium = AdvertisingMedium::factory()->create([
            'category_id' => $spots->id,
            'code' => 'race_react_'.bin2hex(random_bytes(3)),
            'is_active' => false,
        ]);
        $preview = app(CatalogImpactPreviewService::class)->previewCategoryDeactivate($spots->fresh());

        $results = $this->runParallelWorkers(
            [
                'action' => 'deactivate_category',
                'payload' => [
                    'actor_id' => $admin->id,
                    'category_id' => $spots->id,
                    'lock_version' => $spots->lock_version,
                    'fingerprint' => $preview['fingerprint'],
                    'orchestration' => [
                        'outer_transaction' => true,
                        'prelock' => ['category_id' => $spots->id],
                        'signal_after_prelock' => 'holder_category_locked',
                        'wait_after_prelock' => ['waiter_holds_medium'],
                    ],
                ],
            ],
            [
                'action' => 'reactivate_medium',
                'payload' => [
                    'actor_id' => $admin->id,
                    'medium_id' => $medium->id,
                    'lock_version' => $medium->lock_version,
                    'orchestration' => [
                        'wait_before' => ['holder_category_locked'],
                        'outer_transaction' => true,
                        'prelock' => ['medium_id' => $medium->id],
                        'signal_after_prelock' => 'waiter_holds_medium',
                    ],
                ],
            ],
        );

        $this->assertExclusiveWinner($results, 'deactivate_category');
        $this->assertInvariantNoActiveMediumUnderInactiveCategory();

        $medium->refresh();
        $spots->refresh();
        $this->assertFalse($medium->is_active);
        $this->assertFalse($spots->is_active);
    }

    public function test_c_race_03_target_category_deactivate_versus_category_change(): void
    {
        $this->requireMysql('C-RACE-03');

        $admin = User::factory()->role(Role::Admin)->create();
        $spots = $this->spotsCategory();
        $online = AdvertisingCategory::query()
            ->where('key', CanonicalAdvertisingCategories::ONLINE_AUDIO)
            ->firstOrFail();

        $this->deactivateAllMediaInCategory($spots->id);

        $medium = AdvertisingMedium::factory()->create([
            'category_id' => $spots->id,
            'code' => 'race_change_'.bin2hex(random_bytes(3)),
            'is_active' => true,
        ]);
        DB::table('advertising_media')->where('id', $medium->id)->update([
            'category_id' => $online->id,
        ]);
        $medium->refresh();

        $changePreview = app(CatalogImpactPreviewService::class)->previewMediumCategoryChange($medium, [
            'target_category_id' => $spots->id,
        ]);
        $this->assertTrue($changePreview['can_proceed']);

        $deactivatePreview = app(CatalogImpactPreviewService::class)->previewCategoryDeactivate($spots->fresh());

        $snapshotId = DB::table('configuration_snapshots')->insertGetId([
            'field_set_id' => DB::table('field_sets')->where('key', 'system_calculation_core')->value('id'),
            'field_set_version_id' => DB::table('field_sets')->where('key', 'system_calculation_core')->value('active_version_id'),
            'source' => 'seed_active',
            'format_version' => 3,
            'schema_fingerprint' => str_repeat('c', 64),
            'context_advertising_medium_id' => $medium->id,
            'context_advertising_medium_code' => $medium->code,
            'context_advertising_medium_name' => $medium->name,
            'context_advertising_category_id' => $online->id,
            'context_advertising_category_key' => $online->key,
            'context_advertising_category_name' => $online->name,
            'created_at' => now(),
        ]);

        $results = $this->runParallelWorkers(
            [
                'action' => 'deactivate_category',
                'payload' => [
                    'actor_id' => $admin->id,
                    'category_id' => $spots->id,
                    'lock_version' => $spots->fresh()->lock_version,
                    'fingerprint' => $deactivatePreview['fingerprint'],
                    'orchestration' => [
                        'outer_transaction' => true,
                        'prelock' => ['category_id' => $spots->id],
                        'signal_after_prelock' => 'holder_category_locked',
                        'wait_after_prelock' => ['waiter_holds_medium'],
                    ],
                ],
            ],
            [
                'action' => 'change_category',
                'payload' => [
                    'actor_id' => $admin->id,
                    'medium_id' => $medium->id,
                    'target_category_id' => $spots->id,
                    'lock_version' => $medium->lock_version,
                    'fingerprint' => $changePreview['fingerprint'],
                    'orchestration' => [
                        'wait_before' => ['holder_category_locked'],
                        'outer_transaction' => true,
                        'prelock' => ['medium_id' => $medium->id],
                        'signal_after_prelock' => 'waiter_holds_medium',
                    ],
                ],
            ],
        );

        $this->assertExclusiveWinner($results, 'deactivate_category');
        $this->assertInvariantNoActiveMediumUnderInactiveCategory();

        $medium->refresh();
        $spots->refresh();
        $row = DB::table('configuration_snapshots')->where('id', $snapshotId)->first();
        $this->assertSame($online->id, (int) $row->context_advertising_category_id);
        $this->assertSame($online->key, $row->context_advertising_category_key);
        $this->assertSame($online->name, $row->context_advertising_category_name);
        $this->assertFalse($spots->is_active);
        $this->assertSame($online->id, (int) $medium->category_id);
    }

    public function test_c_race_04_assignment_media_lock_versus_category_deactivate(): void
    {
        $this->requireMysql('C-RACE-04');

        $admin = User::factory()->role(Role::Admin)->create();
        $spots = $this->spotsCategory();
        $this->deactivateAllMediaInCategory($spots->id);

        $lowMedium = AdvertisingMedium::factory()->create([
            'category_id' => $spots->id,
            'code' => 'race_low_'.bin2hex(random_bytes(3)),
            'is_active' => false,
        ]);
        $highMedium = AdvertisingMedium::factory()->create([
            'category_id' => $spots->id,
            'code' => 'race_high_'.bin2hex(random_bytes(3)),
            'is_active' => false,
        ]);
        $this->assertLessThan($highMedium->id, $lowMedium->id);

        $fieldSetId = (int) FieldSet::query()->where('key', 'system_calculation_core')->value('id');
        $this->assertGreaterThan(0, $fieldSetId);
        $assignment = FieldSetAssignment::factory()
            ->forFieldSet(FieldSet::query()->findOrFail($fieldSetId))
            ->forMedium((int) $lowMedium->id)
            ->inactive()
            ->create([
                'applies_to_process' => FieldAppliesTo::Calculation,
                'sort' => 90,
            ]);

        $preview = app(CatalogImpactPreviewService::class)->previewCategoryDeactivate($spots->fresh());

        $results = $this->runParallelWorkers(
            [
                'action' => 'assignment_lock_hold',
                'payload' => [
                    'assignment_id' => $assignment->id,
                    'orchestration' => [
                        'outer_transaction' => true,
                        'prelock' => ['medium_id' => $lowMedium->id],
                        'signal_after_prelock' => 'assignment_holds_medium',
                        'wait_after_prelock' => ['deactivate_holds_category'],
                        'wait_after_action' => ['deactivate_done'],
                    ],
                ],
            ],
            [
                'action' => 'deactivate_category',
                'payload' => [
                    'actor_id' => $admin->id,
                    'category_id' => $spots->id,
                    'lock_version' => $spots->fresh()->lock_version,
                    'fingerprint' => $preview['fingerprint'],
                    'orchestration' => [
                        'wait_before' => ['assignment_holds_medium'],
                        'outer_transaction' => true,
                        'prelock' => ['category_id' => $spots->id],
                        'signal_after_prelock' => 'deactivate_holds_category',
                        'signal_after' => 'deactivate_done',
                        'signal_after_error' => 'deactivate_done',
                    ],
                ],
            ],
        );

        $this->assertCount(2, $results);
        $this->assertNoDeadlockOrLockTimeout($results);
        $this->assertTrue(
            collect($results)->contains(fn (string $line): bool => str_starts_with($line, 'OK:assignment_lock_hold')),
            'Assignment-Pfad muss ohne Deadlock durchkommen: '.implode(' || ', $results),
        );
        $this->assertTrue(
            collect($results)->contains(fn (string $line): bool => str_starts_with($line, 'OK:deactivate_category')),
            'Kategorie-Deaktivierung muss ohne Medienlock durchkommen: '.implode(' || ', $results),
        );
        $this->assertInvariantNoActiveMediumUnderInactiveCategory();

        $spots->refresh();
        $this->assertFalse($spots->is_active);
    }

    public function test_sequential_locks_still_block_inactive_category_create(): void
    {
        $admin = User::factory()->role(Role::Admin)->create();
        $spots = $this->spotsCategory();
        $this->deactivateAllMediaInCategory($spots->id);

        $preview = app(CatalogImpactPreviewService::class)->previewCategoryDeactivate($spots->fresh());
        app(AdvertisingCategoryAdminWriter::class)->deactivate(
            $spots,
            [
                'lock_version' => $spots->fresh()->lock_version,
                'fingerprint' => $preview['fingerprint'],
            ],
            $admin,
        );

        $this->expectException(ValidationException::class);
        app(AdvertisingMediumAdminWriter::class)->create([
            'code' => 'seq_block_'.bin2hex(random_bytes(3)),
            'name' => 'Blocked',
            'category_id' => $spots->id,
        ], $admin);
    }

    public function test_sequential_reactivate_and_change_blocked_on_inactive_category(): void
    {
        $admin = User::factory()->role(Role::Admin)->create();
        $spots = $this->spotsCategory();
        $online = AdvertisingCategory::query()
            ->where('key', CanonicalAdvertisingCategories::ONLINE_AUDIO)
            ->firstOrFail();
        $this->deactivateAllMediaInCategory($spots->id);

        $medium = AdvertisingMedium::factory()->create([
            'category_id' => $spots->id,
            'code' => 'seq_react_'.bin2hex(random_bytes(3)),
            'is_active' => false,
        ]);

        $moving = AdvertisingMedium::factory()->create([
            'category_id' => $spots->id,
            'code' => 'seq_chg_'.bin2hex(random_bytes(3)),
            'is_active' => true,
            'kind' => CalculationKind::SpotClassic,
        ]);
        DB::table('advertising_media')->where('id', $moving->id)->update([
            'category_id' => $online->id,
        ]);
        $moving->refresh();

        $preview = app(CatalogImpactPreviewService::class)->previewCategoryDeactivate($spots->fresh());
        app(AdvertisingCategoryAdminWriter::class)->deactivate(
            $spots,
            [
                'lock_version' => $spots->fresh()->lock_version,
                'fingerprint' => $preview['fingerprint'],
            ],
            $admin,
        );

        try {
            app(AdvertisingMediumAdminWriter::class)->reactivate($medium, [
                'lock_version' => $medium->fresh()->lock_version,
            ], $admin);
            $this->fail('Reactivate muss bei inaktiver Kategorie scheitern.');
        } catch (ValidationException) {
            // expected
        }

        $changePreview = app(CatalogImpactPreviewService::class)->previewMediumCategoryChange($moving->fresh(), [
            'target_category_id' => $spots->id,
        ]);
        $this->assertFalse($changePreview['can_proceed']);

        try {
            app(AdvertisingMediumAdminWriter::class)->changeCategory($moving, [
                'category_id' => $spots->id,
                'lock_version' => $moving->fresh()->lock_version,
                'fingerprint' => $changePreview['fingerprint'],
            ], $admin);
            $this->fail('Change auf inaktive Kategorie muss scheitern.');
        } catch (ValidationException) {
            // expected
        }
    }

    /**
     * @param  array{action: string, payload: array<string, mixed>}  $workerA
     * @param  array{action: string, payload: array<string, mixed>}  $workerB
     * @return list<string>
     */
    private function runParallelWorkers(array $workerA, array $workerB): array
    {
        $runDir = sys_get_temp_dir().'/dispo-catalog-concurrency-'.Str::uuid();
        if (! mkdir($runDir, 0700, true) && ! is_dir($runDir)) {
            $this->fail('Temporäres Barrier-Verzeichnis konnte nicht erstellt werden.');
        }

        try {
            $script = base_path('tests/concurrency/catalog_lifecycle_worker.php');
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
    private function assertExclusiveWinner(array $results, string $expectedOkAction): void
    {
        $this->assertCount(2, $results);
        $this->assertNoDeadlockOrLockTimeout($results);

        $ok = array_values(array_filter($results, fn (string $line): bool => str_starts_with($line, 'OK:')));
        $errors = array_values(array_filter($results, fn (string $line): bool => str_starts_with($line, 'ERROR:')));

        $this->assertCount(1, $ok, 'Genau eine Mutation muss erfolgreich sein: '.implode(' || ', $results));
        $this->assertCount(1, $errors, 'Genau eine Mutation muss kontrolliert scheitern: '.implode(' || ', $results));
        $this->assertTrue(
            str_starts_with($ok[0], 'OK:'.$expectedOkAction),
            "Erwarteter Winner OK:{$expectedOkAction}, got {$ok[0]}",
        );

        $error = $errors[0];
        $this->assertTrue(
            str_contains($error, ValidationException::class)
            || str_contains($error, CatalogAdminConflictException::class),
            'Verlierer muss fachlich kontrolliert scheitern: '.$error,
        );
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

    private function assertInvariantNoActiveMediumUnderInactiveCategory(): void
    {
        $violations = DB::table('advertising_media as m')
            ->join('advertising_categories as c', 'c.id', '=', 'm.category_id')
            ->where('m.is_active', true)
            ->where('c.is_active', false)
            ->count();

        $this->assertSame(0, $violations, 'Invariante verletzt: aktives Medium unter inaktiver Kategorie.');
    }

    private function spotsCategory(): AdvertisingCategory
    {
        return AdvertisingCategory::query()
            ->where('key', CanonicalAdvertisingCategories::SPOTS)
            ->firstOrFail();
    }

    private function deactivateAllMediaInCategory(int $categoryId): void
    {
        AdvertisingMedium::query()
            ->where('category_id', $categoryId)
            ->where('is_active', true)
            ->update(['is_active' => false]);
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
