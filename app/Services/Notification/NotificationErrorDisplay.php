<?php

namespace App\Services\Notification;

/**
 * Sichere Anzeige von Outbox-`last_error` (PO-NOT002-ADMIN-1).
 * Speichert nichts; maskiert und kürzt nur für Inertia-Props.
 */
final class NotificationErrorDisplay
{
    public const int LIST_MAX_CHARS = 160;

    public const int DETAIL_MAX_CHARS = 500;

    public const string UNSAFE_FALLBACK = 'Technischer Zustellfehler (Details aus Sicherheitsgründen nicht anzeigbar).';

    /**
     * @return array{text: string|null, truncated: bool}
     */
    public function forList(?string $raw): array
    {
        return $this->present($raw, self::LIST_MAX_CHARS);
    }

    /**
     * @return array{text: string|null, truncated: bool}
     */
    public function forDetail(?string $raw): array
    {
        return $this->present($raw, self::DETAIL_MAX_CHARS);
    }

    /**
     * @return array{text: string|null, truncated: bool}
     */
    private function present(?string $raw, int $maxChars): array
    {
        if ($raw === null) {
            return ['text' => null, 'truncated' => false];
        }

        $trimmed = trim($raw);
        if ($trimmed === '') {
            return ['text' => null, 'truncated' => false];
        }

        $masked = $this->maskSecrets($trimmed);
        if ($masked === null) {
            return ['text' => self::UNSAFE_FALLBACK, 'truncated' => false];
        }

        if (mb_strlen($masked) <= $maxChars) {
            return ['text' => $masked, 'truncated' => false];
        }

        return [
            'text' => mb_substr($masked, 0, $maxChars).'…',
            'truncated' => true,
        ];
    }

    private function maskSecrets(string $text): ?string
    {
        $patterns = [
            '/(?i)(password|passwd|pwd)\s*[=:]\s*\S+/u',
            '/(?i)(secret|api[_-]?key|access[_-]?key|client[_-]?secret)\s*[=:]\s*\S+/u',
            '/(?i)authorization\s*[=:]\s*\S+/u',
            '/(?i)\bbearer\s+[A-Za-z0-9\-._~+\/=]+/u',
            '/(?i)\bbasic\s+[A-Za-z0-9+\/=]+/u',
            '#(?i)(https?://)([^:\s/@]+):([^@\s/]+)@#u',
        ];

        $replacements = [
            '$1=[redacted]',
            '$1=[redacted]',
            'Authorization=[redacted]',
            'Bearer [redacted]',
            'Basic [redacted]',
            '$1$2:[redacted]@',
        ];

        $masked = preg_replace($patterns, $replacements, $text);
        if (! is_string($masked)) {
            return null;
        }

        // Residual credential-like tokens after masking → fail closed for display.
        if (preg_match('/(?i)\b(password|passwd|secret|api[_-]?key|bearer|authorization)\b\s*[=:]\s*(?!\[redacted\])\S+/u', $masked) === 1) {
            return null;
        }

        return $masked;
    }
}
