<?php

namespace App\Support\Crm;

use App\Models\CrmAccount;

/**
 * Verständliche Account-Kurztexte für Stammdaten/Prüfliste (PO-BLP203-1 / Audit P3).
 * Keine erfundenen Felder; Firmierung primär, technische IDs nur sekundär.
 */
final class CrmAccountPresentation
{
    /**
     * @param  array<string, mixed>  $account  serializeAccount()-Form
     * @return array{
     *     title: string,
     *     meta: list<string>,
     *     identity: list<string>
     * }
     */
    public static function fromSerialized(array $account): array
    {
        $name = self::stringOrNull($account['name'] ?? null);
        $title = $name ?? 'Ohne Firmierung';

        $meta = [];
        $typeLabel = self::stringOrNull($account['type_label'] ?? null);
        if ($typeLabel !== null) {
            $meta[] = ! empty($account['is_provisional'])
                ? $typeLabel.' · vorläufig'
                : $typeLabel;
        } elseif (! empty($account['is_provisional'])) {
            $meta[] = 'vorläufig';
        }

        $domain = self::stringOrNull($account['matching_domain'] ?? null);
        if ($domain !== null) {
            $meta[] = $domain;
        }

        if (! empty($account['meridian_pending'])) {
            $meta[] = 'Meridian-Nummer folgt';
        } else {
            $meridian = self::stringOrNull($account['meridian_number'] ?? null);
            if ($meridian !== null) {
                $meta[] = 'Meridian '.$meridian;
            }
        }

        $recordType = self::stringOrNull($account['salesforce_record_type'] ?? null);
        if ($recordType !== null) {
            $meta[] = $recordType;
        }

        $identity = [];
        $sfRaw = self::stringOrNull($account['salesforce_account_id_raw'] ?? null);
        if ($sfRaw !== null) {
            $identity[] = 'Salesforce '.$sfRaw;
        }

        return [
            'title' => $title,
            'meta' => $meta,
            'identity' => $identity,
        ];
    }

    /**
     * Einzeiler für Listen/Konflikte (ohne Tag-Soup).
     *
     * @param  array<string, mixed>  $account
     */
    public static function oneLine(array $account): string
    {
        $parts = self::fromSerialized($account);
        $chunks = array_merge([$parts['title']], $parts['meta'], $parts['identity']);

        return implode(' · ', $chunks);
    }

    public static function unresolvedReferenceFallback(): string
    {
        return 'Account-Referenz nicht mehr verfügbar';
    }

    /**
     * @return array{title: string, meta: list<string>, identity: list<string>}|null
     */
    public static function fromModel(?CrmAccount $account): ?array
    {
        if ($account === null) {
            return null;
        }

        $account->loadMissing('currentVersion');
        $version = $account->currentVersion;

        return self::fromSerialized([
            'name' => $version?->name,
            'type_label' => $account->type->label(),
            'is_provisional' => $account->is_provisional,
            'matching_domain' => $account->matching_domain,
            'meridian_number' => $version?->meridian_number,
            'meridian_pending' => $version === null
                || $version->meridian_number === null
                || $version->meridian_number === '',
            'salesforce_record_type' => $version?->salesforce_record_type,
            'salesforce_account_id_raw' => $account->salesforce_account_id_raw,
        ]);
    }

    private static function stringOrNull(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }
}
