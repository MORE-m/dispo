<?php

namespace App\Services\Calculation;

use Illuminate\Validation\ValidationException;

/**
 * Normalisiert Budget-Werbeelemente aus dem Proposal-Payload (inkl. Legacy-Felder).
 */
final class BudgetPlanningPayloadNormalizer
{
    /**
     * @param  array<string, mixed>  $payload
     * @return list<array{
     *     client_id: string,
     *     inventory_id: int,
     *     spot_length_seconds: int,
     *     distribution_ranges: list<array{start_hour: int, end_hour_exclusive: int, day_group: string}>,
     *     position_discounts: list<array{type: string, custom_label: string|null, percent: string}>
     * }>
     */
    public function normalizeElements(array $payload): array
    {
        $rawElements = $payload['budget_elements'] ?? [];
        if (is_array($rawElements) && $rawElements !== []) {
            /** @var list<array<string, mixed>> $rows */
            $rows = array_values($rawElements);

            return $this->normalizeElementRows($rows);
        }

        return $this->normalizeLegacyPayload($payload);
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return list<array{
     *     client_id: string,
     *     inventory_id: int,
     *     spot_length_seconds: int,
     *     distribution_ranges: list<array{start_hour: int, end_hour_exclusive: int, day_group: string}>,
     *     position_discounts: list<array{type: string, custom_label: string|null, percent: string}>
     * }>
     */
    public function normalizeElementRows(array $rows): array
    {
        $elements = [];
        $seenInventoryIds = [];

        foreach ($rows as $index => $row) {
            $inventoryId = (int) ($row['inventory_id'] ?? 0);
            if ($inventoryId < 1) {
                throw ValidationException::withMessages([
                    "budget_elements.{$index}.inventory_id" => 'Sender ist erforderlich.',
                ]);
            }

            if (isset($seenInventoryIds[$inventoryId])) {
                throw ValidationException::withMessages([
                    "budget_elements.{$index}.inventory_id" => 'Dieser Sender ist bereits in einem Werbeelement enthalten.',
                ]);
            }
            $seenInventoryIds[$inventoryId] = true;

            $lengthSeconds = (int) ($row['spot_length_seconds'] ?? 0);
            if ($lengthSeconds < 1) {
                throw ValidationException::withMessages([
                    "budget_elements.{$index}.spot_length_seconds" => 'Spotlänge ist erforderlich.',
                ]);
            }

            $ranges = (new TimeRangeValidator)->validated(
                $row['distribution_ranges'] ?? [],
                "budget_elements.{$index}.distribution_ranges",
                requireAtLeastOne: true,
                requireSpotCount: false,
            );

            $discounts = $this->normalizeDiscountRows($row['position_discounts'] ?? []);

            $clientId = trim((string) ($row['client_id'] ?? ''));
            if ($clientId === '') {
                $clientId = 'element-'.$inventoryId;
            }

            $elements[] = [
                'client_id' => $clientId,
                'inventory_id' => $inventoryId,
                'spot_length_seconds' => $lengthSeconds,
                'distribution_ranges' => $ranges,
                'position_discounts' => $discounts,
            ];
        }

        if ($elements === []) {
            throw ValidationException::withMessages([
                'budget_elements' => 'Mindestens ein Budget-Werbeelement ist erforderlich.',
            ]);
        }

        return $elements;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return list<array{
     *     client_id: string,
     *     inventory_id: int,
     *     spot_length_seconds: int,
     *     distribution_ranges: list<array{start_hour: int, end_hour_exclusive: int, day_group: string}>,
     *     position_discounts: list<array{type: string, custom_label: string|null, percent: string}>
     * }>
     */
    private function normalizeLegacyPayload(array $payload): array
    {
        $wishIds = [];
        foreach ($payload['budget_wish_inventory_ids'] ?? [] as $id) {
            $parsed = (int) $id;
            if ($parsed > 0) {
                $wishIds[] = $parsed;
            }
        }

        if ($wishIds === []) {
            throw ValidationException::withMessages([
                'budget_elements' => 'Mindestens ein Budget-Werbeelement ist erforderlich.',
            ]);
        }

        $lengthSeconds = (int) ($payload['budget_spot_length_seconds'] ?? 0);
        if ($lengthSeconds < 1) {
            throw ValidationException::withMessages([
                'budget_spot_length_seconds' => 'Spotlänge ist erforderlich.',
            ]);
        }

        $ranges = (new TimeRangeValidator)->validated(
            $payload['budget_distribution_ranges'] ?? [],
            'budget_distribution_ranges',
            requireAtLeastOne: true,
            requireSpotCount: false,
        );

        $discountsByInventory = [];
        foreach ($payload['budget_position_discounts_by_inventory'] ?? [] as $row) {
            if (! is_array($row)) {
                continue;
            }
            $inventoryId = (int) ($row['inventory_id'] ?? 0);
            if ($inventoryId < 1) {
                continue;
            }
            $discountsByInventory[$inventoryId] = $this->normalizeDiscountRows($row['discounts'] ?? []);
        }

        foreach ($payload['positions'] ?? [] as $position) {
            if (! is_array($position)) {
                continue;
            }
            $inventoryId = (int) ($position['inventory_id'] ?? 0);
            if ($inventoryId < 1 || isset($discountsByInventory[$inventoryId])) {
                continue;
            }
            $discountsByInventory[$inventoryId] = $this->normalizeDiscountRows($position['position_discounts'] ?? []);
        }

        $elements = [];
        foreach ($wishIds as $inventoryId) {
            $elements[] = [
                'client_id' => 'inventory:'.$inventoryId,
                'inventory_id' => $inventoryId,
                'spot_length_seconds' => $lengthSeconds,
                'distribution_ranges' => $ranges,
                'position_discounts' => $discountsByInventory[$inventoryId] ?? [],
            ];
        }

        return $elements;
    }

    /**
     * @param  array<string, mixed>  $proposalPayload
     * @return list<array{
     *     client_id: string,
     *     inventory_id: int,
     *     spot_length_seconds: int,
     *     distribution_ranges: list<array{start_hour: int, end_hour_exclusive: int, day_group: string}>,
     *     position_discounts: list<array{type: string, custom_label: string|null, percent: string}>
     * }>
     */
    public function normalizeFromStoredProposal(array $proposalPayload): array
    {
        if (is_array($proposalPayload['budget_elements'] ?? null) && $proposalPayload['budget_elements'] !== []) {
            /** @var list<array<string, mixed>> $rows */
            $rows = array_values($proposalPayload['budget_elements']);

            return $this->normalizeElementRows($rows);
        }

        $legacyPayload = [
            'budget_wish_inventory_ids' => $proposalPayload['wish_inventory_ids'] ?? [],
            'budget_spot_length_seconds' => $proposalPayload['spot_length_seconds'] ?? 0,
            'budget_distribution_ranges' => $proposalPayload['distribution_ranges'] ?? [],
            'budget_position_discounts_by_inventory' => $proposalPayload['budget_position_discounts_by_inventory'] ?? [],
            'order_discounts' => $proposalPayload['order_discounts'] ?? [],
            'ae_enabled' => $proposalPayload['ae_enabled'] ?? false,
        ];

        return $this->normalizeLegacyPayload($legacyPayload);
    }

    /**
     * @param  list<array<string, mixed>>  $discounts
     * @return list<array{type: string, custom_label: string|null, percent: string}>
     */
    private function normalizeDiscountRows(array $discounts): array
    {
        $rows = [];
        foreach ($discounts as $discount) {
            $rows[] = [
                'type' => (string) ($discount['type'] ?? ''),
                'custom_label' => isset($discount['custom_label'])
                    ? (string) $discount['custom_label']
                    : null,
                'percent' => (string) ($discount['percent'] ?? '0'),
            ];
        }

        return $rows;
    }
}
