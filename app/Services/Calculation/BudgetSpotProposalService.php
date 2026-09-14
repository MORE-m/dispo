<?php

namespace App\Services\Calculation;

use App\Enums\BudgetProposalStatus;
use App\Enums\BudgetStrategy;
use App\Enums\DayGroup;
use App\Enums\DiscountType;
use App\Enums\SpotCalculationMethod;
use App\Models\AdvertisingMedium;
use App\Models\Calculation;
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
        private readonly BudgetPlanningPayloadNormalizer $elementNormalizer,
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

        $elements = $this->elementNormalizer->normalizeElements($payload);

        $mediumId = $this->spotClassicMediumId();
        $orderDiscounts = $this->orderDiscountInputs($payload);
        $orderDiscountPercent = $this->effectivePercentFromDiscounts($orderDiscounts);
        $aeEnabled = (bool) ($payload['ae_enabled'] ?? false);

        /** @var list<array{
         *     client_id: string,
         *     inventory_id: int,
         *     length_seconds: int,
         *     distribution_ranges: list<array{start_hour: int, end_hour_exclusive: int, day_group: string}>,
         *     position_discounts: list<DiscountInput>,
         *     catalog: array<string, mixed>,
         *     buckets: list<BudgetBucket>,
         *     bucket_prices: list<string>
         * }> $elementConfigs */
        $elementConfigs = [];

        foreach ($elements as $element) {
            $catalog = $this->catalog->resolveInventoryForBudget($element['inventory_id'], $mediumId);
            $buckets = $this->buildBuckets($element['distribution_ranges']);
            if ($buckets === []) {
                throw ValidationException::withMessages([
                    'budget_elements' => 'Mindestens ein erlaubter Verteilungszeitraum ist erforderlich.',
                ]);
            }

            $elementConfigs[] = [
                'client_id' => $element['client_id'],
                'inventory_id' => $element['inventory_id'],
                'length_seconds' => $element['spot_length_seconds'],
                'distribution_ranges' => $element['distribution_ranges'],
                'position_discounts' => $this->discountInputsFromRows($element['position_discounts']),
                'catalog' => $catalog,
                'buckets' => $buckets,
                'bucket_prices' => $this->resolveBucketPrices($catalog, $buckets),
            ];
        }

        $wishInventoryIds = array_column($elementConfigs, 'inventory_id');
        $catalogs = [];
        foreach ($elementConfigs as $config) {
            $catalogs[$config['inventory_id']] = $config['catalog'];
        }

        $maxSpots = $this->findMaxSpotsPerElement(
            $elementConfigs,
            $orderDiscountPercent,
            $orderDiscounts,
            $aeEnabled,
            $target,
        );

        $nextPackage = $this->calculateAtSpots(
            $elementConfigs,
            $orderDiscountPercent,
            $orderDiscounts,
            $aeEnabled,
            $maxSpots + 1,
        );

        $result = $this->calculateAtSpots(
            $elementConfigs,
            $orderDiscountPercent,
            $orderDiscounts,
            $aeEnabled,
            $maxSpots,
        );

        $usedNn = $result['nn_invest'];
        $remainder = Decimal::roundMoney(Decimal::sub($target, $usedNn));
        $spotsPerSender = $maxSpots;
        $totalSpots = $spotsPerSender * count($elementConfigs);
        $utilizationPercent = Decimal::roundPrice(Decimal::mul(Decimal::div($usedNn, $target), '100'));

        $nextPackageCost = $nextPackage['nn_invest'];
        $nextPackageExceeds = Decimal::cmp($nextPackageCost, $target) > 0;
        $nextPackageShortfall = $nextPackageExceeds
            ? Decimal::roundMoney(Decimal::sub($nextPackageCost, $target))
            : '0.00';

        $inputFingerprint = $this->fingerprint->compute(
            $this->fingerprint->inputFromElements($payload, $elements, $catalogs),
        );

        $budgetElementsPayload = array_map(
            fn (array $element): array => [
                'client_id' => $element['client_id'],
                'inventory_id' => $element['inventory_id'],
                'spot_length_seconds' => $element['spot_length_seconds'],
                'distribution_ranges' => $element['distribution_ranges'],
                'position_discounts' => $element['position_discounts'],
            ],
            $elements,
        );

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
            'budget_elements' => $budgetElementsPayload,
            'wish_inventory_ids' => $wishInventoryIds,
            'spot_length_seconds' => $elementConfigs[0]['length_seconds'] ?? 0,
            'distribution_ranges' => $elementConfigs[0]['distribution_ranges'] ?? [],
            'positions' => $result['positions'],
            'media_gross' => $result['media_gross'],
            'position_discount_total' => $result['position_discount_total'],
            'order_discount_total' => $result['order_discount_total'],
            'ae_total' => $result['ae_total'],
            'ae_enabled' => $aeEnabled,
            'order_discounts' => $payload['order_discounts'] ?? [],
            'explanation' => $this->buildExplanation($spotsPerSender, count($elementConfigs)),
        ];

        if ($spotsPerSender < 1) {
            $firstPackage = $this->calculateAtSpots(
                $elementConfigs,
                $orderDiscountPercent,
                $orderDiscounts,
                $aeEnabled,
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
                'budget_wish_inventory_ids' => 'Spot Classic ist nicht verfügbar.',
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

                continue;
            }

            $prices[] = $price;
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
     * @param  list<array{
     *     client_id: string,
     *     inventory_id: int,
     *     length_seconds: int,
     *     distribution_ranges: list<array{start_hour: int, end_hour_exclusive: int, day_group: string}>,
     *     position_discounts: list<DiscountInput>,
     *     catalog: array<string, mixed>,
     *     buckets: list<BudgetBucket>,
     *     bucket_prices: list<string>
     * }> $elementConfigs
     * @param  list<DiscountInput>  $orderDiscounts
     */
    private function findMaxSpotsPerElement(
        array $elementConfigs,
        string $orderDiscountPercent,
        array $orderDiscounts,
        bool $aeEnabled,
        string $targetBudget,
    ): int {
        $low = 0;
        $high = 1;

        while ($this->fitsBudget(
            $elementConfigs,
            $orderDiscountPercent,
            $orderDiscounts,
            $aeEnabled,
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
                $elementConfigs,
                $orderDiscountPercent,
                $orderDiscounts,
                $aeEnabled,
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
     * @param  list<array{
     *     client_id: string,
     *     inventory_id: int,
     *     length_seconds: int,
     *     distribution_ranges: list<array{start_hour: int, end_hour_exclusive: int, day_group: string}>,
     *     position_discounts: list<DiscountInput>,
     *     catalog: array<string, mixed>,
     *     buckets: list<BudgetBucket>,
     *     bucket_prices: list<string>
     * }> $elementConfigs
     * @param  list<DiscountInput>  $orderDiscounts
     */
    private function fitsBudget(
        array $elementConfigs,
        string $orderDiscountPercent,
        array $orderDiscounts,
        bool $aeEnabled,
        int $spotsPerElement,
        string $targetBudget,
    ): bool {
        if ($spotsPerElement < 0) {
            return false;
        }

        $result = $this->calculateAtSpots(
            $elementConfigs,
            $orderDiscountPercent,
            $orderDiscounts,
            $aeEnabled,
            $spotsPerElement,
        );

        return Decimal::cmp($result['nn_invest'], $targetBudget) <= 0;
    }

    /**
     * @param  list<array{
     *     client_id: string,
     *     inventory_id: int,
     *     length_seconds: int,
     *     distribution_ranges: list<array{start_hour: int, end_hour_exclusive: int, day_group: string}>,
     *     position_discounts: list<DiscountInput>,
     *     catalog: array<string, mixed>,
     *     buckets: list<BudgetBucket>,
     *     bucket_prices: list<string>
     * }> $elementConfigs
     * @param  list<DiscountInput>  $orderDiscounts
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
        array $elementConfigs,
        string $orderDiscountPercent,
        array $orderDiscounts,
        bool $aeEnabled,
        int $spotsPerElement,
    ): array {
        $inputs = [];

        foreach ($elementConfigs as $config) {
            $distribution = $this->allocator->distribute(
                $spotsPerElement,
                count($config['buckets']),
            );

            $inputs[] = $this->buildPositionInput(
                $config['catalog'],
                $config['buckets'],
                $config['bucket_prices'],
                $distribution,
                $config['length_seconds'],
                $config['position_discounts'],
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
            $config = $elementConfigs[$index];
            $catalog = $config['catalog'];
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
                'position_key' => 'inventory:'.$config['inventory_id'],
                'client_id' => $config['client_id'],
                'inventory_id' => $config['inventory_id'],
                'inventory_name' => $inventory->name,
                'advertising_medium_id' => $catalog['medium']->id,
                'length_seconds' => $config['length_seconds'],
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
