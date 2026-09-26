<?php

namespace App\Services\Notification;

use App\Enums\NotificationOutboxChannel;
use InvalidArgumentException;

/**
 * Absicht für einen Outbox-Eintrag (BL-P1-05a).
 * payload enthält ausschließlich den NOT-001-Snapshot (Vorgangsnummer, Kunde/Kampagne,
 * Ereignis, handelnde Person, interner Link) – ohne zusätzliche oder verschachtelte
 * Anlagen-/Dateidaten.
 */
final class NotificationOutboxIntent
{
    public const int EVENT_TYPE_MAX = 128;

    public const int SOURCE_TYPE_MAX = 128;

    /** @var list<string> */
    public const array PAYLOAD_KEYS = [
        'order_number',
        'customer_name',
        'campaign',
        'event_label',
        'actor_id',
        'actor_name',
        'internal_url',
        'occurred_at',
    ];

    public readonly string $eventType;

    public readonly string $sourceType;

    public readonly string $recipientEmail;

    public readonly string $recipientName;

    /** @var array<string, mixed> */
    public readonly array $payload;

    public readonly ?string $idempotencyKey;

    /**
     * @param  array<string, mixed>  $payload
     */
    public function __construct(
        string $eventType,
        string $sourceType,
        public readonly int $sourceId,
        public readonly NotificationOutboxChannel $channel,
        public readonly ?int $recipientUserId,
        string $recipientEmail,
        string $recipientName,
        array $payload,
        ?string $idempotencyKey = null,
    ) {
        $this->eventType = trim($eventType);
        $this->sourceType = trim($sourceType);
        $this->recipientEmail = trim($recipientEmail);
        $this->recipientName = trim($recipientName);
        $this->idempotencyKey = $idempotencyKey === null ? null : trim($idempotencyKey);

        if ($this->eventType === '') {
            throw new InvalidArgumentException('event_type darf nicht leer sein.');
        }
        if (mb_strlen($this->eventType) > self::EVENT_TYPE_MAX) {
            throw new InvalidArgumentException(
                sprintf('event_type darf höchstens %d Zeichen haben.', self::EVENT_TYPE_MAX),
            );
        }
        if ($this->sourceType === '') {
            throw new InvalidArgumentException('source_type darf nicht leer sein.');
        }
        if (mb_strlen($this->sourceType) > self::SOURCE_TYPE_MAX) {
            throw new InvalidArgumentException(
                sprintf('source_type darf höchstens %d Zeichen haben.', self::SOURCE_TYPE_MAX),
            );
        }
        if ($this->sourceId <= 0) {
            throw new InvalidArgumentException('source_id muss positiv sein.');
        }
        if ($this->recipientEmail === '') {
            throw new InvalidArgumentException('recipient_email darf nicht leer sein.');
        }
        if ($this->recipientName === '') {
            throw new InvalidArgumentException('recipient_name darf nicht leer sein.');
        }

        $this->payload = $this->normalizeNot001Payload($payload);

        if ($this->idempotencyKey !== null) {
            NotificationOutboxIdempotency::assertValidKey($this->idempotencyKey);
        }
    }

    public function resolvedIdempotencyKey(): string
    {
        if ($this->idempotencyKey !== null) {
            return $this->idempotencyKey;
        }

        return NotificationOutboxIdempotency::key(
            $this->eventType,
            $this->sourceType,
            $this->sourceId,
            $this->channel,
            $this->recipientUserId,
            $this->recipientEmail,
        );
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{
     *     order_number: string,
     *     customer_name: string|null,
     *     campaign: string|null,
     *     event_label: string,
     *     actor_id: int|null,
     *     actor_name: string,
     *     internal_url: string,
     *     occurred_at: string
     * }
     */
    private function normalizeNot001Payload(array $payload): array
    {
        $extra = array_diff_key($payload, array_flip(self::PAYLOAD_KEYS));
        if ($extra !== []) {
            throw new InvalidArgumentException(
                'payload darf nur NOT-001-Felder enthalten; unzulässig: '.implode(', ', array_keys($extra)),
            );
        }

        foreach (self::PAYLOAD_KEYS as $required) {
            if (! array_key_exists($required, $payload)) {
                throw new InvalidArgumentException("payload.{$required} fehlt.");
            }
        }

        foreach ($payload as $key => $value) {
            if (is_array($value)) {
                throw new InvalidArgumentException(
                    "payload.{$key} darf keine verschachtelten Anlagen-/Dateidaten enthalten.",
                );
            }
        }

        foreach (['order_number', 'event_label', 'actor_name', 'internal_url', 'occurred_at'] as $required) {
            if (! is_string($payload[$required]) || trim($payload[$required]) === '') {
                throw new InvalidArgumentException("payload.{$required} muss ein nicht-leerer String sein.");
            }
        }

        foreach (['customer_name', 'campaign'] as $optionalString) {
            if ($payload[$optionalString] !== null && ! is_string($payload[$optionalString])) {
                throw new InvalidArgumentException("payload.{$optionalString} muss string|null sein.");
            }
        }

        if ($payload['actor_id'] !== null && (! is_int($payload['actor_id']) || $payload['actor_id'] <= 0)) {
            throw new InvalidArgumentException('payload.actor_id muss int>0|null sein.');
        }

        return [
            'order_number' => trim($payload['order_number']),
            'customer_name' => $payload['customer_name'] === null ? null : trim((string) $payload['customer_name']),
            'campaign' => $payload['campaign'] === null ? null : trim((string) $payload['campaign']),
            'event_label' => trim($payload['event_label']),
            'actor_id' => $payload['actor_id'],
            'actor_name' => trim($payload['actor_name']),
            'internal_url' => trim($payload['internal_url']),
            'occurred_at' => trim($payload['occurred_at']),
        ];
    }
}
