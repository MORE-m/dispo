<?php

namespace Tests\Feature\DispoOrder;

use App\Enums\DispoOrderStatus;
use App\Enums\Role;
use App\Exceptions\DispoOrderConflictException;
use App\Models\AuditEvent;
use App\Models\DispoOrder;
use App\Models\DispoOrderStatusEvent;
use App\Models\User;
use App\Services\Calculation\CalculationWriter;
use App\Services\DispoOrder\DispoOrderApprovalService;
use App\Services\DispoOrder\DispoOrderCompletionService;
use App\Services\DispoOrder\DispoOrderInvoiceEndService;
use App\Services\DispoOrder\DispoOrderOperationalStatusService;
use App\Services\DynamicField\ConfigurationSnapshotFreezeService;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use Tests\Concerns\CreatesSpotClassicCatalog;
use Tests\Concerns\EnsuresCustomerConfirmationException;
use Tests\TestCase;

class DispoOrderCompletedReopenCancellationConcurrencyTest extends TestCase
{
    use CreatesSpotClassicCatalog;
    use DatabaseMigrations;
    use EnsuresCustomerConfirmationException;

    public function test_mysql_parallel_reopen_vs_cancel_exactly_one_winner(): void
    {
        if (DB::connection()->getDriverName() !== 'mysql') {
            $this->markTestSkipped('Paralleler Reopen/Cancel-Test erfordert MySQL (GitHub-Job mysql).');
        }

        ['order' => $order, 'admin' => $admin, 'disposition' => $disposition] = $this->completedOrder();
        $lockBefore = $order->lock_version;
        $eventsBefore = DispoOrderStatusEvent::query()->where('dispo_order_id', $order->id)->count();

        $results = $this->runMixedWorkers(
            (string) $order->id,
            (string) $admin->id,
            (string) $lockBefore,
            (string) $disposition->id,
            (string) $lockBefore,
        );

        $ok = array_values(array_filter($results, fn (string $line): bool => str_starts_with($line, 'OK:')));
        $errors = array_values(array_filter($results, fn (string $line): bool => str_starts_with($line, 'ERROR:')));

        $this->assertCount(1, $ok);
        $this->assertCount(1, $errors);
        $this->assertStringContainsString(DispoOrderConflictException::class, $errors[0]);

        $order->refresh();
        $this->assertContains($order->status, [
            DispoOrderStatus::InProgress,
            DispoOrderStatus::Cancelled,
        ]);
        $this->assertSame($lockBefore + 1, $order->lock_version);
        $this->assertSame(
            $eventsBefore + 1,
            DispoOrderStatusEvent::query()->where('dispo_order_id', $order->id)->count(),
        );
    }

    public function test_mysql_parallel_cancel_exactly_one_winner(): void
    {
        if (DB::connection()->getDriverName() !== 'mysql') {
            $this->markTestSkipped('Paralleler Cancel-Test erfordert MySQL (GitHub-Job mysql).');
        }

        ['order' => $order, 'disposition' => $disposition, 'admin' => $admin] = $this->completedOrder();
        $lockBefore = $order->lock_version;
        $auditsBefore = AuditEvent::query()
            ->where('action', 'dispo_order.cancelled')
            ->where('auditable_id', $order->id)
            ->count();

        $results = $this->runCancelWorkers(
            (string) $order->id,
            (string) $disposition->id,
            (string) $lockBefore,
            (string) $admin->id,
            (string) $lockBefore,
        );

        $ok = array_values(array_filter($results, fn (string $line): bool => str_starts_with($line, 'OK:')));
        $errors = array_values(array_filter($results, fn (string $line): bool => str_starts_with($line, 'ERROR:')));

        $this->assertCount(1, $ok);
        $this->assertCount(1, $errors);
        $this->assertStringContainsString(DispoOrderConflictException::class, $errors[0]);

        $order->refresh();
        $this->assertSame(DispoOrderStatus::Cancelled, $order->status);
        $this->assertSame($lockBefore + 1, $order->lock_version);
        $this->assertSame(
            1,
            DispoOrderStatusEvent::query()
                ->where('dispo_order_id', $order->id)
                ->where('to_status', DispoOrderStatus::Cancelled->value)
                ->count(),
        );
        $this->assertSame(
            $auditsBefore + 1,
            AuditEvent::query()
                ->where('action', 'dispo_order.cancelled')
                ->where('auditable_id', $order->id)
                ->count(),
        );
    }

    /**
     * @return array{order: DispoOrder, admin: User, disposition: User}
     */
    private function completedOrder(): array
    {
        $catalog = $this->createSpotClassicCatalog();
        $creator = User::factory()->role(Role::Sales)->create();
        $approver = User::factory()->role(Role::Sales)->create();
        $disposition = User::factory()->role(Role::Disposition)->create();
        $admin = User::factory()->role(Role::Admin)->create();

        $fpHeader = (string) app(ConfigurationSnapshotFreezeService::class)
            ->resolveLiveSchemaForCalculationV3()['schema_fingerprint'];
        $fpPos = (string) app(ConfigurationSnapshotFreezeService::class)
            ->resolveLivePositionSchema((int) $catalog['medium']->id)['schema_fingerprint'];

        $calculation = app(CalculationWriter::class)->create([
            'planning_mode' => 'manual',
            'customer_name' => 'Reopen Cancel Concurrency',
            'order_discount_percent' => '0',
            'schema_fingerprint' => $fpHeader,
            'dynamic_field_values' => ['campaign_period' => null],
            'positions' => [[
                'inventory_id' => $catalog['hamburg']->id,
                'advertising_medium_id' => $catalog['medium']->id,
                'schema_fingerprint' => $fpPos,
                'spot_method' => 'average',
                'length_seconds' => 30,
                'total_spot_count' => 10,
                'position_discount_percent' => '0',
                'ae_percent' => '0',
                'plan_rows' => [['hour' => 8, 'day_group' => 'mo_fr']],
                'time_ranges' => [[
                    'start_hour' => 8,
                    'end_hour_exclusive' => 9,
                    'day_group' => 'mo_fr',
                    'spot_count' => 10,
                ]],
                'dynamic_field_values' => [
                    'period_open' => true,
                    'position_flight_period' => null,
                ],
            ]],
        ], $creator);

        $this->actingAs($creator)->post(route('dispo-orders.store', $calculation), [
            'position_ids' => [$calculation->positions()->first()->id],
        ])->assertRedirect();

        $order = DispoOrder::query()->latest('id')->firstOrFail();
        $order = $this->seedCustomerConfirmationException($order, $creator);
        $approvals = app(DispoOrderApprovalService::class);
        $submitted = $approvals->submit($order, $creator, $order->lock_version);
        $order = $approvals->approve($submitted, $approver, $submitted->lock_version, null, true);

        $ops = app(DispoOrderOperationalStatusService::class);
        $order = $ops->transition($order, $disposition, $order->lock_version, DispoOrderStatus::InProgress);
        $position = $order->positions()->firstOrFail();
        $order = app(DispoOrderInvoiceEndService::class)->update(
            $order,
            $position,
            $disposition,
            $order->lock_version,
            [3],
        );
        $order = $ops->transition($order, $disposition, $order->lock_version, DispoOrderStatus::Disposed);
        $order = app(DispoOrderCompletionService::class)->complete(
            $order,
            $disposition,
            $order->lock_version,
        );

        return [
            'order' => $order->fresh(),
            'admin' => $admin,
            'disposition' => $disposition,
        ];
    }

    /**
     * @return list<string>
     */
    private function runMixedWorkers(
        string $orderId,
        string $adminId,
        string $lockA,
        string $dispositionId,
        string $lockB,
    ): array {
        $runDir = storage_path('framework/testing/concurrency-'.Str::uuid());
        if (! mkdir($runDir, 0700, true) && ! is_dir($runDir)) {
            $this->fail('Run directory could not be created.');
        }

        $reopenWorker = base_path('tests/concurrency/dispo_order_completed_reopen_worker.php');
        $cancelWorker = base_path('tests/concurrency/dispo_order_cancellation_worker.php');
        $php = PHP_BINARY;

        $processA = new Process([
            $php, $reopenWorker, $runDir, '0', $orderId, $adminId, $lockA, 'Parallel Reopen',
        ], base_path());
        $processB = new Process([
            $php, $cancelWorker, $runDir, '1', $orderId, $dispositionId, $lockB, 'Parallel Cancel',
        ], base_path());

        $processA->start();
        $processB->start();
        $processA->wait();
        $processB->wait();

        $results = [];
        foreach ([0, 1] as $id) {
            $file = $runDir.'/worker-'.$id.'.result';
            $this->assertFileExists($file);
            $results[] = trim((string) file_get_contents($file));
        }

        return $results;
    }

    /**
     * @return list<string>
     */
    private function runCancelWorkers(
        string $orderId,
        string $userIdA,
        string $lockA,
        string $userIdB,
        string $lockB,
    ): array {
        $runDir = storage_path('framework/testing/concurrency-'.Str::uuid());
        if (! mkdir($runDir, 0700, true) && ! is_dir($runDir)) {
            $this->fail('Run directory could not be created.');
        }

        $worker = base_path('tests/concurrency/dispo_order_cancellation_worker.php');
        $php = PHP_BINARY;

        $processA = new Process([
            $php, $worker, $runDir, '0', $orderId, $userIdA, $lockA, 'Cancel A',
        ], base_path());
        $processB = new Process([
            $php, $worker, $runDir, '1', $orderId, $userIdB, $lockB, 'Cancel B',
        ], base_path());

        $processA->start();
        $processB->start();
        $processA->wait();
        $processB->wait();

        $results = [];
        foreach ([0, 1] as $id) {
            $file = $runDir.'/worker-'.$id.'.result';
            $this->assertFileExists($file);
            $results[] = trim((string) file_get_contents($file));
        }

        return $results;
    }
}
