<?php

namespace Tests\Feature\DispoOrder;

use App\Enums\DispoOrderApprovalStatus;
use App\Enums\DispoOrderStatus;
use App\Enums\Role;
use App\Exceptions\DispoOrderConflictException;
use App\Models\DispoOrder;
use App\Models\DispoOrderApprovalRequest;
use App\Models\User;
use App\Services\DispoOrder\DispoOrderApprovalService;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use Tests\Concerns\CreatesSavedCalculation;
use Tests\Concerns\CreatesSpotClassicCatalog;
use Tests\TestCase;

class DispoOrderApprovalConcurrencyTest extends TestCase
{
    use CreatesSavedCalculation;
    use CreatesSpotClassicCatalog;
    use DatabaseMigrations;

    public function test_mysql_parallel_approvals_yield_exactly_one_winner(): void
    {
        if (DB::connection()->getDriverName() !== 'mysql') {
            $this->markTestSkipped('Paralleler Freigabe-Test erfordert MySQL (GitHub-Job mysql).');
        }

        $creator = User::factory()->role(Role::Sales)->create();
        $approverA = User::factory()->role(Role::Sales)->create();
        $approverB = User::factory()->role(Role::Sales)->create();

        $catalog = $this->createSpotClassicCatalog();
        $calculation = $this->createSavedCalculation($catalog, [
            ['inventory_id' => $catalog['hamburg']->id],
        ], $creator);

        $this->actingAs($creator)->post(route('dispo-orders.store', $calculation), [
            'position_ids' => [$calculation->positions()->first()->id],
        ])->assertRedirect();

        $order = DispoOrder::query()->firstOrFail();
        $service = app(DispoOrderApprovalService::class);
        $submitted = $service->submit($order, $creator, $order->lock_version);

        $results = $this->runParallelWorkers(
            (string) $submitted->id,
            (string) $approverA->id,
            'approve',
            (string) $submitted->lock_version,
            (string) $approverB->id,
            'approve',
            (string) $submitted->lock_version,
        );

        $ok = array_values(array_filter($results, fn (string $line): bool => str_starts_with($line, 'OK:')));
        $errors = array_values(array_filter($results, fn (string $line): bool => str_starts_with($line, 'ERROR:')));

        $this->assertCount(1, $ok);
        $this->assertCount(1, $errors);
        $this->assertStringContainsString(DispoOrderConflictException::class, $errors[0]);

        $order->refresh();
        $this->assertSame(DispoOrderStatus::AtDisposition, $order->status);
        $this->assertSame(1, DispoOrderApprovalRequest::query()
            ->where('dispo_order_id', $order->id)
            ->where('status', DispoOrderApprovalStatus::Approved->value)
            ->count());
        $this->assertSame(0, DispoOrderApprovalRequest::query()
            ->where('dispo_order_id', $order->id)
            ->where('status', DispoOrderApprovalStatus::Pending->value)
            ->count());
    }

    public function test_mysql_approve_versus_reject_yields_one_final_decision(): void
    {
        if (DB::connection()->getDriverName() !== 'mysql') {
            $this->markTestSkipped('Paralleler Freigabe-Test erfordert MySQL (GitHub-Job mysql).');
        }

        $creator = User::factory()->role(Role::Sales)->create();
        $approver = User::factory()->role(Role::Sales)->create();
        $rejector = User::factory()->role(Role::Sales)->create();

        $catalog = $this->createSpotClassicCatalog();
        $calculation = $this->createSavedCalculation($catalog, [
            ['inventory_id' => $catalog['hamburg']->id],
        ], $creator);

        $this->actingAs($creator)->post(route('dispo-orders.store', $calculation), [
            'position_ids' => [$calculation->positions()->first()->id],
        ])->assertRedirect();

        $order = DispoOrder::query()->firstOrFail();
        $service = app(DispoOrderApprovalService::class);
        $submitted = $service->submit($order, $creator, $order->lock_version);

        $results = $this->runParallelWorkers(
            (string) $submitted->id,
            (string) $approver->id,
            'approve',
            (string) $submitted->lock_version,
            (string) $rejector->id,
            'reject',
            (string) $submitted->lock_version,
        );

        $ok = array_values(array_filter($results, fn (string $line): bool => str_starts_with($line, 'OK:')));
        $errors = array_values(array_filter($results, fn (string $line): bool => str_starts_with($line, 'ERROR:')));

        $this->assertCount(1, $ok);
        $this->assertCount(1, $errors);

        $order->refresh();
        $this->assertContains($order->status, [
            DispoOrderStatus::AtDisposition,
            DispoOrderStatus::ApprovalRejected,
        ]);
        $this->assertSame(1, DispoOrderApprovalRequest::query()
            ->where('dispo_order_id', $order->id)
            ->whereIn('status', [
                DispoOrderApprovalStatus::Approved->value,
                DispoOrderApprovalStatus::Rejected->value,
            ])
            ->count());
    }

    public function test_mysql_parallel_submit_yields_exactly_one_pending_request(): void
    {
        if (DB::connection()->getDriverName() !== 'mysql') {
            $this->markTestSkipped('Paralleler Freigabe-Test erfordert MySQL (GitHub-Job mysql).');
        }

        $creator = User::factory()->role(Role::Sales)->create();
        $catalog = $this->createSpotClassicCatalog();
        $calculation = $this->createSavedCalculation($catalog, [
            ['inventory_id' => $catalog['hamburg']->id],
        ], $creator);

        $this->actingAs($creator)->post(route('dispo-orders.store', $calculation), [
            'position_ids' => [$calculation->positions()->first()->id],
        ])->assertRedirect();

        $order = DispoOrder::query()->firstOrFail();

        $results = $this->runParallelWorkers(
            (string) $order->id,
            (string) $creator->id,
            'submit',
            (string) $order->lock_version,
            (string) $creator->id,
            'submit',
            (string) $order->lock_version,
        );

        $ok = array_values(array_filter($results, fn (string $line): bool => str_starts_with($line, 'OK:')));
        $errors = array_values(array_filter($results, fn (string $line): bool => str_starts_with($line, 'ERROR:')));

        $this->assertCount(1, $ok);
        $this->assertCount(1, $errors);

        $order->refresh();
        $this->assertSame(DispoOrderStatus::AwaitingSalesApproval, $order->status);
        $this->assertSame(1, DispoOrderApprovalRequest::query()
            ->where('dispo_order_id', $order->id)
            ->count());
    }

    private function runParallelWorkers(
        string $orderId,
        string $userIdA,
        string $actionA,
        string $lockA,
        string $userIdB,
        string $actionB,
        string $lockB,
    ): array {
        $runDir = sys_get_temp_dir().'/dispo-order-approval-concurrency-'.Str::uuid();
        if (! mkdir($runDir, 0700, true) && ! is_dir($runDir)) {
            $this->fail('Temporäres Barrier-Verzeichnis konnte nicht erstellt werden.');
        }

        try {
            $script = base_path('tests/concurrency/dispo_order_approval_worker.php');
            $env = $this->workerEnvironment();

            $worker0 = new Process(
                [PHP_BINARY, $script, $runDir, '0', $orderId, $userIdA, $actionA, $lockA],
                null,
                $env,
            );
            $worker1 = new Process(
                [PHP_BINARY, $script, $runDir, '1', $orderId, $userIdB, $actionB, $lockB],
                null,
                $env,
            );

            $worker0->start();
            $worker1->start();
            $worker0->wait();
            $worker1->wait();

            $results = [];
            foreach (glob($runDir.'/worker-*.result') ?: [] as $resultFile) {
                $results[] = trim((string) file_get_contents($resultFile));
            }

            $this->assertCount(2, $results);

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
