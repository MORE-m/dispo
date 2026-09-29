<?php

namespace App\Console\Commands;

use App\Services\Notification\NotificationOutboxDeliveryService;
use Illuminate\Console\Command;

/**
 * Stuck-Recovery + Dispatch fälliger Ask/Answer-Outbox-Jobs (BL-P9-02c).
 */
class DispatchSalesInquiryOutboxCommand extends Command
{
    protected $signature = 'notification-outbox:dispatch-sales-inquiry
                            {--limit=100 : Max. neue Jobs pro Lauf}';

    protected $description = 'Ask/Answer-Outbox: Stuck-Recovery und Dispatch fälliger E-Mail-Jobs';

    public function handle(NotificationOutboxDeliveryService $delivery): int
    {
        $recovered = $delivery->recoverStuck();
        $dispatched = $delivery->dispatchDue(limit: max(1, (int) $this->option('limit')));

        $this->info(sprintf(
            'Sales-inquiry outbox: recovered=%d dispatched=%d',
            $recovered,
            $dispatched,
        ));

        return self::SUCCESS;
    }
}
