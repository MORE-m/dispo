<?php

namespace App\Services\Crm;

use App\Enums\CrmAccountType;
use App\Enums\InvoiceRecipient;
use App\Models\Calculation;
use App\Models\CrmAccount;
use App\Models\CrmAccountVersion;
use App\Models\User;
use Illuminate\Validation\ValidationException;

/**
 * Bindet CRM-Accounts an Calc-/Dispo-Kopf-Snapshots (BL-P2-03a + Wizard-Picker).
 *
 * Payload-Vertrag (CRM-001 / PO-BLP203-1 I1):
 * - Seite nicht mitgesendet (weder Account- noch Name-/Create-Schlüssel):
 *   bestehende Bindung bzw. historischer Freitext vollständig erhalten
 * - `*_account_id` = int: explizite Account-Auswahl
 * - `*_account_id` = null + Name: Freitext
 *   - Neuanlage / leerer Bestand / Wechsel von gebundenem Account → vorläufiger Account
 *   - historischer Freitext-Bestand → ohne Migration behalten,
 *     außer `*_create_provisional` / `ensure_provisional_*`
 * - `*_account_id` = null + leerer Name: Seite leeren
 * - fehlender `*_account_id` + Name: Neuanlage → Provisional; historischer Bestand → unlinked;
 *   gebundene Calc → bestehende Account-Identität erhalten
 *
 * Bestandsschutz am gespeicherten Zustand, nicht an der Payload-Form.
 * Preview ruft diesen Binder nicht auf. Unveränderte Account-Identität behält Version/Firmierung.
 */
final class CrmOrderHeaderBinder
{
    public function __construct(
        private readonly CrmAccountService $accounts,
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     * @return array{
     *     customer_name: ?string,
     *     agency_name: ?string,
     *     customer_account_id: int<1, max>|null,
     *     agency_account_id: int<1, max>|null,
     *     customer_version_id: int<1, max>|null,
     *     agency_version_id: int<1, max>|null,
     *     invoice_recipient: ?InvoiceRecipient,
     *     customer_meridian_number: ?string,
     *     agency_meridian_number: ?string,
     *     customer_salesforce_account_id: ?string,
     *     agency_salesforce_account_id: ?string
     * }
     */
    public function resolveForCalculation(
        array $payload,
        User $actor,
        bool $allowFreitextProvisional,
        ?Calculation $existing = null,
    ): array {
        $customerTouched = $this->sideTouched(
            $payload,
            'customer_account_id',
            'customer_name',
            'customer_create_provisional',
            'ensure_provisional_customer',
        );
        $agencyTouched = $this->sideTouched(
            $payload,
            'agency_account_id',
            'agency_name',
            'agency_create_provisional',
            'ensure_provisional_agency',
        );

        $customer = $customerTouched
            ? $this->resolveSide(
                payload: $payload,
                accountKey: 'customer_account_id',
                nameKey: 'customer_name',
                emailKey: 'customer_billing_email',
                domainKey: 'customer_matching_domain',
                createProvisionalKey: 'customer_create_provisional',
                legacyEnsureKey: 'ensure_provisional_customer',
                type: CrmAccountType::Customer,
                actor: $actor,
                allowFreitextProvisional: $allowFreitextProvisional,
                existingAccountId: $existing?->customer_account_id !== null
                    ? (int) $existing->customer_account_id
                    : null,
                existingVersionId: $existing?->customer_version_id !== null
                    ? (int) $existing->customer_version_id
                    : null,
                existingName: $existing?->customer_name,
                existingMeridian: $existing?->customer_meridian_number,
                existingSalesforce: $existing?->customer_salesforce_account_id,
            )
            : $this->preserveSide(
                existingAccountId: $existing?->customer_account_id !== null
                    ? (int) $existing->customer_account_id
                    : null,
                existingVersionId: $existing?->customer_version_id !== null
                    ? (int) $existing->customer_version_id
                    : null,
                existingName: $existing?->customer_name,
                existingMeridian: $existing?->customer_meridian_number,
                existingSalesforce: $existing?->customer_salesforce_account_id,
            );

        $agency = $agencyTouched
            ? $this->resolveSide(
                payload: $payload,
                accountKey: 'agency_account_id',
                nameKey: 'agency_name',
                emailKey: 'agency_billing_email',
                domainKey: 'agency_matching_domain',
                createProvisionalKey: 'agency_create_provisional',
                legacyEnsureKey: 'ensure_provisional_agency',
                type: CrmAccountType::Agency,
                actor: $actor,
                allowFreitextProvisional: $allowFreitextProvisional,
                existingAccountId: $existing?->agency_account_id !== null
                    ? (int) $existing->agency_account_id
                    : null,
                existingVersionId: $existing?->agency_version_id !== null
                    ? (int) $existing->agency_version_id
                    : null,
                existingName: $existing?->agency_name,
                existingMeridian: $existing?->agency_meridian_number,
                existingSalesforce: $existing?->agency_salesforce_account_id,
            )
            : $this->preserveSide(
                existingAccountId: $existing?->agency_account_id !== null
                    ? (int) $existing->agency_account_id
                    : null,
                existingVersionId: $existing?->agency_version_id !== null
                    ? (int) $existing->agency_version_id
                    : null,
                existingName: $existing?->agency_name,
                existingMeridian: $existing?->agency_meridian_number,
                existingSalesforce: $existing?->agency_salesforce_account_id,
            );

        $invoice = $this->resolveInvoiceRecipient(
            array_key_exists('invoice_recipient', $payload)
                ? $payload['invoice_recipient']
                : ($existing?->invoice_recipient?->value),
            $customer,
            $agency,
        );

        return [
            'customer_name' => $customer['name'] ?? null,
            'agency_name' => $agency['name'] ?? null,
            'customer_account_id' => $customer['account_id'] ?? null,
            'agency_account_id' => $agency['account_id'] ?? null,
            'customer_version_id' => $customer['version_id'] ?? null,
            'agency_version_id' => $agency['version_id'] ?? null,
            'invoice_recipient' => $invoice,
            'customer_meridian_number' => $customer['meridian'] ?? null,
            'agency_meridian_number' => $agency['meridian'] ?? null,
            'customer_salesforce_account_id' => $customer['salesforce_raw'] ?? null,
            'agency_salesforce_account_id' => $agency['salesforce_raw'] ?? null,
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function sideTouched(
        array $payload,
        string $accountKey,
        string $nameKey,
        string $createProvisionalKey,
        string $legacyEnsureKey,
    ): bool {
        return array_key_exists($accountKey, $payload)
            || array_key_exists($nameKey, $payload)
            || ! empty($payload[$createProvisionalKey])
            || ! empty($payload[$legacyEnsureKey]);
    }

    /**
     * @return array{account_id: int<1, max>|null, version_id: int<1, max>|null, name: ?string, meridian: ?string, salesforce_raw: ?string}|null
     */
    /**
     * @param  int<1, max>|null  $existingAccountId
     * @param  int<1, max>|null  $existingVersionId
     * @return array{account_id: int<1, max>|null, version_id: int<1, max>|null, name: ?string, meridian: ?string, salesforce_raw: ?string}|null
     */
    private function preserveSide(
        ?int $existingAccountId,
        ?int $existingVersionId,
        ?string $existingName,
        ?string $existingMeridian,
        ?string $existingSalesforce,
    ): ?array {
        if ($existingAccountId !== null) {
            return [
                'account_id' => $existingAccountId,
                'version_id' => $existingVersionId,
                'name' => $existingName,
                'meridian' => $existingMeridian,
                'salesforce_raw' => $existingSalesforce,
            ];
        }

        if ($existingName !== null && $existingName !== '') {
            return [
                'account_id' => null,
                'version_id' => null,
                'name' => $existingName,
                'meridian' => null,
                'salesforce_raw' => null,
            ];
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{account_id: int<1, max>|null, version_id: int<1, max>|null, name: ?string, meridian: ?string, salesforce_raw: ?string}|null
     */
    private function resolveSide(
        array $payload,
        string $accountKey,
        string $nameKey,
        string $emailKey,
        string $domainKey,
        string $createProvisionalKey,
        string $legacyEnsureKey,
        CrmAccountType $type,
        User $actor,
        bool $allowFreitextProvisional,
        ?int $existingAccountId,
        ?int $existingVersionId,
        ?string $existingName,
        ?string $existingMeridian,
        ?string $existingSalesforce,
    ): ?array {
        $hasAccountKey = array_key_exists($accountKey, $payload);
        $rawAccountId = $hasAccountKey ? $payload[$accountKey] : null;
        $accountId = $rawAccountId !== null && $rawAccountId !== ''
            ? (int) $rawAccountId
            : null;
        $freitextName = array_key_exists($nameKey, $payload)
            ? trim((string) ($payload[$nameKey] ?? ''))
            : null;
        if ($freitextName === '') {
            $freitextName = null;
        }
        $freitextEmail = isset($payload[$emailKey]) ? (string) $payload[$emailKey] : null;
        $freitextDomain = isset($payload[$domainKey]) ? (string) $payload[$domainKey] : null;
        $forceProvisional = ! empty($payload[$createProvisionalKey]) || ! empty($payload[$legacyEnsureKey]);

        if ($hasAccountKey && $accountId !== null) {
            return $this->bindExistingAccount(
                accountId: $accountId,
                type: $type,
                existingAccountId: $existingAccountId,
                existingVersionId: $existingVersionId,
                existingName: $existingName,
                existingMeridian: $existingMeridian,
                existingSalesforce: $existingSalesforce,
            );
        }

        if ($hasAccountKey) {
            // account_id explizit null (Freitext / leeren).
            if ($freitextName === null) {
                return null;
            }

            return $this->resolveFreitextName(
                freitextName: $freitextName,
                freitextEmail: $freitextEmail,
                freitextDomain: $freitextDomain,
                type: $type,
                actor: $actor,
                allowFreitextProvisional: $allowFreitextProvisional,
                forceProvisional: $forceProvisional,
                existingAccountId: $existingAccountId,
                existingName: $existingName,
                switchFromBoundAccount: $existingAccountId !== null,
            );
        }

        // Fehlender Account-Schlüssel, aber Name/Create-Flags (Seite ist „touched“).
        if ($freitextName !== null) {
            if ($existingAccountId !== null) {
                // Name ohne Account-Schlüssel bei gebundener Calc: Identität erhalten.
                return $this->bindExistingAccount(
                    accountId: $existingAccountId,
                    type: $type,
                    existingAccountId: $existingAccountId,
                    existingVersionId: $existingVersionId,
                    existingName: $existingName,
                    existingMeridian: $existingMeridian,
                    existingSalesforce: $existingSalesforce,
                );
            }

            return $this->resolveFreitextName(
                freitextName: $freitextName,
                freitextEmail: $freitextEmail,
                freitextDomain: $freitextDomain,
                type: $type,
                actor: $actor,
                allowFreitextProvisional: $allowFreitextProvisional,
                forceProvisional: $forceProvisional,
                existingAccountId: null,
                existingName: $existingName,
                switchFromBoundAccount: false,
            );
        }

        if ($forceProvisional) {
            throw ValidationException::withMessages([
                $nameKey => 'Firmierung für vorläufigen Account fehlt.',
            ]);
        }

        return null;
    }

    /**
     * @return array{account_id: int<1, max>|null, version_id: int<1, max>|null, name: ?string, meridian: ?string, salesforce_raw: ?string}
     */
    private function resolveFreitextName(
        string $freitextName,
        ?string $freitextEmail,
        ?string $freitextDomain,
        CrmAccountType $type,
        User $actor,
        bool $allowFreitextProvisional,
        bool $forceProvisional,
        ?int $existingAccountId,
        ?string $existingName,
        bool $switchFromBoundAccount,
    ): array {
        $existingIsLegacyFreitext = $existingAccountId === null
            && $existingName !== null
            && $existingName !== '';

        $shouldCreateProvisional = $allowFreitextProvisional && (
            $switchFromBoundAccount
            || ($existingIsLegacyFreitext && $forceProvisional)
            || (! $existingIsLegacyFreitext && $existingAccountId === null)
        );

        if ($shouldCreateProvisional) {
            return $this->createProvisionalBinding(
                name: $freitextName,
                email: $freitextEmail,
                domain: $freitextDomain,
                type: $type,
                actor: $actor,
            );
        }

        return [
            'account_id' => null,
            'version_id' => null,
            'name' => $freitextName,
            'meridian' => null,
            'salesforce_raw' => null,
        ];
    }

    /**
     * @return array{account_id: int<1, max>, version_id: int<1, max>, name: ?string, meridian: ?string, salesforce_raw: ?string}
     */
    private function bindExistingAccount(
        int $accountId,
        CrmAccountType $type,
        ?int $existingAccountId,
        ?int $existingVersionId,
        ?string $existingName,
        ?string $existingMeridian,
        ?string $existingSalesforce,
    ): array {
        $account = CrmAccount::query()->with('currentVersion')->find($accountId);
        if ($account === null) {
            throw ValidationException::withMessages([
                $type === CrmAccountType::Customer ? 'customer_account_id' : 'agency_account_id' => 'Account nicht gefunden.',
            ]);
        }
        $account = $this->accounts->resolveCanonicalAccount($account);
        $account->loadMissing('currentVersion');
        if ($account->type !== $type) {
            throw ValidationException::withMessages([
                $type === CrmAccountType::Customer ? 'customer_account_id' : 'agency_account_id' => 'Account-Typ passt nicht.',
            ]);
        }

        if ($this->sameAccountIdentity($existingAccountId, $account) && $existingVersionId !== null) {
            $version = CrmAccountVersion::query()->whereKey($existingVersionId)->first();
            if ($version !== null) {
                return [
                    'account_id' => $account->id,
                    'version_id' => $version->id,
                    'name' => $existingName ?? $version->name,
                    'meridian' => $existingMeridian,
                    'salesforce_raw' => $account->salesforce_account_id_raw ?? $existingSalesforce,
                ];
            }
        }

        $version = $account->currentVersion;
        if ($version === null) {
            throw ValidationException::withMessages([
                $type === CrmAccountType::Customer ? 'customer_account_id' : 'agency_account_id' => 'Account hat keine Stammdatenversion.',
            ]);
        }

        return [
            'account_id' => $account->id,
            'version_id' => $version->id,
            'name' => $version->name,
            'meridian' => $version->meridian_number,
            'salesforce_raw' => $account->salesforce_account_id_raw,
        ];
    }

    /**
     * @return array{account_id: int<1, max>, version_id: int<1, max>, name: ?string, meridian: ?string, salesforce_raw: ?string}
     */
    private function createProvisionalBinding(
        string $name,
        ?string $email,
        ?string $domain,
        CrmAccountType $type,
        User $actor,
    ): array {
        if (! $actor->canCreateProvisionalCrmAccounts()) {
            throw ValidationException::withMessages([
                $type === CrmAccountType::Customer ? 'customer_name' : 'agency_name' => 'Keine Berechtigung, vorläufige Accounts anzulegen.',
            ]);
        }

        $account = $this->accounts->createProvisional([
            'name' => $name,
            'type' => $type->value,
            'billing_email' => $email,
            'matching_domain' => $domain,
        ], $actor);

        $version = $account->currentVersion;
        if ($version === null) {
            throw ValidationException::withMessages([
                $type === CrmAccountType::Customer ? 'customer_name' : 'agency_name' => 'Vorläufiger Account ohne Version.',
            ]);
        }

        return [
            'account_id' => $account->id,
            'version_id' => $version->id,
            'name' => $version->name,
            'meridian' => $version->meridian_number,
            'salesforce_raw' => $account->salesforce_account_id_raw,
        ];
    }

    private function sameAccountIdentity(?int $storedAccountId, CrmAccount $resolved): bool
    {
        if ($storedAccountId === null) {
            return false;
        }
        if ($storedAccountId === $resolved->id) {
            return true;
        }

        $stored = CrmAccount::query()->find($storedAccountId);
        if ($stored === null) {
            return false;
        }

        return $this->accounts->resolveCanonicalAccount($stored)->id === $resolved->id;
    }

    /**
     * @param  array{account_id: int<1, max>|null, version_id: int<1, max>|null, name: ?string, meridian: ?string, salesforce_raw: ?string}|null  $customer
     * @param  array{account_id: int<1, max>|null, version_id: int<1, max>|null, name: ?string, meridian: ?string, salesforce_raw: ?string}|null  $agency
     */
    private function resolveInvoiceRecipient(
        mixed $raw,
        ?array $customer,
        ?array $agency,
    ): ?InvoiceRecipient {
        $customerBound = $customer !== null && ($customer['account_id'] ?? null) !== null;
        $agencyBound = $agency !== null && ($agency['account_id'] ?? null) !== null;
        $customerName = $customer === null ? null : $customer['name'];
        $agencyName = $agency === null ? null : $agency['name'];
        $customerPresent = $customerBound
            || ($customerName !== null && $customerName !== '');
        $agencyPresent = $agencyBound
            || ($agencyName !== null && $agencyName !== '');

        if (! $customerBound && ! $agencyBound) {
            // Historischer Freitext ohne Account: keine RE-Zuordnung erfinden.
            return null;
        }

        if ($raw === null || $raw === '') {
            if ($agencyBound) {
                throw ValidationException::withMessages([
                    'invoice_recipient' => 'Rechnungsempfänger muss explizit gewählt werden (Kunde oder Agentur).',
                ]);
            }

            return InvoiceRecipient::Customer;
        }

        $recipient = $raw instanceof InvoiceRecipient
            ? $raw
            : InvoiceRecipient::from((string) $raw);

        if ($recipient === InvoiceRecipient::Agency && ! $agencyPresent) {
            throw ValidationException::withMessages([
                'invoice_recipient' => 'Agentur als Rechnungsempfänger nur bei gesetzter Agentur.',
            ]);
        }
        if ($recipient === InvoiceRecipient::Customer && ! $customerBound) {
            throw ValidationException::withMessages([
                'invoice_recipient' => 'Kunde als Rechnungsempfänger erfordert einen Kundenaccount.',
            ]);
        }
        if ($recipient === InvoiceRecipient::Agency && ! $agencyBound) {
            throw ValidationException::withMessages([
                'invoice_recipient' => 'Agentur als Rechnungsempfänger erfordert einen Agenturaccount.',
            ]);
        }

        return $recipient;
    }

    /**
     * @param  array<string, mixed>  $header
     * @return array<string, mixed>
     */
    public function appendToDispoHeader(array $header, Calculation $calculation): array
    {
        $header['customer_account_id'] = $calculation->customer_account_id;
        $header['agency_account_id'] = $calculation->agency_account_id;
        $header['customer_version_id'] = $calculation->customer_version_id;
        $header['agency_version_id'] = $calculation->agency_version_id;
        $header['invoice_recipient'] = $calculation->invoice_recipient?->value;
        $header['customer_meridian_number'] = $calculation->customer_meridian_number;
        $header['agency_meridian_number'] = $calculation->agency_meridian_number;
        $header['customer_salesforce_account_id'] = $calculation->customer_salesforce_account_id;
        $header['agency_salesforce_account_id'] = $calculation->agency_salesforce_account_id;

        return $header;
    }
}
