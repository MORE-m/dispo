<?php

namespace Tests\Feature\Administration;

use App\Enums\DispoOrderApprovalStatus;
use App\Enums\DispoOrderStatus;
use App\Enums\Role;
use App\Models\AuditEvent;
use App\Models\DispoOrder;
use App\Models\DispoOrderApprovalRequest;
use App\Models\User;
use App\Services\DispoOrder\DispoOrderApprovalService;
use App\Services\User\Admin\SpecialApproveRightAdminWriter;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use Tests\Concerns\CreatesSavedCalculation;
use Tests\Concerns\CreatesSpotClassicCatalog;
use Tests\Concerns\EnsuresCustomerConfirmationException;
use Tests\TestCase;

class SpecialApproveRightConcurrencyTest extends TestCase
{
    use CreatesSavedCalculation;
    use CreatesSpotClassicCatalog;
    use DatabaseMigrations;
    use EnsuresCustomerConfirmationException;

    public function test_mysql_revoke_first_blocks_special_decision_without_mutation(): void
    {
        if (DB::connection()->getDriverName() !== 'mysql') {
            $this->markTestSkipped('Paralleler Entzug-Test erfordert MySQL (dispo_test).');
        }

        [$admin, $approver, $submitted] = $this->prepareSpecialPendingOrder();

        $statusBefore = $submitted->status;
        $lockBefore = $submitted->lock_version;
        $pendingId = $submitted->pendingApprovalRequest?->id;
        $decisionAuditsBefore = AuditEvent::query()
            ->where('auditable_type', DispoOrder::class)
            ->where('auditable_id', $submitted->id)
            ->whereIn('action', ['dispo_order.approved', 'dispo_order.rejected'])
            ->count();

        $results = $this->runParallelWorkers(
            [
                'action' => 'revoke',
                'payload' => [
                    'actor_id' => $admin->id,
                    'target_user_id' => $approver->id,
                    'orchestration' => [
                        'outer_transaction' => true,
                        'prelock' => ['user_id' => $approver->id],
                        'signal_after_prelock' => 'revoke_holds_user',
                        'wait_after_prelock' => ['approve_entered'],
                        'assert_waiter_blocked_seconds' => 0.35,
                        'waiter_worker_id' => '1',
                    ],
                ],
            ],
            [
                'action' => 'approve',
                'payload' => [
                    'actor_id' => $approver->id,
                    'order_id' => $submitted->id,
                    'lock_version' => $submitted->lock_version,
                    'orchestration' => [
                        'wait_before' => ['revoke_holds_user'],
                        'signal_before' => 'approve_entered',
                    ],
                ],
            ],
        );

        $this->assertTrue(
            collect($results)->contains(fn (string $line): bool => str_starts_with($line, 'OK:revoke')),
            implode(' || ', $results),
        );
        $this->assertTrue(
            collect($results)->contains(
                fn (string $line): bool => str_starts_with($line, 'ERROR:'.AuthorizationException::class),
            ),
            implode(' || ', $results),
        );

        $submitted->refresh();
        $this->assertSame($statusBefore, $submitted->status);
        $this->assertSame($lockBefore, $submitted->lock_version);
        $this->assertSame($pendingId, $submitted->pendingApprovalRequest?->id);
        $this->assertFalse((bool) $approver->fresh()->can_special_approve);
        $this->assertSame(
            DispoOrderApprovalStatus::Pending,
            $submitted->pendingApprovalRequest?->status,
        );
        $this->assertSame(
            $decisionAuditsBefore,
            AuditEvent::query()
                ->where('auditable_type', DispoOrder::class)
                ->where('auditable_id', $submitted->id)
                ->whereIn('action', ['dispo_order.approved', 'dispo_order.rejected'])
                ->count(),
        );
    }

    public function test_mysql_decision_first_then_revoke_keeps_history_and_blocks_next(): void
    {
        if (DB::connection()->getDriverName() !== 'mysql') {
            $this->markTestSkipped('Paralleler Entscheidungs-Test erfordert MySQL (dispo_test).');
        }

        [$admin, $approver, $submitted, $catalog] = $this->prepareSpecialPendingOrder();
        [, , $second] = $this->prepareSpecialPendingOrder($approver, $catalog);

        $results = $this->runParallelWorkers(
            [
                'action' => 'approve',
                'payload' => [
                    'actor_id' => $approver->id,
                    'order_id' => $submitted->id,
                    'lock_version' => $submitted->lock_version,
                    'orchestration' => [
                        'outer_transaction' => true,
                        'prelock' => ['user_id' => $approver->id],
                        'signal_after_prelock' => 'approve_holds_user',
                        'wait_after_prelock' => ['revoke_entered'],
                        'assert_waiter_blocked_seconds' => 0.35,
                        'waiter_worker_id' => '1',
                    ],
                ],
            ],
            [
                'action' => 'revoke',
                'payload' => [
                    'actor_id' => $admin->id,
                    'target_user_id' => $approver->id,
                    'orchestration' => [
                        'wait_before' => ['approve_holds_user'],
                        'signal_before' => 'revoke_entered',
                    ],
                ],
            ],
        );

        $this->assertTrue(
            collect($results)->contains(fn (string $line): bool => str_starts_with($line, 'OK:approve')),
            implode(' || ', $results),
        );
        $this->assertTrue(
            collect($results)->contains(fn (string $line): bool => str_starts_with($line, 'OK:revoke')),
            implode(' || ', $results),
        );

        $submitted->refresh();
        $this->assertSame(DispoOrderStatus::AtDisposition, $submitted->status);
        $this->assertSame(1, DispoOrderApprovalRequest::query()
            ->where('dispo_order_id', $submitted->id)
            ->where('status', DispoOrderApprovalStatus::Approved->value)
            ->count());
        $this->assertFalse((bool) $approver->fresh()->can_special_approve);

        $staleActor = User::query()->findOrFail($approver->id);
        $staleActor->can_special_approve = true;

        $service = app(DispoOrderApprovalService::class);
        $second->refresh();

        try {
            $service->approve($second, $staleActor, $second->lock_version, null, true);
            $this->fail('Approve nach Entzug mit stale Actor musste scheitern.');
        } catch (AuthorizationException) {
            // erwartet
        }

        try {
            $service->reject($second, $staleActor, $second->lock_version, 'Nach Entzug');
            $this->fail('Reject nach Entzug mit stale Actor musste scheitern.');
        } catch (AuthorizationException) {
            // erwartet
        }

        $second->refresh();
        $this->assertSame(DispoOrderStatus::AwaitingSalesApproval, $second->status);
        $this->assertSame(
            DispoOrderApprovalStatus::Pending,
            $second->pendingApprovalRequest?->status,
        );
    }

    public function test_service_rejects_approve_and_reject_after_revoke_with_stale_actor(): void
    {
        [$admin, $approver, $submitted] = $this->prepareSpecialPendingOrder();

        app(SpecialApproveRightAdminWriter::class)->update($approver, false, $admin);

        $staleActor = User::query()->findOrFail($approver->id);
        $staleActor->can_special_approve = true;
        $this->assertTrue($staleActor->can_special_approve);
        $this->assertFalse((bool) $approver->fresh()->can_special_approve);

        $service = app(DispoOrderApprovalService::class);
        $pendingId = $submitted->pendingApprovalRequest?->id;

        try {
            $service->approve($submitted, $staleActor, $submitted->lock_version, null, true);
            $this->fail('Approve mit stale Actor nach Entzug musste scheitern.');
        } catch (AuthorizationException) {
            // erwartet
        }

        try {
            $service->reject($submitted, $staleActor, $submitted->lock_version, 'Stale Reject');
            $this->fail('Reject mit stale Actor nach Entzug musste scheitern.');
        } catch (AuthorizationException) {
            // erwartet
        }

        $submitted->refresh();
        $this->assertSame(DispoOrderStatus::AwaitingSalesApproval, $submitted->status);
        $this->assertSame($pendingId, $submitted->pendingApprovalRequest?->id);
    }

    /**
     * @param  array{hamburg: mixed, rock: mixed, medium: mixed}|null  $catalog
     * @return array{0: User, 1: User, 2: DispoOrder, 3: array{hamburg: mixed, rock: mixed, medium: mixed}}
     */
    private function prepareSpecialPendingOrder(?User $existingApprover = null, ?array $catalog = null): array
    {
        $admin = User::factory()->role(Role::Admin)->create();
        $approver = $existingApprover ?? User::factory()->role(Role::Sales)->create([
            'can_special_approve' => true,
        ]);
        if ($existingApprover !== null && ! $approver->can_special_approve) {
            $approver->forceFill(['can_special_approve' => true])->save();
        }

        $creator = User::factory()->role(Role::Sales)->create([
            'discount_limit_percent' => '10',
        ]);
        $catalog ??= $this->createSpotClassicCatalog();
        $calculation = $this->createSavedCalculation($catalog, [
            [
                'inventory_id' => $catalog['hamburg']->id,
                'position_discount_percent' => '20',
            ],
        ], $creator, [
            'first_position_discount_percent' => '20',
        ]);

        $this->actingAs($creator)->post(route('dispo-orders.store', $calculation), [
            'position_ids' => [$calculation->positions()->first()->id],
        ])->assertRedirect();

        $order = DispoOrder::query()->latest('id')->firstOrFail();
        $service = app(DispoOrderApprovalService::class);
        $order = $this->seedCustomerConfirmationException($order, $creator);
        $submitted = $service->submit($order, $creator, $order->lock_version);
        $this->assertTrue($submitted->requiresSpecialApproval());

        return [$admin, $approver->fresh() ?? $approver, $submitted, $catalog];
    }

    /**
     * @param  array{action: string, payload: array<string, mixed>}  $workerA
     * @param  array{action: string, payload: array<string, mixed>}  $workerB
     * @return list<string>
     */
    private function runParallelWorkers(array $workerA, array $workerB): array
    {
        $runDir = sys_get_temp_dir().'/dispo-special-approve-concurrency-'.Str::uuid();
        if (! mkdir($runDir, 0700, true) && ! is_dir($runDir)) {
            $this->fail('Temporäres Barrier-Verzeichnis konnte nicht erstellt werden.');
        }

        try {
            $script = base_path('tests/concurrency/special_approve_right_worker.php');
            $env = $this->workerEnvironment();
            $processA = new Process(
                [PHP_BINARY, $script, $runDir, '0', $workerA['action'], json_encode($workerA['payload'], JSON_THROW_ON_ERROR)],
                null,
                $env,
            );
            $processB = new Process(
                [PHP_BINARY, $script, $runDir, '1', $workerB['action'], json_encode($workerB['payload'], JSON_THROW_ON_ERROR)],
                null,
                $env,
            );
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

            $this->assertCount(
                2,
                $results,
                'Beide Worker müssen ein Resultat schreiben. stderr='
                .$processA->getErrorOutput().' | '.$processB->getErrorOutput(),
            );

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

        return $env;
    }
}
