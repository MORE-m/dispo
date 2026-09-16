<?php

namespace App\Services\DispoOrder;

use App\Enums\DispoOrderApprovalKind;
use App\Models\Calculation;
use App\Models\CalculationOrderDiscount;
use App\Models\CalculationPosition;
use App\Models\CalculationPositionDiscount;
use App\Models\CalculationPositionPlannerEntry;
use App\Models\CalculationPositionTimeRange;
use App\Models\SpotClassicPlanRow;
use App\Services\Calculation\SpecialApprovalAssessor;
use App\Services\Calculation\StoredPositionTotals;
use App\Support\Calculation\CalculationMethodFreezeResolver;
use App\Support\Inventory\InventoryIdentity;
use Illuminate\Support\Collection;

/**
 * Snapshot-Mapping aus gespeicherten Kalkulationswerten – keine Neuberechnung.
 */
final class DispoOrderSnapshotMapper
{
    public function __construct(
        private readonly SpecialApprovalAssessor $assessor = new SpecialApprovalAssessor,
        private readonly CalculationMethodFreezeResolver $freezeResolver = new CalculationMethodFreezeResolver,
    ) {}

    /**
     * @param  Collection<int, CalculationPosition>  $selectedPositions
     * @return array<string, mixed>
     */
    public function headerFromCalculation(Calculation $calculation, Collection $selectedPositions): array
    {
        $calculation->loadMissing(['advisor', 'orderDiscounts', 'positions.inventory']);
        $allSelected = $selectedPositions->count() === $calculation->positions->count();
        $orderTotals = StoredPositionTotals::sum($selectedPositions);
        $sourceTotals = StoredPositionTotals::fromCalculation($calculation);
        $assessment = $this->assessor->assessFromCalculation($calculation, $selectedPositions);

        return [
            'source_calculation_number' => $calculation->number,
            'customer_name' => $calculation->customer_name,
            'agency_name' => $calculation->agency_name,
            'campaign' => $calculation->campaign,
            'product_title' => $calculation->product_title,
            'briefing' => $calculation->briefing,
            'advisor_id' => $calculation->advisor_id,
            'advisor_name' => $calculation->advisor?->name,
            'order_discount_percent' => (string) $calculation->order_discount_percent,
            'ae_enabled' => (bool) $calculation->ae_enabled,
            'target_budget_nn' => $allSelected && $calculation->target_budget_nn !== null
                ? (string) $calculation->target_budget_nn
                : null,
            'media_gross' => $orderTotals['media_gross'],
            'position_discount_total' => $orderTotals['position_discount_total'],
            'order_discount_total' => $orderTotals['order_discount_total'],
            'ae_total' => $orderTotals['ae_total'],
            'nn_invest' => $orderTotals['nn_invest'],
            'requires_special_approval' => $assessment->requiresSpecialApproval,
            'approval_kind' => $assessment->requiresSpecialApproval
                ? DispoOrderApprovalKind::Special->value
                : DispoOrderApprovalKind::Regular->value,
            'special_approval_reasons' => $assessment->reasons,
            'order_discounts_snapshot' => $calculation->orderDiscounts->map(
                fn (CalculationOrderDiscount $discount): array => [
                    'type' => $discount->type->value,
                    'custom_label' => $discount->custom_label,
                    'percent' => (string) $discount->percent,
                ]
            )->all(),
            'source_calculation_totals_snapshot' => $sourceTotals,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function positionFromCalculationPosition(CalculationPosition $position, int $sort): array
    {
        $position->loadMissing(['inventory', 'advertisingMedium', 'priceList', 'planRows', 'timeRanges', 'plannerEntries', 'discounts']);

        // Dispoerzeugung ist Mutation: Descriptor muss ausführbar sein.
        $freeze = $this->freezeResolver->resolveStoredPosition($position, forExecution: true);

        return [
            'calculation_position_id' => $position->id,
            'sort' => $sort,
            'inventory_id' => $position->inventory_id,
            'inventory_name' => InventoryIdentity::displayName($position),
            'inventory_code' => InventoryIdentity::displayCode($position),
            'advertising_medium_id' => $position->advertising_medium_id,
            'advertising_medium_name' => $position->advertisingMedium->name ?? 'Unbekannt',
            'advertising_medium_code' => $position->advertisingMedium->code ?? null,
            'kind' => $freeze->legacyKind()->value,
            'spot_method' => $freeze->legacySpotMethod()->value,
            'engine_profile_key' => $freeze->engineProfileKey,
            'calculation_method_key' => $freeze->calculationMethodKey,
            'calculation_method_name' => $freeze->calculationMethodName,
            'algorithm_version' => $freeze->algorithmVersion,
            'length_seconds' => $position->length_seconds,
            'total_spot_count' => $position->total_spot_count,
            'needs_spot_redistribution' => (bool) $position->needs_spot_redistribution,
            'price_list_id' => $position->price_list_id,
            'price_list_version' => $position->price_list_version,
            'price_list_name' => $position->priceList?->name,
            'average_second_price' => $position->average_second_price === null ? null : (string) $position->average_second_price,
            'length_index' => $position->length_index,
            'surcharge_percent' => (string) $position->surcharge_percent,
            'position_discount_percent' => (string) $position->position_discount_percent,
            'ae_percent' => (string) $position->ae_percent,
            'is_discountable' => $position->is_discountable,
            'is_ae_eligible' => $position->is_ae_eligible,
            'media_gross' => (string) $position->media_gross,
            'position_discount_amount' => (string) $position->position_discount_amount,
            'order_discount_amount' => (string) $position->order_discount_amount,
            'ae_amount' => (string) $position->ae_amount,
            'nn_invest' => (string) $position->nn_invest,
            'plan_rows_snapshot' => $position->planRows->map(
                fn (SpotClassicPlanRow $row): array => [
                    'hour' => $row->hour,
                    'day_group' => $row->day_group->value,
                    'spot_count' => $row->spot_count,
                    'second_price' => (string) $row->second_price,
                    'line_gross' => (string) $row->line_gross,
                ]
            )->all(),
            'time_ranges_snapshot' => $position->timeRanges->map(
                fn (CalculationPositionTimeRange $range): array => [
                    'start_hour' => $range->start_hour,
                    'end_hour_exclusive' => $range->end_hour_exclusive,
                    'day_group' => $range->day_group->value,
                    'spot_count' => $range->spot_count,
                    'average_second_price' => $range->average_second_price === null ? null : (string) $range->average_second_price,
                    'range_gross' => $range->range_gross === null ? null : (string) $range->range_gross,
                ]
            )->all(),
            'planner_entries_snapshot' => $position->plannerEntries->map(
                fn (CalculationPositionPlannerEntry $entry): array => [
                    'date' => $entry->dateIso(),
                    'hour' => $entry->hour,
                    'day_group' => $entry->day_group->value,
                    'spot_count' => $entry->spot_count,
                    'second_price' => (string) $entry->second_price,
                    'line_gross' => (string) $entry->line_gross,
                ]
            )->all(),
            'position_discounts_snapshot' => $position->discounts->map(
                fn (CalculationPositionDiscount $discount): array => [
                    'type' => $discount->type->value,
                    'custom_label' => $discount->custom_label,
                    'percent' => (string) $discount->percent,
                ]
            )->all(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function orderSnapshot(DispoOrderWriterResult $result): array
    {
        $order = $result->order;
        $order->loadMissing(['positions', 'creator']);

        return [
            'number' => $order->number,
            'calculation_id' => $order->calculation_id,
            'source_calculation_number' => $order->source_calculation_number,
            'status' => $order->status->value,
            'created_by_id' => $order->created_by_id,
            'position_ids' => $result->positionIds,
            'nn_invest' => (string) $order->nn_invest,
            'approval_kind' => $order->approval_kind->value,
            'requires_special_approval' => (bool) $order->requires_special_approval,
            'special_approval_reasons' => $order->special_approval_reasons ?? [],
            'positions' => $order->positions->map(fn ($position): array => [
                'calculation_position_id' => $position->calculation_position_id,
                'inventory_name' => $position->inventory_name,
                'nn_invest' => (string) $position->nn_invest,
            ])->all(),
        ];
    }
}
