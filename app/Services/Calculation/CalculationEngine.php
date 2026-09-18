<?php

namespace App\Services\Calculation;

use App\Enums\ComponentCalculationStrategy;
use App\Enums\DiscountType;
use App\Enums\PricingSettlementMode;
use App\Enums\SpotCalculationMethod;

/**
 * GEN-002, SPT-001–SPT-004, SPT-009, SPT-014 (Hauptspot/Allonge), SPT-015, SPT-016,
 * CAL-005, COM-001, COM-002, COM-007, COM-008.
 * BL-P4-02c / AT-04: Spot-Komponenten (Hauptspot + Allonge).
 * BL-P4-02d: Preisabschluss normal|fixed_price (Festpreis-N/N unabhängig von spot_method).
 * SPT-012 und Tandem/Tridem/Abbinder/Reminder sind nicht vollständig umgesetzt.
 */
final class CalculationEngine
{
    public function __construct(
        private readonly SpecialApprovalAssessor $assessor = new SpecialApprovalAssessor,
    ) {}

    /**
     * @param  list<PositionInput>  $positions
     * @param  list<DiscountInput>  $orderDiscounts
     */
    public function calculate(
        array $positions,
        string $orderDiscountPercent,
        ?string $targetBudgetNn,
        ?string $personalDiscountLimitPercent,
        array $orderDiscounts = [],
        bool $aeEnabled = false,
    ): CalculationTotals {
        $resolvedOrderDiscounts = $this->resolveDiscountList($orderDiscounts, $orderDiscountPercent);
        $results = [];

        foreach ($positions as $position) {
            $results[] = $this->calculatePosition($position, $orderDiscountPercent, $resolvedOrderDiscounts);
        }

        $mediaGross = '0.00';
        $positionDiscountTotal = '0.00';
        $orderDiscountTotal = '0.00';
        $aeTotal = '0.00';
        $nnInvest = '0.00';
        $afterPositionTotal = '0.00';
        $afterOrderTotal = '0.00';
        $aeEligibleBase = '0';
        $specialApprovalReasons = [];

        $orderStackedPercent = $this->assessor->stackedPercent(
            array_map(fn (DiscountInput $discount): string => $discount->percent, $resolvedOrderDiscounts),
        );

        foreach ($results as $index => $result) {
            $mediaGross = Decimal::roundMoney(Decimal::add($mediaGross, $result->mediaGross));
            $positionDiscountTotal = Decimal::roundMoney(Decimal::add($positionDiscountTotal, $result->positionDiscountAmount));
            $orderDiscountTotal = Decimal::roundMoney(Decimal::add($orderDiscountTotal, $result->orderDiscountAmount));
            $aeTotal = Decimal::roundMoney(Decimal::add($aeTotal, $result->aeAmount));
            $nnInvest = Decimal::roundMoney(Decimal::add($nnInvest, $result->nnInvest));
            $afterPositionTotal = Decimal::roundMoney(Decimal::add($afterPositionTotal, $result->afterPositionDiscount));
            $afterOrderTotal = Decimal::roundMoney(Decimal::add($afterOrderTotal, $result->afterOrderDiscount));

            $position = $positions[$index];
            $positionStackedPercent = $this->stackedPositionPercent($position);
            $appliedOrderPercent = $position->isDiscountable ? $orderStackedPercent : '0';

            $specialApprovalReasons = array_merge(
                $specialApprovalReasons,
                $this->assessor->reasonsForRates(
                    $positionStackedPercent,
                    $appliedOrderPercent,
                    $result->effectiveDiscountPercent,
                    $personalDiscountLimitPercent,
                    null,
                    $position->positionKey,
                    $position->inventoryName,
                ),
            );
        }

        foreach ($positions as $index => $position) {
            if ($position->isAeEligible) {
                $aeEligibleBase = Decimal::add($aeEligibleBase, $results[$index]->afterOrderDiscount);
            }
        }

        $orderDiscountBreakdown = $this->applyDiscountSequence($afterPositionTotal, $resolvedOrderDiscounts);
        $assessment = SpecialApprovalAssessment::fromReasons($specialApprovalReasons);

        $budgetDelta = null;
        $roundedTarget = $targetBudgetNn === null || $targetBudgetNn === ''
            ? null
            : Decimal::roundMoney($targetBudgetNn);

        if ($roundedTarget !== null) {
            $budgetDelta = Decimal::roundMoney(Decimal::sub($roundedTarget, $nnInvest));
        }

        return new CalculationTotals(
            mediaGross: $mediaGross,
            positionDiscountTotal: $positionDiscountTotal,
            orderDiscountTotal: $orderDiscountTotal,
            aeTotal: $aeTotal,
            nnInvest: $nnInvest,
            targetBudgetNn: $roundedTarget,
            budgetDelta: $budgetDelta,
            requiresSpecialApproval: $assessment->requiresSpecialApproval,
            positions: $results,
            aeEnabled: $aeEnabled,
            orderDiscounts: $orderDiscountBreakdown,
            afterPositionDiscountTotal: $afterPositionTotal,
            afterOrderDiscountTotal: $afterOrderTotal,
            aeEligibleBase: Decimal::roundMoney($aeEligibleBase),
            specialApprovalReasons: $assessment->reasons,
        );
    }

    /**
     * @param  list<DiscountInput>  $orderDiscounts
     */
    public function calculatePosition(
        PositionInput $position,
        string $orderDiscountPercent,
        array $orderDiscounts = [],
    ): PositionResult {
        return match ($position->spotMethod) {
            SpotCalculationMethod::Average => $this->calculateAveragePosition(
                $position,
                $orderDiscountPercent,
                $this->resolveDiscountList($orderDiscounts, $orderDiscountPercent),
            ),
            SpotCalculationMethod::Calendar => $this->calculateCalendarPosition(
                $position,
                $orderDiscountPercent,
                $this->resolveDiscountList($orderDiscounts, $orderDiscountPercent),
            ),
            SpotCalculationMethod::FixedPrice => throw new \InvalidArgumentException(
                'Kalkulationsart '.$position->spotMethod->value.' ist in UX-GATE-B noch nicht implementiert.',
            ),
        };
    }

    /**
     * @param  list<DiscountInput>  $orderDiscounts
     */
    private function calculateCalendarPosition(
        PositionInput $position,
        string $orderDiscountPercent,
        array $orderDiscounts,
    ): PositionResult {
        $resolved = $this->resolveComponentPlan($position);
        $index = $resolved['position_index'];
        $mediaGrossInternal = '0';
        $totalSpots = 0;
        $plannerResults = [];
        $priceSum = '0';
        $entryCount = 0;
        $componentGrosses = [];
        foreach ($resolved['components'] as $_) {
            $componentGrosses[] = '0';
        }

        foreach ($position->plannerEntries as $entry) {
            if ($entry->spotCount < 1) {
                continue;
            }

            $lineGrossInternal = '0';
            foreach ($resolved['components'] as $componentOffset => $component) {
                $part = $this->lengthSpotPrice(
                    $entry->secondPrice,
                    $component['length_seconds'],
                    $component['length_index'],
                    $position->surchargePercent,
                    $entry->spotCount,
                );
                $lineGrossInternal = Decimal::add($lineGrossInternal, $part);
                $componentGrosses[$componentOffset] = Decimal::add($componentGrosses[$componentOffset], $part);
            }

            $mediaGrossInternal = Decimal::add($mediaGrossInternal, $lineGrossInternal);
            $totalSpots += $entry->spotCount;
            $priceSum = Decimal::add($priceSum, $entry->secondPrice);
            $entryCount++;

            $plannerResults[] = [
                'date' => $entry->date,
                'hour' => $entry->hour,
                'day_group' => $entry->dayGroup->value,
                'spot_count' => $entry->spotCount,
                'second_price' => Decimal::roundPrice($entry->secondPrice),
                'line_gross' => Decimal::roundMoney($lineGrossInternal),
            ];
        }

        if ($plannerResults === []) {
            return $this->emptyPositionResult($position, $orderDiscountPercent, $orderDiscounts);
        }

        $displayAverage = $this->spotWeightedAverageSecondPrice(
            array_map(
                fn (array $entry): array => [
                    'spot_count' => (int) $entry['spot_count'],
                    'second_price' => (string) $entry['second_price'],
                ],
                $plannerResults,
            ),
        );
        if ($displayAverage === null && $entryCount > 0) {
            $displayAverage = Decimal::roundPrice(Decimal::div($priceSum, (string) $entryCount, 4));
        }

        return $this->finalizePosition(
            $position,
            $orderDiscountPercent,
            $orderDiscounts,
            $mediaGrossInternal,
            $totalSpots,
            $index,
            [],
            $displayAverage ?? '0',
            [],
            $plannerResults,
            $this->componentResults($resolved, array_values($componentGrosses)),
        );
    }

    /**
     * @param  list<DiscountInput>  $orderDiscounts
     */
    private function calculateAveragePosition(
        PositionInput $position,
        string $orderDiscountPercent,
        array $orderDiscounts,
    ): PositionResult {
        if ($position->timeRanges !== []) {
            return $this->calculateTimeRangePosition($position, $orderDiscountPercent, $orderDiscounts);
        }

        $uniqueRows = $this->uniqueHourRows($position->rows);

        if ($uniqueRows === []) {
            return $this->emptyPositionResult($position, $orderDiscountPercent, $orderDiscounts);
        }

        $averageSecondPrice = $this->averageSecondPrice($uniqueRows);
        $resolved = $this->resolveComponentPlan($position);
        $index = $resolved['position_index'];
        $spotCount = max(0, $position->totalSpotCount);
        $mediaGrossInternal = '0';
        $componentGrosses = [];

        foreach ($resolved['components'] as $component) {
            $part = $this->lengthSpotPrice(
                $averageSecondPrice,
                $component['length_seconds'],
                $component['length_index'],
                $position->surchargePercent,
                $spotCount,
            );
            $mediaGrossInternal = Decimal::add($mediaGrossInternal, $part);
            $componentGrosses[] = $part;
        }

        $rowResults = [];
        foreach ($uniqueRows as $row) {
            $rowResults[] = [
                'hour' => $row->hour,
                'day_group' => $row->dayGroup->value,
                'spot_count' => 0,
                'second_price' => Decimal::roundPrice($row->secondPrice),
                'line_gross' => '0.00',
            ];
        }

        return $this->finalizePosition(
            $position,
            $orderDiscountPercent,
            $orderDiscounts,
            $mediaGrossInternal,
            $spotCount,
            $index,
            $rowResults,
            $averageSecondPrice,
            [],
            [],
            $this->componentResults($resolved, $componentGrosses),
        );
    }

    /**
     * Jeder Zeitraum wird separat gerechnet und anschließend addiert.
     *
     * @param  list<DiscountInput>  $orderDiscounts
     */
    private function calculateTimeRangePosition(
        PositionInput $position,
        string $orderDiscountPercent,
        array $orderDiscounts,
    ): PositionResult {
        $resolved = $this->resolveComponentPlan($position);
        $index = $resolved['position_index'];
        $mediaGrossInternal = '0';
        $totalSpots = 0;
        $rangeResults = [];
        $rowResults = [];
        $priceSum = '0';
        $hourCount = 0;
        $componentGrosses = [];
        foreach ($resolved['components'] as $_) {
            $componentGrosses[] = '0';
        }

        foreach ($position->timeRanges as $range) {
            if ($range->hours === [] || $range->spotCount < 1) {
                continue;
            }

            $averageSecondPrice = $this->averageSecondPrice($range->hours);
            $rangeGross = '0';
            foreach ($resolved['components'] as $componentOffset => $component) {
                $part = $this->lengthSpotPrice(
                    $averageSecondPrice,
                    $component['length_seconds'],
                    $component['length_index'],
                    $position->surchargePercent,
                    $range->spotCount,
                );
                $rangeGross = Decimal::add($rangeGross, $part);
                $componentGrosses[$componentOffset] = Decimal::add($componentGrosses[$componentOffset], $part);
            }
            $mediaGrossInternal = Decimal::add($mediaGrossInternal, $rangeGross);
            $totalSpots += $range->spotCount;

            $hours = [];
            foreach ($range->hours as $row) {
                $hours[] = $row->hour;
                $priceSum = Decimal::add($priceSum, $row->secondPrice);
                $hourCount++;
                $rowResults[] = [
                    'hour' => $row->hour,
                    'day_group' => $row->dayGroup->value,
                    'spot_count' => 0,
                    'second_price' => Decimal::roundPrice($row->secondPrice),
                    'line_gross' => '0.00',
                ];
            }

            $rangeResults[] = [
                'start_hour' => $range->startHour,
                'end_hour_exclusive' => $range->endHourExclusive,
                'day_group' => $range->dayGroup->value,
                'spot_count' => $range->spotCount,
                'average_second_price' => Decimal::roundPrice($averageSecondPrice),
                'range_gross' => Decimal::roundMoney($rangeGross),
                'hours' => $hours,
            ];
        }

        if ($rangeResults === []) {
            return $this->emptyPositionResult($position, $orderDiscountPercent, $orderDiscounts);
        }

        $displayAverage = $this->spotWeightedAverageSecondPrice(
            array_map(
                fn (array $range): array => [
                    'spot_count' => (int) $range['spot_count'],
                    'second_price' => (string) $range['average_second_price'],
                ],
                $rangeResults,
            ),
        );
        if ($displayAverage === null && $hourCount > 0) {
            $displayAverage = Decimal::roundPrice(Decimal::div($priceSum, (string) $hourCount, 4));
        }

        return $this->finalizePosition(
            $position,
            $orderDiscountPercent,
            $orderDiscounts,
            $mediaGrossInternal,
            $totalSpots,
            $index,
            $rowResults,
            $displayAverage ?? '0',
            $rangeResults,
            [],
            $this->componentResults($resolved, array_values($componentGrosses)),
        );
    }

    private function lengthSpotPrice(
        string $secondPrice,
        int $lengthSeconds,
        int $index,
        string $surchargePercent,
        int $spotCount,
    ): string {
        $lengthFactor = Decimal::div((string) $index, '100');
        $surchargeFactor = Decimal::add('1', Decimal::percentFactor($surchargePercent));

        return Decimal::mulMany([
            $secondPrice,
            (string) $lengthSeconds,
            $lengthFactor,
            $surchargeFactor,
            (string) max(0, $spotCount),
        ]);
    }

    /**
     * Spotgewichteter durchschnittlicher Sekundenpreis aus den bepreisten Buckets.
     * Unabhängig von Komponentenlängen/-indizes und Aufschlag (gemeinsamer Vertrag für
     * Legacy, shared_total_length und individual).
     *
     * @param  list<array{spot_count: int, second_price: string}>  $buckets
     */
    private function spotWeightedAverageSecondPrice(array $buckets): ?string
    {
        $weighted = '0';
        $totalSpots = 0;

        foreach ($buckets as $bucket) {
            $spots = max(0, (int) $bucket['spot_count']);
            if ($spots < 1) {
                continue;
            }
            $weighted = Decimal::add(
                $weighted,
                Decimal::mul((string) $bucket['second_price'], (string) $spots),
            );
            $totalSpots += $spots;
        }

        if ($totalSpots < 1) {
            return null;
        }

        return Decimal::roundPrice(Decimal::div($weighted, (string) $totalSpots, 4));
    }

    /**
     * @return array{
     *     strategy: ComponentCalculationStrategy|null,
     *     position_index: int,
     *     components: list<array{role: string, label: string, length_seconds: int, sort: int, length_index: int}>
     * }
     */
    private function resolveComponentPlan(PositionInput $position): array
    {
        if (! $position->hasComponents()) {
            $index = $position->lengthIndex ?? SpotLengthIndex::forSeconds($position->lengthSeconds);

            return [
                'strategy' => null,
                'position_index' => $index,
                'components' => [[
                    'role' => 'legacy',
                    'label' => 'Spot',
                    'length_seconds' => $position->lengthSeconds,
                    'sort' => 0,
                    'length_index' => $index,
                ]],
            ];
        }

        $strategy = $position->componentCalculationStrategy
            ?? ComponentCalculationStrategy::SharedTotalLength;

        if ($strategy === ComponentCalculationStrategy::SharedTotalLength) {
            $totalLength = array_sum(array_map(
                fn (ComponentInput $component): int => $component->lengthSeconds,
                $position->components,
            ));
            $index = $position->lengthIndex ?? SpotLengthIndex::forSeconds($totalLength);
            $components = [];
            foreach ($position->components as $component) {
                $components[] = [
                    'role' => $component->role->value,
                    'label' => $component->label,
                    'length_seconds' => $component->lengthSeconds,
                    'sort' => $component->sort,
                    'length_index' => $index,
                ];
            }

            return [
                'strategy' => $strategy,
                'position_index' => $index,
                'components' => [[
                    'role' => 'shared',
                    'label' => 'Gesamtlänge',
                    'length_seconds' => $totalLength,
                    'sort' => 0,
                    'length_index' => $index,
                    '_display' => $components,
                ]],
            ];
        }

        $components = [];
        foreach ($position->components as $component) {
            $componentIndex = $component->lengthIndex ?? SpotLengthIndex::forSeconds($component->lengthSeconds);
            $components[] = [
                'role' => $component->role->value,
                'label' => $component->label,
                'length_seconds' => $component->lengthSeconds,
                'sort' => $component->sort,
                'length_index' => $componentIndex,
            ];
        }

        $totalLength = array_sum(array_column($components, 'length_seconds'));
        $positionIndex = $position->lengthIndex ?? SpotLengthIndex::forSeconds(max(1, $totalLength));

        return [
            'strategy' => $strategy,
            'position_index' => $positionIndex,
            'components' => $components,
        ];
    }

    /**
     * @param  array{
     *     strategy: ComponentCalculationStrategy|null,
     *     position_index: int,
     *     components: list<array<string, mixed>>
     * }  $resolved
     * @param  array<int, string>  $componentGrosses
     * @return list<array{role: string, label: string, length_seconds: int, sort: int, length_index: int, media_gross: string}>
     */
    private function componentResults(array $resolved, array $componentGrosses): array
    {
        if ($resolved['strategy'] === null) {
            return [];
        }

        if ($resolved['strategy'] === ComponentCalculationStrategy::SharedTotalLength) {
            $display = $resolved['components'][0]['_display'] ?? [];
            $rows = [];
            foreach ($display as $component) {
                $rows[] = [
                    'role' => (string) $component['role'],
                    'label' => (string) $component['label'],
                    'length_seconds' => (int) $component['length_seconds'],
                    'sort' => (int) $component['sort'],
                    'length_index' => (int) $component['length_index'],
                    // Gemeinsames Brutto liegt am Positionsbrutto; Komponenten zeigen keine Einzelbeträge.
                    'media_gross' => '',
                ];
            }

            return $rows;
        }

        $rows = [];
        foreach ($resolved['components'] as $offset => $component) {
            $rows[] = [
                'role' => (string) $component['role'],
                'label' => (string) $component['label'],
                'length_seconds' => (int) $component['length_seconds'],
                'sort' => (int) $component['sort'],
                'length_index' => (int) $component['length_index'],
                'media_gross' => Decimal::roundMoney($componentGrosses[$offset] ?? '0'),
            ];
        }

        return $rows;
    }

    /**
     * @param  list<PlanRowInput>  $rows
     * @return list<PlanRowInput>
     */
    public function uniqueHourRows(array $rows): array
    {
        $seen = [];
        $unique = [];

        foreach ($rows as $row) {
            $key = $row->hour.'|'.$row->dayGroup->value;
            if (isset($seen[$key])) {
                continue;
            }

            $seen[$key] = true;
            $unique[] = $row;
        }

        usort($unique, fn (PlanRowInput $a, PlanRowInput $b): int => $a->hour <=> $b->hour
            ?: strcmp($a->dayGroup->value, $b->dayGroup->value));

        return $unique;
    }

    /**
     * @param  list<PlanRowInput>  $rows
     */
    public function averageSecondPrice(array $rows): string
    {
        if ($rows === []) {
            return '0';
        }

        $sum = '0';
        foreach ($rows as $row) {
            $sum = Decimal::add($sum, $row->secondPrice);
        }

        return Decimal::roundPrice(Decimal::div($sum, (string) count($rows), 4));
    }

    public function nnPerSpotFromAverage(PositionInput $position, string $orderDiscountPercent): string
    {
        if ($position->timeRanges !== []) {
            $totalSpots = 0;
            foreach ($position->timeRanges as $range) {
                $totalSpots += max(0, $range->spotCount);
            }

            if ($totalSpots < 1) {
                return '0';
            }

            $result = $this->calculatePosition($position, $orderDiscountPercent);

            return Decimal::div($result->nnInvest, (string) $totalSpots);
        }

        $uniqueRows = $this->uniqueHourRows($position->rows);

        if ($uniqueRows === []) {
            return '0';
        }

        $averagePosition = new PositionInput(
            inventoryId: $position->inventoryId,
            inventoryName: $position->inventoryName,
            positionKey: $position->positionKey,
            lengthSeconds: $position->lengthSeconds,
            surchargePercent: $position->surchargePercent,
            positionDiscountPercent: $position->positionDiscountPercent,
            aePercent: $position->aePercent,
            isDiscountable: $position->isDiscountable,
            isAeEligible: $position->isAeEligible,
            totalSpotCount: 1,
            spotMethod: SpotCalculationMethod::Average,
            rows: $uniqueRows,
            lengthIndex: $position->lengthIndex,
            timeRanges: [],
            positionDiscounts: $position->positionDiscounts,
            components: $position->components,
            componentCalculationStrategy: $position->componentCalculationStrategy,
        );

        return $this->calculateAveragePosition(
            $averagePosition,
            $orderDiscountPercent,
            $this->resolveDiscountList([], $orderDiscountPercent),
        )->nnInvest;
    }

    public function nnPerSpot(PositionInput $position, string $orderDiscountPercent, PlanRowInput $row): string
    {
        $averagePosition = new PositionInput(
            inventoryId: $position->inventoryId,
            inventoryName: $position->inventoryName,
            positionKey: $position->positionKey,
            lengthSeconds: $position->lengthSeconds,
            surchargePercent: $position->surchargePercent,
            positionDiscountPercent: $position->positionDiscountPercent,
            aePercent: $position->aePercent,
            isDiscountable: $position->isDiscountable,
            isAeEligible: $position->isAeEligible,
            totalSpotCount: 1,
            spotMethod: SpotCalculationMethod::Average,
            rows: [$row],
            lengthIndex: $position->lengthIndex,
            timeRanges: [],
            positionDiscounts: $position->positionDiscounts,
            components: $position->components,
            componentCalculationStrategy: $position->componentCalculationStrategy,
        );

        return $this->calculateAveragePosition(
            $averagePosition,
            $orderDiscountPercent,
            $this->resolveDiscountList([], $orderDiscountPercent),
        )->nnInvest;
    }

    /**
     * @param  list<DiscountInput>  $orderDiscounts
     * @param  list<array{hour: int, day_group: string, spot_count: int, second_price: string, line_gross: string}>  $rowResults
     * @param  list<array{start_hour: int, end_hour_exclusive: int, day_group: string, spot_count: int, average_second_price: string, range_gross: string, hours: list<int>}>  $timeRanges
     * @param  list<array{date: string, hour: int, day_group: string, spot_count: int, second_price: string, line_gross: string}>  $plannerEntries
     * @param  list<array{role: string, label: string, length_seconds: int, sort: int, length_index: int, media_gross: string}>  $components
     */
    private function finalizePosition(
        PositionInput $position,
        string $orderDiscountPercent,
        array $orderDiscounts,
        string $mediaGrossInternal,
        int $spotCount,
        int $index,
        array $rowResults,
        string $averageSecondPrice,
        array $timeRanges = [],
        array $plannerEntries = [],
        array $components = [],
    ): PositionResult {
        $mediaGrossRounded = Decimal::roundMoney($mediaGrossInternal);

        if ($position->pricingSettlementMode === PricingSettlementMode::FixedPrice) {
            if (Decimal::cmp($mediaGrossInternal, '0') === 0) {
                throw new \InvalidArgumentException(
                    'Festpreis erfordert ein berechenbares Mediabrutto größer 0.',
                );
            }

            $fixedNnRaw = $position->fixedPriceNn ?? '0';
            if (Decimal::cmp($fixedNnRaw, '0') <= 0) {
                throw new \InvalidArgumentException(
                    'Festpreis erfordert einen N/N-Endbetrag größer 0.',
                );
            }

            $nnInvest = Decimal::roundMoney($fixedNnRaw);
            $aeSettlement = $this->deriveFixedPriceAe($nnInvest, $position);
            $factors = $this->deriveSettlementFactors($mediaGrossInternal, $nnInvest);
            $discountBeforeAe = $this->deriveSettlementFactors(
                $mediaGrossInternal,
                $aeSettlement['net_before_ae'],
            )['effectiveDiscountPercent'];

            return new PositionResult(
                mediaGross: $mediaGrossRounded,
                positionDiscountAmount: '0.00',
                afterPositionDiscount: $mediaGrossRounded,
                orderDiscountAmount: '0.00',
                afterOrderDiscount: $aeSettlement['net_before_ae'],
                aeAmount: $aeSettlement['ae_amount'],
                nnInvest: $nnInvest,
                effectiveDiscountPercent: $factors['effectiveDiscountPercent'],
                spotCount: $spotCount,
                lengthIndex: $index,
                rows: $rowResults,
                averageSecondPrice: Decimal::roundPrice($averageSecondPrice),
                timeRanges: $timeRanges,
                plannerEntries: $plannerEntries,
                positionDiscounts: [],
                orderDiscounts: [],
                needsSpotRedistribution: $position->needsSpotRedistribution,
                legacyTotalSpotCount: $position->needsSpotRedistribution ? $position->totalSpotCount : null,
                components: $components,
                componentCalculationStrategy: $position->hasComponents()
                    ? ($position->componentCalculationStrategy ?? ComponentCalculationStrategy::SharedTotalLength)
                    : null,
                pricingSettlementMode: PricingSettlementMode::FixedPrice,
                fixedPriceNn: $nnInvest,
                effectivePayFactorPercent: $factors['effectivePayFactorPercent'],
                effectiveDiscountBeforeAePercent: $discountBeforeAe,
            );
        }

        $positionDiscounts = $position->isDiscountable
            ? $this->resolveDiscountList($position->positionDiscounts, $position->positionDiscountPercent)
            : [];
        $appliedOrderDiscounts = $position->isDiscountable
            ? $orderDiscounts
            : [];

        $positionSequence = $this->applyDiscountSequence($mediaGrossInternal, $positionDiscounts);
        $afterPosition = $positionSequence === []
            ? $mediaGrossInternal
            : $positionSequence[array_key_last($positionSequence)]['remaining'];
        $orderSequence = $this->applyDiscountSequence($afterPosition, $appliedOrderDiscounts);
        $afterOrder = $orderSequence === []
            ? $afterPosition
            : $orderSequence[array_key_last($orderSequence)]['remaining'];

        $aeAmountInternal = $position->isAeEligible
            ? Decimal::mul($afterOrder, Decimal::percentFactor($position->aePercent))
            : '0';
        $nnInternal = Decimal::sub($afterOrder, $aeAmountInternal);

        $effectiveDiscount = '0';
        if (Decimal::cmp($mediaGrossInternal, '0') !== 0) {
            $effectiveDiscount = Decimal::mul(
                Decimal::sub('1', Decimal::div($afterOrder, $mediaGrossInternal)),
                '100',
            );
        }

        $effectivePayFactor = null;
        if (Decimal::cmp($mediaGrossInternal, '0') !== 0) {
            $effectivePayFactor = Decimal::roundPrice(
                Decimal::mul(Decimal::div($nnInternal, $mediaGrossInternal), '100'),
            );
        }

        return new PositionResult(
            mediaGross: Decimal::roundMoney($mediaGrossInternal),
            positionDiscountAmount: Decimal::roundMoney(Decimal::sub($mediaGrossInternal, $afterPosition)),
            afterPositionDiscount: Decimal::roundMoney($afterPosition),
            orderDiscountAmount: Decimal::roundMoney(Decimal::sub($afterPosition, $afterOrder)),
            afterOrderDiscount: Decimal::roundMoney($afterOrder),
            aeAmount: Decimal::roundMoney($aeAmountInternal),
            nnInvest: Decimal::roundMoney($nnInternal),
            effectiveDiscountPercent: Decimal::roundPrice($effectiveDiscount),
            spotCount: $spotCount,
            lengthIndex: $index,
            rows: $rowResults,
            averageSecondPrice: Decimal::roundPrice($averageSecondPrice),
            timeRanges: $timeRanges,
            plannerEntries: $plannerEntries,
            positionDiscounts: $positionSequence,
            orderDiscounts: $orderSequence,
            needsSpotRedistribution: $position->needsSpotRedistribution,
            legacyTotalSpotCount: $position->needsSpotRedistribution ? $position->totalSpotCount : null,
            components: $components,
            componentCalculationStrategy: $position->hasComponents()
                ? ($position->componentCalculationStrategy ?? ComponentCalculationStrategy::SharedTotalLength)
                : null,
            pricingSettlementMode: PricingSettlementMode::Normal,
            fixedPriceNn: null,
            effectivePayFactorPercent: $effectivePayFactor,
        );
    }

    /**
     * @param  list<DiscountInput>  $orderDiscounts
     */
    private function emptyPositionResult(PositionInput $position, string $orderDiscountPercent, array $orderDiscounts): PositionResult
    {
        $index = $position->lengthIndex ?? SpotLengthIndex::forSeconds($position->lengthSeconds);

        if ($position->pricingSettlementMode === PricingSettlementMode::FixedPrice
            && $position->fixedPriceNn !== null
            && $position->fixedPriceNn !== ''
            && Decimal::cmp($position->fixedPriceNn, '0') > 0
        ) {
            throw new \InvalidArgumentException(
                'Festpreis erfordert ein berechenbares Mediabrutto größer 0.',
            );
        }

        return $this->finalizePosition(
            $position,
            $orderDiscountPercent,
            $orderDiscounts,
            '0',
            0,
            $index,
            [],
            '0',
        );
    }

    /**
     * BL-P4-02d: AE wird aus dem N/N-Festpreis rückwärts ausgewiesen, ohne ihn zu ändern.
     *
     * @return array{net_before_ae: string, ae_amount: string}
     */
    private function deriveFixedPriceAe(string $fixedNn, PositionInput $position): array
    {
        if (! $position->isAeEligible) {
            return [
                'net_before_ae' => $fixedNn,
                'ae_amount' => '0.00',
            ];
        }

        if (Decimal::cmp($position->aePercent, '0') < 0) {
            throw new \InvalidArgumentException('AE-Satz darf nicht negativ sein.');
        }

        if (Decimal::cmp($position->aePercent, '0') === 0) {
            return [
                'net_before_ae' => $fixedNn,
                'ae_amount' => '0.00',
            ];
        }

        if (Decimal::cmp($position->aePercent, '100') >= 0) {
            throw new \InvalidArgumentException(
                'AE-Satz muss kleiner als 100 % sein (Festpreis-Rückrechnung).',
            );
        }

        $netBeforeAe = Decimal::roundMoney(
            Decimal::div($fixedNn, Decimal::oneMinusPercent($position->aePercent)),
        );
        $aeAmount = Decimal::roundMoney(Decimal::sub($netBeforeAe, $fixedNn));

        return [
            'net_before_ae' => $netBeforeAe,
            'ae_amount' => $aeAmount,
        ];
    }

    /**
     * @return array{effectiveDiscountPercent: string, effectivePayFactorPercent: string}
     */
    private function deriveSettlementFactors(string $mediaGrossInternal, string $nnInternal): array
    {
        return [
            'effectiveDiscountPercent' => Decimal::roundPrice(
                Decimal::mul(
                    Decimal::sub('1', Decimal::div($nnInternal, $mediaGrossInternal)),
                    '100',
                ),
            ),
            'effectivePayFactorPercent' => Decimal::roundPrice(
                Decimal::mul(Decimal::div($nnInternal, $mediaGrossInternal), '100'),
            ),
        ];
    }

    /**
     * @param  list<DiscountInput>  $discounts
     * @return list<DiscountInput>
     */
    private function resolveDiscountList(array $discounts, string $legacyPercent): array
    {
        if ($discounts !== []) {
            return $discounts;
        }

        if (Decimal::cmp($legacyPercent, '0') <= 0) {
            return [];
        }

        return [new DiscountInput(DiscountType::Quantity, $legacyPercent)];
    }

    /**
     * @param  list<DiscountInput>  $discounts
     * @return list<array{type: string, label: string, percent: string, amount: string, remaining: string}>
     */
    private function applyDiscountSequence(string $startAmount, array $discounts): array
    {
        $remaining = $startAmount;
        $rows = [];

        foreach ($discounts as $discount) {
            $next = Decimal::mul($remaining, Decimal::oneMinusPercent($discount->percent));
            $amount = Decimal::sub($remaining, $next);
            $rows[] = [
                'type' => $discount->type->value,
                'label' => $discount->displayName(),
                'percent' => Decimal::roundPrice($discount->percent),
                'amount' => Decimal::roundMoney($amount),
                'remaining' => Decimal::roundMoney($next),
            ];
            $remaining = $next;
        }

        return $rows;
    }

    private function stackedPositionPercent(PositionInput $position): string
    {
        if (! $position->isDiscountable) {
            return '0';
        }

        $discounts = $this->resolveDiscountList($position->positionDiscounts, $position->positionDiscountPercent);

        return $this->assessor->stackedPercent(
            array_map(fn (DiscountInput $discount): string => $discount->percent, $discounts),
        );
    }
}
