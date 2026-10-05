<?php

namespace Tests\Unit\Notification;

use App\Services\Notification\NotificationErrorDisplay;
use PHPUnit\Framework\Attributes\DataProvider;
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

    public function test_plain_smtp_timeout_remains_readable(): void
    {
        $display = new NotificationErrorDisplay;
        $this->assertSame(
            ['text' => 'SMTP timeout', 'truncated' => false],
            $display->forList('SMTP timeout'),
        );
        $this->assertSame(
            ['text' => 'SMTP timeout', 'truncated' => false],
            $display->forDetail('SMTP timeout'),
        );
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function syntheticSecretCases(): array
    {
        return [
            'authorization_bearer' => [
                'Authorization: Bearer SYNTHETIC_TOKEN',
                'SYNTHETIC_TOKEN',
            ],
            'authorization_basic' => [
                'Authorization: Basic SYNTHETIC_BASE64',
                'SYNTHETIC_BASE64',
            ],
            'authorization_quoted' => [
                'authorization = "Bearer SYNTHETIC_QUOTED"',
                'SYNTHETIC_QUOTED',
            ],
            'smtp_url' => [
                'connect failed smtp://user:SYNTHETIC_PASSWORD@mail.example.test',
                'SYNTHETIC_PASSWORD',
            ],
            'smtps_url' => [
                'tls smtps://user:SYNTHETIC_PASSWORD@mail.example.test/send',
                'SYNTHETIC_PASSWORD',
            ],
            'json_password' => [
                'payload {"password":"SYNTHETIC_JSON_SECRET"} rejected',
                'SYNTHETIC_JSON_SECRET',
            ],
            'token_equals' => [
                'upstream rejected token=SYNTHETIC_TOKEN',
                'SYNTHETIC_TOKEN',
            ],
            'mixed_case_spacing' => [
                'AUTHORIZation:   Bearer   SYNTHETIC_SPACED',
                'SYNTHETIC_SPACED',
            ],
        ];
    }

    #[DataProvider('syntheticSecretCases')]
    public function test_synthetic_secrets_are_fully_removed_from_display(string $raw, string $secret): void
    {
        $display = new NotificationErrorDisplay;

        foreach ([$display->forList($raw), $display->forDetail($raw)] as $presented) {
            $this->assertNotNull($presented['text']);
            $this->assertStringNotContainsString($secret, $presented['text']);
            $this->assertTrue(
                str_contains($presented['text'], '[redacted]')
                || $presented['text'] === NotificationErrorDisplay::UNSAFE_FALLBACK,
                'Erwartet Maskierung oder sicheren Fallback, got: '.$presented['text'],
            );
        }
    }
}
