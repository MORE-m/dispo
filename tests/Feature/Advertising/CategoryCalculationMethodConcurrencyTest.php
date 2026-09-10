<?php

namespace Tests\Feature\Advertising;

use App\Enums\CalculationKind;
use App\Enums\CalculationMethodMode;
use App\Enums\Role;
use App\Exceptions\CatalogAdminConflictException;
use App\Models\AdvertisingCategory;
use App\Models\AdvertisingCategoryCalculationMethod;
use App\Models\AdvertisingMedium;
use App\Models\CalculationMethod;
use App\Models\User;
use App\Services\Advertising\Admin\AdvertisingCategoryCalculationMethodImpactPreviewService;
use App\Services\Advertising\Admin\CalculationMethodImpactPreviewService;
use App\Services\Advertising\Admin\CatalogImpactPreviewService;
use App\Support\Advertising\CanonicalAdvertisingCategories;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * ADV-001c3b2: echte parallele MySQL-Transaktionen für Kategorie-Methoden-Desired-State.
 */
class CategoryCalculationMethodConcurrencyTest extends TestCase
{
    use DatabaseMigrations;

    protected function tearDown(): void
    {
        // c2-down ist fail-closed bei Null-kind und abweichendem Spot-Methodenkatalog.
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

    public function test_c_race_two_parallel_applies_same_category(): void
    {
        $this->requireMysql('C-RACE-CAT-METHODS-01');

        $admin = User::factory()->role(Role::Admin)->create();
        $spots = $this->spots();
        $tkp = CalculationMethod::query()->where('key', 'tkp')->firstOrFail();
        $calendar = CalculationMethod::query()->where('key', 'calendar')->firstOrFail();

        $base = $this->buildDesiredFromCurrent($spots);
        $desiredA = $base;
        $desiredA['assignments'][] = [
            'calculation_method_id' => (int) $tkp->id,
            'is_active' => true,
            'sort' => 90,
        ];
        $desiredB = $base;
        foreach ($desiredB['assignments'] as &$row) {
            if ((int) $row['calculation_method_id'] === (int) $calendar->id) {
                $row['sort'] = (int) $row['sort'] + 3;
            }
        }
        unset($row);

        $previewA = app(AdvertisingCategoryCalculationMethodImpactPreviewService::class)
            ->preview($spots->fresh(), $desiredA);
        $previewB = app(AdvertisingCategoryCalculationMethodImpactPreviewService::class)
            ->preview($spots->fresh(), $desiredB);

        $results = $this->runParallelWorkers(
            [
                'action' => 'replace_category_methods',
                'payload' => [
                    'actor_id' => $admin->id,
                    'category_id' => $spots->id,
                    'lock_version' => $desiredA['lock_version'],
                    'fingerprint' => $previewA['fingerprint'],
                    'default_calculation_method_id' => $desiredA['default_calculation_method_id'],
                    'assignments' => $desiredA['assignments'],
                    'orchestration' => [
                        'outer_transaction' => true,
                        'prelock' => ['category_id' => $spots->id],
                        'signal_after_prelock' => 'holder_category_locked',
                        'wait_after_prelock' => ['waiter_entered'],
                    ],
                ],
            ],
            [
                'action' => 'replace_category_methods',
                'payload' => [
                    'actor_id' => $admin->id,
                    'category_id' => $spots->id,
                    'lock_version' => $desiredB['lock_version'],
                    'fingerprint' => $previewB['fingerprint'],
                    'default_calculation_method_id' => $desiredB['default_calculation_method_id'],
                    'assignments' => $desiredB['assignments'],
                    'orchestration' => [
                        'wait_before' => ['holder_category_locked'],
                        'signal_before' => 'waiter_entered',
                    ],
                ],
            ],
        );

        $this->assertCount(2, $results);
        $this->assertNoDeadlockOrLockTimeout($results);
        $this->assertValidSerialOutcomes($results, ['replace_category_methods']);
    }

    public function test_c_race_apply_versus_method_deactivate(): void
    {
        $this->requireMysql('C-RACE-CAT-METHODS-02');

        $admin = User::factory()->role(Role::Admin)->create();
        $spots = $this->spots();
        $tkp = CalculationMethod::query()->where('key', 'tkp')->firstOrFail();
        $this->assertTrue($tkp->is_active);

        $desired = $this->buildDesiredFromCurrent($spots);
        $desired['assignments'][] = [
            'calculation_method_id' => (int) $tkp->id,
            'is_active' => true,
            'sort' => 91,
        ];
        $previewApply = app(AdvertisingCategoryCalculationMethodImpactPreviewService::class)
            ->preview($spots->fresh(), $desired);
        $previewDeactivate = app(CalculationMethodImpactPreviewService::class)
            ->previewDeactivate($tkp->fresh());
        $this->assertTrue($previewDeactivate['can_proceed']);

        $results = $this->runParallelWorkers(
            [
                'action' => 'replace_category_methods',
                'payload' => [
                    'actor_id' => $admin->id,
                    'category_id' => $spots->id,
                    'lock_version' => $desired['lock_version'],
                    'fingerprint' => $previewApply['fingerprint'],
                    'default_calculation_method_id' => $desired['default_calculation_method_id'],
                    'assignments' => $desired['assignments'],
                    'orchestration' => [
                        'outer_transaction' => true,
                        'prelock' => ['category_id' => $spots->id],
                        'signal_after_prelock' => 'holder_category_locked',
                        'wait_after_prelock' => ['waiter_entered'],
                    ],
                ],
            ],
            [
                'action' => 'deactivate_method',
                'payload' => [
                    'actor_id' => $admin->id,
                    'method_id' => $tkp->id,
                    'lock_version' => $tkp->lock_version,
                    'fingerprint' => $previewDeactivate['fingerprint'],
                    'orchestration' => [
                        'wait_before' => ['holder_category_locked'],
                        'signal_before' => 'waiter_entered',
                    ],
                ],
            ],
        );

        $this->assertCount(2, $results);
        $this->assertNoDeadlockOrLockTimeout($results);
        $this->assertValidSerialOutcomes($results, ['replace_category_methods', 'deactivate_method']);

        $tkp->refresh();
        $activeTkp = AdvertisingCategoryCalculationMethod::query()
            ->where('advertising_category_id', $spots->id)
            ->where('calculation_method_id', $tkp->id)
            ->where('is_active', true)
            ->exists();
        if (! $tkp->is_active) {
            $this->assertFalse($activeTkp);
        }
    }

    public function test_c_race_apply_versus_method_metadata_update(): void
    {
        $this->requireMysql('C-RACE-CAT-METHODS-03');

        $admin = User::factory()->role(Role::Admin)->create();
        $spots = $this->spots();
        $calendar = CalculationMethod::query()->where('key', 'calendar')->firstOrFail();
        $tkp = CalculationMethod::query()->where('key', 'tkp')->firstOrFail();

        $desired = $this->buildDesiredFromCurrent($spots);
        $desired['assignments'][] = [
            'calculation_method_id' => (int) $tkp->id,
            'is_active' => true,
            'sort' => 92,
        ];
        $preview = app(AdvertisingCategoryCalculationMethodImpactPreviewService::class)
            ->preview($spots->fresh(), $desired);

        $results = $this->runParallelWorkers(
            [
                'action' => 'replace_category_methods',
                'payload' => [
                    'actor_id' => $admin->id,
                    'category_id' => $spots->id,
                    'lock_version' => $desired['lock_version'],
                    'fingerprint' => $preview['fingerprint'],
                    'default_calculation_method_id' => $desired['default_calculation_method_id'],
                    'assignments' => $desired['assignments'],
                    'orchestration' => [
                        'outer_transaction' => true,
                        'prelock' => ['category_id' => $spots->id],
                        'signal_after_prelock' => 'holder_category_locked',
                        'wait_after_prelock' => ['waiter_entered'],
                    ],
                ],
            ],
            [
                'action' => 'update_method_metadata',
                'payload' => [
                    'actor_id' => $admin->id,
                    'method_id' => $calendar->id,
                    'name' => $calendar->name.' Race',
                    'help_text' => $calendar->help_text,
                    'sort' => $calendar->sort,
                    'lock_version' => $calendar->lock_version,
                    'orchestration' => [
                        'wait_before' => ['holder_category_locked'],
                        'signal_before' => 'waiter_entered',
                    ],
                ],
            ],
        );

        $this->assertCount(2, $results);
        $this->assertNoDeadlockOrLockTimeout($results);
        $this->assertValidSerialOutcomes($results, [
            'replace_category_methods',
            'update_method_metadata',
        ]);
    }

    public function test_c_race_apply_versus_category_metadata_update(): void
    {
        $this->requireMysql('C-RACE-CAT-METHODS-04');

        $admin = User::factory()->role(Role::Admin)->create();
        $spots = $this->spots();
        $tkp = CalculationMethod::query()->where('key', 'tkp')->firstOrFail();

        $desired = $this->buildDesiredFromCurrent($spots);
        $desired['assignments'][] = [
            'calculation_method_id' => (int) $tkp->id,
            'is_active' => true,
            'sort' => 93,
        ];
        $preview = app(AdvertisingCategoryCalculationMethodImpactPreviewService::class)
            ->preview($spots->fresh(), $desired);

        $results = $this->runParallelWorkers(
            [
                'action' => 'replace_category_methods',
                'payload' => [
                    'actor_id' => $admin->id,
                    'category_id' => $spots->id,
                    'lock_version' => $desired['lock_version'],
                    'fingerprint' => $preview['fingerprint'],
                    'default_calculation_method_id' => $desired['default_calculation_method_id'],
                    'assignments' => $desired['assignments'],
                    'orchestration' => [
                        'outer_transaction' => true,
                        'prelock' => ['category_id' => $spots->id],
                        'signal_after_prelock' => 'holder_category_locked',
                        'wait_after_prelock' => ['waiter_entered'],
                    ],
                ],
            ],
            [
                'action' => 'update_category_metadata',
                'payload' => [
                    'actor_id' => $admin->id,
                    'category_id' => $spots->id,
                    'name' => $spots->name.' Race',
                    'sort' => $spots->sort,
                    'lock_version' => $spots->lock_version,
                    'orchestration' => [
                        'wait_before' => ['holder_category_locked'],
                        'signal_before' => 'waiter_entered',
                    ],
                ],
            ],
        );

        $this->assertCount(2, $results);
        $this->assertNoDeadlockOrLockTimeout($results);
        $this->assertValidSerialOutcomes($results, [
            'replace_category_methods',
            'update_category_metadata',
        ]);
    }

    public function test_c_race_apply_versus_category_deactivate(): void
    {
        $this->requireMysql('C-RACE-CAT-METHODS-05');

        $admin = User::factory()->role(Role::Admin)->create();
        $spots = $this->spots();
        AdvertisingMedium::query()->where('category_id', $spots->id)->update(['is_active' => false]);

        $tkp = CalculationMethod::query()->where('key', 'tkp')->firstOrFail();
        $desired = $this->buildDesiredFromCurrent($spots->fresh());
        $desired['assignments'][] = [
            'calculation_method_id' => (int) $tkp->id,
            'is_active' => true,
            'sort' => 94,
        ];
        $previewApply = app(AdvertisingCategoryCalculationMethodImpactPreviewService::class)
            ->preview($spots->fresh(), $desired);
        $previewDeactivate = app(CatalogImpactPreviewService::class)
            ->previewCategoryDeactivate($spots->fresh());
        $this->assertTrue($previewDeactivate['can_proceed']);

        $results = $this->runParallelWorkers(
            [
                'action' => 'replace_category_methods',
                'payload' => [
                    'actor_id' => $admin->id,
                    'category_id' => $spots->id,
                    'lock_version' => $desired['lock_version'],
                    'fingerprint' => $previewApply['fingerprint'],
                    'default_calculation_method_id' => $desired['default_calculation_method_id'],
                    'assignments' => $desired['assignments'],
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
                    'lock_version' => $spots->fresh()->lock_version,
                    'fingerprint' => $previewDeactivate['fingerprint'],
                    'orchestration' => [
                        'wait_before' => ['holder_category_locked'],
                        'signal_before' => 'waiter_entered',
                    ],
                ],
            ],
        );

        $this->assertCount(2, $results);
        $this->assertNoDeadlockOrLockTimeout($results);
        $this->assertValidSerialOutcomes($results, [
            'replace_category_methods',
            'deactivate_category',
        ]);
    }

    public function test_c_race_apply_versus_medium_category_change(): void
    {
        $this->requireMysql('C-RACE-CAT-METHODS-06');

        $admin = User::factory()->role(Role::Admin)->create();
        $spots = $this->spots();
        $online = AdvertisingCategory::query()
            ->where('key', CanonicalAdvertisingCategories::ONLINE_AUDIO)
            ->firstOrFail();

        $medium = AdvertisingMedium::factory()->create([
            'category_id' => $online->id,
            'code' => 'race_cat_methods_chg_'.bin2hex(random_bytes(2)),
            'kind' => null,
            'is_active' => true,
        ]);
        $changePreview = app(CatalogImpactPreviewService::class)->previewMediumCategoryChange($medium, [
            'target_category_id' => $spots->id,
        ]);
        $this->assertTrue($changePreview['can_proceed']);

        $tkp = CalculationMethod::query()->where('key', 'tkp')->firstOrFail();
        $desired = $this->buildDesiredFromCurrent($spots);
        $desired['assignments'][] = [
            'calculation_method_id' => (int) $tkp->id,
            'is_active' => true,
            'sort' => 95,
        ];
        $previewApply = app(AdvertisingCategoryCalculationMethodImpactPreviewService::class)
            ->preview($spots->fresh(), $desired);

        $results = $this->runParallelWorkers(
            [
                'action' => 'replace_category_methods',
                'payload' => [
                    'actor_id' => $admin->id,
                    'category_id' => $spots->id,
                    'lock_version' => $desired['lock_version'],
                    'fingerprint' => $previewApply['fingerprint'],
                    'default_calculation_method_id' => $desired['default_calculation_method_id'],
                    'assignments' => $desired['assignments'],
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

        $this->assertCount(2, $results);
        $this->assertNoDeadlockOrLockTimeout($results);
        $this->assertValidSerialOutcomes($results, [
            'replace_category_methods',
            'change_category',
        ]);
    }

    public function test_c_race_apply_versus_medium_reactivate(): void
    {
        $this->requireMysql('C-RACE-CAT-METHODS-07');

        $admin = User::factory()->role(Role::Admin)->create();
        $spots = $this->spots();
        $medium = AdvertisingMedium::factory()->create([
            'category_id' => $spots->id,
            'code' => 'race_cat_methods_react_'.bin2hex(random_bytes(2)),
            'kind' => CalculationKind::SpotClassic,
            'calculation_method_mode' => CalculationMethodMode::Inherit,
            'is_active' => false,
        ]);

        $tkp = CalculationMethod::query()->where('key', 'tkp')->firstOrFail();
        $desired = $this->buildDesiredFromCurrent($spots);
        $desired['assignments'][] = [
            'calculation_method_id' => (int) $tkp->id,
            'is_active' => true,
            'sort' => 96,
        ];
        $previewApply = app(AdvertisingCategoryCalculationMethodImpactPreviewService::class)
            ->preview($spots->fresh(), $desired);

        $results = $this->runParallelWorkers(
            [
                'action' => 'replace_category_methods',
                'payload' => [
                    'actor_id' => $admin->id,
                    'category_id' => $spots->id,
                    'lock_version' => $desired['lock_version'],
                    'fingerprint' => $previewApply['fingerprint'],
                    'default_calculation_method_id' => $desired['default_calculation_method_id'],
                    'assignments' => $desired['assignments'],
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

        $this->assertCount(2, $results);
        $this->assertNoDeadlockOrLockTimeout($results);
        $this->assertValidSerialOutcomes($results, [
            'replace_category_methods',
            'reactivate_medium',
        ]);
    }

    public function test_c_race_apply_parallel_medium_deactivate(): void
    {
        $this->requireMysql('C-RACE-CAT-METHODS-08');

        $admin = User::factory()->role(Role::Admin)->create();
        $spots = $this->spots();
        $medium = AdvertisingMedium::factory()->create([
            'category_id' => $spots->id,
            'code' => 'race_cat_methods_deact_'.bin2hex(random_bytes(2)),
            'kind' => CalculationKind::SpotClassic,
            'calculation_method_mode' => CalculationMethodMode::Inherit,
            'is_active' => true,
        ]);
        $deactivatePreview = app(CatalogImpactPreviewService::class)
            ->previewMediumDeactivate($medium->fresh());

        $tkp = CalculationMethod::query()->where('key', 'tkp')->firstOrFail();
        $desired = $this->buildDesiredFromCurrent($spots);
        $desired['assignments'][] = [
            'calculation_method_id' => (int) $tkp->id,
            'is_active' => true,
            'sort' => 97,
        ];
        $previewApply = app(AdvertisingCategoryCalculationMethodImpactPreviewService::class)
            ->preview($spots->fresh(), $desired);

        $results = $this->runParallelWorkers(
            [
                'action' => 'preview_and_replace_category_methods',
                'payload' => [
                    'actor_id' => $admin->id,
                    'category_id' => $spots->id,
                    'lock_version' => $desired['lock_version'],
                    'default_calculation_method_id' => $desired['default_calculation_method_id'],
                    'assignments' => $desired['assignments'],
                    'orchestration' => [
                        'outer_transaction' => true,
                        'prelock' => ['category_id' => $spots->id],
                        'signal_after_prelock' => 'holder_category_locked',
                        'wait_after_prelock' => ['waiter_holds_medium'],
                    ],
                ],
            ],
            [
                'action' => 'deactivate_medium',
                'payload' => [
                    'actor_id' => $admin->id,
                    'medium_id' => $medium->id,
                    'lock_version' => $medium->lock_version,
                    'fingerprint' => $deactivatePreview['fingerprint'],
                    'orchestration' => [
                        'wait_before' => ['holder_category_locked'],
                        'outer_transaction' => true,
                        'prelock' => ['medium_id' => $medium->id],
                        'signal_after_prelock' => 'waiter_holds_medium',
                    ],
                ],
            ],
        );

        $this->assertCount(2, $results);
        $this->assertNoDeadlockOrLockTimeout($results);
        $this->assertValidSerialOutcomes($results, [
            'preview_and_replace_category_methods',
            'deactivate_medium',
        ]);
    }

    /**
     * @return array{
     *     lock_version: int,
     *     default_calculation_method_id: int|null,
     *     assignments: list<array{calculation_method_id: int, is_active: bool, sort: int}>
     * }
     */
    private function buildDesiredFromCurrent(AdvertisingCategory $category): array
    {
        $assignments = AdvertisingCategoryCalculationMethod::query()
            ->where('advertising_category_id', $category->id)
            ->orderBy('id')
            ->get()
            ->map(static fn (AdvertisingCategoryCalculationMethod $row): array => [
                'calculation_method_id' => (int) $row->calculation_method_id,
                'is_active' => (bool) $row->is_active,
                'sort' => (int) $row->sort,
            ])
            ->values()
            ->all();

        return [
            'lock_version' => (int) $category->lock_version,
            'default_calculation_method_id' => $category->default_calculation_method_id !== null
                ? (int) $category->default_calculation_method_id
                : null,
            'assignments' => $assignments,
        ];
    }

    private function spots(): AdvertisingCategory
    {
        return AdvertisingCategory::query()
            ->where('key', CanonicalAdvertisingCategories::SPOTS)
            ->firstOrFail();
    }

    /**
     * @param  array{action: string, payload: array<string, mixed>}  ...$workers
     * @return list<string>
     */
    private function runParallelWorkers(array ...$workers): array
    {
        $runDir = sys_get_temp_dir().'/c3b2-race-'.bin2hex(random_bytes(8));
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
            $this->workerEnvironment(),
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
