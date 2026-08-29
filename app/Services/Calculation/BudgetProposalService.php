<?php

namespace App\Services\Calculation;

use App\Enums\BudgetStrategy;

/**
 * BUD-001–BUD-009 – Durchschnitts-Budgetvorschlag ohne Stundenverteilung.
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
     *     positions: list<array{position_key: string, inventory_id: int, inventory_name: string, length_seconds: int, total_spot_count: int}>,
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
        $entries = $this->positionEntries($positions, $orderDiscountPercent);

        $counts = array_fill(0, count($entries), 0);

        if ($strategy === BudgetStrategy::EqualBudget) {
            $this->fillEqualBudget($entries, $counts, $target);
            $explanation = 'Zielbudget N/N wird gleich auf alle Positionen verteilt. '
                .'Je Position wird der gleichgewichtete Stunden-Durchschnitt als Preisbasis verwendet. '
                .'Es entsteht nur eine Gesamtspotanzahl je Position, keine Verteilung auf einzelne Stunden.';
        } else {
            $this->fillMaximize($entries, $counts, $target);
            $explanation = 'Spotanzahl wird maximiert, indem das Budget vorrangig der günstigsten Position '
                .'(Stunden-Durchschnitt inkl. Konditionen) zugewiesen wird. '
                .'Positionen ohne Budget erhalten null Spots. Keine Stundenverteilung.';
        }

        $usedNn = '0.00';
        $resultPositions = [];

        foreach ($entries as $index => $entry) {
            $count = $counts[$index];
            if ($count < 1) {
                continue;
            }

            $usedNn = Decimal::roundMoney(Decimal::add(
                $usedNn,
                Decimal::mul($entry['nn_per_spot'], (string) $count),
            ));

            $resultPositions[] = [
                'position_key' => $entry['position_key'],
                'inventory_id' => $entry['inventory_id'],
                'inventory_name' => $entry['inventory_name'],
                'length_seconds' => $entry['length_seconds'],
                'total_spot_count' => $count,
            ];
        }

        $remainder = Decimal::roundMoney(Decimal::sub($target, $usedNn));

        return [
            'strategy' => $strategy->value,
            'target_budget_nn' => $target,
            'used_nn' => $usedNn,
            'remainder' => $remainder,
            'positions' => $resultPositions,
            'explanation' => $explanation,
        ];
    }

    /**
     * @param  list<PositionInput>  $positions
     * @return list<array{position_key: string, inventory_id: int, inventory_name: string, length_seconds: int, nn_per_spot: string}>
     */
    private function positionEntries(array $positions, string $orderDiscountPercent): array
    {
        $entries = [];

        foreach ($positions as $position) {
            $nn = $this->engine->nnPerSpotFromAverage($position, $orderDiscountPercent);
            if (Decimal::cmp($nn, '0') <= 0) {
                continue;
            }

            $entries[] = [
                'position_key' => $position->positionKey ?? ('inventory:'.$position->inventoryId),
                'inventory_id' => $position->inventoryId,
                'inventory_name' => $position->inventoryName,
                'length_seconds' => $position->lengthSeconds,
                'nn_per_spot' => $nn,
            ];
        }

        usort($entries, fn (array $left, array $right): int => Decimal::cmp(
            $left['nn_per_spot'],
            $right['nn_per_spot'],
        ));

        return $entries;
    }

    /**
     * @param  list<array{nn_per_spot: string}>  $entries
     * @param  array<int, int>  $counts
     */
    private function fillMaximize(array $entries, array &$counts, string $budget): void
    {
        $remaining = $budget;

        foreach ($entries as $index => $entry) {
            $nn = $entry['nn_per_spot'];
            $max = (int) Decimal::div($remaining, $nn, 0);
            if ($max < 1) {
                continue;
            }

            $counts[$index] = $max;
            $remaining = Decimal::sub($remaining, Decimal::mul($nn, (string) $max));
        }
    }

    /**
     * @param  list<array{nn_per_spot: string}>  $entries
     * @param  array<int, int>  $counts
     */
    private function fillEqualBudget(array $entries, array &$counts, string $budget): void
    {
        $n = count($entries);
        if ($n === 0) {
            return;
        }

        $allocated = '0.00';
        foreach ($entries as $index => $entry) {
            $share = $index === $n - 1
                ? Decimal::roundMoney(Decimal::sub($budget, $allocated))
                : Decimal::roundMoney(Decimal::div($budget, (string) $n));
            $allocated = Decimal::roundMoney(Decimal::add($allocated, $share));

            $max = (int) Decimal::div($share, $entry['nn_per_spot'], 0);
            $counts[$index] = max(0, $max);
        }
    }
}
