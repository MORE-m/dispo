<?php

namespace App\Services\Notification;

use App\Enums\NotificationOutboxStatus;
use App\Models\NotificationOutbox;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;

/**
 * Findet fällige Outbox-Einträge unabhängig von einem Job-Dispatch (BL-P1-05a).
 *
 * afterCommit-Dispatch allein ist keine ausreichende Wiederaufnahme:
 * Dieses Query ist die kanonische Recovery-Quelle für `pending` mit
 * `available_at <= now`. Ein späterer Scheduler/Worker muss dieses API nutzen.
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
}
