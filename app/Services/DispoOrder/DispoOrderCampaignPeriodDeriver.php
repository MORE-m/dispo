<?php

namespace App\Services\DispoOrder;

use App\Enums\DerivedCampaignPeriodStatus;
use App\Enums\SpotCalculationMethod;
use App\Models\DispoOrder;
use App\Models\DispoOrderPosition;
use App\Support\DispoOrder\DerivedCampaignPeriodContract;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use RuntimeException;

/**
 * Leitet den globalen Kampagnenzeitraum ausschließlich aus Frozen Dispo-Positionen ab.
 *
 * Keine Live-Calc-/Katalog-/Preislisten-Daten. Keine Umdeutung von {@see campaign_period}.
 */
final class DispoOrderCampaignPeriodDeriver
{
    public function deriveAndPersist(DispoOrder $order): DerivedCampaignPeriodResult
    {
        $order->loadMissing([
            'positions' => fn ($q) => $q->orderBy('sort')->orderBy('id'),
            'positions.fieldValues.snapshotFieldDefinition',
        ]);

        $result = $this->deriveFromPositions($order->positions);
        $this->persist($order, $result);

        return $result;
    }

    /**
     * @param  Collection<int, DispoOrderPosition>  $positions
     */
    public function deriveFromPositions(Collection $positions): DerivedCampaignPeriodResult
    {
        $sorted = $positions
            ->sortBy([
                ['sort', 'asc'],
                ['id', 'asc'],
            ])
            ->values();

        $entries = [];
        $contributingStarts = [];
        $contributingEnds = [];

        foreach ($sorted as $position) {
            $entry = $this->resolvePosition($position);
            $entries[] = $entry;

            if ($entry['result'] === DerivedCampaignPeriodContract::RESULT_CONTRIBUTING) {
                $contributingStarts[] = (string) $entry['start'];
                $contributingEnds[] = (string) $entry['end'];
            }
        }

        $contributingCount = count($contributingStarts);
        $unresolvedCount = count($entries) - $contributingCount;
        $positionCount = count($entries);

        if ($positionCount === 0 || $contributingCount === 0) {
            $status = DerivedCampaignPeriodStatus::Open;
            $start = null;
            $end = null;
        } elseif ($unresolvedCount === 0) {
            $status = DerivedCampaignPeriodStatus::Complete;
            sort($contributingStarts);
            sort($contributingEnds);
            $start = $contributingStarts[0];
            $end = $contributingEnds[count($contributingEnds) - 1];
        } else {
            $status = DerivedCampaignPeriodStatus::Partial;
            sort($contributingStarts);
            sort($contributingEnds);
            $start = $contributingStarts[0];
            $end = $contributingEnds[count($contributingEnds) - 1];
        }

        return new DerivedCampaignPeriodResult(
            start: $start,
            end: $end,
            status: $status,
            derivedAt: Carbon::now(),
            provenance: [
                'contract_version' => DerivedCampaignPeriodContract::CONTRACT_VERSION,
                'position_count' => $positionCount,
                'contributing_count' => $contributingCount,
                'unresolved_count' => $unresolvedCount,
                'positions' => $entries,
            ],
        );
    }

    public function persist(DispoOrder $order, DerivedCampaignPeriodResult $result): void
    {
        if ($result->status === DerivedCampaignPeriodStatus::Legacy) {
            throw new RuntimeException('Deriver darf Status legacy nicht schreiben.');
        }

        $order->derived_campaign_period_start = $result->start;
        $order->derived_campaign_period_end = $result->end;
        $order->derived_campaign_period_status = $result->status;
        $order->derived_campaign_period_at = $result->derivedAt;
        $order->derived_campaign_period_snapshot = $result->provenance;
        $order->save();
    }

    /**
     * @return array{
     *     dispo_order_position_id: int,
     *     sort: int,
     *     label: string,
     *     spot_method: string,
     *     result: string,
     *     source: string|null,
     *     start: string|null,
     *     end: string|null,
     *     unresolved_reason: string|null
     * }
     */
    private function resolvePosition(DispoOrderPosition $position): array
    {
        $base = [
            'dispo_order_position_id' => (int) $position->id,
            'sort' => (int) $position->sort,
            'label' => $this->positionLabel($position),
            'spot_method' => $position->spot_method->value,
            'result' => DerivedCampaignPeriodContract::RESULT_UNRESOLVED,
            'source' => null,
            'start' => null,
            'end' => null,
            'unresolved_reason' => null,
        ];

        if ($position->spot_method === SpotCalculationMethod::Calendar) {
            return array_merge($base, $this->resolveCalendar($position));
        }

        return array_merge($base, $this->resolveFlightPeriod($position));
    }

    /**
     * @return array{
     *     result: string,
     *     source: string|null,
     *     start: string|null,
     *     end: string|null,
     *     unresolved_reason: string|null
     * }
     */
    private function resolveCalendar(DispoOrderPosition $position): array
    {
        $entries = $position->getAttribute('planner_entries_snapshot');

        if (! is_array($entries)) {
            return $this->unresolved(DerivedCampaignPeriodContract::REASON_CORRUPT_SNAPSHOT);
        }

        $dates = [];
        foreach ($entries as $entry) {
            if (! is_array($entry)) {
                return $this->unresolved(DerivedCampaignPeriodContract::REASON_CORRUPT_SNAPSHOT);
            }

            $spotCount = $entry['spot_count'] ?? null;
            $usable = false;
            if (is_int($spotCount) && $spotCount >= 1) {
                $usable = true;
            } elseif (is_numeric($spotCount) && (int) $spotCount >= 1) {
                $usable = true;
            }

            if (! $usable) {
                continue;
            }

            $date = $this->normalizeDateIso($entry['date'] ?? null);
            if ($date === null) {
                return $this->unresolved(DerivedCampaignPeriodContract::REASON_CORRUPT_SNAPSHOT);
            }

            $dates[] = $date;
        }

        if ($dates === []) {
            return $this->unresolved(DerivedCampaignPeriodContract::REASON_NO_USABLE_PLANNER);
        }

        sort($dates);

        return [
            'result' => DerivedCampaignPeriodContract::RESULT_CONTRIBUTING,
            'source' => DerivedCampaignPeriodContract::SOURCE_PLANNER,
            'start' => $dates[0],
            'end' => $dates[count($dates) - 1],
            'unresolved_reason' => null,
        ];
    }

    /**
     * Average und sonstige Nicht-Calendar-Methoden: nur geschlossener Flight-Period.
     *
     * @return array{
     *     result: string,
     *     source: string|null,
     *     start: string|null,
     *     end: string|null,
     *     unresolved_reason: string|null
     * }
     */
    private function resolveFlightPeriod(DispoOrderPosition $position): array
    {
        $position->loadMissing(['fieldValues.snapshotFieldDefinition']);

        $periodOpen = null;
        $startRaw = null;
        $endRaw = null;
        $hasFlightRow = false;

        foreach ($position->fieldValues as $value) {
            $key = $value->snapshotFieldDefinition?->key;
            if ($key === 'period_open') {
                $periodOpen = $value->value_boolean;
            }
            if ($key === 'position_flight_period') {
                $hasFlightRow = true;
                $startRaw = $value->value_period_start;
                $endRaw = $value->value_period_end;
            }
        }

        if ($periodOpen === true) {
            return $this->unresolved(DerivedCampaignPeriodContract::REASON_PERIOD_OPEN);
        }

        if ($periodOpen !== false) {
            // Capture fehlt oder kein bool → fail-closed.
            return $this->unresolved(DerivedCampaignPeriodContract::REASON_FLIGHT_MISSING);
        }

        if (! $hasFlightRow) {
            return $this->unresolved(DerivedCampaignPeriodContract::REASON_FLIGHT_MISSING);
        }

        $start = $this->normalizeDateIso($startRaw);
        $end = $this->normalizeDateIso($endRaw);

        if ($start === null && $end === null) {
            return $this->unresolved(DerivedCampaignPeriodContract::REASON_FLIGHT_MISSING);
        }

        if ($start === null || $end === null) {
            return $this->unresolved(DerivedCampaignPeriodContract::REASON_FLIGHT_INCOMPLETE);
        }

        if ($end < $start) {
            return $this->unresolved(DerivedCampaignPeriodContract::REASON_FLIGHT_INVALID);
        }

        return [
            'result' => DerivedCampaignPeriodContract::RESULT_CONTRIBUTING,
            'source' => DerivedCampaignPeriodContract::SOURCE_FLIGHT,
            'start' => $start,
            'end' => $end,
            'unresolved_reason' => null,
        ];
    }

    /**
     * @return array{
     *     result: string,
     *     source: null,
     *     start: null,
     *     end: null,
     *     unresolved_reason: string
     * }
     */
    private function unresolved(string $reason): array
    {
        return [
            'result' => DerivedCampaignPeriodContract::RESULT_UNRESOLVED,
            'source' => null,
            'start' => null,
            'end' => null,
            'unresolved_reason' => $reason,
        ];
    }

    private function normalizeDateIso(mixed $raw): ?string
    {
        if ($raw === null || $raw === '') {
            return null;
        }

        if ($raw instanceof \DateTimeInterface) {
            return $raw->format('Y-m-d');
        }

        $string = substr(trim((string) $raw), 0, 10);
        if ($string === '' || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $string)) {
            return null;
        }

        $parts = explode('-', $string);
        if (! checkdate((int) $parts[1], (int) $parts[2], (int) $parts[0])) {
            return null;
        }

        return $string;
    }

    private function positionLabel(DispoOrderPosition $position): string
    {
        $inventory = trim((string) $position->inventory_name);
        $medium = trim((string) $position->advertising_medium_name);

        if ($inventory !== '' && $medium !== '') {
            return $inventory.' · '.$medium;
        }

        if ($inventory !== '') {
            return $inventory;
        }

        if ($medium !== '') {
            return $medium;
        }

        return 'Position #'.(int) $position->id;
    }
}
