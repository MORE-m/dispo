<?php

namespace App\Services\Notification;

use App\Enums\NotificationOutboxChannel;
use InvalidArgumentException;

/**
 * Stabiler Idempotenzschlüssel: Ereignis + Quelle + Kanal + Empfänger.
 * Gleiche Kombination → derselbe Schlüssel → kein zweiter Outbox-Eintrag.
 *
 * Der Schlüssel bleibt unabhängig von Feldlängen fest ≤ {@see self::MAX_LENGTH}
 * (SHA-256 über kanonische Identität), damit varchar(191) nie überschritten wird.
 */
final class NotificationOutboxIdempotency
{
    public const int MAX_LENGTH = 191;

    public const string PREFIX = 'v1:';

    public static function key(
        string $eventType,
        string $sourceType,
        int $sourceId,
        NotificationOutboxChannel $channel,
        ?int $recipientUserId,
        string $recipientEmail,
    ): string {
        $digest = hash('sha256', self::canonical(
            $eventType,
            $sourceType,
            $sourceId,
            $channel,
            $recipientUserId,
            $recipientEmail,
        ));

        $key = self::PREFIX.$digest;
        self::assertValidKey($key);

        return $key;
    }

    /**
     * Kanonische, eindeutig abgegrenzte Identität (nicht der DB-Schlüssel).
     */
    public static function canonical(
        string $eventType,
        string $sourceType,
        int $sourceId,
        NotificationOutboxChannel $channel,
        ?int $recipientUserId,
        string $recipientEmail,
    ): string {
        $recipient = ($recipientUserId !== null && $recipientUserId > 0)
            ? ['uid' => $recipientUserId]
            : ['email' => mb_strtolower(trim($recipientEmail))];

        return json_encode(
            [
                'event_type' => trim($eventType),
                'source_type' => trim($sourceType),
                'source_id' => $sourceId,
                'channel' => $channel->value,
                'recipient' => $recipient,
            ],
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE,
        );
    }

    public static function assertValidKey(string $key): void
    {
        $trimmed = trim($key);
        if ($trimmed === '' || $trimmed !== $key) {
            throw new InvalidArgumentException(
                'idempotency_key darf nicht leer sein und keine äußeren Whitespaces enthalten.',
            );
        }

        if (strlen($key) > self::MAX_LENGTH) {
            throw new InvalidArgumentException(
                sprintf(
                    'idempotency_key darf höchstens %d Zeichen haben (ist %d).',
                    self::MAX_LENGTH,
                    strlen($key),
                ),
            );
        }
    }
}
