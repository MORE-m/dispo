<?php

namespace Tests\Feature\DispoOrder;

use App\Enums\DispoOrderApprovalStatus;
use App\Enums\DispoOrderStatus;
use App\Enums\DispoOrderUploadCategory;
use App\Enums\Role;
use App\Exceptions\DispoOrderConflictException;
use App\Models\DispoOrder;
use App\Models\DispoOrderApprovalRequest;
use App\Models\DispoOrderUpload;
use App\Models\User;
use App\Services\DispoOrder\DispoOrderApprovalService;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\Process\Process;
use Tests\Concerns\CreatesSavedCalculation;
use Tests\Concerns\CreatesSpotClassicCatalog;
use Tests\TestCase;

/**
 * MySQL-Concurrency für PO-AT13-CC-1: Archiv vs. operative Statusaktion.
 */
class DispoOrderCustomerConfirmationApprovalInvalidationConcurrencyTest extends TestCase
{
    use CreatesSavedCalculation;
    use CreatesSpotClassicCatalog;
    use DatabaseMigrations;

    public function test_mysql_archive_versus_operational_transition_yields_one_winner(): void
    {
        if (DB::connection()->getDriverName() !== 'mysql') {
            $this->markTestSkipped('Paralleler CC-Archiv-Test erfordert MySQL (GitHub-Job mysql).');
        }

        Storage::fake((string) config('dispo.files_disk'));

        $creator = User::factory()->role(Role::Sales)->create();
        $approver = User::factory()->role(Role::Sales)->create();
        $admin = User::factory()->role(Role::Admin)->create();
        $disposition = User::factory()->role(Role::Disposition)->create();

        $catalog = $this->createSpotClassicCatalog();
        $calculation = $this->createSavedCalculation($catalog, [
            ['inventory_id' => $catalog['hamburg']->id],
        ], $creator);

        $this->actingAs($creator)->post(route('dispo-orders.store', $calculation), [
            'position_ids' => [$calculation->positions()->first()->id],
        ])->assertRedirect();

        $order = DispoOrder::query()->firstOrFail();
        $path = base_path('tests/fixtures/customer-confirmation-sample.pdf');
        $this->actingAs($creator)
            ->withHeader('Accept', 'application/json')
            ->post(route('dispo-orders.uploads.customer-confirmation', $order), [
                'lock_version' => $order->lock_version,
                'file' => new UploadedFile($path, 'bestaetigung.pdf', 'application/pdf', null, true),
            ])
            ->assertOk();

        $order->refresh();
        $upload = DispoOrderUpload::query()
            ->where('dispo_order_id', $order->id)
            ->where('category', DispoOrderUploadCategory::CustomerConfirmation->value)
            ->whereNull('archived_at')
            ->firstOrFail();

        $approvals = app(DispoOrderApprovalService::class);
        $submitted = $approvals->submit($order, $creator, $order->lock_version);
        $approved = $approvals->approve($submitted, $approver, $submitted->lock_version, null, true);
        $lock = $approved->lock_version;
        $approvalId = $approved->latestApprovalRequest?->id;
        $this->assertNotNull($approvalId);

        $results = $this->runParallelWorkers(
            (string) $approved->id,
            (string) $admin->id,
            'archive_cc',
            (string) $upload->id,
            (string) $lock,
            (string) $disposition->id,
            'transition',
            DispoOrderStatus::InProgress->value,
            (string) $lock,
        );

        $ok = array_values(array_filter($results, fn (string $line): bool => str_starts_with($line, 'OK:')));
        $errors = array_values(array_filter($results, fn (string $line): bool => str_starts_with($line, 'ERROR:')));

        $this->assertCount(1, $ok);
        $this->assertCount(1, $errors);
        $this->assertTrue(
            str_contains($errors[0], DispoOrderConflictException::class)
                || str_contains($errors[0], ValidationException::class),
            'Expected conflict or validation on loser, got: '.$errors[0],
        );

        $approved->refresh();
        $upload->refresh();
        $approval = DispoOrderApprovalRequest::query()->findOrFail($approvalId);
        $this->assertSame(DispoOrderApprovalStatus::Approved, $approval->status);

        if (str_starts_with($ok[0], 'OK:draft')) {
            $this->assertSame(DispoOrderStatus::Draft, $approved->status);
            $this->assertNotNull($upload->archived_at);
            $this->assertStringContainsString('|1', $ok[0]);
        } else {
            $this->assertSame(DispoOrderStatus::InProgress, $approved->status);
            $this->assertNull($upload->archived_at);
        }

        $this->assertSame(1, DispoOrderApprovalRequest::query()
            ->where('dispo_order_id', $approved->id)
            ->where('status', DispoOrderApprovalStatus::Approved->value)
            ->count());
    }

    /**
     * @return list<string>
     */
    private function runParallelWorkers(
        string $orderId,
        string $userIdA,
        string $actionA,
        string $payloadA,
        string $lockA,
        string $userIdB,
        string $actionB,
        string $payloadB,
        string $lockB,
    ): array {
        $runDir = storage_path('framework/testing/concurrency-'.Str::uuid());
        if (! mkdir($runDir, 0700, true) && ! is_dir($runDir)) {
            $this->fail('Run directory could not be created.');
        }

        $worker = base_path('tests/concurrency/dispo_order_cc_archive_invalidation_worker.php');
        $php = PHP_BINARY;

        $processA = new Process([
            $php, $worker, $runDir, '0', $orderId, $userIdA, $lockA, $actionA, $payloadA,
        ], base_path());
        $processB = new Process([
            $php, $worker, $runDir, '1', $orderId, $userIdB, $lockB, $actionB, $payloadB,
        ], base_path());

        $processA->start();
        $processB->start();
        $processA->wait();
        $processB->wait();

        $out = [];
        foreach ([0, 1] as $id) {
            $file = $runDir.'/worker-'.$id.'.result';
            $this->assertFileExists($file);
            $out[] = trim((string) file_get_contents($file));
        }

        return $out;
    }
}
