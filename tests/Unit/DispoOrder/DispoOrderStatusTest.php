<?php

namespace Tests\Unit\DispoOrder;

use App\Enums\DispoOrderStatus;
use Tests\TestCase;

class DispoOrderStatusTest extends TestCase
{
    public function test_draft_label_is_german(): void
    {
        $this->assertSame('Entwurf', DispoOrderStatus::Draft->label());
    }

    public function test_all_statuses_have_german_labels(): void
    {
        foreach (DispoOrderStatus::cases() as $status) {
            $this->assertNotSame('', $status->label());
            $this->assertMatchesRegularExpression('/\p{L}/u', $status->label());
        }
    }

    public function test_status_count_matches_workflow_model(): void
    {
        $this->assertCount(11, DispoOrderStatus::cases());
    }
}
