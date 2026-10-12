<?php

namespace App\Support\Crm;

use App\Enums\CrmAccountType;
use App\Enums\CrmConflictType;
use App\Enums\DispoOrderStatus;
use App\Models\CrmConflict;
use Illuminate\Support\Collection;

/**
 * Fachliche Konfliktzusammenfassung für die Prüfliste (Audit P2).
 * Liest nur gespeicherte details; mutiert nichts.
 */
final class CrmConflictPresentation
{
    /**
     * @param  Collection<int, array<string, mixed>>  $accountsById  serializeAccount()-Form
     * @param  Collection<int, mixed>  $calculationsById
     * @param  Collection<int, mixed>  $dispoOrdersById
     * @return array{
     *     summary_lines: list<string>,
     *     account_expected: bool,
     *     entity_link: array{label: string, url: string}|null,
     *     technical_details: list<array{key: string, value: string}>
     * }
     */
    public static function summarize(
        CrmConflict $conflict,
        Collection $accountsById,
        Collection $calculationsById,
        Collection $dispoOrdersById,
        bool $canViewCalculations,
        bool $canViewDispoOrders,
    ): array {
        $details = $conflict->details ?? [];
        $consumed = [];
        $lines = [];
        $entityLink = null;

        match ($conflict->type) {
            CrmConflictType::OrderMeridianMismatch => self::appendOrderMeridian(
                $details,
                $calculationsById,
                $dispoOrdersById,
                $canViewCalculations,
                $canViewDispoOrders,
                $lines,
                $consumed,
                $entityLink,
            ),
            CrmConflictType::MeridianMismatch => self::appendStammdatenMeridian($details, $lines, $consumed),
            CrmConflictType::LinkConflict => self::appendLinkConflict($details, $accountsById, $lines, $consumed),
            CrmConflictType::AmbiguousDomain => self::appendAmbiguousDomain($details, $accountsById, $lines, $consumed),
            CrmConflictType::DivergentDomains => self::appendDivergentDomains($details, $lines, $consumed),
            CrmConflictType::TypeChange => self::appendTypeChange($details, $lines, $consumed),
            CrmConflictType::InvalidSalesforceId,
            CrmConflictType::DuplicateContradictoryRow => self::appendGenericMessage($details, $lines, $consumed),
        };

        if ($lines === []) {
            self::appendGenericMessage($details, $lines, $consumed);
        }

        return [
            'summary_lines' => $lines,
            'account_expected' => $conflict->crm_account_id !== null,
            'entity_link' => $entityLink,
            'technical_details' => self::technicalDetails($details, $consumed),
        ];
    }

    /**
     * @param  array<string, mixed>  $details
     * @param  Collection<int, mixed>  $calculationsById
     * @param  Collection<int, mixed>  $dispoOrdersById
     * @param  list<string>  $lines
     * @param  list<string>  $consumed
     * @param  array{label: string, url: string}|null  $entityLink
     */
    private static function appendOrderMeridian(
        array $details,
        Collection $calculationsById,
        Collection $dispoOrdersById,
        bool $canViewCalculations,
        bool $canViewDispoOrders,
        array &$lines,
        array &$consumed,
        ?array &$entityLink,
    ): void {
        $entity = self::stringOrNull($details['entity'] ?? null);
        $entityId = self::positiveInt($details['entity_id'] ?? null);
        $field = self::stringOrNull($details['field'] ?? null);
        self::markConsumed($consumed, ['entity', 'entity_id', 'field', 'existing', 'incoming', 'order_status']);

        $side = match ($field) {
            'customer_meridian_number' => 'Kunde',
            'agency_meridian_number' => 'Agentur',
            default => null,
        };

        if ($entity === 'calculation' && $entityId !== null) {
            $row = $calculationsById->get($entityId);
            if (! $canViewCalculations) {
                $lines[] = 'Betroffener Vorgang: Kalkulation nicht einsehbar (keine Berechtigung)';
            } elseif (! is_object($row)) {
                $lines[] = 'Betroffener Vorgang: Kalkulation-Referenz nicht mehr verfügbar';
            } else {
                $number = self::stringOrNull($row->number ?? null) ?? 'ohne Vorgangsnummer';
                $lines[] = 'Betroffener Vorgang: Kalkulation '.$number;
                $entityLink = [
                    'label' => 'Zur Kalkulation '.$number,
                    'url' => route('calculations.edit', ['calculation' => $entityId]),
                ];
            }
        } elseif ($entity === 'dispo_order' && $entityId !== null) {
            $row = $dispoOrdersById->get($entityId);
            if (! $canViewDispoOrders) {
                $lines[] = 'Betroffener Vorgang: Dispoauftrag nicht einsehbar (keine Berechtigung)';
            } elseif (! is_object($row)) {
                $lines[] = 'Betroffener Vorgang: Dispoauftrag-Referenz nicht mehr verfügbar';
            } else {
                $number = self::stringOrNull($row->number ?? null) ?? 'ohne Vorgangsnummer';
                $lines[] = 'Betroffener Vorgang: Dispoauftrag '.$number;
                $entityLink = [
                    'label' => 'Zum Dispoauftrag '.$number,
                    'url' => route('dispo-orders.show', ['dispoOrder' => $entityId]),
                ];
            }
        } elseif ($entity !== null || $entityId !== null) {
            $lines[] = 'Betroffener Vorgang: Auftragsreferenz unvollständig oder unbekannt';
        } else {
            $lines[] = 'Betroffener Vorgang: Auftragsreferenz fehlt';
        }

        if ($side !== null) {
            $lines[] = 'Betroffene Seite: '.$side;
        } elseif ($field !== null) {
            $lines[] = 'Betroffene Seite: '.$field;
        }

        $lines[] = 'Bestehende Meridian-Nummer: '.self::displayScalar($details['existing'] ?? null);
        $lines[] = 'Eingehende Meridian-Nummer: '.self::displayScalar($details['incoming'] ?? null);

        if (array_key_exists('order_status', $details)) {
            $stored = self::stringOrNull($details['order_status'] ?? null);
            $statusLabel = $stored !== null
                ? (DispoOrderStatus::tryFrom($stored)?->label() ?? $stored)
                : '–';
            $lines[] = 'Gespeicherter Auftragsstatus (Konfliktstand): '.$statusLabel;
        }
    }

    /**
     * @param  array<string, mixed>  $details
     * @param  list<string>  $lines
     * @param  list<string>  $consumed
     */
    private static function appendStammdatenMeridian(array $details, array &$lines, array &$consumed): void
    {
        self::markConsumed($consumed, ['existing_meridian', 'incoming_meridian', 'salesforce_canonical']);
        $lines[] = 'Bestehende Meridian-Nummer: '.self::displayScalar($details['existing_meridian'] ?? null);
        $lines[] = 'Eingehende Meridian-Nummer: '.self::displayScalar($details['incoming_meridian'] ?? null);
        self::appendSalesforceCanonical($details, $lines);
    }

    /**
     * @param  array<string, mixed>  $details
     * @param  Collection<int, array<string, mixed>>  $accountsById
     * @param  list<string>  $lines
     * @param  list<string>  $consumed
     */
    private static function appendLinkConflict(
        array $details,
        Collection $accountsById,
        array &$lines,
        array &$consumed,
    ): void {
        self::markConsumed($consumed, ['message', 'existing_target_id', 'requested_target_id']);
        self::appendMessage($details, $lines);
        self::appendAccountRef($details, 'existing_target_id', 'Bestehendes Ziel', $accountsById, $lines);
        self::appendAccountRef($details, 'requested_target_id', 'Angefragtes Ziel', $accountsById, $lines);
    }

    /**
     * @param  array<string, mixed>  $details
     * @param  Collection<int, array<string, mixed>>  $accountsById
     * @param  list<string>  $lines
     * @param  list<string>  $consumed
     */
    private static function appendAmbiguousDomain(
        array $details,
        Collection $accountsById,
        array &$lines,
        array &$consumed,
    ): void {
        self::markConsumed($consumed, ['domain', 'candidate_ids']);
        $domain = self::stringOrNull($details['domain'] ?? null);
        $lines[] = 'Konfliktdomain: '.($domain ?? '–');

        $ids = $details['candidate_ids'] ?? null;
        if (! is_array($ids) || $ids === []) {
            $lines[] = 'Kandidaten: keine IDs gespeichert';

            return;
        }

        foreach ($ids as $index => $rawId) {
            $id = self::positiveInt($rawId);
            if ($id === null) {
                $lines[] = 'Kandidat '.((int) $index + 1).': ungültige Referenz';

                continue;
            }
            $account = $accountsById->get($id);
            if (is_array($account)) {
                $lines[] = 'Kandidat '.((int) $index + 1).': '.CrmAccountPresentation::oneLine($account);
            } else {
                $lines[] = 'Kandidat '.((int) $index + 1).': '.CrmAccountPresentation::unresolvedReferenceFallback();
            }
        }
    }

    /**
     * @param  array<string, mixed>  $details
     * @param  list<string>  $lines
     * @param  list<string>  $consumed
     */
    private static function appendDivergentDomains(array $details, array &$lines, array &$consumed): void
    {
        self::markConsumed($consumed, ['line', 'domains', 'salesforce_canonical']);
        $lines[] = 'Importkonflikt ohne Account-Zuordnung (keine verschwundene Account-Referenz)';

        $domains = $details['domains'] ?? null;
        if (is_array($domains)) {
            $parts = [];
            foreach ($domains as $domain) {
                $parts[] = self::displayScalar($domain);
            }
            $lines[] = 'Domains: '.($parts === [] ? '–' : implode(', ', $parts));
        } else {
            $lines[] = 'Domains: –';
        }

        if (array_key_exists('line', $details)) {
            $lines[] = 'Exportzeile: '.self::displayScalar($details['line']);
        }
        self::appendSalesforceCanonical($details, $lines);
    }

    /**
     * @param  array<string, mixed>  $details
     * @param  list<string>  $lines
     * @param  list<string>  $consumed
     */
    private static function appendTypeChange(array $details, array &$lines, array &$consumed): void
    {
        self::markConsumed($consumed, ['message', 'existing_type', 'incoming_type', 'salesforce_canonical']);
        self::appendMessage($details, $lines);
        $lines[] = 'Bestehender Typ: '.self::accountTypeLabel($details['existing_type'] ?? null);
        $lines[] = 'Eingehender Typ: '.self::accountTypeLabel($details['incoming_type'] ?? null);
        self::appendSalesforceCanonical($details, $lines);
    }

    /**
     * @param  array<string, mixed>  $details
     * @param  list<string>  $lines
     * @param  list<string>  $consumed
     */
    private static function appendGenericMessage(array $details, array &$lines, array &$consumed): void
    {
        if (isset($details['message']) && is_string($details['message']) && trim($details['message']) !== '') {
            self::markConsumed($consumed, ['message']);
            $lines[] = trim($details['message']);
        }

        if (array_key_exists('existing_meridian', $details) || array_key_exists('incoming_meridian', $details)) {
            self::appendStammdatenMeridian($details, $lines, $consumed);
        }
    }

    /**
     * @param  array<string, mixed>  $details
     * @param  list<string>  $lines
     */
    private static function appendMessage(array $details, array &$lines): void
    {
        if (isset($details['message']) && is_string($details['message']) && trim($details['message']) !== '') {
            $lines[] = trim($details['message']);
        }
    }

    /**
     * @param  array<string, mixed>  $details
     * @param  list<string>  $lines
     */
    private static function appendSalesforceCanonical(array $details, array &$lines): void
    {
        if (! array_key_exists('salesforce_canonical', $details)) {
            return;
        }
        $lines[] = 'Salesforce-Identität: '.self::displayScalar($details['salesforce_canonical']);
    }

    /**
     * @param  array<string, mixed>  $details
     * @param  Collection<int, array<string, mixed>>  $accountsById
     * @param  list<string>  $lines
     */
    private static function appendAccountRef(
        array $details,
        string $key,
        string $prefix,
        Collection $accountsById,
        array &$lines,
    ): void {
        if (! isset($details[$key]) || ! is_numeric($details[$key])) {
            return;
        }
        $id = (int) $details[$key];
        $related = $accountsById->get($id);
        if (is_array($related)) {
            $lines[] = $prefix.': '.CrmAccountPresentation::oneLine($related);
        } else {
            $lines[] = $prefix.': '.CrmAccountPresentation::unresolvedReferenceFallback();
        }
    }

    /**
     * @param  array<string, mixed>  $details
     * @param  list<string>  $consumed
     * @return list<array{key: string, value: string}>
     */
    private static function technicalDetails(array $details, array $consumed): array
    {
        $skip = array_fill_keys($consumed, true);
        $pairs = [];
        foreach ($details as $key => $value) {
            if (isset($skip[$key])) {
                continue;
            }
            if (in_array($key, ['resolution', 'resolved_note', 'resolved_by'], true)) {
                continue;
            }
            $pairs[] = [
                'key' => (string) $key,
                'value' => self::stringifyDetailValue($value),
            ];
        }

        return $pairs;
    }

    private static function stringifyDetailValue(mixed $value): string
    {
        if ($value === null) {
            return 'null';
        }
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }
        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }
        if (is_string($value)) {
            $trimmed = trim($value);

            return $trimmed === '' ? '""' : (mb_strlen($trimmed) > 200 ? mb_substr($trimmed, 0, 200).'…' : $trimmed);
        }
        if (is_array($value)) {
            $encoded = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            if (! is_string($encoded)) {
                return '[unserialisierbar]';
            }

            return mb_strlen($encoded) > 200 ? mb_substr($encoded, 0, 200).'…' : $encoded;
        }

        return '[nicht darstellbar]';
    }

    private static function accountTypeLabel(mixed $raw): string
    {
        if (! is_string($raw) || trim($raw) === '') {
            return '–';
        }
        $type = CrmAccountType::tryFrom(trim($raw));

        return $type?->label() ?? trim($raw);
    }

    private static function displayScalar(mixed $value): string
    {
        if ($value === null) {
            return '–';
        }
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }
        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }
        if (is_string($value)) {
            $trimmed = trim($value);

            return $trimmed === '' ? '–' : $trimmed;
        }

        return '–';
    }

    private static function stringOrNull(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }
        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }

    private static function positiveInt(mixed $value): ?int
    {
        if (is_int($value) && $value > 0) {
            return $value;
        }
        if (is_string($value) && ctype_digit($value) && (int) $value > 0) {
            return (int) $value;
        }

        return null;
    }

    /**
     * @param  list<string>  $consumed
     * @param  list<string>  $keys
     */
    private static function markConsumed(array &$consumed, array $keys): void
    {
        foreach ($keys as $key) {
            $consumed[] = $key;
        }
    }
}
