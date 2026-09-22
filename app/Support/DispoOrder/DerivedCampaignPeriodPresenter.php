<?php

namespace App\Support\DispoOrder;

use App\Enums\DerivedCampaignPeriodStatus;
use App\Models\DispoOrder;
use Illuminate\Support\Carbon;

/**
 * Serverseitige Anzeige-/Vergleichshilfe für den abgeleiteten Kampagnenzeitraum.
 */
final class DerivedCampaignPeriodPresenter
{
    /**
     * @param  array{start?: string|null, end?: string|null}|null  $calcCampaignPeriod
     * @return array<string, mixed>
     */
    public static function forOrder(DispoOrder $order, ?array $calcCampaignPeriod = null): array
    {
        $status = $order->derived_campaign_period_status
            ?? DerivedCampaignPeriodStatus::Legacy;

        $start = self::dateString($order->derived_campaign_period_start);
        $end = self::dateString($order->derived_campaign_period_end);
        $snapshot = is_array($order->derived_campaign_period_snapshot)
            ? $order->derived_campaign_period_snapshot
            : null;

        $unresolved = [];
        if (is_array($snapshot) && isset($snapshot['positions']) && is_array($snapshot['positions'])) {
            foreach ($snapshot['positions'] as $position) {
                if (! is_array($position)) {
                    continue;
                }
                if (($position['result'] ?? null) !== DerivedCampaignPeriodContract::RESULT_UNRESOLVED) {
                    continue;
                }
                $reason = is_string($position['unresolved_reason'] ?? null)
                    ? (string) $position['unresolved_reason']
                    : null;
                $unresolved[] = [
                    'dispo_order_position_id' => (int) ($position['dispo_order_position_id'] ?? 0),
                    'sort' => (int) ($position['sort'] ?? 0),
                    'label' => is_string($position['label'] ?? null) ? (string) $position['label'] : 'Position',
                    'spot_method' => is_string($position['spot_method'] ?? null) ? (string) $position['spot_method'] : null,
                    'unresolved_reason' => $reason,
                    'unresolved_reason_label' => $reason !== null
                        ? DerivedCampaignPeriodContract::unresolvedReasonLabel($reason)
                        : null,
                ];
            }
        }

        $calcStart = is_array($calcCampaignPeriod)
            ? self::nullableString($calcCampaignPeriod['start'] ?? null)
            : null;
        $calcEnd = is_array($calcCampaignPeriod)
            ? self::nullableString($calcCampaignPeriod['end'] ?? null)
            : null;
        $calcComplete = $calcStart !== null && $calcEnd !== null;
        $derivedComplete = $start !== null && $end !== null
            && in_array($status, [
                DerivedCampaignPeriodStatus::Complete,
                DerivedCampaignPeriodStatus::Partial,
            ], true);

        $conflict = false;
        if ($calcComplete && $derivedComplete) {
            $conflict = $calcStart !== $start || $calcEnd !== $end;
        }

        return [
            'start' => $start,
            'end' => $end,
            'status' => $status->value,
            'status_label' => $status->label(),
            'derived_at' => $order->derived_campaign_period_at?->toIso8601String(),
            'position_count' => is_array($snapshot) ? (int) ($snapshot['position_count'] ?? 0) : null,
            'contributing_count' => is_array($snapshot) ? (int) ($snapshot['contributing_count'] ?? 0) : null,
            'unresolved_count' => is_array($snapshot) ? (int) ($snapshot['unresolved_count'] ?? 0) : null,
            'unresolved_positions' => $unresolved,
            'conflict_with_calculation' => $conflict,
            'calculation_period_complete' => $calcComplete,
            'derived_period_complete' => $derivedComplete,
            'contract_version' => is_array($snapshot)
                ? (int) ($snapshot['contract_version'] ?? DerivedCampaignPeriodContract::CONTRACT_VERSION)
                : null,
        ];
    }

    private static function dateString(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        if ($value instanceof Carbon) {
            return $value->toDateString();
        }

        if ($value instanceof \DateTimeInterface) {
            return $value->format('Y-m-d');
        }

        $string = substr(trim((string) $value), 0, 10);

        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $string) === 1 ? $string : null;
    }

    private static function nullableString(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return self::dateString($value);
    }
}
