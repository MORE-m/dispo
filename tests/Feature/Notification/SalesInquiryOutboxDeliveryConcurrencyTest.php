<?php

namespace Tests\Feature\Notification;

use App\Enums\NotificationOutboxStatus;
use App\Jobs\DeliverSalesInquiryOutboxJob;
use App\Models\NotificationOutbox;
use App\Services\DispoOrder\DispoOrderSalesInquiryService;
use App\Services\Notification\NotificationOutboxWriter;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use Tests\Support\NotificationOutboxTestFactory;
use Tests\TestCase;

/**
 * Parallele MySQL-Absicherung für Ask/Answer-Dispatch (BL-P9-02c).
 *
 * Zwei Prozesse rufen synchronisiert `dispatchDue()` auf derselben fälligen
 * `pending`-Zeile auf (Ready-Barrier wie übrige Outbox-Concurrency-Worker).
 */
class SalesInquiryOutboxDeliveryConcurrencyTest extends TestCase
{
    use DatabaseMigrations;

    public function test_mysql_parallel_dispatch_due_claims_exactly_once(): void
    {
        if (DB::connection()->getDriverName() !== 'mysql') {
            $this->markTestSkipped('Paralleler Outbox-Dispatch erfordert MySQL (GitHub-Job mysql).');
        }

        $row = app(NotificationOutboxWriter::class)->enqueue(
            NotificationOutboxTestFactory::intent([
                'eventType' => DispoOrderSalesInquiryService::EVENT_ASKED,
                'sourceType' => DispoOrderSalesInquiryService::SOURCE_TYPE_COMMENT,
                'sourceId' => 9001,
                'recipientEmail' => 'race-advisor@example.test',
                'recipientName' => 'Race Advisor',
            ]),
        );

        $this->assertSame(NotificationOutboxStatus::Pending, $row->status);
        $this->assertSame(0, $this->countDeliveryJobsForOutbox($row->id));

        $results = $this->runParallelDispatchWorkers((string) $row->id);

        $ok = array_values(array_filter($results, fn (string $line): bool => str_starts_with($line, 'OK:')));
        $errors = array_values(array_filter($results, fn (string $line): bool => str_starts_with($line, 'ERROR:')));

        $this->assertSame([], $errors, 'Unerwartete Worker-Fehler: '.implode(' | ', $results));
        $this->assertCount(2, $ok, 'Beide Dispatch-Worker müssen enden. Got: '.implode(' | ', $results));

        $dispatchedCounts = array_map(
            static function (string $line): int {
                $parts = explode(':', $line);

                // OK:<dispatched>:<outboxId>
                return (int) ($parts[1] ?? -1);
            },
            $ok,
        );

        $this->assertSame(
            1,
            array_sum($dispatchedCounts),
            'Genau ein Worker darf dispatchen (Summe dispatched). Got: '.implode(',', $dispatchedCounts),
        );
        $this->assertContains(1, $dispatchedCounts);
        $this->assertContains(0, $dispatchedCounts);

        $row->refresh();
        $this->assertSame(NotificationOutboxStatus::Queued, $row->status);
        $this->assertSame(1, NotificationOutbox::query()->count());

        $jobCount = $this->countDeliveryJobsForOutbox($row->id);
        if ($jobCount !== 1) {
            $payloads = DB::table('jobs')->pluck('payload')->map(
                static fn ($payload): string => Str::limit((string) $payload, 400),
            )->all();
            $this->fail(
                'Erwartet genau 1 Delivery-Job, got '.$jobCount
                .'. Worker: '.implode(' | ', $results)
                .'. Jobs: '.json_encode($payloads, JSON_UNESCAPED_UNICODE),
            );
        }
    }

    private function countDeliveryJobsForOutbox(int $outboxId): int
    {
        $jobs = DB::table('jobs')->get(['id', 'payload', 'queue']);
        $needleClass = str_replace('\\', '\\\\', DeliverSalesInquiryOutboxJob::class);
        $count = 0;

        foreach ($jobs as $job) {
            $payloadString = (string) $job->payload;
            if (! str_contains($payloadString, 'DeliverSalesInquiryOutboxJob')) {
                continue;
            }

            // PHP-serialize: s:8:"outboxId";i:123;  oder JSON-ähnlich
            if (preg_match('/outboxId";i:'.$outboxId.';/', $payloadString) === 1
                || preg_match('/"outboxId"\s*:\s*'.$outboxId.'\b/', $payloadString) === 1
                || preg_match('/outboxId[^0-9]{0,20}'.$outboxId.'\b/', $payloadString) === 1) {
                $count++;
            }
        }

        return $count;
    }

    /**
     * @return array<string, string|null>
     */
    private function workerProcessEnv(): array
    {
        $env = [];
        foreach ([
            'APP_KEY', 'APP_ENV', 'DB_CONNECTION', 'DB_HOST', 'DB_PORT',
            'DB_DATABASE', 'DB_USERNAME', 'DB_PASSWORD', 'DB_SOCKET', 'DB_URL',
            'QUEUE_CONNECTION', 'CACHE_STORE', 'MAIL_MAILER',
        ] as $var) {
            $value = getenv($var);
            if ($value !== false) {
                $env[$var] = $value;
            }
        }

        $env['APP_ENV'] = $env['APP_ENV'] ?? 'testing';
        $env['DB_CONNECTION'] = $env['DB_CONNECTION'] ?? 'mysql';
        $env['DB_DATABASE'] = $env['DB_DATABASE'] ?? 'dispo_test';
        $env['QUEUE_CONNECTION'] = 'database';
        $env['MAIL_MAILER'] = $env['MAIL_MAILER'] ?? 'array';
        $env['CACHE_STORE'] = $env['CACHE_STORE'] ?? 'database';

        return $env;
    }

    /**
     * @return list<string>
     */
    private function runParallelDispatchWorkers(string $outboxId): array
    {
        $runDir = storage_path('framework/testing/concurrency-'.Str::uuid());
        if (! mkdir($runDir, 0700, true) && ! is_dir($runDir)) {
            $this->fail('Run directory could not be created.');
        }

        $worker = base_path('tests/concurrency/sales_inquiry_outbox_dispatch_worker.php');
        $php = PHP_BINARY;
        $env = $this->workerProcessEnv();

        $processA = new Process([$php, $worker, $runDir, '0', $outboxId], base_path(), $env);
        $processB = new Process([$php, $worker, $runDir, '1', $outboxId], base_path(), $env);

        $processA->start();
        $processB->start();
        $processA->wait();
        $processB->wait();

        $results = [];
        foreach ([0, 1] as $id) {
            $file = $runDir.'/worker-'.$id.'.result';
            $this->assertFileExists(
                $file,
                'Worker '.$id.' result fehlt. stderrA='.$processA->getErrorOutput()
                .' stderrB='.$processB->getErrorOutput()
                .' stdoutA='.$processA->getOutput()
                .' stdoutB='.$processB->getOutput(),
            );
            $results[] = trim((string) file_get_contents($file));
        }

        return $results;
    }
}
