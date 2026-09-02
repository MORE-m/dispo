<?php

namespace App\Services\DispoOrder;

use App\Models\Calculation;
use App\Models\CalculationOrderDiscount;
use App\Models\CalculationPosition;
use App\Models\CalculationPositionDiscount;
use App\Models\CalculationPositionTimeRange;
use App\Models\SpotClassicPlanRow;

/**
 * Snapshot-Mapping aus gespeicherten Kalkulationswerten – keine Neuberechnung.
 */
final class DispoOrderSnapshotMapper
{
    /**
     * @return array<string, mixed>
     */
    public function headerFromCalculation(Calculation $calculation): array
    {
        $calculation->loadMissing(['advisor', 'orderDiscounts']);

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
            'target_budget_nn' => $calculation->target_budget_nn === null ? null : (string) $calculation->target_budget_nn,
            'media_gross' => (string) $calculation->media_gross,
            'position_discount_total' => (string) $calculation->position_discount_total,
            'order_discount_total' => (string) $calculation->order_discount_total,
            'ae_total' => (string) $calculation->ae_total,
            'nn_invest' => (string) $calculation->nn_invest,
            'requires_special_approval' => (bool) $calculation->requires_special_approval,
            'order_discounts_snapshot' => $calculation->orderDiscounts->map(
                fn (CalculationOrderDiscount $discount): array => [
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
    public function positionFromCalculationPosition(CalculationPosition $position, int $sort): array
    {
        $position->loadMissing(['inventory', 'advertisingMedium', 'priceList', 'planRows', 'timeRanges', 'discounts']);

        return [
            'calculation_position_id' => $position->id,
            'sort' => $sort,
            'inventory_id' => $position->inventory_id,
            'inventory_name' => $position->inventory->name ?? 'Unbekannt',
            'inventory_code' => $position->inventory->code ?? null,
            'advertising_medium_id' => $position->advertising_medium_id,
            'advertising_medium_name' => $position->advertisingMedium->name ?? 'Unbekannt',
            'advertising_medium_code' => $position->advertisingMedium->code ?? null,
            'kind' => $position->kind->value,
            'spot_method' => $position->spot_method->value,
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
            'positions' => $order->positions->map(fn ($position): array => [
                'calculation_position_id' => $position->calculation_position_id,
                'inventory_name' => $position->inventory_name,
                'nn_invest' => (string) $position->nn_invest,
            ])->all(),
        ];
    }
}
