<?php

namespace App\Services\Notification;

use App\Enums\NotificationOutboxChannel;
use App\Enums\NotificationOutboxStatus;
use App\Exceptions\NotificationOutboxConflictException;
use App\Jobs\DeliverSalesInquiryOutboxJob;
use App\Mail\SalesInquiryOutboxMail;
use App\Models\NotificationOutbox;
use App\Services\DispoOrder\DispoOrderSalesInquiryService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * SMTP-Zustellung nur für Ask/Answer-Outbox (BL-P9-02c / PO-BLP902C-1).
 *
 * Scope: event_type asked|answered, channel email.
 * Max. {@see self::MAX_ATTEMPTS} Zustellversuche → `failed`.
 * At-least-once: Abbruch nach SMTP-Annahme vor markSent kann Doppelsendung erzeugen.
 */
final class NotificationOutboxDeliveryService
{
    public const int MAX_ATTEMPTS = 3;

    /** Aligniert mit Queue-Backoff (30 s). */
    public const int RETRY_BACKOFF_SECONDS = 30;

    /** Aligniert mit DB_QUEUE_RETRY_AFTER (90 s). */
    public const int STUCK_AFTER_SECONDS = 90;

    /** @var list<string> */
    public const array DELIVERABLE_EVENT_TYPES = [
        DispoOrderSalesInquiryService::EVENT_ASKED,
        DispoOrderSalesInquiryService::EVENT_ANSWERED,
    ];

    public function __construct(
        private readonly NotificationOutboxReclaimer $reclaimer,
        private readonly NotificationOutboxStateMachine $states,
    ) {}

    public static function isDeliverable(NotificationOutbox $row): bool
    {
        $channel = $row->getAttributes()['channel'] ?? null;
        if ($channel !== NotificationOutboxChannel::Email->value) {
            return false;
        }

        return in_array($row->event_type, self::DELIVERABLE_EVENT_TYPES, true);
    }

    /**
     * Hängengebliebene queued/sending-Zeilen zurück nach pending.
     *
     * @return int Anzahl wiederaufgenommener Zeilen
     */
    public function recoverStuck(?CarbonImmutable $now = null, ?int $staleSeconds = null): int
    {
        $now ??= CarbonImmutable::now();
        $staleSeconds ??= self::STUCK_AFTER_SECONDS;
        $cutoff = $now->subSeconds(max(1, $staleSeconds));

        $stuck = $this->reclaimer->stuckDeliverable(
            self::DELIVERABLE_EVENT_TYPES,
            NotificationOutboxChannel::Email,
            $cutoff,
        );

        $recovered = 0;
        foreach ($stuck as $row) {
            try {
                $this->states->returnToPending(
                    $row,
                    $now,
                    'Stuck-Recovery: Status '.$row->status->value.' älter als '.$staleSeconds.'s.',
                );
                $recovered++;
            } catch (NotificationOutboxConflictException) {
                // Parallel fortgeschritten – ignorieren.
            }
        }

        return $recovered;
    }

    /**
     * Fällige pending Ask/Answer-Zeilen claimen und Jobs dispatchen.
     *
     * @return int Anzahl dispatched Jobs
     */
    public function dispatchDue(?CarbonImmutable $now = null, int $limit = 100): int
    {
        $now ??= CarbonImmutable::now();
        $due = $this->reclaimer->pendingDueDeliverable(
            self::DELIVERABLE_EVENT_TYPES,
            NotificationOutboxChannel::Email,
            $now,
            $limit,
        );

        $dispatched = 0;
        foreach ($due as $row) {
            try {
                $claimed = $this->states->tryClaimPending($row);
            } catch (NotificationOutboxConflictException) {
                continue;
            }

            DeliverSalesInquiryOutboxJob::dispatch($claimed->id);
            $dispatched++;
        }

        return $dispatched;
    }

    /**
     * Ein Zustellversuch für eine bereits geclaimte oder wiederaufgenommene Zeile.
     */
    public function deliver(int $outboxId): void
    {
        $row = NotificationOutbox::query()->find($outboxId);
        if ($row === null) {
            return;
        }

        if (! self::isDeliverable($row)) {
            return;
        }

        if ($row->status === NotificationOutboxStatus::Sent
            || $row->status === NotificationOutboxStatus::Failed) {
            return;
        }

        try {
            if ($row->status === NotificationOutboxStatus::Pending) {
                $row = $this->states->tryClaimPending($row);
            }

            if ($row->status === NotificationOutboxStatus::Queued) {
                if ($row->attempt_count >= self::MAX_ATTEMPTS) {
                    $this->states->markFailed(
                        $row,
                        'Maximale Zustellversuche ('.self::MAX_ATTEMPTS.') bereits erreicht.',
                    );

                    return;
                }
                $row = $this->states->markSending($row);
            } elseif ($row->status === NotificationOutboxStatus::Sending) {
                // Laravel-Retry oder Crash vor markSent: erneuter SMTP-Versuch (at-least-once).
                if ($row->attempt_count >= self::MAX_ATTEMPTS) {
                    $this->states->markFailed(
                        $row,
                        'Maximale Zustellversuche ('.self::MAX_ATTEMPTS.') erreicht.',
                    );

                    return;
                }
            } else {
                return;
            }
        } catch (NotificationOutboxConflictException) {
            return;
        }

        try {
            Mail::to($row->recipient_email, $row->recipient_name)
                ->send(new SalesInquiryOutboxMail($row));
            $this->states->markSent($row);
        } catch (Throwable $exception) {
            $fresh = $row->fresh() ?? $row;
            $message = mb_substr(trim($exception->getMessage()), 0, 2000);
            if ($message === '') {
                $message = 'Unbekannter SMTP-/Mail-Fehler.';
            }

            if ($fresh->attempt_count >= self::MAX_ATTEMPTS) {
                $this->states->markFailed($fresh, $message);

                return;
            }

            $this->states->returnToPending(
                $fresh,
                CarbonImmutable::now()->addSeconds(self::RETRY_BACKOFF_SECONDS),
                $message,
            );

            throw $exception;
        }
    }
}
