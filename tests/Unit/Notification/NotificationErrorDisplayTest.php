<?php

namespace Tests\Unit\Notification;

use App\Services\Notification\NotificationErrorDisplay;
use Tests\TestCase;

/**
 * PO-NOT002-ADMIN-1 – sichere Fehlerdarstellung.
 */
class NotificationErrorDisplayTest extends TestCase
{
    public function test_masks_credentials_before_truncation_and_marks_truncated(): void
    {
        $display = new NotificationErrorDisplay;
        $raw = 'SMTP failed password=supersecret token Bearer abcdef.ghijk.lmno '
            .'https://user:secretpass@mail.example.test/send '
            .str_repeat('x', 400);

        $list = $display->forList($raw);
        $this->assertNotNull($list['text']);
        $this->assertStringNotContainsString('supersecret', $list['text']);
        $this->assertStringNotContainsString('abcdef.ghijk.lmno', $list['text']);
        $this->assertStringNotContainsString('secretpass', $list['text']);
        $this->assertStringContainsString('[redacted]', $list['text']);
        $this->assertTrue($list['truncated']);
        $this->assertLessThanOrEqual(
            NotificationErrorDisplay::LIST_MAX_CHARS + 1,
            mb_strlen($list['text']),
        );

        $detail = $display->forDetail($raw);
        $this->assertNotNull($detail['text']);
        $this->assertStringNotContainsString('supersecret', $detail['text']);
        $this->assertStringContainsString('[redacted]', $detail['text']);
    }

    public function test_null_and_blank_error_yield_null_text(): void
    {
        $display = new NotificationErrorDisplay;
        $this->assertSame(['text' => null, 'truncated' => false], $display->forDetail(null));
        $this->assertSame(['text' => null, 'truncated' => false], $display->forList('   '));
    }
}
