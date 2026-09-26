<?php

namespace App\Services\Notification;

use App\Enums\NotificationOutboxStatus;
use App\Exceptions\NotificationOutboxConflictException;

/**
 * Erlaubte Zustandsübergänge der Outbox (BL-P1-05a).
 *
 * pending  → queued | failed
 * queued   → sending | pending | failed
 * sending  → sent | failed | pending
 * failed   → pending
 * sent     → (terminal)
 *
 * Hinweis Zustellung: E-Mail gilt als mindestens-einmal. Ein Worker-Abbruch
 * nach SMTP-Erfolg vor markSent kann zu erneuter Zustellung führen – keine
 * garantierte Exactly-Once-Zusage.
 */
final class NotificationOutboxTransitions
{
    /**
     * @return list<NotificationOutboxStatus>
     */
    public static function allowedTargets(NotificationOutboxStatus $from): array
    {
        return match ($from) {
            NotificationOutboxStatus::Pending => [
                NotificationOutboxStatus::Queued,
                NotificationOutboxStatus::Failed,
            ],
            NotificationOutboxStatus::Queued => [
                NotificationOutboxStatus::Sending,
                NotificationOutboxStatus::Pending,
                NotificationOutboxStatus::Failed,
            ],
            NotificationOutboxStatus::Sending => [
                NotificationOutboxStatus::Sent,
                NotificationOutboxStatus::Failed,
                NotificationOutboxStatus::Pending,
            ],
            NotificationOutboxStatus::Failed => [
                NotificationOutboxStatus::Pending,
            ],
            NotificationOutboxStatus::Sent => [],
        };
    }

    public static function canTransition(
        NotificationOutboxStatus $from,
        NotificationOutboxStatus $to,
    ): bool {
        return in_array($to, self::allowedTargets($from), true);
    }

    public static function assertCanTransition(
        NotificationOutboxStatus $from,
        NotificationOutboxStatus $to,
    ): void {
        if (! self::canTransition($from, $to)) {
            throw new NotificationOutboxConflictException(
                sprintf(
                    'Ungültiger Outbox-Zustandsübergang: %s → %s.',
                    $from->value,
                    $to->value,
                ),
            );
        }
    }
}
