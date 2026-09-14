<?php

namespace Tests\Feature\PriceList;

use App\Enums\DayGroup;
use App\Enums\PlanningMode;
use App\Enums\PriceListStatus;
use App\Enums\Role;
use App\Exceptions\PriceListAdminConflictException;
use App\Models\AuditEvent;
use App\Models\BudgetProposal;
use App\Models\Calculation;
use App\Models\Inventory;
use App\Models\PriceList;
use App\Models\User;
use App\Support\PriceList\PriceListCalendar;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\Process\Process;
use Tests\Concerns\CreatesSpotClassicCatalog;
use Tests\TestCase;

class PriceListConcurrencyTest extends TestCase
{
    use CreatesSpotClassicCatalog;
    use DatabaseMigrations;

    public function test_concurrent_create_assigns_distinct_versions(): void
    {
        $this->requireMysql('PRI-CREATE-RACE');
        $catalog = $this->createSpotClassicCatalog();
        $admin = User::factory()->role(Role::Admin)->create();
        $year = PriceListCalendar::currentYear();
        $inventory = Inventory::factory()->create([
            'organization_id' => $catalog['hamburg']->organization_id,
            'code' => 'CR',
        ]);

        $results = $this->runParallelWorkers(
            [
                'action' => 'create_draft',
                'payload' => [
                    'actor_id' => $admin->id,
                    'inventory_id' => $inventory->id,
                    'year' => $year,
                    'name' => 'Parallel A',
                ],
            ],
            [
                'action' => 'create_draft',
                'payload' => [
                    'actor_id' => $admin->id,
                    'inventory_id' => $inventory->id,
                    'year' => $year,
                    'name' => 'Parallel B',
                ],
            ],
        );

        $this->assertNoDeadlockOrServerError($results);
        $ok = array_values(array_filter($results, fn (string $line): bool => str_starts_with($line, 'OK:create_draft')));
        $this->assertCount(2, $ok, implode(' || ', $results));
        $versions = array_map(fn (string $line): string => explode('|', $line)[2] ?? '', $ok);
        $this->assertCount(2, array_unique($versions));
        $this->assertSame(
            2,
            PriceList::query()->where('inventory_id', $inventory->id)->where('year', $year)->count(),
        );
    }

    public function test_copy_and_activate_same_source_do_not_deadlock_or_double_active(): void
    {
        $this->requireMysql('PRI-COPY-ACTIVATE-RACE');
        [$admin, $draft] = $this->preparedDraft();

        $results = $this->runParallelWorkers(
            [
                'action' => 'copy_as_draft',
                'payload' => [
                    'actor_id' => $admin->id,
                    'source_id' => $draft->id,
                    'year' => $draft->year,
                    'name' => 'Kopie während Activate',
                ],
            ],
            [
                'action' => 'activate',
                'payload' => [
                    'actor_id' => $admin->id,
                    'price_list_id' => $draft->id,
                    'lock_version' => $draft->lock_version,
                ],
            ],
        );

        $this->assertNoDeadlockOrServerError($results);
        $this->assertTrue(
            collect($results)->contains(fn (string $line): bool => str_starts_with($line, 'OK:')),
            implode(' || ', $results),
        );
        $this->assertSame(
            1,
            PriceList::query()
                ->where('inventory_id', $draft->inventory_id)
                ->where('year', $draft->year)
                ->where('status', PriceListStatus::Active)
                ->count(),
        );
        $activatedAudits = AuditEvent::query()->where('action', 'price_list.activated')->count();
        $this->assertLessThanOrEqual(1, $activatedAudits);
        if ($activatedAudits === 1) {
            $this->assertSame(PriceListStatus::Active, $draft->fresh()->status);
        }
    }

    public function test_two_copies_of_different_lists_same_inventory_succeed(): void
    {
        $this->requireMysql('PRI-TWO-COPIES-RACE');
        $catalog = $this->createSpotClassicCatalog();
        $admin = User::factory()->role(Role::Admin)->create();
        $year = PriceListCalendar::currentYear();
        $first = $this->storeDraft($admin, (int) $catalog['hamburg']->id, $year, 'Quelle A');
        $second = $this->storeDraft($admin, (int) $catalog['hamburg']->id, $year, 'Quelle B');

        $results = $this->runParallelWorkers(
            [
                'action' => 'copy_as_draft',
                'payload' => [
                    'actor_id' => $admin->id,
                    'source_id' => $first->id,
                    'year' => $year,
                    'name' => 'Kopie A',
                ],
            ],
            [
                'action' => 'copy_as_draft',
                'payload' => [
                    'actor_id' => $admin->id,
                    'source_id' => $second->id,
                    'year' => $year,
                    'name' => 'Kopie B',
                ],
            ],
        );

        $this->assertNoDeadlockOrServerError($results);
        $ok = array_values(array_filter($results, fn (string $line): bool => str_starts_with($line, 'OK:copy_as_draft')));
        $this->assertCount(2, $ok, implode(' || ', $results));
        $versions = array_map(fn (string $line): string => explode('|', $line)[2] ?? '', $ok);
        $this->assertCount(2, array_unique($versions));
    }

    public function test_copy_to_other_year_vs_activate_current_year(): void
    {
        $this->requireMysql('PRI-COPY-YEAR-RACE');
        [$admin, $draft] = $this->preparedDraft();

        $results = $this->runParallelWorkers(
            [
                'action' => 'copy_as_draft',
                'payload' => [
                    'actor_id' => $admin->id,
                    'source_id' => $draft->id,
                    'year' => ((int) $draft->year) + 1,
                    'name' => 'Anderes Jahr',
                ],
            ],
            [
                'action' => 'activate',
                'payload' => [
                    'actor_id' => $admin->id,
                    'price_list_id' => $draft->id,
                    'lock_version' => $draft->lock_version,
                ],
            ],
        );

        $this->assertNoDeadlockOrServerError($results);
        $this->assertLessThanOrEqual(
            1,
            PriceList::query()
                ->where('inventory_id', $draft->inventory_id)
                ->where('year', $draft->year)
                ->where('status', PriceListStatus::Active)
                ->count(),
        );
        $copy = PriceList::query()->where('name', 'Anderes Jahr')->first();
        if ($copy !== null) {
            $this->assertSame(((int) $draft->year) + 1, (int) $copy->year);
            $copy->version = 'other-'.$copy->id;
            $copy->save();
        }
    }

    public function test_update_during_activate_is_controlled(): void
    {
        $this->requireMysql('PRI-UPDATE-ACTIVATE-RACE');
        [$admin, $draft] = $this->preparedDraft();

        $results = $this->runParallelWorkers(
            [
                'action' => 'update_draft',
                'payload' => [
                    'actor_id' => $admin->id,
                    'price_list_id' => $draft->id,
                    'lock_version' => $draft->lock_version,
                    'name' => 'Geändert während Activate',
                    'items' => $this->hourItems(8, '3.0000'),
                ],
            ],
            [
                'action' => 'activate',
                'payload' => [
                    'actor_id' => $admin->id,
                    'price_list_id' => $draft->id,
                    'lock_version' => $draft->lock_version,
                ],
            ],
        );

        $this->assertNoDeadlockOrServerError($results);
        $ok = array_values(array_filter($results, fn (string $line): bool => str_starts_with($line, 'OK:')));
        $errors = array_values(array_filter($results, fn (string $line): bool => str_starts_with($line, 'ERROR:')));
        $this->assertNotEmpty($ok, implode(' || ', $results));
        $this->assertTrue(
            $errors === [] || str_contains($errors[0], ValidationException::class) || str_contains($errors[0], PriceListAdminConflictException::class),
            implode(' || ', $results),
        );
        $this->assertLessThanOrEqual(
            1,
            PriceList::query()
                ->where('inventory_id', $draft->inventory_id)
                ->where('year', $draft->year)
                ->where('status', PriceListStatus::Active)
                ->count(),
        );
        $fresh = $draft->fresh();
        if ($fresh->status === PriceListStatus::Active) {
            $this->assertNotSame('Geändert während Activate', $fresh->name);
        }
        if ($fresh->name === 'Geändert während Activate') {
            $this->assertSame(PriceListStatus::Draft, $fresh->status);
            $this->assertSame('3.0000', (string) $fresh->items()->where('day_group', DayGroup::MoFr)->value('second_price'));
        }
    }

    public function test_budget_apply_vs_price_list_activate_keeps_checked_identity(): void
    {
        $this->requireMysql('PRI-BUDGET-APPLY-ACTIVATE-RACE');
        $catalog = $this->createSpotClassicCatalog();
        $user = User::factory()->role(Role::Admin)->create();
        $year = PriceListCalendar::currentYear();
        $activeId = (int) PriceList::query()
            ->where('inventory_id', $catalog['hamburg']->id)
            ->where('status', PriceListStatus::Active)
            ->value('id');

        $draft = $this->storeDraft($user, (int) $catalog['hamburg']->id, $year, 'Nachfolger Draft', '9.0000');

        $this->actingAs($user)->post(route('calculations.store'), [
            'planning_mode' => PlanningMode::Budget->value,
            'schema_fingerprint' => $this->liveSchemaFingerprint(),
            'target_budget_nn' => '500',
            'order_discount_percent' => '0',
            'positions' => [],
        ]);
        $calculation = Calculation::query()->firstOrFail();
        $propose = $this->actingAs($user)->postJson(route('calculations.budget-propose'), [
            'planning_mode' => PlanningMode::Budget->value,
            'target_budget_nn' => '500',
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

        $results = $this->runParallelWorkers(
            [
                'action' => 'apply_budget',
                'payload' => [
                    'actor_id' => $user->id,
                    'calculation_id' => $calculation->id,
                    'proposal_id' => $proposalId,
                ],
            ],
            [
                'action' => 'activate',
                'payload' => [
                    'actor_id' => $user->id,
                    'price_list_id' => $draft->id,
                    'lock_version' => $draft->lock_version,
                ],
            ],
        );

        $this->assertNoDeadlockOrServerError($results);
        $calculation->refresh()->load('positions');
        $proposal = BudgetProposal::query()->findOrFail($proposalId);
        if ($proposal->applied_at !== null) {
            $this->assertGreaterThan(0, $calculation->positions->count());
            $this->assertSame($activeId, (int) $calculation->positions->first()->price_list_id);
            $this->assertNotSame($draft->id, (int) $calculation->positions->first()->price_list_id);
        } else {
            $this->assertSame(0, $calculation->positions->count());
        }
        $this->assertLessThanOrEqual(
            1,
            PriceList::query()
                ->where('inventory_id', $catalog['hamburg']->id)
                ->where('year', $year)
                ->where('status', PriceListStatus::Active)
                ->count(),
        );
    }

    /**
     * @return array{0: User, 1: PriceList}
     */
    private function preparedDraft(): array
    {
        $catalog = $this->createSpotClassicCatalog();
        $admin = User::factory()->role(Role::Admin)->create();
        $year = PriceListCalendar::currentYear();
        $inventory = Inventory::factory()->create([
            'organization_id' => $catalog['hamburg']->organization_id,
            'code' => 'CX',
        ]);
        $draft = $this->storeDraft($admin, $inventory->id, $year, 'Concurrency Draft');

        return [$admin, $draft];
    }

    private function storeDraft(User $admin, int $inventoryId, int $year, string $name, string $price = '1.2500'): PriceList
    {
        $this->actingAs($admin)->post(route('administration.price-lists.store'), [
            'inventory_id' => $inventoryId,
            'year' => $year,
            'name' => $name,
            'items' => $this->hourItems(8, $price),
        ])->assertRedirect();

        return PriceList::query()->where('name', $name)->firstOrFail();
    }

    /**
     * @return list<array{hour: int, day_group: string, second_price: string}>
     */
    private function hourItems(int $hour, string $price): array
    {
        return [
            ['hour' => $hour, 'day_group' => DayGroup::MoFr->value, 'second_price' => $price],
            ['hour' => $hour, 'day_group' => DayGroup::Sa->value, 'second_price' => $price],
            ['hour' => $hour, 'day_group' => DayGroup::So->value, 'second_price' => $price],
        ];
    }

    /**
     * @param  array{action: string, payload: array<string, mixed>}  $workerA
     * @param  array{action: string, payload: array<string, mixed>}  $workerB
     * @return list<string>
     */
    private function runParallelWorkers(array $workerA, array $workerB): array
    {
        $runDir = sys_get_temp_dir().'/dispo-pri-concurrency-'.Str::uuid();
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
