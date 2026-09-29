<?php

namespace App\Services\Notification;

use App\Enums\NotificationOutboxChannel;
use App\Enums\NotificationOutboxStatus;
use App\Models\NotificationOutbox;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;

/**
 * Findet fällige Outbox-Einträge unabhängig von einem Job-Dispatch (BL-P1-05a).
 *
 * afterCommit-Dispatch allein ist keine ausreichende Wiederaufnahme:
 * Dieses Query ist die kanonische Recovery-Quelle für `pending` mit
 * `available_at <= now`. Scheduler/Worker (BL-P9-02c) nutzen dieses API.
 */
final class NotificationOutboxReclaimer
{
    /**
     * @return Collection<int, NotificationOutbox>
     */
    public function pendingDue(?CarbonImmutable $now = null, int $limit = 100): Collection
    {
        $limit = max(1, min($limit, 500));
        $now ??= CarbonImmutable::now();

        return NotificationOutbox::query()
            ->where('status', NotificationOutboxStatus::Pending)
            ->where('available_at', '<=', $now)
            ->orderBy('available_at')
            ->orderBy('id')
            ->limit($limit)
            ->get();
    }

    /**
     * Fällige pending-Zeilen nur für freigegebene Delivery-Events (BL-P9-02c).
     *
     * @param  list<string>  $eventTypes
     * @return Collection<int, NotificationOutbox>
     */
    public function pendingDueDeliverable(
        array $eventTypes,
        NotificationOutboxChannel $channel,
        ?CarbonImmutable $now = null,
        int $limit = 100,
    ): Collection {
        $limit = max(1, min($limit, 500));
        $now ??= CarbonImmutable::now();

        if ($eventTypes === []) {
            return new Collection;
        }

        return NotificationOutbox::query()
            ->where('status', NotificationOutboxStatus::Pending)
            ->where('channel', $channel->value)
            ->whereIn('event_type', $eventTypes)
            ->where('available_at', '<=', $now)
            ->orderBy('available_at')
            ->orderBy('id')
            ->limit($limit)
            ->get();
    }

    /**
     * Hängengebliebene queued/sending-Zeilen für Delivery-Recovery (BL-P9-02c).
     *
     * queued: `updated_at` älter als Cutoff (noch kein last_attempt_at).
     * sending: `last_attempt_at` älter als Cutoff (Fallback updated_at).
     *
     * @param  list<string>  $eventTypes
     * @return Collection<int, NotificationOutbox>
     */
    public function stuckDeliverable(
        array $eventTypes,
        NotificationOutboxChannel $channel,
        CarbonImmutable $cutoff,
        int $limit = 100,
    ): Collection {
        $limit = max(1, min($limit, 500));

        if ($eventTypes === []) {
            return new Collection;
        }

        return NotificationOutbox::query()
            ->where('channel', $channel->value)
            ->whereIn('event_type', $eventTypes)
            ->where(function ($query) use ($cutoff): void {
                $query->where(function ($queued) use ($cutoff): void {
                    $queued->where('status', NotificationOutboxStatus::Queued)
                        ->where('updated_at', '<=', $cutoff);
                })->orWhere(function ($sending) use ($cutoff): void {
                    $sending->where('status', NotificationOutboxStatus::Sending)
                        ->where(function ($attempt) use ($cutoff): void {
                            $attempt->where(function ($withAttempt) use ($cutoff): void {
                                $withAttempt->whereNotNull('last_attempt_at')
                                    ->where('last_attempt_at', '<=', $cutoff);
                            })->orWhere(function ($withoutAttempt) use ($cutoff): void {
                                $withoutAttempt->whereNull('last_attempt_at')
                                    ->where('updated_at', '<=', $cutoff);
                            });
                        });
                });
            })
            ->orderBy('id')
            ->limit($limit)
            ->get();
    }
}
