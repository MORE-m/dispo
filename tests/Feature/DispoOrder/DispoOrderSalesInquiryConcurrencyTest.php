<?php

namespace Tests\Feature\DispoOrder;

use App\Enums\DispoOrderCommentType;
use App\Enums\DispoOrderStatus;
use App\Enums\Role;
use App\Exceptions\DispoOrderConflictException;
use App\Models\DispoOrder;
use App\Models\DispoOrderComment;
use App\Models\DispoOrderStatusEvent;
use App\Models\User;
use App\Services\DispoOrder\DispoOrderApprovalService;
use App\Services\DispoOrder\DispoOrderOperationalStatusService;
use App\Services\DispoOrder\DispoOrderSalesInquiryService;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use Tests\Concerns\CreatesSavedCalculation;
use Tests\Concerns\CreatesSpotClassicCatalog;
use Tests\Concerns\EnsuresCustomerConfirmationException;
use Tests\TestCase;

class DispoOrderSalesInquiryConcurrencyTest extends TestCase
{
    use CreatesSavedCalculation;
    use CreatesSpotClassicCatalog;
    use DatabaseMigrations;
    use EnsuresCustomerConfirmationException;

    public function test_mysql_parallel_asks_yield_exactly_one_winner(): void
    {
        if (DB::connection()->getDriverName() !== 'mysql') {
            $this->markTestSkipped('Paralleler Ask-Test erfordert MySQL (GitHub-Job mysql).');
        }

        ['order' => $order, 'actorA' => $actorA, 'actorB' => $actorB] = $this->orderInProgressWithTwoDisposition();

        $results = $this->runParallelWorkers(
            'ask',
            (string) $order->id,
            (string) $actorA->id,
            (string) $order->lock_version,
            'Frage A',
            '0',
            (string) $actorB->id,
            (string) $order->lock_version,
            'Frage B',
            '0',
        );

        $ok = array_values(array_filter($results, fn (string $line): bool => str_starts_with($line, 'OK:')));
        $errors = array_values(array_filter($results, fn (string $line): bool => str_starts_with($line, 'ERROR:')));

        $this->assertCount(1, $ok);
        $this->assertCount(1, $errors);
        $this->assertStringContainsString(DispoOrderConflictException::class, $errors[0]);

        $order->refresh();
        $this->assertSame(DispoOrderStatus::SalesInquiry, $order->status);
        $this->assertSame(1, DispoOrderComment::query()
            ->where('dispo_order_id', $order->id)
            ->where('type', DispoOrderCommentType::SalesInquiry)
            ->count());
        $this->assertSame(1, DispoOrderStatusEvent::query()
            ->where('dispo_order_id', $order->id)
            ->where('to_status', DispoOrderStatus::SalesInquiry->value)
            ->count());
    }

    public function test_mysql_parallel_answers_yield_exactly_one_winner(): void
    {
        if (DB::connection()->getDriverName() !== 'mysql') {
            $this->markTestSkipped('Paralleler Answer-Test erfordert MySQL (GitHub-Job mysql).');
        }

        ['order' => $order, 'disposition' => $disposition] = $this->orderInProgressWithTwoDisposition();
        $asked = app(DispoOrderSalesInquiryService::class)->ask(
            $order,
            $disposition,
            $order->lock_version,
            'Parallele Antwort?',
        );
        $inquiry = DispoOrderComment::query()
            ->where('dispo_order_id', $asked->id)
            ->firstOrFail();

        $salesA = User::factory()->role(Role::Sales)->create();
        $salesB = User::factory()->role(Role::Sales)->create();

        $results = $this->runParallelWorkers(
            'answer',
            (string) $asked->id,
            (string) $salesA->id,
            (string) $asked->lock_version,
            'Antwort A',
            (string) $inquiry->id,
            (string) $salesB->id,
            (string) $asked->lock_version,
            'Antwort B',
            (string) $inquiry->id,
        );

        $ok = array_values(array_filter($results, fn (string $line): bool => str_starts_with($line, 'OK:')));
        $errors = array_values(array_filter($results, fn (string $line): bool => str_starts_with($line, 'ERROR:')));

        $this->assertCount(1, $ok);
        $this->assertCount(1, $errors);
        $this->assertTrue(
            str_contains($errors[0], DispoOrderConflictException::class)
            || str_contains($errors[0], 'Unique')
            || str_contains($errors[0], 'Integrity'),
            'Expected conflict or unique violation, got: '.$errors[0],
        );

        $asked->refresh();
        $this->assertSame(DispoOrderStatus::AtDisposition, $asked->status);
        $this->assertSame(1, DispoOrderComment::query()
            ->where('dispo_order_id', $asked->id)
            ->where('type', DispoOrderCommentType::SalesInquiryResponse)
            ->count());
    }

    /**
     * @return array{order: DispoOrder, disposition: User, actorA: User, actorB: User}
     */
    private function orderInProgressWithTwoDisposition(): array
    {
        $creator = User::factory()->role(Role::Sales)->create();
        $approver = User::factory()->role(Role::Sales)->create();
        $disposition = User::factory()->role(Role::Disposition)->create();
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
        $order = $this->seedCustomerConfirmationException($order, $creator);
        $submitted = $approvals->submit($order, $creator, $order->lock_version);
        $approved = $approvals->approve($submitted, $approver, $submitted->lock_version, null, true);
        $inProgress = app(DispoOrderOperationalStatusService::class)->transition(
            $approved,
            $disposition,
            $approved->lock_version,
            DispoOrderStatus::InProgress,
        );

        return [
            'order' => $inProgress,
            'disposition' => $disposition,
            'actorA' => $actorA,
            'actorB' => $actorB,
        ];
    }

    /**
     * @return list<string>
     */
    private function runParallelWorkers(
        string $mode,
        string $orderId,
        string $userIdA,
        string $lockA,
        string $textA,
        string $inquiryA,
        string $userIdB,
        string $lockB,
        string $textB,
        string $inquiryB,
    ): array {
        $runDir = storage_path('framework/testing/concurrency-'.Str::uuid());
        if (! mkdir($runDir, 0700, true) && ! is_dir($runDir)) {
            $this->fail('Run directory could not be created.');
        }

        $worker = base_path('tests/concurrency/dispo_order_sales_inquiry_worker.php');
        $php = PHP_BINARY;

        $processA = new Process([
            $php, $worker, $runDir, '0', $mode, $orderId, $userIdA, $lockA, $textA, $inquiryA,
        ], base_path());
        $processB = new Process([
            $php, $worker, $runDir, '1', $mode, $orderId, $userIdB, $lockB, $textB, $inquiryB,
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
