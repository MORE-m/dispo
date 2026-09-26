<?php

namespace Tests\Unit\Notification;

use App\Enums\NotificationOutboxChannel;
use App\Enums\NotificationOutboxStatus;
use App\Services\Notification\NotificationOutboxIdempotency;
use App\Services\Notification\NotificationOutboxIntent;
use App\Services\Notification\NotificationOutboxTransitions;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\NotificationOutboxTestFactory;
use Tests\TestCase;

class NotificationOutboxContractTest extends TestCase
{
    public function test_idempotency_key_is_stable_for_same_recipient_user(): void
    {
        $a = NotificationOutboxIdempotency::key(
            'dispo_order.sales_inquiry.created',
            'dispo_order_comment',
            99,
            NotificationOutboxChannel::Email,
            5,
            'a@example.test',
        );
        $b = NotificationOutboxIdempotency::key(
            'dispo_order.sales_inquiry.created',
            'dispo_order_comment',
            99,
            NotificationOutboxChannel::Email,
            5,
            'other@example.test',
        );

        $this->assertSame($a, $b);
        $this->assertStringStartsWith(NotificationOutboxIdempotency::PREFIX, $a);
        $this->assertLessThanOrEqual(NotificationOutboxIdempotency::MAX_LENGTH, strlen($a));
    }

    public function test_idempotency_key_falls_back_to_email_without_user_id(): void
    {
        $lower = NotificationOutboxIdempotency::key(
            'test.event',
            'test_source',
            1,
            NotificationOutboxChannel::Email,
            null,
            'Person@Example.TEST',
        );
        $upper = NotificationOutboxIdempotency::key(
            'test.event',
            'test_source',
            1,
            NotificationOutboxChannel::Email,
            null,
            'person@example.test',
        );
        $other = NotificationOutboxIdempotency::key(
            'test.event',
            'test_source',
            1,
            NotificationOutboxChannel::Email,
            null,
            'other@example.test',
        );

        $this->assertSame($lower, $upper);
        $this->assertNotSame($lower, $other);
    }

    public function test_idempotency_key_stays_within_column_for_max_identity(): void
    {
        $event = str_repeat('e', NotificationOutboxIntent::EVENT_TYPE_MAX);
        $source = str_repeat('s', NotificationOutboxIntent::SOURCE_TYPE_MAX);
        $email = str_repeat('a', 64).'@'.str_repeat('b', 60).'.example.test';
        $this->assertLessThanOrEqual(255, strlen($email));

        $key = NotificationOutboxIdempotency::key(
            $event,
            $source,
            PHP_INT_MAX,
            NotificationOutboxChannel::Email,
            null,
            $email,
        );

        $this->assertSame(67, strlen($key));
        $this->assertLessThanOrEqual(NotificationOutboxIdempotency::MAX_LENGTH, strlen($key));
        $this->assertNotSame(
            $key,
            NotificationOutboxIdempotency::key(
                $event,
                $source,
                PHP_INT_MAX,
                NotificationOutboxChannel::Email,
                null,
                $email.'x',
            ),
        );
    }

    public function test_explicit_idempotency_key_rejects_overlong_values(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('höchstens');

        NotificationOutboxTestFactory::intent([
            'idempotencyKey' => str_repeat('k', NotificationOutboxIdempotency::MAX_LENGTH + 1),
        ]);
    }

    public function test_intent_rejects_extra_and_nested_payload_keys(): void
    {
        try {
            NotificationOutboxTestFactory::intent([
                'payload' => [
                    'order_number' => 'D-1',
                    'customer_name' => null,
                    'campaign' => null,
                    'event_label' => 'X',
                    'actor_id' => null,
                    'actor_name' => 'A',
                    'internal_url' => 'https://example.test/x',
                    'occurred_at' => '2026-01-01T00:00:00Z',
                    'upload_id' => 9,
                ],
            ]);
            $this->fail('Expected InvalidArgumentException for extra payload key.');
        } catch (InvalidArgumentException $exception) {
            $this->assertStringContainsString('NOT-001', $exception->getMessage());
        }

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('verschachtelt');

        NotificationOutboxTestFactory::intent([
            'payload' => [
                'order_number' => 'D-1',
                'customer_name' => ['file' => 'geheim.pdf'],
                'campaign' => null,
                'event_label' => 'X',
                'actor_id' => null,
                'actor_name' => 'A',
                'internal_url' => 'https://example.test/x',
                'occurred_at' => '2026-01-01T00:00:00Z',
            ],
        ]);
    }

    public function test_intent_requires_not001_fields(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new NotificationOutboxIntent(
            eventType: 'e',
            sourceType: 's',
            sourceId: 1,
            channel: NotificationOutboxChannel::Email,
            recipientUserId: 1,
            recipientEmail: 'a@b.c',
            recipientName: 'A',
            payload: ['order_number' => 'D-1'],
        );
    }

    public function test_intent_normalizes_payload_to_not001_keys_only(): void
    {
        $intent = NotificationOutboxTestFactory::intent();
        $this->assertSame(NotificationOutboxIntent::PAYLOAD_KEYS, array_keys($intent->payload));
    }

    /**
     * @return list<array{0: NotificationOutboxStatus, 1: NotificationOutboxStatus}>
     */
    public static function allowedTransitionsProvider(): array
    {
        return [
            [NotificationOutboxStatus::Pending, NotificationOutboxStatus::Queued],
            [NotificationOutboxStatus::Pending, NotificationOutboxStatus::Failed],
            [NotificationOutboxStatus::Queued, NotificationOutboxStatus::Sending],
            [NotificationOutboxStatus::Queued, NotificationOutboxStatus::Pending],
            [NotificationOutboxStatus::Queued, NotificationOutboxStatus::Failed],
            [NotificationOutboxStatus::Sending, NotificationOutboxStatus::Sent],
            [NotificationOutboxStatus::Sending, NotificationOutboxStatus::Failed],
            [NotificationOutboxStatus::Sending, NotificationOutboxStatus::Pending],
            [NotificationOutboxStatus::Failed, NotificationOutboxStatus::Pending],
        ];
    }

    #[DataProvider('allowedTransitionsProvider')]
    public function test_allowed_transitions(
        NotificationOutboxStatus $from,
        NotificationOutboxStatus $to,
    ): void {
        $this->assertTrue(NotificationOutboxTransitions::canTransition($from, $to));
    }

    /**
     * @return list<array{0: NotificationOutboxStatus, 1: NotificationOutboxStatus}>
     */
    public static function forbiddenTransitionsProvider(): array
    {
        return [
            [NotificationOutboxStatus::Sent, NotificationOutboxStatus::Pending],
            [NotificationOutboxStatus::Sent, NotificationOutboxStatus::Failed],
            [NotificationOutboxStatus::Pending, NotificationOutboxStatus::Sent],
            [NotificationOutboxStatus::Pending, NotificationOutboxStatus::Sending],
            [NotificationOutboxStatus::Failed, NotificationOutboxStatus::Sent],
            [NotificationOutboxStatus::Failed, NotificationOutboxStatus::Queued],
        ];
    }

    #[DataProvider('forbiddenTransitionsProvider')]
    public function test_forbidden_transitions(
        NotificationOutboxStatus $from,
        NotificationOutboxStatus $to,
    ): void {
        $this->assertFalse(NotificationOutboxTransitions::canTransition($from, $to));
    }
}
