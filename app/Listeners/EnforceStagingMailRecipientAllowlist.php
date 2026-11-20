<?php

namespace App\Listeners;

use App\Exceptions\StagingMailRecipientBlockedException;
use App\Mail\StagingMailRecipientGuard;
use Illuminate\Mail\Events\MessageSending;
use Illuminate\Support\Facades\Log;

/**
 * EPIC-01-MAIL-1: MessageSending-Hook für alle Laravel-Mail-Pfade
 * (Mailable, Notification/MailChannel, Outbox → Mail::send).
 *
 * Bewusst Exception statt return false: sonst würde Mail::send() null
 * zurückgeben und die Outbox fälschlich markSent aufrufen.
 *
 * Ruft den Guard immer auf (kein Early-Return bei Mode off), damit
 * Staging+Live-Mail ohne Allowlist fail-closed bleibt.
 */
final class EnforceStagingMailRecipientAllowlist
{
    public function __construct(
        private readonly StagingMailRecipientGuard $guard,
    ) {}

    /**
     * @throws StagingMailRecipientBlockedException
     */
    public function handle(MessageSending $event): void
    {
        try {
            $this->guard->assertMessageAllowed($event->message);
        } catch (StagingMailRecipientBlockedException $exception) {
            Log::warning('dispo.mail.recipient_guard.blocked', [
                'reason' => $exception->getMessage(),
                'recipient_count' => count($this->guard->collectRecipients($event->message)),
                'allowlist_count' => count($this->guard->normalizedAllowlist()),
                'mode' => $this->guard->mode(),
                'must_enforce' => $this->guard->mustEnforceAllowlist(),
                // Keine Betreff-/Body-Inhalte, keine Roh-Allowlist-Dump in Logs.
            ]);

            throw $exception;
        }
    }
}
