<?php

namespace App\Jobs;

use App\Services\Notification\NotificationOutboxDeliveryService;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Zustellung einer Outbox-Zeile (Ask/Answer BL-P9-02c, Freigabe PO-APPROVAL-NOTIFY-1).
 * uniqueId-Präfix unverändert, damit bereits gequeuete Jobs kompatibel bleiben.
 *
 * Queue-Parameter aligniert mit Speedit-Worker:
 * tries=3, backoff=30, timeout=45 (retry_after=90 am Connection).
 */
class DeliverSalesInquiryOutboxJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $backoff = 30;

    public int $timeout = 45;

    /** Unique-Fenster > retry_after, verhindert parallele Doppel-Jobs. */
    public int $uniqueFor = 120;

    public function __construct(
        public readonly int $outboxId,
    ) {}

    public function uniqueId(): string
    {
        return 'sales-inquiry-outbox-'.$this->outboxId;
    }

    public function handle(NotificationOutboxDeliveryService $delivery): void
    {
        $delivery->deliver($this->outboxId);
    }
}
