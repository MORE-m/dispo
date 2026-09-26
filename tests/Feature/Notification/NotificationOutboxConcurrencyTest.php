<?php

namespace Tests\Feature\Notification;

use App\Models\NotificationOutbox;
use App\Services\Notification\NotificationOutboxWriter;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use Tests\Support\NotificationOutboxTestFactory;
use Tests\TestCase;

class NotificationOutboxConcurrencyTest extends TestCase
{
    use DatabaseMigrations;

    public function test_mysql_parallel_enqueue_yields_exactly_one_row(): void
    {
        if (DB::connection()->getDriverName() !== 'mysql') {
            $this->markTestSkipped('Paralleler Outbox-Writer erfordert MySQL (GitHub-Job mysql).');
        }

        $intent = NotificationOutboxTestFactory::intent([
            'eventType' => 'concurrency.event',
            'sourceType' => 'concurrency_source',
            'sourceId' => 777,
            'recipientUserId' => 55,
            'recipientEmail' => 'race@example.test',
            'recipientName' => 'Race Empfänger',
        ]);

        $payload = json_encode([
            'eventType' => $intent->eventType,
            'sourceType' => $intent->sourceType,
            'sourceId' => $intent->sourceId,
            'channel' => $intent->channel->value,
            'recipientUserId' => $intent->recipientUserId,
            'recipientEmail' => $intent->recipientEmail,
            'recipientName' => $intent->recipientName,
            'payload' => $intent->payload,
        ], JSON_THROW_ON_ERROR);

        $results = $this->runParallelWorkers($payload);

        $ok = array_values(array_filter($results, fn (string $line): bool => str_starts_with($line, 'OK:')));
        $this->assertCount(2, $ok, 'Beide Writer sollen ohne Exception enden. Got: '.implode(' | ', $results));

        $ids = array_map(
            static fn (string $line): int => (int) explode(':', $line, 2)[1],
            $ok,
        );
        $this->assertSame($ids[0], $ids[1]);
        $this->assertSame(1, NotificationOutbox::query()->count());
        $this->assertSame(
            $intent->resolvedIdempotencyKey(),
            NotificationOutbox::query()->firstOrFail()->idempotency_key,
        );
    }

    public function test_mysql_parallel_claim_pending_yields_one_winner(): void
    {
        if (DB::connection()->getDriverName() !== 'mysql') {
            $this->markTestSkipped('Paralleler Claim erfordert MySQL (GitHub-Job mysql).');
        }

        $row = app(NotificationOutboxWriter::class)->enqueue(
            NotificationOutboxTestFactory::intent(['sourceId' => 888]),
        );

        $results = $this->runParallelClaimWorkers((string) $row->id);

        $ok = array_values(array_filter($results, fn (string $line): bool => str_starts_with($line, 'OK:')));
        $errors = array_values(array_filter($results, fn (string $line): bool => str_starts_with($line, 'ERROR:')));

        $this->assertCount(1, $ok);
        $this->assertCount(1, $errors);

        $row->refresh();
        $this->assertSame('queued', $row->status->value);
    }

    /**
     * @return list<string>
     */
    private function runParallelWorkers(string $payloadJson): array
    {
        $runDir = storage_path('framework/testing/concurrency-'.Str::uuid());
        if (! mkdir($runDir, 0700, true) && ! is_dir($runDir)) {
            $this->fail('Run directory could not be created.');
        }

        $worker = base_path('tests/concurrency/notification_outbox_writer_worker.php');
        $php = PHP_BINARY;

        $processA = new Process([$php, $worker, $runDir, '0', $payloadJson], base_path());
        $processB = new Process([$php, $worker, $runDir, '1', $payloadJson], base_path());

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
    private function runParallelClaimWorkers(string $outboxId): array
    {
        $runDir = storage_path('framework/testing/concurrency-'.Str::uuid());
        if (! mkdir($runDir, 0700, true) && ! is_dir($runDir)) {
            $this->fail('Run directory could not be created.');
        }

        $worker = base_path('tests/concurrency/notification_outbox_claim_worker.php');
        $php = PHP_BINARY;

        $processA = new Process([$php, $worker, $runDir, '0', $outboxId], base_path());
        $processB = new Process([$php, $worker, $runDir, '1', $outboxId], base_path());

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
