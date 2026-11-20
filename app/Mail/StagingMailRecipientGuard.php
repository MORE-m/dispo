<?php

namespace App\Mail;

use App\Exceptions\StagingMailRecipientBlockedException;
use Symfony\Component\Mime\Email;

/**
 * EPIC-01-MAIL-1: zentrale Empfänger-Allowlist für Staging-SMTP.
 *
 * Fail-closed bei Mode „allowlist“: leere/ungültige Allowlist oder jeder
 * nicht freigegebene To/Cc/Bcc-Empfänger blockiert die gesamte Nachricht.
 * Keine Domain-Wildcards, keine Teilzustellung.
 */
final class StagingMailRecipientGuard
{
    public const string MODE_OFF = 'off';

    public const string MODE_ALLOWLIST = 'allowlist';

    public function isEnabled(): bool
    {
        return $this->mode() === self::MODE_ALLOWLIST;
    }

    public function mode(): string
    {
        $mode = strtolower(trim((string) config('dispo.mail_recipient_guard', self::MODE_OFF)));

        return $mode === self::MODE_ALLOWLIST ? self::MODE_ALLOWLIST : self::MODE_OFF;
    }

    /**
     * @return list<string> normalisierte Allowlist (lowercase) oder leer bei ungültiger Konfiguration
     */
    public function normalizedAllowlist(): array
    {
        $raw = config('dispo.mail_recipient_allowlist', []);
        if (! is_array($raw)) {
            return [];
        }

        $normalized = [];
        foreach ($raw as $entry) {
            if (! is_string($entry)) {
                return [];
            }

            $email = $this->normalizeAddress($entry);
            if ($email === null) {
                return [];
            }

            $normalized[$email] = $email;
        }

        return array_values($normalized);
    }

    /**
     * @throws StagingMailRecipientBlockedException
     */
    public function assertMessageAllowed(Email $message): void
    {
        if (! $this->isEnabled()) {
            return;
        }

        $allowlist = $this->normalizedAllowlist();
        if ($allowlist === []) {
            throw new StagingMailRecipientBlockedException(
                'Staging-Mailversand gesperrt: Allowlist leer oder ungültig.',
            );
        }

        $recipients = $this->collectRecipients($message);
        if ($recipients === []) {
            throw new StagingMailRecipientBlockedException(
                'Staging-Mailversand gesperrt: Nachricht ohne Empfänger.',
            );
        }

        $allowed = array_fill_keys($allowlist, true);
        $blocked = [];
        foreach ($recipients as $recipient) {
            if (! isset($allowed[$recipient])) {
                $blocked[] = $recipient;
            }
        }

        if ($blocked !== []) {
            throw new StagingMailRecipientBlockedException(
                'Staging-Mailversand gesperrt: Empfänger nicht auf Allowlist ('.count($blocked).').',
            );
        }
    }

    public function normalizeAddress(string $value): ?string
    {
        $email = strtolower(trim($value));
        if ($email === '' || ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return null;
        }

        return $email;
    }

    /**
     * @return list<string>
     */
    public function collectRecipients(Email $message): array
    {
        $addresses = array_merge(
            $message->getTo(),
            $message->getCc(),
            $message->getBcc(),
        );

        $normalized = [];
        foreach ($addresses as $address) {
            $email = $this->normalizeAddress($address->getAddress());
            if ($email === null) {
                continue;
            }

            $normalized[$email] = $email;
        }

        return array_values($normalized);
    }
}
