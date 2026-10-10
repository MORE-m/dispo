<?php

namespace App\Support\Crm;

/**
 * Salesforce 15-/18-stellige Account-IDs.
 * 15-stellig ist case-sensitive; 18-stellig ergänzt eine Case-Checksumme.
 */
final class SalesforceAccountId
{
    private const string CHECKSUM = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ012345';

    /**
     * @return array{raw: string, canonical: string}|null
     */
    public static function normalize(string $value): ?array
    {
        $raw = trim($value);
        if ($raw === '') {
            return null;
        }

        if (preg_match('/^[A-Za-z0-9]{15}$/', $raw) === 1) {
            return [
                'raw' => $raw,
                'canonical' => self::toEighteen($raw),
            ];
        }

        if (preg_match('/^[A-Za-z0-9]{18}$/', $raw) === 1) {
            $base = substr($raw, 0, 15);
            $suffix = substr($raw, 15, 3);
            $expected = self::toEighteen($base);
            if (strcasecmp($suffix, substr($expected, 15, 3)) !== 0) {
                return null;
            }

            return [
                'raw' => $raw,
                'canonical' => $expected,
            ];
        }

        return null;
    }

    public static function toEighteen(string $fifteen): string
    {
        if (strlen($fifteen) !== 15) {
            throw new \InvalidArgumentException('Salesforce-ID muss 15 Zeichen haben.');
        }

        $suffix = '';
        for ($block = 0; $block < 3; $block++) {
            $flags = 0;
            for ($bit = 0; $bit < 5; $bit++) {
                $char = $fifteen[($block * 5) + $bit];
                if ($char >= 'A' && $char <= 'Z') {
                    $flags |= 1 << $bit;
                }
            }
            $suffix .= self::CHECKSUM[$flags];
        }

        return $fifteen.$suffix;
    }

    public static function sameIdentity(?string $a, ?string $b): bool
    {
        if ($a === null || $b === null || trim($a) === '' || trim($b) === '') {
            return false;
        }
        $na = self::normalize($a);
        $nb = self::normalize($b);
        if ($na === null || $nb === null) {
            return false;
        }

        return $na['canonical'] === $nb['canonical'];
    }
}
