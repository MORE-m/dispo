<?php

namespace Tests\Feature\PriceList;

use App\Enums\BudgetProposalStatus;
use App\Enums\DayGroup;
use App\Enums\PlanningMode;
use App\Enums\PriceListStatus;
use App\Enums\Role;
use App\Models\BudgetProposal;
use App\Models\Calculation;
use App\Models\PriceList;
use App\Models\PriceListItem;
use App\Models\User;
use App\Services\PriceList\Admin\PriceListAdminWriter;
use App\Services\PriceList\Admin\PriceListImpactPreviewService;
use App\Support\PriceList\PriceListCalendar;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use Tests\Concerns\CreatesSavedCalculation;
use Tests\Concerns\CreatesSpotClassicCatalog;
use Tests\TestCase;

/**
 * Gezielte MySQL-Regressionen für PO-PRI-YEAR-1 (Rebind, 409, Budget-Stale, Lock-Reihenfolge).
 */
class PriceListYearSelectionMysqlTest extends TestCase
{
    use CreatesSavedCalculation;
    use CreatesSpotClassicCatalog;
    use DatabaseMigrations;

    protected function tearDown(): void
    {
        // Writer-Entwürfe haben year + valid_from=null; migrate:rollback darf daran
        // nicht scheitern. Zuerst abhängige Calc-Zeilen, dann Preislisten.
        foreach ([
            'spot_classic_plan_rows',
            'calculation_position_time_ranges',
            'calculation_position_discounts',
            'calculation_position_field_values',
            'calculation_positions',
            'calculation_field_values',
            'calculation_order_discounts',
            'budget_proposals',
            'calculations',
            'price_list_items',
            'price_lists',
        ] as $table) {
            if (Schema::hasTable($table)) {
                DB::table($table)->delete();
            }
        }

        parent::tearDown();
    }

    public function test_mysql_rebind_to_next_year_pins_expected_active_list(): void
    {
        $this->requireMysql('PRI-YEAR-REBIND');
        Carbon::setTestNow(Carbon::parse('2026-06-15 12:00:00', 'Europe/Berlin'));

        $catalog = $this->createSpotClassicCatalog();
        $user = User::factory()->role(Role::Sales)->create();
        $calculation = $this->createSavedCalculation($catalog, [
            ['inventory_id' => $catalog['hamburg']->id, 'total_spot_count' => 1, 'hour' => 8],
        ], $user);
        $position = $calculation->positions()->firstOrFail();
        $originalId = (int) $position->price_list_id;
        $nextYear = PriceListCalendar::currentYear() + 1;
        $nextList = $this->createActiveList($catalog['hamburg']->id, $nextYear, 'mysql-next');

        $this->actingAs($user)->put(
            route('calculations.update', $calculation),
            [
                'lock_version' => $calculation->fresh()->lock_version,
                'planning_mode' => 'manual',
                'schema_fingerprint' => $this->liveSchemaFingerprint(),
                'order_discount_percent' => '0',
                'positions' => $this->withPositionSchemaFingerprints($calculation, [[
                    'id' => $position->id,
                    'client_key' => $position->client_key,
                    'inventory_id' => $catalog['hamburg']->id,
                    'advertising_medium_id' => $catalog['medium']->id,
                    'spot_method' => 'average',
                    'price_year' => $nextYear,
                    'expected_price_list_id' => $nextList->id,
                    'length_seconds' => 30,
                    'total_spot_count' => 4,
                    'position_discount_percent' => '0',
                    'ae_percent' => '0',
                    'plan_rows' => [['hour' => 8, 'day_group' => 'mo_fr']],
                ]]),
            ],
        )->assertRedirect();

        $position->refresh();
        $this->assertSame($nextList->id, (int) $position->price_list_id);
        $this->assertSame(4, (int) $position->total_spot_count);
        $this->assertNotSame($originalId, (int) $position->price_list_id);
    }

    public function test_mysql_stale_expected_id_returns_409_without_partial_persist(): void
    {
        $this->requireMysql('PRI-YEAR-409');
        Carbon::setTestNow(Carbon::parse('2026-06-15 12:00:00', 'Europe/Berlin'));

        $catalog = $this->createSpotClassicCatalog();
        $user = User::factory()->role(Role::Sales)->create();
        $calculation = $this->createSavedCalculation($catalog, [
            ['inventory_id' => $catalog['hamburg']->id, 'total_spot_count' => 2, 'hour' => 8],
        ], $user);
        $position = $calculation->positions()->firstOrFail();
        $pinnedId = (int) $position->price_list_id;
        $nextYear = PriceListCalendar::currentYear() + 1;
        $nextList = $this->createActiveList($catalog['hamburg']->id, $nextYear, 'mysql-stale-target');

        $response = $this->actingAs($user)->putJson(
            route('calculations.update', $calculation),
            [
                'lock_version' => $calculation->fresh()->lock_version,
                'planning_mode' => 'manual',
                'schema_fingerprint' => $this->liveSchemaFingerprint(),
                'order_discount_percent' => '0',
                'positions' => $this->withPositionSchemaFingerprints($calculation, [[
                    'id' => $position->id,
                    'client_key' => $position->client_key,
                    'inventory_id' => $catalog['hamburg']->id,
                    'advertising_medium_id' => $catalog['medium']->id,
                    'spot_method' => 'average',
                    'price_year' => $nextYear,
                    'expected_price_list_id' => 999999,
                    'length_seconds' => 30,
                    'total_spot_count' => 99,
                    'position_discount_percent' => '0',
                    'ae_percent' => '0',
                    'plan_rows' => [['hour' => 8, 'day_group' => 'mo_fr']],
                ]]),
            ],
        );

        $response->assertStatus(409);
        $position->refresh();
        $this->assertSame($pinnedId, (int) $position->price_list_id);
        $this->assertSame(2, (int) $position->total_spot_count);
        $this->assertNotSame($nextList->id, (int) $position->price_list_id);
    }

    public function test_mysql_budget_stale_when_active_list_changes_between_propose_and_apply(): void
    {
        $this->requireMysql('PRI-YEAR-BUDGET-STALE');
        Carbon::setTestNow(Carbon::parse('2026-06-15 12:00:00', 'Europe/Berlin'));

        $catalog = $this->createSpotClassicCatalog();
        $user = User::factory()->role(Role::Sales)->create();
        $year = PriceListCalendar::currentYear();

        $this->actingAs($user)->post(route('calculations.store'), [
            'planning_mode' => PlanningMode::Budget->value,
            'schema_fingerprint' => $this->liveSchemaFingerprint(),
            'target_budget_nn' => '3000',
            'order_discount_percent' => '0',
            'positions' => [],
        ]);
        $calculation = Calculation::query()->firstOrFail();
        $active = PriceList::query()
            ->where('inventory_id', $catalog['hamburg']->id)
            ->where('year', $year)
            ->where('status', PriceListStatus::Active)
            ->firstOrFail();

        $propose = $this->actingAs($user)->postJson(route('calculations.budget-propose'), [
            'planning_mode' => PlanningMode::Budget->value,
            'target_budget_nn' => '3000',
            'price_year' => $year,
            'expected_price_list_ids' => [
                $catalog['hamburg']->id => $active->id,
            ],
            'budget_elements' => [[
                'client_id' => 'hamburg',
                'inventory_id' => $catalog['hamburg']->id,
                'spot_length_seconds' => 30,
                'distribution_ranges' => [[
                    'start_hour' => 8,
                    'end_hour_exclusive' => 12,
                    'day_group' => DayGroup::MoFr->value,
                ]],
                'position_discounts' => [],
            ]],
            'order_discount_percent' => '0',
            'order_discounts' => [],
            'ae_enabled' => false,
            'positions' => [],
            'calculation_id' => $calculation->id,
        ]);
        $propose->assertOk();
        $proposalId = (int) $propose->json('proposal.id');

        $active->status = PriceListStatus::Archived;
        $active->save();
        $this->createActiveList($catalog['hamburg']->id, $year, 'mysql-budget-successor');

        $this->actingAs($user)
            ->from(route('calculations.edit', $calculation))
            ->post(route('calculations.budget-apply', [
                'calculation' => $calculation,
                'proposal' => $proposalId,
            ]))
            ->assertRedirect()
            ->assertSessionHasErrors('proposal');

        $this->assertSame(BudgetProposalStatus::Stale, BudgetProposal::query()->findOrFail($proposalId)->status);
        $this->assertSame(0, $calculation->fresh()->positions()->count());
    }

    public function test_mysql_activate_before_rebind_yields_409_without_partial_persist(): void
    {
        $this->requireMysql('PRI-YEAR-ACTIVATE-THEN-REBIND');
        Carbon::setTestNow(Carbon::parse('2026-06-15 12:00:00', 'Europe/Berlin'));

        $catalog = $this->createSpotClassicCatalog();
        $admin = User::factory()->role(Role::Admin)->create();
        $sales = User::factory()->role(Role::Sales)->create();
        $calculation = $this->createSavedCalculation($catalog, [
            ['inventory_id' => $catalog['hamburg']->id, 'total_spot_count' => 1, 'hour' => 8],
        ], $sales);
        $position = $calculation->positions()->firstOrFail();
        $pinnedBefore = (int) $position->price_list_id;
        $spotsBefore = (int) $position->total_spot_count;
        $nextYear = PriceListCalendar::currentYear() + 1;
        $nextList = $this->createActiveList($catalog['hamburg']->id, $nextYear, 'mysql-ordered-next');

        $draft = PriceList::factory()->create([
            'inventory_id' => $catalog['hamburg']->id,
            'year' => $nextYear,
            'status' => PriceListStatus::Draft,
            'version' => 'mysql-ordered-draft',
            'name' => 'Ordered Draft',
        ]);
        foreach (range(0, 23) as $hour) {
            foreach ([DayGroup::MoFr, DayGroup::Sa, DayGroup::So] as $group) {
                PriceListItem::factory()->create([
                    'price_list_id' => $draft->id,
                    'hour' => $hour,
                    'day_group' => $group,
                    'second_price' => '5.0000',
                ]);
            }
        }

        // Sequenz: Aktivierung zuerst, danach Rebind mit veraltetem Expected → 409.
        $preview = app(PriceListImpactPreviewService::class)
            ->previewActivate($draft);
        app(PriceListAdminWriter::class)->activate($draft, [
            'lock_version' => $draft->lock_version,
            'fingerprint' => (string) $preview['fingerprint'],
        ], $admin);

        $this->assertSame(PriceListStatus::Archived, $nextList->fresh()->status);
        $this->assertSame(PriceListStatus::Active, $draft->fresh()->status);

        $response = $this->actingAs($sales)->putJson(
            route('calculations.update', $calculation),
            [
                'lock_version' => $calculation->fresh()->lock_version,
                'planning_mode' => 'manual',
                'schema_fingerprint' => $this->liveSchemaFingerprint(),
                'order_discount_percent' => '0',
                'positions' => $this->withPositionSchemaFingerprints($calculation, [[
                    'id' => $position->id,
                    'client_key' => $position->client_key,
                    'inventory_id' => $catalog['hamburg']->id,
                    'advertising_medium_id' => $catalog['medium']->id,
                    'spot_method' => 'average',
                    'price_year' => $nextYear,
                    'expected_price_list_id' => $nextList->id,
                    'length_seconds' => 30,
                    'total_spot_count' => 7,
                    'position_discount_percent' => '0',
                    'ae_percent' => '0',
                    'plan_rows' => [['hour' => 8, 'day_group' => 'mo_fr']],
                ]]),
            ],
        );
        $response->assertStatus(409);
        $position->refresh();
        $this->assertSame($pinnedBefore, (int) $position->price_list_id);
        $this->assertSame($spotsBefore, (int) $position->total_spot_count);
        $this->assertSame(
            1,
            PriceList::query()
                ->where('inventory_id', $catalog['hamburg']->id)
                ->where('year', $nextYear)
                ->where('status', PriceListStatus::Active)
                ->count(),
        );
    }

    public function test_mysql_rebind_before_activate_pins_expected_list(): void
    {
        $this->requireMysql('PRI-YEAR-REBIND-THEN-ACTIVATE');
        Carbon::setTestNow(Carbon::parse('2026-06-15 12:00:00', 'Europe/Berlin'));

        $catalog = $this->createSpotClassicCatalog();
        $admin = User::factory()->role(Role::Admin)->create();
        $sales = User::factory()->role(Role::Sales)->create();
        $calculation = $this->createSavedCalculation($catalog, [
            ['inventory_id' => $catalog['hamburg']->id, 'total_spot_count' => 1, 'hour' => 8],
        ], $sales);
        $nextYear = PriceListCalendar::currentYear() + 1;
        $nextList = $this->createActiveList($catalog['hamburg']->id, $nextYear, 'mysql-rebind-first');

        $draft = PriceList::factory()->create([
            'inventory_id' => $catalog['hamburg']->id,
            'year' => $nextYear,
            'status' => PriceListStatus::Draft,
            'version' => 'mysql-rebind-first-draft',
            'name' => 'Rebind First Draft',
        ]);
        foreach (range(0, 23) as $hour) {
            foreach ([DayGroup::MoFr, DayGroup::Sa, DayGroup::So] as $group) {
                PriceListItem::factory()->create([
                    'price_list_id' => $draft->id,
                    'hour' => $hour,
                    'day_group' => $group,
                    'second_price' => '5.0000',
                ]);
            }
        }

        $results = $this->runParallelWorkers(
            [
                'action' => 'rebind_calculation_year',
                'payload' => [
                    'actor_id' => $sales->id,
                    'calculation_id' => $calculation->id,
                    'lock_version' => $calculation->lock_version,
                    'price_year' => $nextYear,
                    'expected_price_list_id' => $nextList->id,
                    'total_spot_count' => 7,
                    'orchestration' => [
                        'outer_transaction' => true,
                        'prelock' => ['inventory_id' => $catalog['hamburg']->id],
                        'signal_after_prelock' => 'rebind_holds_inventory',
                        'wait_after_prelock' => ['activate_entered'],
                    ],
                ],
            ],
            [
                'action' => 'activate',
                'payload' => [
                    'actor_id' => $admin->id,
                    'price_list_id' => $draft->id,
                    'lock_version' => $draft->lock_version,
                    'orchestration' => [
                        'wait_before' => ['rebind_holds_inventory'],
                        'signal_before' => 'activate_entered',
                    ],
                ],
            ],
        );

        $this->assertNoDeadlockOrServerError($results);
        $this->assertTrue(
            collect($results)->contains(fn (string $line): bool => str_starts_with($line, 'OK:rebind_calculation_year')),
            implode(' || ', $results),
        );
        $this->assertTrue(
            collect($results)->contains(fn (string $line): bool => str_starts_with($line, 'OK:activate')),
            implode(' || ', $results),
        );

        $position = $calculation->fresh()->positions()->firstOrFail();
        $this->assertSame($nextList->id, (int) $position->price_list_id);
        $this->assertSame(7, (int) $position->total_spot_count);
        $this->assertSame(
            1,
            PriceList::query()
                ->where('inventory_id', $catalog['hamburg']->id)
                ->where('year', $nextYear)
                ->where('status', PriceListStatus::Active)
                ->count(),
        );
        $this->assertSame(PriceListStatus::Archived, $nextList->fresh()->status);
        $this->assertSame(PriceListStatus::Active, $draft->fresh()->status);
    }

    private function createActiveList(int $inventoryId, int $year, string $version): PriceList
    {
        $list = PriceList::factory()->create([
            'inventory_id' => $inventoryId,
            'year' => $year,
            'status' => PriceListStatus::Active,
            'version' => $version,
            'valid_from' => sprintf('%04d-01-01', $year),
        ]);
        foreach (range(0, 23) as $hour) {
            foreach ([DayGroup::MoFr, DayGroup::Sa, DayGroup::So] as $group) {
                PriceListItem::factory()->create([
                    'price_list_id' => $list->id,
                    'hour' => $hour,
                    'day_group' => $group,
                    'second_price' => '3.5000',
                ]);
            }
        }

        return $list;
    }

    /**
     * @param  array{action: string, payload: array<string, mixed>}  $workerA
     * @param  array{action: string, payload: array<string, mixed>}  $workerB
     * @return list<string>
     */
    private function runParallelWorkers(array $workerA, array $workerB): array
    {
        $runDir = sys_get_temp_dir().'/dispo-pri-year-concurrency-'.Str::uuid();
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
            foreach (['Deadlock', '1213', '1205', 'Lock wait timeout', 'lock wait timeout', 'SQLSTATE'] as $needle) {
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
