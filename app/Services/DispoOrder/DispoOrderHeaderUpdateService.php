<?php

namespace App\Services\DispoOrder;

use App\Enums\DispoOrderStatus;
use App\Exceptions\DispoOrderConflictException;
use App\Models\DispoOrder;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Services\Crm\CrmOrderHeaderBinder;
use App\Support\DispoOrder\OperationalCampaignPeriodContract;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Atomare Aktualisierung operativer Dispo-Kopfdaten (PO-CALC-DISPO-UX-1 / PR3+PR5).
 *
 * Kunde bleibt unverändert. CRM-Agentur-/RE-Regeln über {@see CrmOrderHeaderBinder}
 * mit Dispo-Semantik: RE darf im Draft null bleiben (kein stilles customer-Default).
 * Operativer Kampagnenzeitraum: atomare Paar-Einheit (PO-PR5-01…06).
 */
final class DispoOrderHeaderUpdateService
{
    public function __construct(
        private readonly CrmOrderHeaderBinder $crmHeaders,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     */
    public function update(
        DispoOrder $order,
        User $user,
        int $expectedLockVersion,
        array $payload,
    ): DispoOrder {
        return DB::transaction(function () use ($order, $user, $expectedLockVersion, $payload): DispoOrder {
            $locked = DispoOrder::query()->whereKey($order->id)->lockForUpdate()->firstOrFail();

            if ($locked->lock_version !== $expectedLockVersion) {
                throw new DispoOrderConflictException(
                    'Der Dispoauftrag wurde zwischenzeitlich geändert. Bitte die Seite neu laden.',
                );
            }

            if ($locked->status !== DispoOrderStatus::Draft) {
                throw ValidationException::withMessages([
                    'status' => 'Nur Entwürfe können bearbeitet werden.',
                ]);
            }

            $before = $this->snapshotForAudit($locked);

            // Kunde niemals anfassen: Customer-Keys aus der Payload entfernen.
            unset(
                $payload['customer_name'],
                $payload['customer_account_id'],
                $payload['customer_create_provisional'],
                $payload['ensure_provisional_customer'],
                $payload['customer_billing_email'],
                $payload['customer_matching_domain'],
            );

            $briefingProvided = array_key_exists('briefing', $payload);
            $briefingRaw = $briefingProvided ? $payload['briefing'] : $locked->briefing;
            unset($payload['briefing']);

            $periodProvided = array_key_exists(OperationalCampaignPeriodContract::START_KEY, $payload)
                && array_key_exists(OperationalCampaignPeriodContract::END_KEY, $payload);
            $periodStart = $periodProvided
                ? $payload[OperationalCampaignPeriodContract::START_KEY]
                : null;
            $periodEnd = $periodProvided
                ? $payload[OperationalCampaignPeriodContract::END_KEY]
                : null;
            unset(
                $payload[OperationalCampaignPeriodContract::START_KEY],
                $payload[OperationalCampaignPeriodContract::END_KEY],
            );

            // Agentur immer als „touched“ behandeln, wenn Agency-Keys fehlen:
            // Frontend sendet stets den vollständigen Agentur-Zustand.
            if (! $this->agencyTouched($payload)) {
                $payload['agency_name'] = $locked->agency_name;
                $payload['agency_account_id'] = $locked->agency_account_id;
            }

            if (! array_key_exists('invoice_recipient', $payload)) {
                $payload['invoice_recipient'] = $locked->invoice_recipient?->value;
            }

            $crm = $this->crmHeaders->resolveForCalculation(
                $payload,
                $user,
                allowFreitextProvisional: true,
                existing: $locked,
                allowUnsetInvoiceRecipient: true,
            );

            // Kunde unverändert aus dem gelockten Bestand übernehmen.
            $locked->agency_name = $crm['agency_name'];
            $locked->agency_account_id = $crm['agency_account_id'];
            $locked->agency_version_id = $crm['agency_version_id'];
            $locked->agency_meridian_number = $crm['agency_meridian_number'];
            $locked->agency_salesforce_account_id = $crm['agency_salesforce_account_id'];
            $locked->invoice_recipient = $crm['invoice_recipient'];

            if ($briefingProvided) {
                $locked->briefing = $this->normalizeBriefing($briefingRaw);
            }

            if ($periodProvided) {
                $pair = OperationalCampaignPeriodContract::canonicalizeForPersist(
                    $periodStart,
                    $periodEnd,
                );
                $locked->operational_campaign_starts_on = $pair['start'];
                $locked->operational_campaign_ends_on = $pair['end'];
            }

            if (! $locked->isDirty()) {
                return $this->reload($locked);
            }

            $locked->lock_version = $locked->lock_version + 1;
            $locked->save();

            $fresh = $this->reload($locked);
            $after = $this->snapshotForAudit($fresh);

            $this->audit->record(
                $fresh,
                'dispo_order.header_updated',
                $user,
                array_merge($before, ['lock_version' => $expectedLockVersion]),
                array_merge($after, ['lock_version' => $fresh->lock_version]),
            );

            return $fresh;
        });
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function agencyTouched(array $payload): bool
    {
        foreach ([
            'agency_account_id',
            'agency_name',
            'agency_create_provisional',
            'ensure_provisional_agency',
        ] as $key) {
            if (array_key_exists($key, $payload)) {
                return true;
            }
        }

        return false;
    }

    private function normalizeBriefing(mixed $raw): ?string
    {
        if ($raw === null) {
            return null;
        }

        $text = is_string($raw) ? $raw : (string) $raw;
        $trimmed = trim($text);

        return $trimmed === '' ? null : $text;
    }

    /**
     * @return array<string, mixed>
     */
    private function snapshotForAudit(DispoOrder $order): array
    {
        return [
            'agency_name' => $order->agency_name,
            'agency_account_id' => $order->agency_account_id,
            'agency_version_id' => $order->agency_version_id,
            'agency_meridian_number' => $order->agency_meridian_number,
            'agency_salesforce_account_id' => $order->agency_salesforce_account_id,
            'invoice_recipient' => $order->invoice_recipient?->value,
            'briefing' => $order->briefing,
            'operational_campaign_starts_on' => OperationalCampaignPeriodContract::dateToIso(
                $order->operational_campaign_starts_on,
            ),
            'operational_campaign_ends_on' => OperationalCampaignPeriodContract::dateToIso(
                $order->operational_campaign_ends_on,
            ),
            'customer_name' => $order->customer_name,
            'customer_account_id' => $order->customer_account_id,
        ];
    }

    private function reload(DispoOrder $order): DispoOrder
    {
        $order->refresh();
        $order->load([
            'positions',
            'creator',
            'advisor',
            'calculation',
            'approvalRequests',
            'pendingApprovalRequest',
            'latestApprovalRequest',
        ]);

        return $order;
    }
}
