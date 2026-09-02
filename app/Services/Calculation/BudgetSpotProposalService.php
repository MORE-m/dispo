<?php

namespace App\Services\Calculation;

use App\Enums\BudgetProposalStatus;
use App\Enums\BudgetStrategy;
use App\Enums\DayGroup;
use App\Enums\DiscountType;
use App\Enums\SpotCalculationMethod;
use App\Models\AdvertisingMedium;
use App\Models\Calculation;
use App\Models\CalculationPosition;
use Illuminate\Validation\ValidationException;

/**
 * Budgetbasierter Spotvorschlag mit gleicher Spotanzahl je Wunschsender
 * und gleichmäßiger Stundenverteilung.
 *
 * Der Budgetvorschlag ist preis- und verteilungsbasiert, nicht reichweitenoptimiert.
 */
final class BudgetSpotProposalService
{
    public function __construct(
        private readonly CalculationEngine $engine,
        private readonly CatalogResolver $catalog,
        private readonly BudgetSpotAllocator $allocator,
        private readonly BudgetProposalFingerprint $fingerprint,
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function propose(array $payload, ?Calculation $existing = null): array
    {
        $target = Decimal::roundMoney((string) $payload['target_budget_nn']);
        if (Decimal::cmp($target, '0') <= 0) {
            throw ValidationException::withMessages([
                'target_budget_nn' => 'Zielbudget N/N muss größer als 0 sein.',
            ]);
        }

        $wishInventoryIds = $this->wishInventoryIds($payload);
        if ($wishInventoryIds === []) {
            throw ValidationException::withMessages([
                'budget_wish_inventory_ids' => 'Mindestens ein Wunschsender ist erforderlich.',
            ]);
        }

        $lengthSeconds = (int) ($payload['budget_spot_length_seconds'] ?? 0);
        if ($lengthSeconds < 1) {
            throw ValidationException::withMessages([
                'budget_spot_length_seconds' => 'Spotlänge ist erforderlich.',
            ]);
        }

        $distributionRanges = $this->validatedDistributionRanges($payload);
        $buckets = $this->buildBuckets($distributionRanges);
        if ($buckets === []) {
            throw ValidationException::withMessages([
                'budget_distribution_ranges' => 'Mindestens ein erlaubter Verteilungszeitraum ist erforderlich.',
            ]);
        }

        $mediumId = $this->spotClassicMediumId();
        $orderDiscounts = $this->orderDiscountInputs($payload);
        $orderDiscountPercent = $this->effectivePercentFromDiscounts($orderDiscounts);
        $aeEnabled = (bool) ($payload['ae_enabled'] ?? false);
        $positionDiscountsByInventory = $this->positionDiscountsByInventory($payload, $existing);

        $catalogs = [];
        $bucketPrices = [];

        foreach ($wishInventoryIds as $inventoryId) {
            $catalogs[$inventoryId] = $this->catalog->resolveInventoryForBudget($inventoryId, $mediumId);
            $bucketPrices[$inventoryId] = $this->resolveBucketPrices(
                $catalogs[$inventoryId],
                $buckets,
            );
        }

        $maxSpots = $this->findMaxSpotsPerSender(
            $wishInventoryIds,
            $catalogs,
            $bucketPrices,
            $buckets,
            $lengthSeconds,
            $orderDiscountPercent,
            $orderDiscounts,
            $aeEnabled,
            $positionDiscountsByInventory,
            $target,
        );

        $nextPackage = $this->calculateAtSpots(
            $wishInventoryIds,
            $catalogs,
            $bucketPrices,
            $buckets,
            $lengthSeconds,
            $orderDiscountPercent,
            $orderDiscounts,
            $aeEnabled,
            $positionDiscountsByInventory,
            $maxSpots + 1,
        );

        $result = $this->calculateAtSpots(
            $wishInventoryIds,
            $catalogs,
            $bucketPrices,
            $buckets,
            $lengthSeconds,
            $orderDiscountPercent,
            $orderDiscounts,
            $aeEnabled,
            $positionDiscountsByInventory,
            $maxSpots,
        );

        $usedNn = $result['nn_invest'];
        $remainder = Decimal::roundMoney(Decimal::sub($target, $usedNn));
        $spotsPerSender = $maxSpots;
        $totalSpots = $spotsPerSender * count($wishInventoryIds);
        $utilizationPercent = Decimal::roundPrice(Decimal::mul(Decimal::div($usedNn, $target), '100'));

        $nextPackageCost = $nextPackage['nn_invest'];
        $nextPackageExceeds = Decimal::cmp($nextPackageCost, $target) > 0;
        $nextPackageShortfall = $nextPackageExceeds
            ? Decimal::roundMoney(Decimal::sub($nextPackageCost, $target))
            : '0.00';

        $fingerprintInput = $this->fingerprint->inputFromPayload(
            $payload,
            $wishInventoryIds,
            $lengthSeconds,
            $distributionRanges,
            $catalogs,
        );
        $inputFingerprint = $this->fingerprint->compute($fingerprintInput);

        $proposal = [
            'strategy' => BudgetStrategy::EqualSpotCount->value,
            'distribution_strategy' => BudgetSpotAllocator::DISTRIBUTION_STRATEGY,
            'algorithm_version' => BudgetSpotAllocator::ALGORITHM_VERSION,
            'status' => BudgetProposalStatus::Current->value,
            'target_budget_nn' => $target,
            'used_nn' => $usedNn,
            'nn_invest' => $usedNn,
            'remainder' => $remainder,
            'spots_per_sender' => $spotsPerSender,
            'total_spots' => $totalSpots,
            'budget_utilization_percent' => $utilizationPercent,
            'next_package_cost_nn' => $nextPackageCost,
            'next_package_exceeds_budget' => $nextPackageExceeds,
            'next_package_shortfall' => $nextPackageShortfall,
            'input_fingerprint' => $inputFingerprint,
            'wish_inventory_ids' => $wishInventoryIds,
            'spot_length_seconds' => $lengthSeconds,
            'distribution_ranges' => $distributionRanges,
            'positions' => $result['positions'],
            'media_gross' => $result['media_gross'],
            'position_discount_total' => $result['position_discount_total'],
            'order_discount_total' => $result['order_discount_total'],
            'ae_total' => $result['ae_total'],
            'ae_enabled' => $aeEnabled,
            'order_discounts' => $payload['order_discounts'] ?? [],
            'budget_position_discounts_by_inventory' => $payload['budget_position_discounts_by_inventory'] ?? [],
            'explanation' => $this->buildExplanation($spotsPerSender, count($wishInventoryIds)),
        ];

        if ($spotsPerSender < 1) {
            $firstPackage = $this->calculateAtSpots(
                $wishInventoryIds,
                $catalogs,
                $bucketPrices,
                $buckets,
                $lengthSeconds,
                $orderDiscountPercent,
                $orderDiscounts,
                $aeEnabled,
                $positionDiscountsByInventory,
                1,
            );
            $proposal['minimum_budget_nn'] = $firstPackage['nn_invest'];
            $proposal['budget_shortfall'] = Decimal::roundMoney(
                Decimal::sub($firstPackage['nn_invest'], $target),
            );
            $proposal['insufficient_budget'] = true;
        } else {
            $proposal['minimum_budget_nn'] = null;
            $proposal['budget_shortfall'] = null;
            $proposal['insufficient_budget'] = false;
        }

        return $proposal;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return list<int>
     */
    private function wishInventoryIds(array $payload): array
    {
        $raw = $payload['budget_wish_inventory_ids'] ?? [];
        if (! is_array($raw)) {
            return [];
        }

        $ids = [];
        foreach ($raw as $id) {
            $parsed = (int) $id;
            if ($parsed > 0) {
                $ids[] = $parsed;
            }
        }

        return array_values(array_unique($ids));
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return list<array{start_hour: int, end_hour_exclusive: int, day_group: string}>
     */
    private function validatedDistributionRanges(array $payload): array
    {
        $raw = $payload['budget_distribution_ranges'] ?? [];
        if (! is_array($raw) || $raw === []) {
            return [];
        }

        return (new TimeRangeValidator)->validated(
            $raw,
            'budget_distribution_ranges',
            requireAtLeastOne: true,
            requireSpotCount: false,
        );
    }

    /**
     * @param  list<array{start_hour: int, end_hour_exclusive: int, day_group: string}>  $ranges
     * @return list<BudgetBucket>
     */
    private function buildBuckets(array $ranges): array
    {
        $buckets = [];
        $dayGroupCases = DayGroup::cases();

        foreach ($ranges as $range) {
            $dayGroup = DayGroup::from($range['day_group']);
            $dayGroupOrder = 0;
            foreach ($dayGroupCases as $index => $case) {
                if ($case === $dayGroup) {
                    $dayGroupOrder = $index;
                    break;
                }
            }

            foreach (TimeRangeHours::expand($range['start_hour'], $range['end_hour_exclusive']) as $hour) {
                $buckets[] = new BudgetBucket($dayGroup, $hour, $dayGroupOrder);
            }
        }

        usort(
            $buckets,
            fn (BudgetBucket $left, BudgetBucket $right): int => $left->dayGroupOrder <=> $right->dayGroupOrder
                ?: $left->hour <=> $right->hour,
        );

        return $buckets;
    }

    private function spotClassicMediumId(): int
    {
        $medium = AdvertisingMedium::query()
            ->where('code', 'spot_classic')
            ->where('is_active', true)
            ->first();

        if ($medium === null) {
            throw ValidationException::withMessages([
                'positions' => 'Spot Classic ist nicht verfügbar.',
            ]);
        }

        return $medium->id;
    }

    /**
     * @param  array<string, mixed>  $catalog
     * @param  list<BudgetBucket>  $buckets
     * @return list<string>
     */
    private function resolveBucketPrices(array $catalog, array $buckets): array
    {
        $inventoryName = $catalog['inventory']->name;
        $priceList = $catalog['priceList'];
        /** @var array<string, list<string>> $missingByDayGroup */
        $missingByDayGroup = [];
        $prices = [];

        foreach ($buckets as $bucket) {
            $price = $this->catalog->findSecondPrice($priceList, $bucket->hour, $bucket->dayGroup);
            if ($price === null) {
                $missingByDayGroup[$bucket->dayGroup->label()][] = TimeRangeHours::formatHour($bucket->hour);
                $prices[] = '0';
            } else {
                $prices[] = $price;
            }
        }

        if ($missingByDayGroup !== []) {
            throw ValidationException::withMessages([
                'budget_distribution_ranges' => $this->formatMissingPriceMessage($inventoryName, $missingByDayGroup),
            ]);
        }

        return $prices;
    }

    /**
     * @param  array<string, list<string>>  $missingByDayGroup
     */
    private function formatMissingPriceMessage(string $inventoryName, array $missingByDayGroup): string
    {
        $parts = [];

        foreach ($missingByDayGroup as $dayGroupLabel => $hours) {
            $uniqueHours = array_values(array_unique($hours));
            sort($uniqueHours);
            $parts[] = $dayGroupLabel.' in den Stunden '.implode(', ', $uniqueHours);
        }

        return 'Für '.$inventoryName.' fehlen Preise für '.implode(' und ', $parts).'.';
    }

    /**
     * @param  list<int>  $wishInventoryIds
     * @param  array<int, array<string, mixed>>  $catalogs
     * @param  array<int, list<string>>  $bucketPrices
     * @param  list<BudgetBucket>  $buckets
     * @param  list<DiscountInput>  $orderDiscounts
     * @param  array<int, list<DiscountInput>>  $positionDiscountsByInventory
     */
    private function findMaxSpotsPerSender(
        array $wishInventoryIds,
        array $catalogs,
        array $bucketPrices,
        array $buckets,
        int $lengthSeconds,
        string $orderDiscountPercent,
        array $orderDiscounts,
        bool $aeEnabled,
        array $positionDiscountsByInventory,
        string $targetBudget,
    ): int {
        $low = 0;
        $high = 1;

        while ($this->fitsBudget(
            $wishInventoryIds,
            $catalogs,
            $bucketPrices,
            $buckets,
            $lengthSeconds,
            $orderDiscountPercent,
            $orderDiscounts,
            $aeEnabled,
            $positionDiscountsByInventory,
            $high,
            $targetBudget,
        )) {
            if ($high >= 100000) {
                break;
            }
            $high *= 2;
        }

        while ($low < $high) {
            $mid = $low + intdiv($high - $low + 1, 2);
            if ($this->fitsBudget(
                $wishInventoryIds,
                $catalogs,
                $bucketPrices,
                $buckets,
                $lengthSeconds,
                $orderDiscountPercent,
                $orderDiscounts,
                $aeEnabled,
                $positionDiscountsByInventory,
                $mid,
                $targetBudget,
            )) {
                $low = $mid;
            } else {
                $high = $mid - 1;
            }
        }

        return $low;
    }

    /**
     * @param  list<int>  $wishInventoryIds
     * @param  array<int, array<string, mixed>>  $catalogs
     * @param  array<int, list<string>>  $bucketPrices
     * @param  list<BudgetBucket>  $buckets
     * @param  list<DiscountInput>  $orderDiscounts
     * @param  array<int, list<DiscountInput>>  $positionDiscountsByInventory
     */
    private function fitsBudget(
        array $wishInventoryIds,
        array $catalogs,
        array $bucketPrices,
        array $buckets,
        int $lengthSeconds,
        string $orderDiscountPercent,
        array $orderDiscounts,
        bool $aeEnabled,
        array $positionDiscountsByInventory,
        int $spotsPerSender,
        string $targetBudget,
    ): bool {
        if ($spotsPerSender < 0) {
            return false;
        }

        $result = $this->calculateAtSpots(
            $wishInventoryIds,
            $catalogs,
            $bucketPrices,
            $buckets,
            $lengthSeconds,
            $orderDiscountPercent,
            $orderDiscounts,
            $aeEnabled,
            $positionDiscountsByInventory,
            $spotsPerSender,
        );

        return Decimal::cmp($result['nn_invest'], $targetBudget) <= 0;
    }

    /**
     * @param  list<int>  $wishInventoryIds
     * @param  array<int, array<string, mixed>>  $catalogs
     * @param  array<int, list<string>>  $bucketPrices
     * @param  list<BudgetBucket>  $buckets
     * @param  list<DiscountInput>  $orderDiscounts
     * @param  array<int, list<DiscountInput>>  $positionDiscountsByInventory
     * @return array{
     *     nn_invest: string,
     *     media_gross: string,
     *     position_discount_total: string,
     *     order_discount_total: string,
     *     ae_total: string,
     *     positions: list<array<string, mixed>>
     * }
     */
    private function calculateAtSpots(
        array $wishInventoryIds,
        array $catalogs,
        array $bucketPrices,
        array $buckets,
        int $lengthSeconds,
        string $orderDiscountPercent,
        array $orderDiscounts,
        bool $aeEnabled,
        array $positionDiscountsByInventory,
        int $spotsPerSender,
    ): array {
        $inputs = [];
        $distribution = $this->allocator->distribute($spotsPerSender, count($buckets));

        foreach ($wishInventoryIds as $inventoryId) {
            $catalog = $catalogs[$inventoryId];
            $prices = $bucketPrices[$inventoryId];
            $positionDiscounts = $positionDiscountsByInventory[$inventoryId] ?? [];

            $inputs[] = $this->buildPositionInput(
                $catalog,
                $buckets,
                $prices,
                $distribution,
                $lengthSeconds,
                $positionDiscounts,
                $aeEnabled,
            );
        }

        $totals = $this->engine->calculate(
            $inputs,
            $orderDiscountPercent,
            null,
            null,
            $orderDiscounts,
            $aeEnabled,
        );

        $positions = [];
        foreach ($totals->positions as $index => $positionResult) {
            $inventoryId = $wishInventoryIds[$index];
            $catalog = $catalogs[$inventoryId];
            $inventory = $catalog['inventory'];

            $bucketsPayload = [];
            foreach ($positionResult->timeRanges as $range) {
                if ($range['spot_count'] < 1) {
                    continue;
                }

                $hour = $range['start_hour'];
                $bucketsPayload[] = [
                    'day_group' => $range['day_group'],
                    'hour' => $hour,
                    'hour_label' => TimeRangeHours::formatHour($hour).'–'.TimeRangeHours::formatInclusiveEnd($range['end_hour_exclusive']),
                    'spot_count' => $range['spot_count'],
                    'second_price' => $range['average_second_price'],
                    'range_gross' => $range['range_gross'],
                ];
            }

            $positions[] = [
                'position_key' => 'inventory:'.$inventoryId,
                'inventory_id' => $inventoryId,
                'inventory_name' => $inventory->name,
                'advertising_medium_id' => $catalog['medium']->id,
                'length_seconds' => $lengthSeconds,
                'total_spot_count' => $positionResult->spotCount,
                'media_gross' => $positionResult->mediaGross,
                'position_discount_amount' => $positionResult->positionDiscountAmount,
                'after_position_discount' => $positionResult->afterPositionDiscount,
                'order_discount_amount' => $positionResult->orderDiscountAmount,
                'after_order_discount' => $positionResult->afterOrderDiscount,
                'ae_amount' => $positionResult->aeAmount,
                'nn_invest' => $positionResult->nnInvest,
                'buckets' => $bucketsPayload,
                'time_ranges' => array_values(array_filter(
                    $positionResult->timeRanges,
                    fn (array $range): bool => $range['spot_count'] > 0,
                )),
                'position_discounts' => $positionResult->positionDiscounts,
            ];
        }

        return [
            'nn_invest' => $totals->nnInvest,
            'media_gross' => $totals->mediaGross,
            'position_discount_total' => $totals->positionDiscountTotal,
            'order_discount_total' => $totals->orderDiscountTotal,
            'ae_total' => $totals->aeTotal,
            'positions' => $positions,
        ];
    }

    /**
     * @param  array<string, mixed>  $catalog
     * @param  list<BudgetBucket>  $buckets
     * @param  list<string>  $prices
     * @param  list<int>  $distribution
     * @param  list<DiscountInput>  $positionDiscounts
     */
    private function buildPositionInput(
        array $catalog,
        array $buckets,
        array $prices,
        array $distribution,
        int $lengthSeconds,
        array $positionDiscounts,
        bool $aeEnabled,
    ): PositionInput {
        $timeRanges = [];
        $rows = [];
        $sort = 0;

        foreach ($buckets as $index => $bucket) {
            $spotCount = $distribution[$index] ?? 0;
            $price = $prices[$index];
            $row = new PlanRowInput($bucket->hour, $bucket->dayGroup, 0, $price);
            $rows[] = $row;

            if ($spotCount < 1) {
                continue;
            }

            $timeRanges[] = new TimeRangeInput(
                startHour: $bucket->hour,
                endHourExclusive: $bucket->hour + 1,
                dayGroup: $bucket->dayGroup,
                spotCount: $spotCount,
                hours: [$row],
                sort: $sort++,
            );
        }

        $isAeEligible = (bool) $catalog['is_ae_eligible'];
        $aePercent = $isAeEligible && $aeEnabled ? '15' : '0';

        return new PositionInput(
            inventoryId: $catalog['inventory']->id,
            inventoryName: $catalog['inventory']->name,
            positionKey: 'inventory:'.$catalog['inventory']->id,
            lengthSeconds: $lengthSeconds,
            surchargePercent: (string) $catalog['surcharge_percent'],
            positionDiscountPercent: $this->effectivePercentFromDiscounts($positionDiscounts),
            aePercent: $aePercent,
            isDiscountable: (bool) $catalog['is_discountable'],
            isAeEligible: $isAeEligible,
            totalSpotCount: array_sum($distribution),
            spotMethod: SpotCalculationMethod::Average,
            rows: $rows,
            lengthIndex: SpotLengthIndex::forSeconds($lengthSeconds),
            timeRanges: $timeRanges,
            positionDiscounts: (bool) $catalog['is_discountable'] ? $positionDiscounts : [],
            needsSpotRedistribution: false,
        );
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<int, list<DiscountInput>>
     */
    private function positionDiscountsByInventory(array $payload, ?Calculation $existing): array
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

            $map[$inventoryId] = $this->discountInputsFromRows($row['discounts'] ?? []);
        }

        foreach ($payload['positions'] ?? [] as $position) {
            $inventoryId = (int) ($position['inventory_id'] ?? 0);
            if ($inventoryId < 1 || isset($map[$inventoryId])) {
                continue;
            }

            $map[$inventoryId] = $this->positionDiscountInputs($position);
        }

        if ($existing !== null) {
            foreach ($existing->positions as $position) {
                if (! isset($map[$position->inventory_id])) {
                    $map[$position->inventory_id] = $this->positionDiscountInputsFromModel($position);
                }
            }
        }

        return $map;
    }

    /**
     * @param  array<string, mixed>  $position
     * @return list<DiscountInput>
     */
    private function positionDiscountInputs(array $position): array
    {
        $raw = $position['position_discounts'] ?? [];
        if (! is_array($raw) || $raw === []) {
            $legacy = (string) ($position['position_discount_percent'] ?? '0');
            if (Decimal::cmp($legacy, '0') <= 0) {
                return [];
            }

            return [new DiscountInput(DiscountType::Quantity, $legacy)];
        }

        return $this->discountInputsFromRows($raw);
    }

    /**
     * @return list<DiscountInput>
     */
    private function positionDiscountInputsFromModel(CalculationPosition $position): array
    {
        $position->loadMissing('discounts');
        $rows = $position->discounts->map(
            fn ($discount): array => [
                'type' => $discount->type->value,
                'custom_label' => $discount->custom_label,
                'percent' => (string) $discount->percent,
            ],
        )->all();

        return $this->discountInputsFromRows($rows);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return list<DiscountInput>
     */
    private function orderDiscountInputs(array $payload): array
    {
        $raw = $payload['order_discounts'] ?? [];
        if (! is_array($raw) || $raw === []) {
            $legacy = (string) ($payload['order_discount_percent'] ?? '0');
            if (Decimal::cmp($legacy, '0') <= 0) {
                return [];
            }

            return [new DiscountInput(DiscountType::Quantity, $legacy)];
        }

        return $this->discountInputsFromRows($raw);
    }

    /**
     * @param  array<int|string, mixed>  $rows
     * @return list<DiscountInput>
     */
    private function discountInputsFromRows(array $rows): array
    {
        $validated = (new DiscountValidator)->validated($rows, 'discounts');
        $inputs = [];

        foreach ($validated as $row) {
            $inputs[] = new DiscountInput(
                type: DiscountType::from($row['type']),
                percent: $row['percent'],
                customLabel: $row['custom_label'],
                sort: $row['sort'],
            );
        }

        return $inputs;
    }

    /**
     * @param  list<DiscountInput>  $discounts
     */
    private function effectivePercentFromDiscounts(array $discounts): string
    {
        if ($discounts === []) {
            return '0';
        }

        $factor = '1';
        foreach ($discounts as $discount) {
            $factor = Decimal::mul($factor, Decimal::oneMinusPercent($discount->percent));
        }

        return Decimal::roundPrice(Decimal::mul(Decimal::sub('1', $factor), '100'));
    }

    private function buildExplanation(int $spotsPerSender, int $senderCount): string
    {
        return sprintf(
            'Gleiche Spotanzahl je Wunschsender (%d Spots × %d Sender). '
            .'Gleichmäßige Stundenverteilung über alle erlaubten Buckets. '
            .'Der Budgetvorschlag ist preis- und verteilungsbasiert, nicht reichweitenoptimiert.',
            $spotsPerSender,
            $senderCount,
        );
    }
}
