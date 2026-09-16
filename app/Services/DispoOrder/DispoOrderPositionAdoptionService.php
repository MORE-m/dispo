<?php

namespace App\Services\DispoOrder;

use App\Models\Calculation;
use App\Models\CalculationPosition;
use App\Models\DispoOrderPosition;
use App\Support\Inventory\InventoryIdentity;

final class DispoOrderPositionAdoptionService
{
    /**
     * @return array<int, list<array{dispo_order_id: int, dispo_order_number: string}>>
     */
    public function adoptionsForCalculation(Calculation $calculation): array
    {
        $positionIds = $calculation->positions()->pluck('id');

        if ($positionIds->isEmpty()) {
            return [];
        }

        $rows = DispoOrderPosition::query()
            ->whereIn('calculation_position_id', $positionIds)
            ->with('dispoOrder:id,number')
            ->get(['id', 'calculation_position_id', 'dispo_order_id']);

        /** @var array<int, list<array{dispo_order_id: int, dispo_order_number: string}>> $map */
        $map = [];

        foreach ($rows as $row) {
            if ($row->calculation_position_id === null || $row->dispoOrder === null) {
                continue;
            }

            $map[$row->calculation_position_id][] = [
                'dispo_order_id' => $row->dispo_order_id,
                'dispo_order_number' => $row->dispoOrder->number,
            ];
        }

        return $map;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function selectablePositions(Calculation $calculation): array
    {
        $calculation->loadMissing([
            'positions.inventory',
            'positions.advertisingMedium',
            'positions.timeRanges',
            'positions.plannerEntries',
        ]);
        $adoptions = $this->adoptionsForCalculation($calculation);

        return array_values($calculation->positions->map(function (CalculationPosition $position) use ($adoptions): array {
            $existing = $adoptions[$position->id] ?? [];

            return [
                'id' => $position->id,
                'inventory_name' => InventoryIdentity::displayName($position),
                'advertising_medium_name' => $position->advertisingMedium->name ?? 'Unbekannt',
                'length_seconds' => $position->length_seconds,
                'total_spot_count' => $position->total_spot_count,
                'nn_invest' => (string) $position->nn_invest,
                'time_ranges' => $position->timeRanges->map(fn ($range): array => [
                    'start_hour' => $range->start_hour,
                    'end_hour_exclusive' => $range->end_hour_exclusive,
                    'day_group' => $range->day_group->value,
                    'spot_count' => $range->spot_count,
                ])->all(),
                'planner_entries' => $position->plannerEntries->map(fn ($entry): array => [
                    'date' => $entry->dateIso(),
                    'hour' => $entry->hour,
                    'day_group' => $entry->day_group->value,
                    'spot_count' => $entry->spot_count,
                ])->all(),
                'already_adopted' => $existing !== [],
                'adoptions' => $existing,
            ];
        })->values()->all());
    }
}
