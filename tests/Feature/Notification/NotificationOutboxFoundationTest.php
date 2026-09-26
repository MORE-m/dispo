<?php

namespace Tests\Feature\Notification;

use App\Enums\NotificationOutboxStatus;
use App\Exceptions\NotificationOutboxConflictException;
use App\Models\NotificationOutbox;
use App\Models\User;
use App\Services\Notification\NotificationOutboxIdempotency;
use App\Services\Notification\NotificationOutboxIntent;
use App\Services\Notification\NotificationOutboxReclaimer;
use App\Services\Notification\NotificationOutboxStateMachine;
use App\Services\Notification\NotificationOutboxWriter;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use LogicException;
use RuntimeException;
use Tests\Support\MysqlTestDatabaseGuard;
use Tests\Support\NotificationOutboxTestFactory;
use Tests\TestCase;

class NotificationOutboxFoundationTest extends TestCase
{
    use RefreshDatabase;

    public function test_schema_and_snapshot_persist(): void
    {
        $this->assertSame(
            MysqlTestDatabaseGuard::FORBIDDEN_DEV_DATABASE,
            'dispo',
            'Guard-Konstante muss Dev-DB dispo schützen.',
        );
        $this->assertNotSame(
            MysqlTestDatabaseGuard::FORBIDDEN_DEV_DATABASE,
            (string) config('database.connections.'.config('database.default').'.database'),
        );

        $intent = NotificationOutboxTestFactory::intent();
        $row = app(NotificationOutboxWriter::class)->enqueue($intent);

        $this->assertDatabaseHas('notification_outbox', [
            'id' => $row->id,
            'idempotency_key' => $intent->resolvedIdempotencyKey(),
            'event_type' => 'test.event',
            'source_type' => 'test_source',
            'source_id' => 42,
            'channel' => 'email',
            'status' => 'pending',
            'recipient_user_id' => 11,
            'recipient_email' => 'empfaenger@example.test',
            'recipient_name' => 'Emil Empfänger',
            'attempt_count' => 0,
        ]);

        $row->refresh();
        $this->assertSame(NotificationOutboxIntent::PAYLOAD_KEYS, array_keys($row->payload_json));
        $this->assertSame('D-2026-1-1', $row->payload_json['order_number']);
        $this->assertSame('Beispiel GmbH', $row->payload_json['customer_name']);
        $this->assertSame('Frühjahr', $row->payload_json['campaign']);
        $this->assertSame('Test-Ereignis', $row->payload_json['event_label']);
        $this->assertSame(7, $row->payload_json['actor_id']);
        $this->assertSame('Ada Admin', $row->payload_json['actor_name']);
        $this->assertSame(
            'https://dispo.example.test/dispo-orders/1',
            $row->payload_json['internal_url'],
        );
        $this->assertSame(NotificationOutboxStatus::Pending, $row->status);
        $this->assertLessThanOrEqual(
            NotificationOutboxIdempotency::MAX_LENGTH,
            strlen($row->idempotency_key),
        );
    }

    public function test_repeated_enqueue_keeps_first_payload_snapshot(): void
    {
        $writer = app(NotificationOutboxWriter::class);
        $intent = NotificationOutboxTestFactory::intent();

        $first = $writer->enqueue($intent);
        $second = $writer->enqueue($intent);
        $third = $writer->enqueue(NotificationOutboxTestFactory::intent([
            'payload' => [
                'order_number' => 'OTHER',
                'customer_name' => 'Andere',
                'campaign' => null,
                'event_label' => 'Ignored',
                'actor_id' => 1,
                'actor_name' => 'X',
                'internal_url' => 'https://example.test/y',
                'occurred_at' => '2026-09-26T13:00:00+02:00',
            ],
        ]));

        $this->assertSame($first->id, $second->id);
        $this->assertSame($first->id, $third->id);
        $this->assertSame(1, NotificationOutbox::query()->count());
        $this->assertSame('D-2026-1-1', $third->payload_json['order_number']);
    }

    public function test_max_identity_idempotency_key_persists_within_column(): void
    {
        $event = str_repeat('E', NotificationOutboxIntent::EVENT_TYPE_MAX);
        $source = str_repeat('S', NotificationOutboxIntent::SOURCE_TYPE_MAX);
        $email = 'grenzwert-'.str_repeat('x', 60).'@example.test';
        $this->assertLessThanOrEqual(255, strlen($email));

        $intent = NotificationOutboxTestFactory::intent([
            'eventType' => $event,
            'sourceType' => $source,
            'sourceId' => 9_007_199_254_740_991,
            'recipientUserId' => null,
            'recipientEmail' => $email,
            'recipientName' => 'Ohne User-ID',
        ]);

        $key = $intent->resolvedIdempotencyKey();
        $this->assertLessThanOrEqual(NotificationOutboxIdempotency::MAX_LENGTH, strlen($key));

        $row = app(NotificationOutboxWriter::class)->enqueue($intent);
        $this->assertSame($key, $row->idempotency_key);
        $this->assertSame($event, $row->event_type);
        $this->assertSame($source, $row->source_type);
        $this->assertNull($row->recipient_user_id);
        $this->assertSame($email, $row->recipient_email);
        $this->assertDatabaseHas('notification_outbox', [
            'id' => $row->id,
            'idempotency_key' => $key,
        ]);
    }

    public function test_explicit_key_for_other_identity_is_rejected(): void
    {
        $writer = app(NotificationOutboxWriter::class);
        $sharedKey = 'explicit-shared-key-'.str_repeat('a', 20);

        $first = $writer->enqueue(NotificationOutboxTestFactory::intent([
            'idempotencyKey' => $sharedKey,
            'sourceId' => 1,
            'recipientUserId' => 1,
            'recipientEmail' => 'eins@example.test',
        ]));

        $this->expectException(NotificationOutboxConflictException::class);
        $this->expectExceptionMessage('andere Ereignis-/Quell-/Empfänger-Identität');

        try {
            $writer->enqueue(NotificationOutboxTestFactory::intent([
                'idempotencyKey' => $sharedKey,
                'sourceId' => 2,
                'recipientUserId' => 2,
                'recipientEmail' => 'zwei@example.test',
            ]));
        } finally {
            $this->assertSame(1, NotificationOutbox::query()->count());
            $this->assertSame($first->id, NotificationOutbox::query()->firstOrFail()->id);
        }
    }

    public function test_valid_state_transitions_and_reclaim(): void
    {
        $writer = app(NotificationOutboxWriter::class);
        $states = app(NotificationOutboxStateMachine::class);
        $reclaimer = app(NotificationOutboxReclaimer::class);

        $pending = $writer->enqueue(NotificationOutboxTestFactory::intent());
        $this->assertCount(1, $reclaimer->pendingDue());

        $queued = $states->markQueued($pending);
        $this->assertSame(NotificationOutboxStatus::Queued, $queued->status);
        $this->assertCount(0, $reclaimer->pendingDue());

        $sending = $states->markSending($queued);
        $this->assertSame(NotificationOutboxStatus::Sending, $sending->status);
        $this->assertSame(1, $sending->attempt_count);
        $this->assertNotNull($sending->last_attempt_at);

        $sent = $states->markSent($sending);
        $this->assertSame(NotificationOutboxStatus::Sent, $sent->status);
        $this->assertNotNull($sent->sent_at);

        $other = $writer->enqueue(NotificationOutboxTestFactory::intent([
            'sourceId' => 100,
            'recipientUserId' => 22,
            'recipientEmail' => 'zwei@example.test',
        ]));
        $failed = $states->markFailed($states->markQueued($other), 'SMTP down');
        $this->assertSame(NotificationOutboxStatus::Failed, $failed->status);
        $this->assertSame('SMTP down', $failed->last_error);
        $this->assertCount(0, $reclaimer->pendingDue());

        $requeued = $states->returnToPending($failed);
        $this->assertSame(NotificationOutboxStatus::Pending, $requeued->status);
        $this->assertCount(1, $reclaimer->pendingDue());
    }

    public function test_immutable_fields_reject_eloquent_update_while_state_fields_remain_writable(): void
    {
        $row = app(NotificationOutboxWriter::class)
            ->enqueue(NotificationOutboxTestFactory::intent());

        foreach ([
            'idempotency_key' => 'tampered-key',
            'event_type' => 'tampered.event',
            'source_type' => 'tampered_source',
            'source_id' => 999,
            'recipient_email' => 'tampered@example.test',
            'recipient_name' => 'Tampered',
            'payload_json' => array_merge($row->payload_json, ['order_number' => 'HACK']),
        ] as $field => $value) {
            $fresh = $row->fresh();
            $this->assertNotNull($fresh);
            try {
                $fresh->{$field} = $value;
                $fresh->save();
                $this->fail("Expected immutable guard for {$field}.");
            } catch (LogicException $exception) {
                $this->assertStringContainsString($field, $exception->getMessage());
            }
        }

        $allowed = $row->fresh();
        $this->assertNotNull($allowed);
        $allowed->available_at = CarbonImmutable::now()->addHour();
        $allowed->save();
        $this->assertTrue($allowed->fresh()?->available_at->greaterThan(CarbonImmutable::now()) ?? false);

        $queued = app(NotificationOutboxStateMachine::class)->markQueued(
            NotificationOutbox::query()->findOrFail($row->id),
        );
        $this->assertSame(NotificationOutboxStatus::Queued, $queued->status);
        $this->assertSame('test.event', $queued->event_type);
        $this->assertSame('D-2026-1-1', $queued->payload_json['order_number']);
    }

    public function test_invalid_transition_is_rejected(): void
    {
        $row = app(NotificationOutboxWriter::class)
            ->enqueue(NotificationOutboxTestFactory::intent());

        $this->expectException(NotificationOutboxConflictException::class);
        app(NotificationOutboxStateMachine::class)->markSent($row);
    }

    public function test_future_available_at_is_not_due(): void
    {
        $row = app(NotificationOutboxWriter::class)
            ->enqueue(NotificationOutboxTestFactory::intent());
        $row->available_at = CarbonImmutable::now()->addHour();
        $row->save();

        $due = app(NotificationOutboxReclaimer::class)->pendingDue();
        $this->assertCount(0, $due);
    }

    public function test_outbox_rows_cannot_be_deleted(): void
    {
        $row = app(NotificationOutboxWriter::class)
            ->enqueue(NotificationOutboxTestFactory::intent());

        $this->expectException(LogicException::class);
        $row->delete();
    }

    public function test_successful_outbox_write_rolls_back_with_outer_domain_abort(): void
    {
        $writer = app(NotificationOutboxWriter::class);
        $intent = NotificationOutboxTestFactory::intent();

        try {
            DB::transaction(function () use ($writer, $intent): void {
                $writer->enqueue($intent);
                $this->assertSame(1, NotificationOutbox::query()->count());
                throw new RuntimeException('fachlicher Abbruch');
            });
        } catch (RuntimeException $exception) {
            $this->assertSame('fachlicher Abbruch', $exception->getMessage());
        }

        $this->assertSame(0, NotificationOutbox::query()->count());
    }

    public function test_failed_outbox_write_rolls_back_outer_domain_transaction(): void
    {
        $beforeUsers = User::query()->count();
        $writer = app(NotificationOutboxWriter::class);
        $intent = NotificationOutboxTestFactory::intent(['sourceId' => 55]);

        $failOnce = true;
        NotificationOutbox::saving(function () use (&$failOnce): void {
            if ($failOnce) {
                $failOnce = false;
                throw new RuntimeException('outbox write failed');
            }
        });

        try {
            DB::transaction(function () use ($writer, $intent): void {
                User::factory()->create([
                    'email' => 'outer-tx-marker@example.test',
                ]);
                $writer->enqueue($intent);
            });
            $this->fail('Expected outbox write failure.');
        } catch (RuntimeException $exception) {
            $this->assertSame('outbox write failed', $exception->getMessage());
        }

        $this->assertSame(0, NotificationOutbox::query()->count());
        $this->assertSame($beforeUsers, User::query()->count());
        $this->assertDatabaseMissing('users', [
            'email' => 'outer-tx-marker@example.test',
        ]);
    }
}
