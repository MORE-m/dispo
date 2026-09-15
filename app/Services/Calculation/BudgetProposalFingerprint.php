<?php

namespace App\Services\Calculation;

use App\Enums\BudgetProposalStatus;
use App\Models\AdvertisingMedium;
use App\Models\Calculation;
use App\Support\PriceList\PriceListYearSelection;

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
     * @param  list<array{
     *     client_id: string,
     *     inventory_id: int,
     *     spot_length_seconds: int,
     *     distribution_ranges: list<array{start_hour: int, end_hour_exclusive: int, day_group: string}>,
     *     position_discounts: list<array{type: string, custom_label: string|null, percent: string}>
     * }> $elements
     * @param  array<int, array<string, mixed>>  $catalogs
     * @return array<string, mixed>
     */
    public function inputFromElements(
        array $payload,
        array $elements,
        array $catalogs,
    ): array {
        $priceListIdentity = [];
        foreach ($catalogs as $inventoryId => $catalog) {
            $list = $catalog['priceList'];
            $priceListIdentity[$inventoryId] = [
                'price_list_id' => (int) ($list->id ?? 0),
                'year' => (int) ($list->year ?? 0),
                'version' => (string) ($list->version ?? ''),
            ];
        }

        $elementFingerprint = [];
        foreach ($elements as $element) {
            $elementFingerprint[] = [
                'client_id' => $element['client_id'],
                'inventory_id' => $element['inventory_id'],
                'spot_length_seconds' => $element['spot_length_seconds'],
                'distribution_ranges' => $element['distribution_ranges'],
                'position_discounts' => $this->normalizeDiscountRows($element['position_discounts']),
            ];
        }

        return [
            'algorithm_version' => BudgetSpotAllocator::ALGORITHM_VERSION,
            'target_budget_nn' => Decimal::roundMoney((string) $payload['target_budget_nn']),
            'price_year' => PriceListYearSelection::resolveYearFromPayload($payload),
            'budget_elements' => $elementFingerprint,
            'order_discounts' => $payload['order_discounts'] ?? [],
            'ae_enabled' => (bool) ($payload['ae_enabled'] ?? false),
            'price_list_identity' => $priceListIdentity,
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function currentFingerprint(array $payload, ?Calculation $existing = null): string
    {
        $normalizer = new BudgetPlanningPayloadNormalizer;

        try {
            $elements = $normalizer->normalizeElements($payload);
        } catch (\Throwable) {
            return $this->compute(['invalid' => true]);
        }

        $catalogs = [];
        if ($elements !== []) {
            $resolver = app(CatalogResolver::class);
            $mediumId = (int) (AdvertisingMedium::query()->where('code', 'spot_classic')->value('id') ?? 0);
            $priceYear = PriceListYearSelection::resolveYearFromPayload($payload);
            foreach ($elements as $element) {
                $inventoryId = $element['inventory_id'];
                if ($mediumId > 0 && ! isset($catalogs[$inventoryId])) {
                    try {
                        $catalogs[$inventoryId] = $resolver->resolveInventoryForBudget(
                            $inventoryId,
                            $mediumId,
                            $priceYear,
                        );
                    } catch (\Throwable) {
                        $catalogs[$inventoryId] = ['priceList' => (object) [
                            'id' => 0,
                            'year' => 0,
                            'version' => 'unknown',
                        ]];
                    }
                }
            }
        }

        return $this->compute($this->inputFromElements($payload, $elements, $catalogs));
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
     * @param  list<array{type: string, custom_label: string|null, percent: string}|array<string, mixed>>  $discounts
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
