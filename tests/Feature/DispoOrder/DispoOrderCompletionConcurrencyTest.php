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
use App\Services\DispoOrder\DispoOrderOperationalStatusService;
use App\Services\DynamicField\ConfigurationSnapshotFreezeService;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use Tests\Concerns\CreatesSpotClassicCatalog;
use Tests\Concerns\EnsuresCustomerConfirmationException;
use Tests\TestCase;

class DispoOrderCompletionConcurrencyTest extends TestCase
{
    use CreatesSpotClassicCatalog;
    use DatabaseMigrations;
    use EnsuresCustomerConfirmationException;

    public function test_mysql_parallel_completion_yields_exactly_one_winner(): void
    {
        if (DB::connection()->getDriverName() !== 'mysql') {
            $this->markTestSkipped('Paralleler Completion-Test erfordert MySQL (GitHub-Job mysql).');
        }

        $catalog = $this->createSpotClassicCatalog();
        $creator = User::factory()->role(Role::Sales)->create();
        $approver = User::factory()->role(Role::Sales)->create();
        $actorA = User::factory()->role(Role::Disposition)->create();
        $actorB = User::factory()->role(Role::Disposition)->create();

        $fpHeader = (string) app(ConfigurationSnapshotFreezeService::class)
            ->resolveLiveSchemaForCalculationV3()['schema_fingerprint'];
        $fpPos = (string) app(ConfigurationSnapshotFreezeService::class)
            ->resolveLivePositionSchema((int) $catalog['medium']->id)['schema_fingerprint'];

        $calculation = app(CalculationWriter::class)->create([
            'planning_mode' => 'manual',
            'customer_name' => 'Completion Concurrency',
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
        $order = $ops->transition($order, $actorA, $order->lock_version, DispoOrderStatus::InProgress);
        $order = $ops->transition($order, $actorA, $order->lock_version, DispoOrderStatus::Disposed);

        $lockBefore = $order->lock_version;
        $statusEventsBefore = DispoOrderStatusEvent::query()
            ->where('dispo_order_id', $order->id)
            ->count();
        $auditsBefore = AuditEvent::query()
            ->where('action', 'dispo_order.completed')
            ->where('auditable_id', $order->id)
            ->count();

        $results = $this->runParallelWorkers(
            (string) $order->id,
            (string) $actorA->id,
            (string) $lockBefore,
            (string) $actorB->id,
            (string) $lockBefore,
        );

        $ok = array_values(array_filter($results, fn (string $line): bool => str_starts_with($line, 'OK:')));
        $errors = array_values(array_filter($results, fn (string $line): bool => str_starts_with($line, 'ERROR:')));

        $this->assertCount(1, $ok);
        $this->assertCount(1, $errors);
        $this->assertStringContainsString(DispoOrderConflictException::class, $errors[0]);

        $order->refresh();
        $this->assertSame(DispoOrderStatus::Completed, $order->status);
        $this->assertSame($lockBefore + 1, $order->lock_version);
        $this->assertSame(
            $statusEventsBefore + 1,
            DispoOrderStatusEvent::query()->where('dispo_order_id', $order->id)->count(),
        );
        $this->assertSame(
            1,
            DispoOrderStatusEvent::query()
                ->where('dispo_order_id', $order->id)
                ->where('to_status', DispoOrderStatus::Completed->value)
                ->count(),
        );
        $this->assertSame(
            $auditsBefore + 1,
            AuditEvent::query()
                ->where('action', 'dispo_order.completed')
                ->where('auditable_id', $order->id)
                ->count(),
        );
    }

    /**
     * @return list<string>
     */
    private function runParallelWorkers(
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

        $worker = base_path('tests/concurrency/dispo_order_completion_worker.php');
        $php = PHP_BINARY;

        $processA = new Process([
            $php, $worker, $runDir, '0', $orderId, $userIdA, $lockA, '',
        ], base_path());
        $processB = new Process([
            $php, $worker, $runDir, '1', $orderId, $userIdB, $lockB, '',
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
