<?php

namespace App\Services\Notification;

use App\Enums\NotificationOutboxStatus;
use App\Exceptions\NotificationOutboxConflictException;
use App\Models\NotificationOutbox;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Zustandsübergänge mit Locking (BL-P1-05a).
 * Kein SMTP / kein Queue-Dispatch in diesem Slice.
 */
final class NotificationOutboxStateMachine
{
    public function markQueued(NotificationOutbox $row): NotificationOutbox
    {
        return $this->transition($row, NotificationOutboxStatus::Queued);
    }

    public function markSending(NotificationOutbox $row): NotificationOutbox
    {
        return $this->transition(
            $row,
            NotificationOutboxStatus::Sending,
            incrementAttempt: true,
        );
    }

    public function markSent(NotificationOutbox $row): NotificationOutbox
    {
        return $this->transition(
            $row,
            NotificationOutboxStatus::Sent,
            clearError: true,
            setSentAt: true,
        );
    }

    public function markFailed(NotificationOutbox $row, string $error): NotificationOutbox
    {
        $trimmed = trim($error);
        if ($trimmed === '') {
            $trimmed = 'Unbekannter Zustellfehler.';
        }

        return $this->transition(
            $row,
            NotificationOutboxStatus::Failed,
            error: mb_substr($trimmed, 0, 2000),
        );
    }

    /**
     * Zurück nach pending für erneute Abholung durch den Reclaimer
     * (z. B. nach fehlgeschlagenem Dispatch oder manuellem Retry).
     */
    public function returnToPending(
        NotificationOutbox $row,
        ?CarbonImmutable $availableAt = null,
        ?string $error = null,
    ): NotificationOutbox {
        return $this->transition(
            $row,
            NotificationOutboxStatus::Pending,
            error: $error !== null ? mb_substr(trim($error), 0, 2000) : null,
            availableAt: $availableAt ?? CarbonImmutable::now(),
        );
    }

    private function transition(
        NotificationOutbox $row,
        NotificationOutboxStatus $to,
        bool $incrementAttempt = false,
        bool $clearError = false,
        bool $setSentAt = false,
        ?string $error = null,
        ?CarbonImmutable $availableAt = null,
    ): NotificationOutbox {
        return DB::transaction(function () use (
            $row,
            $to,
            $incrementAttempt,
            $clearError,
            $setSentAt,
            $error,
            $availableAt,
        ): NotificationOutbox {
            /** @var NotificationOutbox $locked */
            $locked = NotificationOutbox::query()
                ->whereKey($row->id)
                ->lockForUpdate()
                ->firstOrFail();

            NotificationOutboxTransitions::assertCanTransition($locked->status, $to);

            $locked->status = $to;

            if ($incrementAttempt) {
                $locked->attempt_count = $locked->attempt_count + 1;
                $locked->last_attempt_at = CarbonImmutable::now();
            }

            if ($clearError) {
                $locked->last_error = null;
            } elseif ($error !== null) {
                $locked->last_error = $error === '' ? null : $error;
            }

            if ($setSentAt) {
                $locked->sent_at = CarbonImmutable::now();
            }

            if ($availableAt !== null) {
                $locked->available_at = $availableAt;
            }

            $locked->save();

            return $locked->fresh() ?? $locked;
        });
    }

    /**
     * Concurrent claim: nur eine Partei darf pending → queued gewinnen.
     */
    public function tryClaimPending(NotificationOutbox $row): NotificationOutbox
    {
        try {
            return $this->markQueued($row);
        } catch (NotificationOutboxConflictException $exception) {
            $fresh = $row->fresh();
            if ($fresh !== null && $fresh->status !== NotificationOutboxStatus::Pending) {
                throw new NotificationOutboxConflictException(
                    'Outbox-Eintrag wurde parallel beansprucht (Status: '.$fresh->status->value.').',
                    0,
                    $exception,
                );
            }

            throw $exception;
        }
    }
}
