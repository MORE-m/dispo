<?php

namespace Tests\Support;

use App\Enums\NotificationOutboxChannel;
use App\Services\Notification\NotificationOutboxIntent;

final class NotificationOutboxTestFactory
{
    /**
     * @param  array<string, mixed>  $overrides
     */
    public static function intent(array $overrides = []): NotificationOutboxIntent
    {
        $payload = array_merge([
            'order_number' => 'D-2026-1-1',
            'customer_name' => 'Beispiel GmbH',
            'campaign' => 'Frühjahr',
            'event_label' => 'Test-Ereignis',
            'actor_id' => 7,
            'actor_name' => 'Ada Admin',
            'internal_url' => 'https://dispo.example.test/dispo-orders/1',
            'occurred_at' => '2026-09-26T12:00:00+02:00',
        ], $overrides['payload'] ?? []);

        unset($overrides['payload']);

        $defaults = [
            'eventType' => 'test.event',
            'sourceType' => 'test_source',
            'sourceId' => 42,
            'channel' => NotificationOutboxChannel::Email,
            'recipientUserId' => 11,
            'recipientEmail' => 'empfaenger@example.test',
            'recipientName' => 'Emil Empfänger',
            'payload' => $payload,
            'idempotencyKey' => null,
        ];

        $merged = array_merge($defaults, $overrides);

        return new NotificationOutboxIntent(
            eventType: $merged['eventType'],
            sourceType: $merged['sourceType'],
            sourceId: $merged['sourceId'],
            channel: $merged['channel'],
            recipientUserId: $merged['recipientUserId'],
            recipientEmail: $merged['recipientEmail'],
            recipientName: $merged['recipientName'],
            payload: $merged['payload'],
            idempotencyKey: $merged['idempotencyKey'],
        );
    }
}
