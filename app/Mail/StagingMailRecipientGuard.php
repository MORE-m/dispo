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
 *
 * Zusätzlich: APP_ENV=staging mit Live-Mailer (nicht log/array) erfordert
 * explizit Mode „allowlist“. off, fehlend oder Tippfehler → kein Versand.
 */
final class StagingMailRecipientGuard
{
    public const string MODE_OFF = 'off';

    public const string MODE_ALLOWLIST = 'allowlist';

    public const string MODE_INVALID = 'invalid';

    /** @var list<string> */
    private const array SAFE_MAILER_NAMES = ['log', 'array'];

    public function isEnabled(): bool
    {
        return $this->mode() === self::MODE_ALLOWLIST;
    }

    /**
     * Bekannte Modi: off | allowlist. Alles andere (Tippfehler) = invalid.
     * Leerer Wert gilt als off (Default), wird aber unter Staging+Live gesperrt.
     */
    public function mode(): string
    {
        $mode = strtolower(trim((string) config('dispo.mail_recipient_guard', self::MODE_OFF)));

        if ($mode === '' || $mode === self::MODE_OFF) {
            return self::MODE_OFF;
        }

        if ($mode === self::MODE_ALLOWLIST) {
            return self::MODE_ALLOWLIST;
        }

        return self::MODE_INVALID;
    }

    /**
     * Staging + Live-Mailer (MAIL_MAILER nicht log/array) → Allowlist Pflicht.
     */
    public function mustEnforceAllowlist(): bool
    {
        return $this->isStagingEnvironment() && $this->usesLiveMailTransport();
    }

    public function isStagingEnvironment(): bool
    {
        return app()->environment('staging');
    }

    /**
     * Live = konfigurierter Default-Mailer-Name ist nicht log/array.
     * Bewusst der Mailer-Name (MAIL_MAILER), nicht der Symfony-Transport,
     * damit Staging-SMTP auch bei Test-Transporten korrekt erkannt wird.
     */
    public function usesLiveMailTransport(): bool
    {
        $mailer = strtolower(trim((string) config('mail.default', 'smtp')));

        return ! in_array($mailer, self::SAFE_MAILER_NAMES, true);
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
        $mode = $this->mode();

        if ($this->mustEnforceAllowlist()) {
            if ($mode !== self::MODE_ALLOWLIST) {
                throw new StagingMailRecipientBlockedException(
                    'Staging-Mailversand gesperrt: Live-Mail erfordert DISPO_MAIL_RECIPIENT_GUARD=allowlist.',
                );
            }
        } elseif ($mode !== self::MODE_ALLOWLIST) {
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
