<?php

namespace App\Services\Calculation;

use App\Enums\BudgetStrategy;

/**
 * BUD-001–BUD-009 – deterministischer Vorschlag; Übernahme separat.
 *
 * BLK-007: Innerhalb eines Senders verteilt der Vorschlag Spots derzeit
 * ausschließlich auf die günstigste gewählte Preisstunde (Greedy). Das ist
 * kein ausgewogener Stundenplan.
 */
final class BudgetProposalService
{
    public function __construct(
        private readonly CalculationEngine $engine,
    ) {}

    /**
     * @param  list<PositionInput>  $positions
     * @return array{
     *     strategy: string,
     *     target_budget_nn: string,
     *     used_nn: string,
     *     remainder: string,
     *     positions: list<array{position_key: string, inventory_id: int, inventory_name: string, length_seconds: int, rows: list<array{hour: int, day_group: string, spot_count: int, second_price: string}>}>,
     *     explanation: string
     * }
     */
    public function propose(
        array $positions,
        string $targetBudgetNn,
        string $orderDiscountPercent,
        BudgetStrategy $strategy,
    ): array {
        $target = Decimal::roundMoney($targetBudgetNn);
        $slots = $this->slots($positions, $orderDiscountPercent);

        $counts = [];
        foreach ($slots as $index => $slot) {
            $counts[$index] = 0;
        }

        if ($strategy === BudgetStrategy::EqualBudget) {
            $this->fillEqualBudget($slots, $counts, $target);
            $explanation = 'Zielbudget N/N wird je ausgewähltem Sender in gleiche Anteile geteilt. '
                .'Innerhalb jedes Senders werden Spots derzeit nur in die günstigste gewählte Preisstunde gelegt (Greedy, BLK-007). '
                .'Das ist keine ausgewogene Stundenverteilung.';
        } else {
            $this->fillMaximize($slots, $counts, $target);
            $explanation = 'Ganzzahlige Spotanzahl wird maximiert, indem zuerst die günstigsten gewählten Preisstunden befüllt werden (Greedy). '
                .'Keine ausgewogene Verteilung über alle Stunden (BLK-007).';
        }

        $usedNn = '0.00';
        $grouped = [];

        foreach ($slots as $index => $slot) {
            $count = $counts[$index];
            if ($count < 1) {
                continue;
            }

            $usedNn = Decimal::roundMoney(Decimal::add(
                $usedNn,
                Decimal::mul($slot['nn_per_spot'], (string) $count),
            ));

            $key = $slot['position_key'];
            $grouped[$key] ??= [
                'position_key' => $key,
                'inventory_id' => $slot['inventory_id'],
                'inventory_name' => $slot['inventory_name'],
                'length_seconds' => $slot['length_seconds'],
                'rows' => [],
            ];
            $grouped[$key]['rows'][] = [
                'hour' => $slot['hour'],
                'day_group' => $slot['day_group'],
                'spot_count' => $count,
                'second_price' => $slot['second_price'],
            ];
        }

        $remainder = Decimal::roundMoney(Decimal::sub($target, $usedNn));

        return [
            'strategy' => $strategy->value,
            'target_budget_nn' => $target,
            'used_nn' => $usedNn,
            'remainder' => $remainder,
            'positions' => array_values($grouped),
            'explanation' => $explanation,
        ];
    }

    /**
     * @param  list<PositionInput>  $positions
     * @return list<array<string, mixed>>
     */
    private function slots(array $positions, string $orderDiscountPercent): array
    {
        $slots = [];

        foreach ($positions as $position) {
            $uniqueRows = $this->engine->uniqueHourRows($position->rows);
            $positionKey = $position->positionKey ?? ('inventory:'.$position->inventoryId);

            foreach ($uniqueRows as $row) {
                $nn = $this->engine->nnPerSpot($position, $orderDiscountPercent, $row);

                if (Decimal::cmp($nn, '0') <= 0) {
                    continue;
                }

                $slots[] = [
                    'position_key' => $positionKey,
                    'inventory_id' => $position->inventoryId,
                    'inventory_name' => $position->inventoryName,
                    'length_seconds' => $position->lengthSeconds,
                    'hour' => $row->hour,
                    'day_group' => $row->dayGroup->value,
                    'second_price' => $row->secondPrice,
                    'nn_per_spot' => $nn,
                ];
            }
        }

        usort($slots, function (array $left, array $right): int {
            $price = Decimal::cmp($left['nn_per_spot'], $right['nn_per_spot']);
            if ($price !== 0) {
                return $price;
            }

            if ($left['inventory_id'] !== $right['inventory_id']) {
                return $left['inventory_id'] <=> $right['inventory_id'];
            }

            if ($left['hour'] !== $right['hour']) {
                return $left['hour'] <=> $right['hour'];
            }

            return strcmp((string) $left['day_group'], (string) $right['day_group']);
        });

        return $slots;
    }

    /**
     * @param  array<int, array<string, mixed>>  $slots
     * @param  array<int, int>  $counts
     */
    private function fillMaximize(array $slots, array &$counts, string $budget): void
    {
        $remaining = $budget;

        foreach ($slots as $index => $slot) {
            $nn = $slot['nn_per_spot'];
            if (Decimal::cmp($nn, '0') <= 0) {
                continue;
            }

            $max = (int) Decimal::div($remaining, $nn, 0);
            if ($max < 1) {
                continue;
            }

            $counts[$index] = $max;
            $remaining = Decimal::sub($remaining, Decimal::mul($nn, (string) $max));
        }
    }

    /**
     * @param  list<array<string, mixed>>  $slots
     * @param  array<int, int>  $counts
     */
    private function fillEqualBudget(array $slots, array &$counts, string $budget): void
    {
        $inventoryIds = [];
        foreach ($slots as $slot) {
            $inventoryIds[$slot['inventory_id']] = true;
        }

        $ids = array_keys($inventoryIds);
        sort($ids);
        $n = count($ids);

        if ($n === 0) {
            return;
        }

        $allocated = '0.00';
        foreach ($ids as $i => $inventoryId) {
            $share = $i === $n - 1
                ? Decimal::roundMoney(Decimal::sub($budget, $allocated))
                : Decimal::roundMoney(Decimal::div($budget, (string) $n));
            $allocated = Decimal::roundMoney(Decimal::add($allocated, $share));

            $inventorySlots = [];
            foreach ($slots as $index => $slot) {
                if ($slot['inventory_id'] === $inventoryId) {
                    $inventorySlots[$index] = $slot;
                }
            }

            $this->fillMaximize($inventorySlots, $counts, $share);
        }
    }
}
