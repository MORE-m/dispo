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
 * Payload-Vertrag (CRM-001 / PO-BLP203-1 Ergänzung):
 * - fehlender `*_account_id`-Schlüssel: Legacy-/Teilpfad (nur Name, kein Auto-Provisional)
 * - `*_account_id` = int: explizite Account-Auswahl
 * - `*_account_id` = null + Name: Freitext
 *   - neue Calc / leerer Bestand / Wechsel von gebundenem Account → vorläufiger Account
 *   - historischer Freitext-Bestand (Name ohne Account) → ohne Migration behalten,
 *     außer `*_create_provisional` / `ensure_provisional_*` (explizite Überführung)
 * - `*_account_id` = null + leerer Name: Seite leeren (Agentur)
 *
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
        $customer = $this->resolveSide(
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
        );

        $agencyTouched = array_key_exists('agency_account_id', $payload)
            || array_key_exists('agency_name', $payload)
            || ! empty($payload['ensure_provisional_agency'])
            || ! empty($payload['agency_create_provisional']);

        $agency = null;
        if ($agencyTouched) {
            $agency = $this->resolveSide(
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
            );
        } elseif ($existing !== null && $existing->agency_account_id !== null) {
            $agency = [
                'account_id' => (int) $existing->agency_account_id,
                'version_id' => $existing->agency_version_id !== null
                    ? (int) $existing->agency_version_id
                    : null,
                'name' => $existing->agency_name,
                'meridian' => $existing->agency_meridian_number,
                'salesforce_raw' => $existing->agency_salesforce_account_id,
            ];
        } elseif ($existing !== null && $existing->agency_name !== null && $existing->agency_name !== '') {
            $agency = [
                'account_id' => null,
                'version_id' => null,
                'name' => $existing->agency_name,
                'meridian' => null,
                'salesforce_raw' => null,
            ];
        }

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

            $existingIsLegacyFreitext = $existingAccountId === null
                && $existingName !== null
                && $existingName !== '';

            if ($allowFreitextProvisional) {
                if ($existingAccountId !== null) {
                    // Wechsel von gebundenem Account → neue Freitext-Firmierung.
                    return $this->createProvisionalBinding(
                        name: $freitextName,
                        email: $freitextEmail,
                        domain: $freitextDomain,
                        type: $type,
                        actor: $actor,
                    );
                }
                if ($existingIsLegacyFreitext) {
                    if ($forceProvisional) {
                        return $this->createProvisionalBinding(
                            name: $freitextName,
                            email: $freitextEmail,
                            domain: $freitextDomain,
                            type: $type,
                            actor: $actor,
                        );
                    }
                } else {
                    // Neue Calc / leerer Bestand.
                    return $this->createProvisionalBinding(
                        name: $freitextName,
                        email: $freitextEmail,
                        domain: $freitextDomain,
                        type: $type,
                        actor: $actor,
                    );
                }
            }

            return [
                'account_id' => null,
                'version_id' => null,
                'name' => $freitextName,
                'meridian' => null,
                'salesforce_raw' => null,
            ];
        }

        // Fehlender Account-Schlüssel: Legacy-/Testpfad ohne Auto-Provisional.
        if ($freitextName !== null) {
            return [
                'account_id' => null,
                'version_id' => null,
                'name' => $freitextName,
                'meridian' => null,
                'salesforce_raw' => null,
            ];
        }

        return null;
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
            'type' => $type,
            'billing_email' => $email,
            'matching_domain' => $domain,
        ], $actor);
        $account->loadMissing('currentVersion');
        $version = $account->currentVersion;
        if ($version === null) {
            throw ValidationException::withMessages([
                $type === CrmAccountType::Customer ? 'customer_name' : 'agency_name' => 'Vorläufiger Account ohne Stammdatenversion.',
            ]);
        }

        return [
            'account_id' => $account->id,
            'version_id' => $version->id,
            'name' => $version->name,
            'meridian' => $version->meridian_number,
            'salesforce_raw' => null,
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

            // Mindestens ein Account ist gebunden; ohne Agentur → Default Kunde.
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
