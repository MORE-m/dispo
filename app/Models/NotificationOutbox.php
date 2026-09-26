<?php

namespace App\Models;

use App\Enums\NotificationOutboxChannel;
use App\Enums\NotificationOutboxStatus;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use LogicException;

/**
 * Persistierte Zustellabsicht (BL-P1-05a).
 *
 * Nach dem INSERT unveränderlich: Identität (`idempotency_key`, `event_type`,
 * `source_type`, `source_id`, `channel`), Empfänger-Snapshot und `payload_json`.
 * Zustandsfelder (`status`, Versuche, Fehler, Zeitstempel, `available_at`) werden
 * über NotificationOutboxStateMachine geändert.
 *
 * @property int $id
 * @property string $idempotency_key
 * @property string $event_type
 * @property string $source_type
 * @property int $source_id
 * @property NotificationOutboxChannel $channel
 * @property NotificationOutboxStatus $status
 * @property int|null $recipient_user_id
 * @property string $recipient_email
 * @property string $recipient_name
 * @property array<string, mixed> $payload_json
 * @property int $attempt_count
 * @property string|null $last_error
 * @property CarbonImmutable|null $last_attempt_at
 * @property CarbonImmutable|null $sent_at
 * @property CarbonImmutable $available_at
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
class NotificationOutbox extends Model
{
    protected $table = 'notification_outbox';

    /** @var list<string> */
    public const IMMUTABLE_AFTER_CREATE = [
        'idempotency_key',
        'event_type',
        'source_type',
        'source_id',
        'channel',
        'recipient_user_id',
        'recipient_email',
        'recipient_name',
        'payload_json',
    ];

    protected $fillable = [
        'idempotency_key',
        'event_type',
        'source_type',
        'source_id',
        'channel',
        'status',
        'recipient_user_id',
        'recipient_email',
        'recipient_name',
        'payload_json',
        'attempt_count',
        'last_error',
        'last_attempt_at',
        'sent_at',
        'available_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'channel' => NotificationOutboxChannel::class,
            'status' => NotificationOutboxStatus::class,
            'payload_json' => 'array',
            'attempt_count' => 'integer',
            'source_id' => 'integer',
            'recipient_user_id' => 'integer',
            'last_attempt_at' => 'immutable_datetime',
            'sent_at' => 'immutable_datetime',
            'available_at' => 'immutable_datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (self $model): void {
            foreach (self::IMMUTABLE_AFTER_CREATE as $field) {
                if ($model->isDirty($field)) {
                    throw new LogicException(
                        "Outbox-Feld „{$field}“ ist nach dem Anlegen unveränderlich.",
                    );
                }
            }
        });

        static::deleting(function (): never {
            throw new LogicException('Outbox-Einträge dürfen nicht gelöscht werden.');
        });
    }
}
