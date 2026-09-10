<?php

namespace Tests\Feature\Advertising;

use App\Enums\CalculationKind;
use App\Enums\CalculationMethodMode;
use App\Enums\Role;
use App\Exceptions\CatalogAdminConflictException;
use App\Models\AdvertisingCategory;
use App\Models\AdvertisingCategoryCalculationMethod;
use App\Models\AdvertisingMedium;
use App\Models\AdvertisingMediumCalculationMethod;
use App\Models\CalculationMethod;
use App\Models\User;
use App\Services\Advertising\Admin\AdvertisingCategoryCalculationMethodImpactPreviewService;
use App\Services\Advertising\Admin\AdvertisingMediumCalculationMethodImpactPreviewService;
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
 * ADV-001c3c: echte parallele MySQL-Transaktionen für Medium-Methoden-Desired-State.
 */
class MediumCalculationMethodConcurrencyTest extends TestCase
{
    use DatabaseMigrations;

    protected function tearDown(): void
    {
        // c2-down ist fail-closed bei Medium-Assignments, Null-kind und abweichendem Spot-Methodenkatalog.
        try {
            if (DB::connection()->getDriverName() === 'mysql') {
                DB::statement('SET SESSION innodb_lock_wait_timeout = 3');
                if (Schema::hasTable('advertising_medium_calculation_methods')) {
                    DB::table('advertising_medium_calculation_methods')->delete();
                }
                if (Schema::hasTable('advertising_media')) {
                    DB::table('advertising_media')->update([
                        'calculation_method_mode' => 'inherit',
                        'default_calculation_method_id' => null,
                    ]);
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

    public function test_c_race_two_parallel_applies_same_medium(): void
    {
        $this->requireMysql('C-RACE-MED-METHODS-01');

        $admin = User::factory()->role(Role::Admin)->create();
        $medium = $this->mediumWithOverrideSetup(
            $this->spotMedium('race_med_methods_same_'.bin2hex(random_bytes(2))),
        );
        $tkp = CalculationMethod::query()->where('key', 'tkp')->firstOrFail();
        $calendar = CalculationMethod::query()->where('key', 'calendar')->firstOrFail();

        $base = $this->buildDesiredFromCurrent($medium);
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

        $previewA = $this->previewPayload($medium, $desiredA);
        $previewB = $this->previewPayload($medium->fresh(), $desiredB);

        $results = $this->runParallelWorkers(
            $this->mediumApplyWorker($admin, $medium, $desiredA, $previewA, [
                'outer_transaction' => true,
                'prelock' => ['medium_id' => $medium->id],
                'signal_after_prelock' => 'holder_medium_locked',
                'wait_after_prelock' => ['waiter_entered'],
            ]),
            $this->mediumApplyWorker($admin, $medium, $desiredB, $previewB, [
                'wait_before' => ['holder_medium_locked'],
                'signal_before' => 'waiter_entered',
            ]),
        );

        $this->assertCount(2, $results);
        $this->assertNoDeadlockOrLockTimeout($results);
        $this->assertValidSerialOutcomes($results, ['replace_medium_methods']);
    }

    public function test_c_race_two_media_same_category_serialize_at_category_lock(): void
    {
        $this->requireMysql('C-RACE-MED-METHODS-02');

        $admin = User::factory()->role(Role::Admin)->create();
        $suffix = bin2hex(random_bytes(2));
        $mediumA = $this->mediumWithOverrideSetup(
            $this->spotMedium('race_med_methods_a_'.$suffix),
        );
        $mediumB = $this->mediumWithOverrideSetup(
            $this->spotMedium('race_med_methods_b_'.$suffix),
        );
        $tkp = CalculationMethod::query()->where('key', 'tkp')->firstOrFail();

        $desiredA = $this->buildDesiredFromCurrent($mediumA);
        $desiredA['assignments'][] = [
            'calculation_method_id' => (int) $tkp->id,
            'is_active' => true,
            'sort' => 91,
        ];
        $desiredB = $this->buildDesiredFromCurrent($mediumB);
        $desiredB['assignments'][] = [
            'calculation_method_id' => (int) $tkp->id,
            'is_active' => true,
            'sort' => 92,
        ];

        $previewA = $this->previewPayload($mediumA, $desiredA);
        $previewB = $this->previewPayload($mediumB, $desiredB);

        $results = $this->runParallelWorkers(
            $this->mediumApplyWorker($admin, $mediumA, $desiredA, $previewA, [
                'outer_transaction' => true,
                'prelock' => ['medium_id' => $mediumA->id],
                'signal_after_prelock' => 'holder_medium_a_locked',
                'wait_after_prelock' => ['waiter_medium_b_entered'],
            ]),
            $this->mediumApplyWorker($admin, $mediumB, $desiredB, $previewB, [
                'wait_before' => ['holder_medium_a_locked'],
                'signal_before' => 'waiter_medium_b_entered',
                'outer_transaction' => true,
                'prelock' => ['medium_id' => $mediumB->id],
            ]),
        );

        $this->assertCount(2, $results);
        $this->assertNoDeadlockOrLockTimeout($results);
        $this->assertValidSerialOutcomes($results, ['replace_medium_methods']);
    }

    public function test_c_race_apply_versus_replace_category_methods(): void
    {
        $this->requireMysql('C-RACE-MED-METHODS-03');

        $admin = User::factory()->role(Role::Admin)->create();
        $spots = $this->spots();
        $medium = $this->mediumWithOverrideSetup(
            $this->spotMedium('race_med_methods_vs_cat_'.bin2hex(random_bytes(2))),
        );
        $tkp = CalculationMethod::query()->where('key', 'tkp')->firstOrFail();

        $desired = $this->buildDesiredFromCurrent($medium);
        $desired['assignments'][] = [
            'calculation_method_id' => (int) $tkp->id,
            'is_active' => true,
            'sort' => 93,
        ];
        $previewApply = $this->previewPayload($medium, $desired);

        $categoryDesired = $this->buildCategoryDesiredFromCurrent($spots);
        $categoryDesired['assignments'][] = [
            'calculation_method_id' => (int) $tkp->id,
            'is_active' => true,
            'sort' => 94,
        ];
        $previewCategory = app(AdvertisingCategoryCalculationMethodImpactPreviewService::class)
            ->preview($spots->fresh(), $categoryDesired);

        $results = $this->runParallelWorkers(
            $this->mediumApplyWorker($admin, $medium, $desired, $previewApply, [
                'outer_transaction' => true,
                'prelock' => ['medium_id' => $medium->id],
                'signal_after_prelock' => 'holder_medium_locked',
                'wait_after_prelock' => ['waiter_category_entered'],
            ]),
            [
                'action' => 'replace_category_methods',
                'payload' => [
                    'actor_id' => $admin->id,
                    'category_id' => $spots->id,
                    'lock_version' => $categoryDesired['lock_version'],
                    'fingerprint' => $previewCategory['fingerprint'],
                    'default_calculation_method_id' => $categoryDesired['default_calculation_method_id'],
                    'assignments' => $categoryDesired['assignments'],
                    'orchestration' => [
                        'wait_before' => ['holder_medium_locked'],
                        'signal_before' => 'waiter_category_entered',
                        'outer_transaction' => true,
                        'prelock' => ['category_id' => $spots->id],
                    ],
                ],
            ],
        );

        $this->assertCount(2, $results);
        $this->assertNoDeadlockOrLockTimeout($results);
        $this->assertValidSerialOutcomes($results, [
            'replace_medium_methods',
            'replace_category_methods',
        ]);
    }

    public function test_c_race_apply_versus_method_deactivate(): void
    {
        $this->requireMysql('C-RACE-MED-METHODS-04');

        $admin = User::factory()->role(Role::Admin)->create();
        $medium = $this->mediumWithOverrideSetup(
            $this->spotMedium('race_med_methods_deact_m_'.bin2hex(random_bytes(2))),
        );
        $tkp = CalculationMethod::query()->where('key', 'tkp')->firstOrFail();
        $this->assertTrue($tkp->is_active);

        $desired = $this->buildDesiredFromCurrent($medium);
        $desired['assignments'][] = [
            'calculation_method_id' => (int) $tkp->id,
            'is_active' => true,
            'sort' => 95,
        ];
        $previewApply = $this->previewPayload($medium, $desired);
        $previewDeactivate = app(CalculationMethodImpactPreviewService::class)
            ->previewDeactivate($tkp->fresh());
        $this->assertTrue($previewDeactivate['can_proceed']);

        $results = $this->runParallelWorkers(
            $this->mediumApplyWorker($admin, $medium, $desired, $previewApply, [
                'outer_transaction' => true,
                'prelock' => ['medium_id' => $medium->id],
                'signal_after_prelock' => 'holder_medium_locked',
                'wait_after_prelock' => ['waiter_entered'],
            ]),
            [
                'action' => 'deactivate_method',
                'payload' => [
                    'actor_id' => $admin->id,
                    'method_id' => $tkp->id,
                    'lock_version' => $tkp->lock_version,
                    'fingerprint' => $previewDeactivate['fingerprint'],
                    'orchestration' => [
                        'wait_before' => ['holder_medium_locked'],
                        'signal_before' => 'waiter_entered',
                    ],
                ],
            ],
        );

        $this->assertCount(2, $results);
        $this->assertNoDeadlockOrLockTimeout($results);
        $this->assertValidSerialOutcomes($results, ['replace_medium_methods', 'deactivate_method']);

        $tkp->refresh();
        $activeTkp = AdvertisingMediumCalculationMethod::query()
            ->where('advertising_medium_id', $medium->id)
            ->where('calculation_method_id', $tkp->id)
            ->where('is_active', true)
            ->exists();
        if (! $tkp->is_active) {
            $this->assertFalse($activeTkp);
        }
    }

    public function test_c_race_apply_versus_method_metadata_update(): void
    {
        $this->requireMysql('C-RACE-MED-METHODS-05');

        $admin = User::factory()->role(Role::Admin)->create();
        $medium = $this->mediumWithOverrideSetup(
            $this->spotMedium('race_med_methods_meta_m_'.bin2hex(random_bytes(2))),
        );
        $calendar = CalculationMethod::query()->where('key', 'calendar')->firstOrFail();
        $tkp = CalculationMethod::query()->where('key', 'tkp')->firstOrFail();

        $desired = $this->buildDesiredFromCurrent($medium);
        $desired['assignments'][] = [
            'calculation_method_id' => (int) $tkp->id,
            'is_active' => true,
            'sort' => 96,
        ];
        $preview = $this->previewPayload($medium, $desired);

        $results = $this->runParallelWorkers(
            $this->mediumApplyWorker($admin, $medium, $desired, $preview, [
                'outer_transaction' => true,
                'prelock' => ['medium_id' => $medium->id],
                'signal_after_prelock' => 'holder_medium_locked',
                'wait_after_prelock' => ['waiter_entered'],
            ]),
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
                        'wait_before' => ['holder_medium_locked'],
                        'signal_before' => 'waiter_entered',
                    ],
                ],
            ],
        );

        $this->assertCount(2, $results);
        $this->assertNoDeadlockOrLockTimeout($results);
        $this->assertValidSerialOutcomes($results, [
            'replace_medium_methods',
            'update_method_metadata',
        ]);
    }

    public function test_c_race_apply_versus_medium_metadata_update(): void
    {
        $this->requireMysql('C-RACE-MED-METHODS-06');

        $admin = User::factory()->role(Role::Admin)->create();
        $medium = $this->mediumWithOverrideSetup(
            $this->spotMedium('race_med_methods_med_meta_'.bin2hex(random_bytes(2))),
        );
        $tkp = CalculationMethod::query()->where('key', 'tkp')->firstOrFail();

        $desired = $this->buildDesiredFromCurrent($medium);
        $desired['assignments'][] = [
            'calculation_method_id' => (int) $tkp->id,
            'is_active' => true,
            'sort' => 97,
        ];
        $preview = $this->previewPayload($medium, $desired);

        $results = $this->runParallelWorkers(
            $this->mediumApplyWorker($admin, $medium, $desired, $preview, [
                'outer_transaction' => true,
                'prelock' => ['medium_id' => $medium->id],
                'signal_after_prelock' => 'holder_medium_locked',
                'wait_after_prelock' => ['waiter_entered'],
            ]),
            [
                'action' => 'update_medium_metadata',
                'payload' => [
                    'actor_id' => $admin->id,
                    'medium_id' => $medium->id,
                    'name' => $medium->name.' Race',
                    'lock_version' => $medium->lock_version,
                    'orchestration' => [
                        'wait_before' => ['holder_medium_locked'],
                        'signal_before' => 'waiter_entered',
                    ],
                ],
            ],
        );

        $this->assertCount(2, $results);
        $this->assertNoDeadlockOrLockTimeout($results);
        $this->assertValidSerialOutcomes($results, [
            'replace_medium_methods',
            'update_medium_metadata',
        ]);
    }

    public function test_c_race_apply_versus_medium_deactivate(): void
    {
        $this->requireMysql('C-RACE-MED-METHODS-07');

        $admin = User::factory()->role(Role::Admin)->create();
        $spots = $this->spots();
        $activeMedium = $this->mediumWithOverrideSetup(
            $this->spotMedium('race_med_methods_apply_'.bin2hex(random_bytes(2))),
        );
        $targetMedium = AdvertisingMedium::factory()->create([
            'category_id' => $spots->id,
            'code' => 'race_med_methods_deact_t_'.bin2hex(random_bytes(2)),
            'kind' => CalculationKind::SpotClassic,
            'calculation_method_mode' => CalculationMethodMode::Inherit,
            'is_active' => true,
        ]);
        $deactivatePreview = app(CatalogImpactPreviewService::class)
            ->previewMediumDeactivate($targetMedium->fresh());

        $tkp = CalculationMethod::query()->where('key', 'tkp')->firstOrFail();
        $desired = $this->buildDesiredFromCurrent($activeMedium);
        $desired['assignments'][] = [
            'calculation_method_id' => (int) $tkp->id,
            'is_active' => true,
            'sort' => 98,
        ];
        $previewApply = $this->previewPayload($activeMedium, $desired);

        $results = $this->runParallelWorkers(
            $this->mediumApplyWorker($admin, $activeMedium, $desired, $previewApply, [
                'outer_transaction' => true,
                'prelock' => ['medium_id' => $activeMedium->id],
                'signal_after_prelock' => 'holder_medium_locked',
                'wait_after_prelock' => ['waiter_holds_medium'],
            ]),
            [
                'action' => 'deactivate_medium',
                'payload' => [
                    'actor_id' => $admin->id,
                    'medium_id' => $targetMedium->id,
                    'lock_version' => $targetMedium->lock_version,
                    'fingerprint' => $deactivatePreview['fingerprint'],
                    'orchestration' => [
                        'wait_before' => ['holder_medium_locked'],
                        'outer_transaction' => true,
                        'prelock' => ['medium_id' => $targetMedium->id],
                        'signal_after_prelock' => 'waiter_holds_medium',
                    ],
                ],
            ],
        );

        $this->assertCount(2, $results);
        $this->assertNoDeadlockOrLockTimeout($results);
        $this->assertValidSerialOutcomes($results, [
            'replace_medium_methods',
            'deactivate_medium',
        ]);
    }

    public function test_c_race_apply_versus_medium_reactivate(): void
    {
        $this->requireMysql('C-RACE-MED-METHODS-08');

        $admin = User::factory()->role(Role::Admin)->create();
        $spots = $this->spots();
        $activeMedium = $this->mediumWithOverrideSetup(
            $this->spotMedium('race_med_methods_react_a_'.bin2hex(random_bytes(2))),
        );
        $inactiveMedium = AdvertisingMedium::factory()->create([
            'category_id' => $spots->id,
            'code' => 'race_med_methods_react_i_'.bin2hex(random_bytes(2)),
            'kind' => CalculationKind::SpotClassic,
            'calculation_method_mode' => CalculationMethodMode::Inherit,
            'is_active' => false,
        ]);

        $tkp = CalculationMethod::query()->where('key', 'tkp')->firstOrFail();
        $desired = $this->buildDesiredFromCurrent($activeMedium);
        $desired['assignments'][] = [
            'calculation_method_id' => (int) $tkp->id,
            'is_active' => true,
            'sort' => 99,
        ];
        $previewApply = $this->previewPayload($activeMedium, $desired);

        $results = $this->runParallelWorkers(
            $this->mediumApplyWorker($admin, $activeMedium, $desired, $previewApply, [
                'outer_transaction' => true,
                'prelock' => ['medium_id' => $activeMedium->id],
                'signal_after_prelock' => 'holder_medium_locked',
                'wait_after_prelock' => ['waiter_holds_medium'],
            ]),
            [
                'action' => 'reactivate_medium',
                'payload' => [
                    'actor_id' => $admin->id,
                    'medium_id' => $inactiveMedium->id,
                    'lock_version' => $inactiveMedium->lock_version,
                    'orchestration' => [
                        'wait_before' => ['holder_medium_locked'],
                        'outer_transaction' => true,
                        'prelock' => ['medium_id' => $inactiveMedium->id],
                        'signal_after_prelock' => 'waiter_holds_medium',
                    ],
                ],
            ],
        );

        $this->assertCount(2, $results);
        $this->assertNoDeadlockOrLockTimeout($results);
        $this->assertValidSerialOutcomes($results, [
            'replace_medium_methods',
            'reactivate_medium',
        ]);
    }

    public function test_c_race_apply_versus_category_change(): void
    {
        $this->requireMysql('C-RACE-MED-METHODS-09');

        $admin = User::factory()->role(Role::Admin)->create();
        $spots = $this->spots();
        $online = AdvertisingCategory::query()
            ->where('key', CanonicalAdvertisingCategories::ONLINE_AUDIO)
            ->firstOrFail();

        $movingMedium = AdvertisingMedium::factory()->create([
            'category_id' => $online->id,
            'code' => 'race_med_methods_chg_'.bin2hex(random_bytes(2)),
            'kind' => null,
            'is_active' => true,
        ]);
        $changePreview = app(CatalogImpactPreviewService::class)->previewMediumCategoryChange($movingMedium, [
            'target_category_id' => $spots->id,
        ]);
        $this->assertTrue($changePreview['can_proceed']);

        $activeMedium = $this->mediumWithOverrideSetup(
            $this->spotMedium('race_med_methods_chg_a_'.bin2hex(random_bytes(2))),
        );
        $tkp = CalculationMethod::query()->where('key', 'tkp')->firstOrFail();
        $desired = $this->buildDesiredFromCurrent($activeMedium);
        $desired['assignments'][] = [
            'calculation_method_id' => (int) $tkp->id,
            'is_active' => true,
            'sort' => 100,
        ];
        $previewApply = $this->previewPayload($activeMedium, $desired);

        $results = $this->runParallelWorkers(
            $this->mediumApplyWorker($admin, $activeMedium, $desired, $previewApply, [
                'outer_transaction' => true,
                'prelock' => ['medium_id' => $activeMedium->id],
                'signal_after_prelock' => 'holder_medium_locked',
                'wait_after_prelock' => ['waiter_holds_medium'],
            ]),
            [
                'action' => 'change_category',
                'payload' => [
                    'actor_id' => $admin->id,
                    'medium_id' => $movingMedium->id,
                    'target_category_id' => $spots->id,
                    'lock_version' => $movingMedium->lock_version,
                    'fingerprint' => $changePreview['fingerprint'],
                    'orchestration' => [
                        'wait_before' => ['holder_medium_locked'],
                        'outer_transaction' => true,
                        'prelock' => ['medium_id' => $movingMedium->id],
                        'signal_after_prelock' => 'waiter_holds_medium',
                    ],
                ],
            ],
        );

        $this->assertCount(2, $results);
        $this->assertNoDeadlockOrLockTimeout($results);
        $this->assertValidSerialOutcomes($results, [
            'replace_medium_methods',
            'change_category',
        ]);
    }

    /**
     * @param  array<string, mixed>  $preview
     * @param  array<string, mixed>  $orchestration
     * @return array{action: string, payload: array<string, mixed>}
     */
    private function mediumApplyWorker(
        User $admin,
        AdvertisingMedium $medium,
        array $desired,
        array $preview,
        array $orchestration = [],
    ): array {
        return [
            'action' => 'replace_medium_methods',
            'payload' => [
                'actor_id' => $admin->id,
                'medium_id' => $medium->id,
                'lock_version' => $desired['lock_version'],
                'fingerprint' => $preview['fingerprint'],
                'calculation_method_mode' => $desired['calculation_method_mode'],
                'default_calculation_method_id' => $desired['default_calculation_method_id'],
                'assignments' => $desired['assignments'],
                'orchestration' => $orchestration,
            ],
        ];
    }

    /**
     * @return array{
     *     lock_version: int,
     *     calculation_method_mode: string,
     *     default_calculation_method_id: int|null,
     *     assignments: list<array{calculation_method_id: int, is_active: bool, sort: int}>
     * }
     */
    private function buildDesiredFromCurrent(AdvertisingMedium $medium): array
    {
        $assignments = AdvertisingMediumCalculationMethod::query()
            ->where('advertising_medium_id', $medium->id)
            ->orderBy('id')
            ->get()
            ->map(static fn (AdvertisingMediumCalculationMethod $row): array => [
                'calculation_method_id' => (int) $row->calculation_method_id,
                'is_active' => (bool) $row->is_active,
                'sort' => (int) $row->sort,
            ])
            ->values()
            ->all();

        return [
            'lock_version' => (int) $medium->lock_version,
            'calculation_method_mode' => $medium->calculation_method_mode->value,
            'default_calculation_method_id' => $medium->default_calculation_method_id !== null
                ? (int) $medium->default_calculation_method_id
                : null,
            'assignments' => $assignments,
        ];
    }

    /**
     * @return array{
     *     lock_version: int,
     *     default_calculation_method_id: int|null,
     *     assignments: list<array{calculation_method_id: int, is_active: bool, sort: int}>
     * }
     */
    private function buildCategoryDesiredFromCurrent(AdvertisingCategory $category): array
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

    /**
     * @param  array<string, mixed>  $desired
     * @return array<string, mixed>
     */
    private function previewPayload(AdvertisingMedium $medium, array $desired): array
    {
        return app(AdvertisingMediumCalculationMethodImpactPreviewService::class)
            ->preview($medium->fresh([
                'category.defaultCalculationMethod',
                'category.calculationMethodAssignments.calculationMethod',
                'defaultCalculationMethod',
                'calculationMethodAssignments.calculationMethod',
            ]), $desired);
    }

    private function mediumWithOverrideSetup(AdvertisingMedium $medium): AdvertisingMedium
    {
        $average = CalculationMethod::query()->where('key', 'average')->firstOrFail();
        AdvertisingMediumCalculationMethod::factory()->create([
            'advertising_medium_id' => $medium->id,
            'calculation_method_id' => $average->id,
            'engine_profile_key' => 'spot_classic',
            'is_active' => true,
            'sort' => 10,
        ]);
        $medium->forceFill([
            'calculation_method_mode' => CalculationMethodMode::Override,
            'default_calculation_method_id' => $average->id,
        ])->save();

        return $medium->fresh([
            'category.defaultCalculationMethod',
            'category.calculationMethodAssignments.calculationMethod',
            'defaultCalculationMethod',
            'calculationMethodAssignments.calculationMethod',
        ]);
    }

    private function spotMedium(string $code): AdvertisingMedium
    {
        return AdvertisingMedium::factory()->create([
            'category_id' => $this->spots()->id,
            'code' => $code,
            'kind' => CalculationKind::SpotClassic,
            'calculation_method_mode' => CalculationMethodMode::Inherit,
            'is_active' => true,
        ]);
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
        $runDir = sys_get_temp_dir().'/c3c3-race-'.bin2hex(random_bytes(8));
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
