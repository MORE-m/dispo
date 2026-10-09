<?php

namespace App\Support\Crm;

/**
 * Domain-/E-Mail-Normalisierung für CRM-Matching (BL-P2-03a).
 * Subdomains werden nicht auf die Hauptdomain reduziert.
 */
final class CrmDomainNormalizer
{
    /**
     * @return array{
     *     emails: list<string>,
     *     domains: list<string>,
     *     unique_domain: string|null,
     *     divergent: bool,
     *     invalid: list<string>
     * }
     */
    public static function analyzeEmailField(?string $raw): array
    {
        $raw = trim((string) $raw);
        if ($raw === '') {
            return [
                'emails' => [],
                'domains' => [],
                'unique_domain' => null,
                'divergent' => false,
                'invalid' => [],
            ];
        }

        $parts = preg_split('/[;,\s]+/u', $raw) ?: [];
        $emails = [];
        $domains = [];
        $invalid = [];

        foreach ($parts as $part) {
            $candidate = trim($part);
            if ($candidate === '') {
                continue;
            }
            $email = self::normalizeEmail($candidate);
            if ($email === null) {
                $invalid[] = $candidate;

                continue;
            }
            $domain = self::domainFromEmail($email);
            if ($domain === null) {
                $invalid[] = $candidate;

                continue;
            }
            $emails[] = $email;
            $domains[] = $domain;
        }

        $uniqueDomains = array_values(array_unique($domains));

        return [
            'emails' => array_values(array_unique($emails)),
            'domains' => $uniqueDomains,
            'unique_domain' => count($uniqueDomains) === 1 ? $uniqueDomains[0] : null,
            'divergent' => count($uniqueDomains) > 1,
            'invalid' => $invalid,
        ];
    }

    public static function normalizeEmail(string $value): ?string
    {
        $value = strtolower(trim($value));
        if ($value === '' || filter_var($value, FILTER_VALIDATE_EMAIL) === false) {
            return null;
        }

        return $value;
    }

    public static function domainFromEmail(string $email): ?string
    {
        $email = self::normalizeEmail($email);
        if ($email === null) {
            return null;
        }
        $pos = strrpos($email, '@');
        if ($pos === false) {
            return null;
        }
        $domain = substr($email, $pos + 1);

        return $domain !== '' ? $domain : null;
    }

    public static function normalizeDomain(?string $domain): ?string
    {
        if ($domain === null) {
            return null;
        }
        $domain = strtolower(trim($domain));
        if ($domain === '' || str_contains($domain, '@') || str_contains($domain, ' ')) {
            return null;
        }
        if (! preg_match('/^[a-z0-9]([a-z0-9.-]*[a-z0-9])?$/i', $domain)) {
            return null;
        }

        return $domain;
    }
}
