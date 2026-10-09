<?php

namespace App\Services\Crm;

use App\Enums\CrmAccountType;
use App\Enums\InvoiceRecipient;
use App\Models\Calculation;
use App\Models\CrmAccount;
use App\Models\User;
use Illuminate\Validation\ValidationException;

/**
 * Bindet CRM-Accounts an Calc-/Dispo-Kopf-Snapshots (BL-P2-03a).
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
    public function resolveForCalculation(array $payload, User $actor, bool $allowFreitextProvisional): array
    {
        $customer = $this->resolveSide(
            accountId: isset($payload['customer_account_id']) ? (int) $payload['customer_account_id'] : null,
            freitextName: isset($payload['customer_name']) ? trim((string) $payload['customer_name']) : null,
            freitextEmail: isset($payload['customer_billing_email']) ? (string) $payload['customer_billing_email'] : null,
            freitextDomain: isset($payload['customer_matching_domain']) ? (string) $payload['customer_matching_domain'] : null,
            type: CrmAccountType::Customer,
            actor: $actor,
            allowFreitextProvisional: $allowFreitextProvisional && ! empty($payload['ensure_provisional_customer']),
        );

        $agency = null;
        $agencyName = isset($payload['agency_name']) ? trim((string) $payload['agency_name']) : null;
        $agencyAccountId = isset($payload['agency_account_id']) ? (int) $payload['agency_account_id'] : null;
        if ($agencyAccountId || ($agencyName !== null && $agencyName !== '') || ! empty($payload['ensure_provisional_agency'])) {
            $agency = $this->resolveSide(
                accountId: $agencyAccountId,
                freitextName: $agencyName,
                freitextEmail: isset($payload['agency_billing_email']) ? (string) $payload['agency_billing_email'] : null,
                freitextDomain: isset($payload['agency_matching_domain']) ? (string) $payload['agency_matching_domain'] : null,
                type: CrmAccountType::Agency,
                actor: $actor,
                allowFreitextProvisional: $allowFreitextProvisional && ! empty($payload['ensure_provisional_agency']),
            );
        }

        $invoice = $this->resolveInvoiceRecipient(
            $payload['invoice_recipient'] ?? null,
            $customer,
            $agency,
        );

        return [
            'customer_name' => $customer['name'] ?? ($payload['customer_name'] ?? null),
            'agency_name' => $agency['name'] ?? (($agencyName !== null && $agencyName !== '') ? $agencyName : null),
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
     * @return array{account_id: int<1, max>|null, version_id: int<1, max>|null, name: ?string, meridian: ?string, salesforce_raw: ?string}|null
     */
    private function resolveSide(
        ?int $accountId,
        ?string $freitextName,
        ?string $freitextEmail,
        ?string $freitextDomain,
        CrmAccountType $type,
        User $actor,
        bool $allowFreitextProvisional,
    ): ?array {
        if ($accountId) {
            $account = CrmAccount::query()->with('currentVersion')->find($accountId);
            if ($account === null || $account->merged_into_account_id !== null) {
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

        if ($allowFreitextProvisional && $freitextName !== null && $freitextName !== '') {
            $account = $this->accounts->createProvisional([
                'name' => $freitextName,
                'type' => $type,
                'billing_email' => $freitextEmail,
                'matching_domain' => $freitextDomain,
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

        if ($freitextName !== null && $freitextName !== '') {
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
     * @param  array{account_id: int<1, max>|null, version_id: int<1, max>|null, name: ?string, meridian: ?string, salesforce_raw: ?string}|null  $customer
     * @param  array{account_id: int<1, max>|null, version_id: int<1, max>|null, name: ?string, meridian: ?string, salesforce_raw: ?string}|null  $agency
     */
    private function resolveInvoiceRecipient(mixed $raw, ?array $customer, ?array $agency): ?InvoiceRecipient
    {
        if (($customer['account_id'] ?? null) === null && ($agency['account_id'] ?? null) === null) {
            // Legacy-Freitext: keine historische Zuordnung erfinden (F1).
            return null;
        }

        if ($raw === null || $raw === '') {
            if (($agency['account_id'] ?? null) === null) {
                return InvoiceRecipient::Customer;
            }
            throw ValidationException::withMessages([
                'invoice_recipient' => 'Rechnungsempfänger muss explizit gewählt werden (Kunde oder Agentur).',
            ]);
        }

        $recipient = $raw instanceof InvoiceRecipient
            ? $raw
            : InvoiceRecipient::from((string) $raw);
        if ($recipient === InvoiceRecipient::Agency && ($agency['account_id'] ?? null) === null) {
            throw ValidationException::withMessages([
                'invoice_recipient' => 'Agentur als Rechnungsempfänger nur bei gesetzter Agentur.',
            ]);
        }
        if ($recipient === InvoiceRecipient::Customer && ($customer['account_id'] ?? null) === null) {
            throw ValidationException::withMessages([
                'invoice_recipient' => 'Kunde als Rechnungsempfänger erfordert einen Kundenaccount.',
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
