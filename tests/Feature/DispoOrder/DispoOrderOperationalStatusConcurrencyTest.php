<?php

namespace Tests\Feature\DispoOrder;

use App\Enums\DispoOrderStatus;
use App\Enums\Role;
use App\Exceptions\DispoOrderConflictException;
use App\Models\DispoOrder;
use App\Models\DispoOrderStatusEvent;
use App\Models\User;
use App\Services\DispoOrder\DispoOrderApprovalService;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use Tests\Concerns\CreatesSavedCalculation;
use Tests\Concerns\CreatesSpotClassicCatalog;
use Tests\TestCase;

class DispoOrderOperationalStatusConcurrencyTest extends TestCase
{
    use CreatesSavedCalculation;
    use CreatesSpotClassicCatalog;
    use DatabaseMigrations;

    public function test_mysql_parallel_status_transitions_yield_exactly_one_winner(): void
    {
        if (DB::connection()->getDriverName() !== 'mysql') {
            $this->markTestSkipped('Paralleler Status-Test erfordert MySQL (GitHub-Job mysql).');
        }

        $creator = User::factory()->role(Role::Sales)->create();
        $approver = User::factory()->role(Role::Sales)->create();
        $actorA = User::factory()->role(Role::Disposition)->create();
        $actorB = User::factory()->role(Role::Disposition)->create();

        $catalog = $this->createSpotClassicCatalog();
        $calculation = $this->createSavedCalculation($catalog, [
            ['inventory_id' => $catalog['hamburg']->id],
        ], $creator);

        $this->actingAs($creator)->post(route('dispo-orders.store', $calculation), [
            'position_ids' => [$calculation->positions()->first()->id],
        ])->assertRedirect();

        $order = DispoOrder::query()->firstOrFail();
        $approvals = app(DispoOrderApprovalService::class);
        $submitted = $approvals->submit($order, $creator, $order->lock_version);
        $approved = $approvals->approve($submitted, $approver, $submitted->lock_version);

        $results = $this->runParallelWorkers(
            (string) $approved->id,
            (string) $actorA->id,
            DispoOrderStatus::InProgress->value,
            (string) $approved->lock_version,
            (string) $actorB->id,
            DispoOrderStatus::InProgress->value,
            (string) $approved->lock_version,
        );

        $ok = array_values(array_filter($results, fn (string $line): bool => str_starts_with($line, 'OK:')));
        $errors = array_values(array_filter($results, fn (string $line): bool => str_starts_with($line, 'ERROR:')));

        $this->assertCount(1, $ok);
        $this->assertCount(1, $errors);
        $this->assertStringContainsString(DispoOrderConflictException::class, $errors[0]);

        $approved->refresh();
        $this->assertSame(DispoOrderStatus::InProgress, $approved->status);
        $this->assertSame(1, DispoOrderStatusEvent::query()
            ->where('dispo_order_id', $approved->id)
            ->count());
    }

    private function runParallelWorkers(
        string $orderId,
        string $userIdA,
        string $targetA,
        string $lockA,
        string $userIdB,
        string $targetB,
        string $lockB,
    ): array {
        $runDir = storage_path('framework/testing/concurrency-'.Str::uuid());
        if (! mkdir($runDir, 0700, true) && ! is_dir($runDir)) {
            $this->fail('Run directory could not be created.');
        }

        $worker = base_path('tests/concurrency/dispo_order_operational_status_worker.php');
        $php = PHP_BINARY;

        $processA = new Process([
            $php, $worker, $runDir, '0', $orderId, $userIdA, $targetA, $lockA,
        ], base_path());
        $processB = new Process([
            $php, $worker, $runDir, '1', $orderId, $userIdB, $targetB, $lockB,
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
