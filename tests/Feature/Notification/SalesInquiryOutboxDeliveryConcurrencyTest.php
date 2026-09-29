<?php

namespace Tests\Feature\Notification;

use App\Enums\NotificationOutboxStatus;
use App\Jobs\DeliverSalesInquiryOutboxJob;
use App\Models\NotificationOutbox;
use App\Services\DispoOrder\DispoOrderSalesInquiryService;
use App\Services\Notification\NotificationOutboxDeliveryService;
use App\Services\Notification\NotificationOutboxWriter;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\Support\NotificationOutboxTestFactory;
use Tests\TestCase;

/**
 * Minimale MySQL-Absicherung für Ask/Answer-Dispatch (BL-P9-02c).
 */
class SalesInquiryOutboxDeliveryConcurrencyTest extends TestCase
{
    use DatabaseMigrations;

    public function test_mysql_dispatch_claims_exactly_once_under_parallel_command(): void
    {
        if (DB::connection()->getDriverName() !== 'mysql') {
            $this->markTestSkipped('Paralleler Outbox-Dispatch erfordert MySQL.');
        }

        Queue::fake();

        $row = app(NotificationOutboxWriter::class)->enqueue(
            NotificationOutboxTestFactory::intent([
                'eventType' => DispoOrderSalesInquiryService::EVENT_ASKED,
                'sourceType' => DispoOrderSalesInquiryService::SOURCE_TYPE_COMMENT,
                'sourceId' => 9001,
                'recipientEmail' => 'race-advisor@example.test',
                'recipientName' => 'Race Advisor',
            ]),
        );

        $delivery = app(NotificationOutboxDeliveryService::class);
        $first = $delivery->dispatchDue();
        $second = $delivery->dispatchDue();

        $this->assertSame(1, $first);
        $this->assertSame(0, $second);
        $this->assertSame(NotificationOutboxStatus::Queued, $row->fresh()->status);
        Queue::assertPushed(DeliverSalesInquiryOutboxJob::class, 1);
        $this->assertSame(1, NotificationOutbox::query()->count());
    }
}
