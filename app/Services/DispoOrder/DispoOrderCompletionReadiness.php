<?php

namespace App\Services\DispoOrder;

use App\Enums\DispoOrderApprovalStatus;
use App\Models\DispoOrder;
use App\Models\DispoOrderApprovalRequest;
use App\Models\DispoOrderPosition;
use App\Services\DynamicField\DispoOrderDynamicFieldWriter;
use App\Support\DispoOrder\DerivedCampaignPeriodContract;
use App\Support\DispoOrder\InvoiceEndMonthsContract;
use Throwable;

/**
 * Abschlussprüfungen für disposed → completed (BL-P8-02d / PO-BLP802D-1).
 */
final class DispoOrderCompletionReadiness
{
    public const string CHECK_REQUIRED_FIELDS = 'required_fields';

    public const string CHECK_OPEN_SALES_INQUIRY = 'open_sales_inquiry';

    public const string CHECK_INVOICE_END_MONTHS = 'invoice_end_months';

    public const string CHECK_CUSTOMER_CONFIRMATION = 'customer_confirmation';

    public const string CHECK_APPROVALS = 'approvals';

    public function __construct(
        private readonly DispoOrderDynamicFieldWriter $dynamicFields,
        private readonly DispoOrderSalesInquiryService $salesInquiry,
    ) {}

    /**
     * @return array{
     *     ready: bool,
     *     checks: list<array{
     *         key: string,
     *         label: string,
     *         passed: bool,
     *         violations: list<array{message: string}>
     *     }>
     * }
     */
    public function evaluate(DispoOrder $order): array
    {
        $order->loadMissing([
            'positions' => fn ($q) => $q->orderBy('sort')->orderBy('id'),
            'positions.fieldValues.snapshotFieldDefinition',
            'fieldValues.snapshotFieldDefinition',
            'approvalRequests',
            'configurationSnapshot.fieldDefinitions',
            'configurationSnapshot.rules',
        ]);

        $checks = [
            $this->checkRequiredFields($order),
            $this->checkOpenSalesInquiry($order),
            $this->checkInvoiceEndMonths($order),
            $this->checkCustomerConfirmation($order),
            $this->checkApprovals($order),
        ];

        $ready = true;
        foreach ($checks as $check) {
            if (! $check['passed']) {
                $ready = false;
                break;
            }
        }

        return [
            'ready' => $ready,
            'checks' => $checks,
        ];
    }

    /**
     * @return array{key: string, label: string, passed: bool, violations: list<array{message: string}>}
     */
    private function checkRequiredFields(DispoOrder $order): array
    {
        $label = 'Pflichtfelder';
        try {
            $missing = $this->dynamicFields->collectMissingRequiredFields($order);
        } catch (Throwable) {
            return [
                'key' => self::CHECK_REQUIRED_FIELDS,
                'label' => $label,
                'passed' => false,
                'violations' => [[
                    'message' => 'Pflichtfelder konnten nicht geprüft werden (Konfigurationssnapshot ungültig).',
                ]],
            ];
        }

        $violations = array_map(
            static fn (array $item): array => ['message' => $item['message']],
            $missing['violations'],
        );

        return [
            'key' => self::CHECK_REQUIRED_FIELDS,
            'label' => $label,
            'passed' => $violations === [],
            'violations' => $violations,
        ];
    }

    /**
     * @return array{key: string, label: string, passed: bool, violations: list<array{message: string}>}
     */
    private function checkOpenSalesInquiry(DispoOrder $order): array
    {
        $open = $this->salesInquiry->findOpenSalesInquiry($order);
        $passed = $open === null;

        return [
            'key' => self::CHECK_OPEN_SALES_INQUIRY,
            'label' => 'Rückfrage',
            'passed' => $passed,
            'violations' => $passed ? [] : [[
                'message' => 'Es besteht eine offene Rückfrage an den Vertrieb.',
            ]],
        ];
    }

    /**
     * @return array{key: string, label: string, passed: bool, violations: list<array{message: string}>}
     */
    private function checkInvoiceEndMonths(DispoOrder $order): array
    {
        $violations = [];
        $snapshot = $order->derived_campaign_period_snapshot;
        $entriesByPositionId = [];

        if (! is_array($snapshot) || ! isset($snapshot['positions']) || ! is_array($snapshot['positions'])) {
            return [
                'key' => self::CHECK_INVOICE_END_MONTHS,
                'label' => 'Rechnung per Ende',
                'passed' => false,
                'violations' => [[
                    'message' => 'Der eingefrorene Zeitraum-Snapshot fehlt oder ist ungültig.',
                ]],
            ];
        }

        foreach ($snapshot['positions'] as $entry) {
            if (! is_array($entry)) {
                continue;
            }
            $positionId = (int) ($entry['dispo_order_position_id'] ?? 0);
            if ($positionId > 0) {
                $entriesByPositionId[$positionId] = $entry;
            }
        }

        foreach ($order->positions->values() as $index => $position) {
            $displayIndex = $index + 1;
            $entry = $entriesByPositionId[(int) $position->id] ?? null;

            if ($entry === null) {
                $violations[] = [
                    'message' => sprintf(
                        'Position %d: Zeitraum-Snapshot ungültig oder unvollständig.',
                        $displayIndex,
                    ),
                ];

                continue;
            }

            $periodState = $this->periodStateForEntry($entry);
            if ($periodState === 'open') {
                continue;
            }

            if ($periodState === 'invalid') {
                $violations[] = [
                    'message' => sprintf(
                        'Position %d: Zeitraum-Snapshot ungültig oder unvollständig.',
                        $displayIndex,
                    ),
                ];

                continue;
            }

            if (! InvoiceEndMonthsContract::isFilled(
                is_array($position->invoice_end_months) ? $position->invoice_end_months : null,
            )) {
                $violations[] = [
                    'message' => sprintf('Position %d: Rechnung per Ende fehlt.', $displayIndex),
                ];
            }
        }

        return [
            'key' => self::CHECK_INVOICE_END_MONTHS,
            'label' => 'Rechnung per Ende',
            'passed' => $violations === [],
            'violations' => $violations,
        ];
    }

    /**
     * @param  array<string, mixed>  $entry
     * @return 'open'|'concrete'|'invalid'
     */
    public function periodStateForEntry(array $entry): string
    {
        $result = $entry['result'] ?? null;
        if ($result === DerivedCampaignPeriodContract::RESULT_CONTRIBUTING) {
            return 'concrete';
        }

        if ($result === DerivedCampaignPeriodContract::RESULT_UNRESOLVED
            && ($entry['unresolved_reason'] ?? null) === DerivedCampaignPeriodContract::REASON_PERIOD_OPEN
        ) {
            return 'open';
        }

        return 'invalid';
    }

    /**
     * @return 'open'|'concrete'|'invalid'
     */
    public function periodStateForPosition(DispoOrder $order, DispoOrderPosition $position): string
    {
        $snapshot = $order->derived_campaign_period_snapshot;
        if (! is_array($snapshot) || ! isset($snapshot['positions']) || ! is_array($snapshot['positions'])) {
            return 'invalid';
        }

        foreach ($snapshot['positions'] as $entry) {
            if (! is_array($entry)) {
                continue;
            }
            if ((int) ($entry['dispo_order_position_id'] ?? 0) === (int) $position->id) {
                return $this->periodStateForEntry($entry);
            }
        }

        return 'invalid';
    }

    /**
     * @return array{key: string, label: string, passed: bool, violations: list<array{message: string}>}
     */
    private function checkCustomerConfirmation(DispoOrder $order): array
    {
        $approved = $order->approvalRequests
            ->filter(fn (DispoOrderApprovalRequest $request): bool => $request->status === DispoOrderApprovalStatus::Approved)
            ->sortByDesc(fn (DispoOrderApprovalRequest $request): int => (int) $request->cycle_number)
            ->first();

        $passed = false;
        if ($approved !== null) {
            if ($approved->hasCustomerConfirmationUploadSnapshot()) {
                $passed = true;
            } elseif (
                $approved->hasCustomerConfirmationExceptionSnapshot()
                && (bool) $approved->customer_confirmation_exception_acknowledged
            ) {
                $passed = true;
            }
        }

        return [
            'key' => self::CHECK_CUSTOMER_CONFIRMATION,
            'label' => 'Kundenbestätigung',
            'passed' => $passed,
            'violations' => $passed ? [] : [[
                'message' => 'Es fehlt eine freigegebene Kundenbestätigung (Datei-Upload oder genehmigte Ausnahme).',
            ]],
        ];
    }

    /**
     * @return array{key: string, label: string, passed: bool, violations: list<array{message: string}>}
     */
    private function checkApprovals(DispoOrder $order): array
    {
        $hasApproved = $order->approvalRequests->contains(
            fn (DispoOrderApprovalRequest $request): bool => $request->status === DispoOrderApprovalStatus::Approved,
        );

        $hasPending = $order->approvalRequests->contains(
            fn (DispoOrderApprovalRequest $request): bool => $request->status === DispoOrderApprovalStatus::Pending,
        );

        $passed = $hasApproved && ! $hasPending;

        $violations = [];
        if (! $hasApproved) {
            $violations[] = ['message' => 'Es fehlt eine gültige Freigabe.'];
        }
        if ($hasPending) {
            $violations[] = ['message' => 'Es besteht noch eine offene Freigabeanforderung.'];
        }

        return [
            'key' => self::CHECK_APPROVALS,
            'label' => 'Freigaben',
            'passed' => $passed,
            'violations' => $violations,
        ];
    }
}
