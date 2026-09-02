<?php

namespace App\Services\Calculation;

use App\Enums\BudgetProposalStatus;
use App\Models\AdvertisingMedium;
use App\Models\Calculation;

/**
 * Fingerprint für Budgetvorschlag-Eingaben und Aktualitätsprüfung.
 */
final class BudgetProposalFingerprint
{
    /**
     * @param  array<string, mixed>  $input
     */
    public function compute(array $input): string
    {
        return hash('sha256', json_encode($input, JSON_THROW_ON_ERROR));
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  list<int>  $wishInventoryIds
     * @param  list<array{start_hour: int, end_hour_exclusive: int, day_group: string}>  $distributionRanges
     * @param  array<int, array<string, mixed>>  $catalogs
     * @return array<string, mixed>
     */
    public function inputFromPayload(
        array $payload,
        array $wishInventoryIds,
        int $lengthSeconds,
        array $distributionRanges,
        array $catalogs,
    ): array {
        $priceListVersions = [];
        foreach ($catalogs as $inventoryId => $catalog) {
            $priceListVersions[$inventoryId] = $catalog['priceList']->version;
        }

        return [
            'algorithm_version' => BudgetSpotAllocator::ALGORITHM_VERSION,
            'target_budget_nn' => Decimal::roundMoney((string) $payload['target_budget_nn']),
            'wish_inventory_ids' => $wishInventoryIds,
            'spot_length_seconds' => $lengthSeconds,
            'distribution_ranges' => $distributionRanges,
            'order_discounts' => $payload['order_discounts'] ?? [],
            'ae_enabled' => (bool) ($payload['ae_enabled'] ?? false),
            'position_discounts' => $this->positionDiscountFingerprint($payload),
            'price_list_versions' => $priceListVersions,
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function currentFingerprint(array $payload, ?Calculation $existing = null): string
    {
        $wishIds = [];
        foreach ($payload['budget_wish_inventory_ids'] ?? [] as $id) {
            $parsed = (int) $id;
            if ($parsed > 0) {
                $wishIds[] = $parsed;
            }
        }

        $ranges = [];
        foreach ($payload['budget_distribution_ranges'] ?? [] as $range) {
            if (! is_array($range)) {
                continue;
            }
            $ranges[] = [
                'start_hour' => (int) ($range['start_hour'] ?? 0),
                'end_hour_exclusive' => (int) ($range['end_hour_exclusive'] ?? 0),
                'day_group' => (string) ($range['day_group'] ?? ''),
            ];
        }

        $catalogs = [];
        if ($wishIds !== []) {
            $resolver = app(CatalogResolver::class);
            $mediumId = (int) (AdvertisingMedium::query()->where('code', 'spot_classic')->value('id') ?? 0);
            foreach ($wishIds as $inventoryId) {
                if ($mediumId > 0) {
                    try {
                        $catalogs[$inventoryId] = $resolver->resolveInventoryForBudget($inventoryId, $mediumId);
                    } catch (\Throwable) {
                        $catalogs[$inventoryId] = ['priceList' => (object) ['version' => 'unknown']];
                    }
                }
            }
        }

        return $this->compute($this->inputFromPayload(
            $payload,
            $wishIds,
            (int) ($payload['budget_spot_length_seconds'] ?? 0),
            $ranges,
            $catalogs,
        ));
    }

    /**
     * @param  array<string, mixed>  $storedProposal
     * @param  array<string, mixed>  $payload
     */
    public function isStale(array $storedProposal, array $payload, ?Calculation $existing = null): bool
    {
        $storedFingerprint = (string) ($storedProposal['input_fingerprint'] ?? '');
        if ($storedFingerprint === '') {
            return true;
        }

        return $storedFingerprint !== $this->currentFingerprint($payload, $existing);
    }

    /**
     * @param  array<string, mixed>  $proposal
     * @param  array<string, mixed>  $payload
     */
    public function statusFromProposal(
        array $proposal,
        array $payload,
        ?Calculation $existing = null,
    ): BudgetProposalStatus {
        if (($proposal['status'] ?? '') === BudgetProposalStatus::Manual->value) {
            return BudgetProposalStatus::Manual;
        }

        if ($this->isStale($proposal, $payload, $existing)) {
            return BudgetProposalStatus::Stale;
        }

        return BudgetProposalStatus::Current;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<int, list<array{type: string, percent: string, custom_label: string|null}>>
     */
    private function positionDiscountFingerprint(array $payload): array
    {
        $map = [];

        foreach ($payload['budget_position_discounts_by_inventory'] ?? [] as $row) {
            if (! is_array($row)) {
                continue;
            }

            $inventoryId = (int) ($row['inventory_id'] ?? 0);
            if ($inventoryId < 1) {
                continue;
            }

            $discounts = $row['discounts'] ?? [];
            if (! is_array($discounts)) {
                continue;
            }

            $map[$inventoryId] = $this->normalizeDiscountRows(array_values($discounts));
        }

        foreach ($payload['positions'] ?? [] as $position) {
            $inventoryId = (int) ($position['inventory_id'] ?? 0);
            if ($inventoryId < 1 || isset($map[$inventoryId])) {
                continue;
            }

            $discounts = $position['position_discounts'] ?? [];
            if (! is_array($discounts)) {
                continue;
            }

            $map[$inventoryId] = $this->normalizeDiscountRows(array_values($discounts));
        }

        return $map;
    }

    /**
     * @param  list<array<string, mixed>>  $discounts
     * @return list<array{type: string, percent: string, custom_label: string|null}>
     */
    private function normalizeDiscountRows(array $discounts): array
    {
        return array_map(
            fn (array $row): array => [
                'type' => (string) ($row['type'] ?? ''),
                'percent' => (string) ($row['percent'] ?? '0'),
                'custom_label' => isset($row['custom_label'])
                    ? (string) $row['custom_label']
                    : null,
            ],
            $discounts,
        );
    }
}
