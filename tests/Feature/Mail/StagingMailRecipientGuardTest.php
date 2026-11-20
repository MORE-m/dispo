<?php

namespace Tests\Feature\Mail;

use App\Enums\NotificationOutboxStatus;
use App\Exceptions\StagingMailRecipientBlockedException;
use App\Jobs\DeliverSalesInquiryOutboxJob;
use App\Mail\StagingMailRecipientGuard;
use App\Models\User;
use App\Services\DispoOrder\DispoOrderSalesInquiryService;
use App\Services\Notification\NotificationOutboxDeliveryService;
use App\Services\Notification\NotificationOutboxStateMachine;
use App\Services\Notification\NotificationOutboxWriter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Mail\Transport\ArrayTransport;
use Illuminate\Support\Facades\Mail;
use Laravel\Fortify\Features;
use Symfony\Component\Mime\Email;
use Tests\Support\NotificationOutboxTestFactory;
use Tests\TestCase;

/**
 * EPIC-01-MAIL-1 – Staging-Empfänger-Allowlist über MessageSending.
 *
 * Nutzt den echten Mailer mit Transport „array“ (kein Netzwerk, kein Mail::fake).
 */
class StagingMailRecipientGuardTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'mail.default' => 'array',
            'dispo.mail_recipient_guard' => StagingMailRecipientGuard::MODE_OFF,
            'dispo.mail_recipient_allowlist' => [],
        ]);
    }

    private function enableAllowlist(array $emails): void
    {
        config([
            'dispo.mail_recipient_guard' => StagingMailRecipientGuard::MODE_ALLOWLIST,
            'dispo.mail_recipient_allowlist' => array_values($emails),
        ]);
    }

    private function setAppEnvironment(string $environment): void
    {
        $this->app['env'] = $environment;
    }

    /**
     * Staging + Live-Mailer-Name (smtp), Versand über array-Mailer (kein Netzwerk).
     */
    private function configureStagingLiveMailIntent(): void
    {
        $this->setAppEnvironment('staging');
        config(['mail.default' => 'smtp']);
    }

    private function arrayTransport(): ArrayTransport
    {
        $transport = Mail::mailer('array')->getSymfonyTransport();
        $this->assertInstanceOf(ArrayTransport::class, $transport);

        return $transport;
    }

    private function sentCount(): int
    {
        return $this->arrayTransport()->messages()->count();
    }

    private function sendRaw(string $to, ?string $cc = null, ?string $bcc = null): void
    {
        // Immer array-Mailer: MessageSending läuft trotzdem; kein Netzwerk.
        Mail::mailer('array')->send([], [], function ($message) use ($to, $cc, $bcc): void {
            $message->to($to)->subject('Guard-Test')->text('body');
            if ($cc !== null) {
                $message->cc($cc);
            }
            if ($bcc !== null) {
                $message->bcc($bcc);
            }
        });
    }

    public function test_allowed_single_recipient_is_sent(): void
    {
        $this->enableAllowlist(['allowed@example.test']);

        $this->sendRaw('Allowed@example.test');

        $this->assertSame(1, $this->sentCount());
    }

    public function test_disallowed_recipient_blocks_entire_message(): void
    {
        $this->enableAllowlist(['allowed@example.test']);

        try {
            $this->sendRaw('other@example.test');
            $this->fail('Erwartete StagingMailRecipientBlockedException.');
        } catch (StagingMailRecipientBlockedException $exception) {
            $this->assertStringContainsString('Allowlist', $exception->getMessage());
        }

        $this->assertSame(0, $this->sentCount());
    }

    public function test_mixed_recipients_block_entire_message(): void
    {
        $this->enableAllowlist(['allowed@example.test']);

        try {
            Mail::mailer()->send([], [], function ($message): void {
                $message->to(['allowed@example.test', 'other@example.test'])
                    ->subject('Guard-Test')
                    ->text('body');
            });
            $this->fail('Erwartete StagingMailRecipientBlockedException.');
        } catch (StagingMailRecipientBlockedException) {
            // erwartet
        }

        $this->assertSame(0, $this->sentCount());
    }

    public function test_disallowed_cc_blocks_message(): void
    {
        $this->enableAllowlist(['allowed@example.test']);

        try {
            $this->sendRaw('allowed@example.test', 'cc@example.test');
            $this->fail('Erwartete StagingMailRecipientBlockedException.');
        } catch (StagingMailRecipientBlockedException) {
            // erwartet
        }

        $this->assertSame(0, $this->sentCount());
    }

    public function test_disallowed_bcc_blocks_message(): void
    {
        $this->enableAllowlist(['allowed@example.test']);

        try {
            $this->sendRaw('allowed@example.test', null, 'bcc@example.test');
            $this->fail('Erwartete StagingMailRecipientBlockedException.');
        } catch (StagingMailRecipientBlockedException) {
            // erwartet
        }

        $this->assertSame(0, $this->sentCount());
    }

    public function test_empty_allowlist_fail_closed(): void
    {
        $this->enableAllowlist([]);

        try {
            $this->sendRaw('anyone@example.test');
            $this->fail('Erwartete StagingMailRecipientBlockedException.');
        } catch (StagingMailRecipientBlockedException $exception) {
            $this->assertStringContainsString('leer oder ungültig', $exception->getMessage());
        }

        $this->assertSame(0, $this->sentCount());
    }

    public function test_invalid_allowlist_entry_fail_closed(): void
    {
        $this->enableAllowlist(['not-an-email', 'ok@example.test']);

        try {
            $this->sendRaw('ok@example.test');
            $this->fail('Erwartete StagingMailRecipientBlockedException.');
        } catch (StagingMailRecipientBlockedException $exception) {
            $this->assertStringContainsString('leer oder ungültig', $exception->getMessage());
        }

        $this->assertSame(0, $this->sentCount());
    }

    public function test_guard_off_allows_any_recipient_production_default(): void
    {
        $this->setAppEnvironment('production');
        config([
            'mail.default' => 'array',
            'dispo.mail_recipient_guard' => StagingMailRecipientGuard::MODE_OFF,
            'dispo.mail_recipient_allowlist' => [],
        ]);

        $this->sendRaw('anyone@example.test');

        $this->assertSame(1, $this->sentCount());
        $this->assertFalse(app(StagingMailRecipientGuard::class)->isEnabled());
        $this->assertFalse(app(StagingMailRecipientGuard::class)->mustEnforceAllowlist());
    }

    public function test_staging_live_mail_with_guard_off_is_blocked(): void
    {
        $this->configureStagingLiveMailIntent();
        config([
            'dispo.mail_recipient_guard' => StagingMailRecipientGuard::MODE_OFF,
            'dispo.mail_recipient_allowlist' => ['allowed@example.test'],
        ]);

        try {
            $this->sendRaw('allowed@example.test');
            $this->fail('Erwartete StagingMailRecipientBlockedException.');
        } catch (StagingMailRecipientBlockedException $exception) {
            $this->assertStringContainsString('allowlist', $exception->getMessage());
        }

        $this->assertSame(0, $this->sentCount());
    }

    public function test_staging_live_mail_with_missing_guard_is_blocked(): void
    {
        $this->configureStagingLiveMailIntent();
        config([
            'dispo.mail_recipient_guard' => '',
            'dispo.mail_recipient_allowlist' => ['allowed@example.test'],
        ]);

        try {
            $this->sendRaw('allowed@example.test');
            $this->fail('Erwartete StagingMailRecipientBlockedException.');
        } catch (StagingMailRecipientBlockedException $exception) {
            $this->assertStringContainsString('allowlist', $exception->getMessage());
        }

        $this->assertSame(0, $this->sentCount());
    }

    public function test_staging_live_mail_with_invalid_guard_mode_is_blocked(): void
    {
        $this->configureStagingLiveMailIntent();
        config([
            'dispo.mail_recipient_guard' => 'alowlist',
            'dispo.mail_recipient_allowlist' => ['allowed@example.test'],
        ]);

        $this->assertSame(
            StagingMailRecipientGuard::MODE_INVALID,
            app(StagingMailRecipientGuard::class)->mode(),
        );

        try {
            $this->sendRaw('allowed@example.test');
            $this->fail('Erwartete StagingMailRecipientBlockedException.');
        } catch (StagingMailRecipientBlockedException $exception) {
            $this->assertStringContainsString('allowlist', $exception->getMessage());
        }

        $this->assertSame(0, $this->sentCount());
    }

    public function test_staging_live_mail_with_allowlist_allows_recipient(): void
    {
        $this->configureStagingLiveMailIntent();
        $this->enableAllowlist(['allowed@example.test']);

        $this->sendRaw('allowed@example.test');

        $this->assertSame(1, $this->sentCount());
    }

    public function test_staging_safe_mailer_with_guard_off_allows_send(): void
    {
        $this->setAppEnvironment('staging');
        config([
            'mail.default' => 'log',
            'dispo.mail_recipient_guard' => StagingMailRecipientGuard::MODE_OFF,
            'dispo.mail_recipient_allowlist' => [],
        ]);

        $this->assertFalse(app(StagingMailRecipientGuard::class)->mustEnforceAllowlist());
        $this->sendRaw('anyone@example.test');
        $this->assertSame(1, $this->sentCount());
    }

    public function test_outbox_mail_allowed_marks_sent(): void
    {
        $this->enableAllowlist(['advisor@example.test']);

        $row = app(NotificationOutboxWriter::class)->enqueue(
            NotificationOutboxTestFactory::intent([
                'eventType' => DispoOrderSalesInquiryService::EVENT_ASKED,
                'sourceType' => DispoOrderSalesInquiryService::SOURCE_TYPE_COMMENT,
                'sourceId' => 901,
                'recipientEmail' => 'advisor@example.test',
            ]),
        );
        app(NotificationOutboxStateMachine::class)->markQueued($row);

        app(NotificationOutboxDeliveryService::class)->deliver($row->id);

        $this->assertSame(NotificationOutboxStatus::Sent, $row->fresh()->status);
        $this->assertSame(1, $this->sentCount());
    }

    public function test_outbox_mail_blocked_marks_failed_not_sent(): void
    {
        $this->enableAllowlist(['other-tester@example.test']);

        $row = app(NotificationOutboxWriter::class)->enqueue(
            NotificationOutboxTestFactory::intent([
                'eventType' => DispoOrderSalesInquiryService::EVENT_ASKED,
                'sourceType' => DispoOrderSalesInquiryService::SOURCE_TYPE_COMMENT,
                'sourceId' => 902,
                'recipientEmail' => 'advisor@example.test',
            ]),
        );
        app(NotificationOutboxStateMachine::class)->markQueued($row);

        app(NotificationOutboxDeliveryService::class)->deliver($row->id);

        $fresh = $row->fresh();
        $this->assertSame(NotificationOutboxStatus::Failed, $fresh->status);
        $this->assertNull($fresh->sent_at);
        $this->assertStringContainsString('Allowlist', (string) $fresh->last_error);
        $this->assertSame(0, $this->sentCount());
    }

    public function test_queue_job_respects_allowlist(): void
    {
        $this->enableAllowlist(['advisor@example.test']);

        $row = app(NotificationOutboxWriter::class)->enqueue(
            NotificationOutboxTestFactory::intent([
                'eventType' => DispoOrderSalesInquiryService::EVENT_ASKED,
                'sourceType' => DispoOrderSalesInquiryService::SOURCE_TYPE_COMMENT,
                'sourceId' => 903,
                'recipientEmail' => 'advisor@example.test',
            ]),
        );
        app(NotificationOutboxStateMachine::class)->markQueued($row);

        (new DeliverSalesInquiryOutboxJob($row->id))
            ->handle(app(NotificationOutboxDeliveryService::class));

        $this->assertSame(NotificationOutboxStatus::Sent, $row->fresh()->status);
        $this->assertSame(1, $this->sentCount());
    }

    public function test_password_reset_allowed_and_blocked(): void
    {
        $this->skipUnlessFortifyHas(Features::resetPasswords());

        $allowed = User::factory()->create(['email' => 'reset-ok@example.test']);
        $blocked = User::factory()->create(['email' => 'reset-blocked@example.test']);

        $this->enableAllowlist(['reset-ok@example.test']);

        $this->post(route('password.email'), ['email' => $allowed->email])
            ->assertSessionHasNoErrors();
        $this->assertSame(1, $this->sentCount());

        $this->withoutExceptionHandling();
        try {
            $this->post(route('password.email'), ['email' => $blocked->email]);
            $this->fail('Erwartete StagingMailRecipientBlockedException.');
        } catch (StagingMailRecipientBlockedException) {
            // erwartet
        }

        $this->assertSame(1, $this->sentCount());
    }

    public function test_guard_collects_to_cc_bcc_on_symfony_email(): void
    {
        $guard = app(StagingMailRecipientGuard::class);
        $email = (new Email)
            ->to('a@example.test')
            ->cc('b@example.test')
            ->bcc('c@example.test')
            ->subject('x')
            ->text('y');

        $this->assertSame(
            ['a@example.test', 'b@example.test', 'c@example.test'],
            $guard->collectRecipients($email),
        );
    }
}
