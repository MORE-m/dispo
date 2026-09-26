<?php

namespace App\Services\Notification;

use App\Enums\NotificationOutboxStatus;
use App\Exceptions\NotificationOutboxConflictException;
use App\Models\NotificationOutbox;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Schreibt Zustellabsichten idempotent in die Outbox (BL-P1-05a).
 *
 * Transaktionsregeln:
 * - enqueue() darf in einer späteren Fach-TX aufgerufen werden. Scheitert das
 *   Outbox-Schreiben, rollt die gesamte Fachaktion mit zurück.
 * - Queue-/Mailfehler *nach* erfolgreichem Commit der Outbox-Zeile dürfen einen
 *   Fachstatus nicht zurückrollen (NOT-002 – Versand folgt in Folgeslices).
 * - Wiederholter Aufruf mit derselben Ereignis-/Empfängerkombination erzeugt
 *   keinen zweiten Eintrag (Unique + Lookup); der erste Payload-Snapshot bleibt.
 * - Derselbe idempotency_key für eine *andere* Identität wird abgelehnt
 *   (kein stilles Zurückgeben fremder Einträge).
 *
 * Wiederaufnahme ohne afterCommit-Dispatch:
 * - Einträge starten als `pending` mit `available_at`.
 * - NotificationOutboxReclaimer findet fällige `pending`-Zeilen über den Index
 *   (status, available_at), auch wenn kein Job jemals dispatched wurde.
 */
final class NotificationOutboxWriter
{
    public function enqueue(NotificationOutboxIntent $intent): NotificationOutbox
    {
        $key = $intent->resolvedIdempotencyKey();

        $existing = NotificationOutbox::query()
            ->where('idempotency_key', $key)
            ->first();
        if ($existing !== null) {
            $this->assertSameIdentity($existing, $intent);

            return $existing;
        }

        $now = CarbonImmutable::now();

        try {
            return DB::transaction(function () use ($intent, $key, $now): NotificationOutbox {
                $row = new NotificationOutbox;
                $row->idempotency_key = $key;
                $row->event_type = $intent->eventType;
                $row->source_type = $intent->sourceType;
                $row->source_id = $intent->sourceId;
                $row->channel = $intent->channel;
                $row->status = NotificationOutboxStatus::Pending;
                $row->recipient_user_id = $intent->recipientUserId;
                $row->recipient_email = $intent->recipientEmail;
                $row->recipient_name = $intent->recipientName;
                $row->payload_json = $intent->payload;
                $row->attempt_count = 0;
                $row->last_error = null;
                $row->last_attempt_at = null;
                $row->sent_at = null;
                $row->available_at = $now;
                $row->save();

                return $row->fresh() ?? $row;
            });
        } catch (UniqueConstraintViolationException) {
            return $this->existingForIntentOrFail($key, $intent);
        } catch (QueryException $exception) {
            if ($this->isUniqueViolation($exception)) {
                return $this->existingForIntentOrFail($key, $intent);
            }

            throw $exception;
        }
    }

    private function existingForIntentOrFail(string $key, NotificationOutboxIntent $intent): NotificationOutbox
    {
        $existing = NotificationOutbox::query()
            ->where('idempotency_key', $key)
            ->firstOrFail();
        $this->assertSameIdentity($existing, $intent);

        return $existing;
    }

    private function assertSameIdentity(NotificationOutbox $row, NotificationOutboxIntent $intent): void
    {
        $rowChannel = (string) ($row->getAttributes()['channel'] ?? '');
        $intentChannel = $intent->channel->value;

        if (
            $row->event_type !== $intent->eventType
            || $row->source_type !== $intent->sourceType
            || (int) $row->source_id !== $intent->sourceId
            || $rowChannel !== $intentChannel
            || ! $this->sameRecipientIdentity($row, $intent)
        ) {
            throw new NotificationOutboxConflictException(
                'idempotency_key ist bereits für eine andere Ereignis-/Quell-/Empfänger-Identität vergeben.',
            );
        }
    }

    private function sameRecipientIdentity(NotificationOutbox $row, NotificationOutboxIntent $intent): bool
    {
        $rowUid = $row->recipient_user_id;
        $intentUid = $intent->recipientUserId;
        $rowHasUid = $rowUid !== null && (int) $rowUid > 0;
        $intentHasUid = $intentUid !== null && $intentUid > 0;

        if ($rowHasUid && $intentHasUid) {
            return (int) $rowUid === (int) $intentUid;
        }

        if ($rowHasUid !== $intentHasUid) {
            return false;
        }

        return mb_strtolower($row->recipient_email) === mb_strtolower($intent->recipientEmail);
    }

    private function isUniqueViolation(QueryException $exception): bool
    {
        $sqlState = (string) ($exception->errorInfo[0] ?? '');
        $driverCode = (int) ($exception->errorInfo[1] ?? 0);
        $message = $exception->getMessage();

        if ($sqlState === '23000' || $driverCode === 1062) {
            return true;
        }

        return str_contains($message, 'UNIQUE constraint failed')
            || str_contains($message, 'idempotency_key');
    }
}
