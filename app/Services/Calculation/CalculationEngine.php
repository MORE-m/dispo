<?php

namespace App\Services\Calculation;

use App\Enums\SpotCalculationMethod;

/**
 * GEN-002, SPT-001–SPT-004, SPT-009, SPT-015, SPT-016, CAL-005, COM-001, COM-002, COM-007, COM-008
 */
final class CalculationEngine
{
    /**
     * @param  list<PositionInput>  $positions
     */
    public function calculate(
        array $positions,
        string $orderDiscountPercent,
        ?string $targetBudgetNn,
        ?string $personalDiscountLimitPercent,
    ): CalculationTotals {
        $results = [];

        foreach ($positions as $position) {
            $results[] = $this->calculatePosition($position, $orderDiscountPercent);
        }

        $mediaGross = '0.00';
        $positionDiscountTotal = '0.00';
        $orderDiscountTotal = '0.00';
        $aeTotal = '0.00';
        $nnInvest = '0.00';
        $requiresSpecialApproval = false;

        foreach ($results as $result) {
            $mediaGross = Decimal::roundMoney(Decimal::add($mediaGross, $result->mediaGross));
            $positionDiscountTotal = Decimal::roundMoney(Decimal::add($positionDiscountTotal, $result->positionDiscountAmount));
            $orderDiscountTotal = Decimal::roundMoney(Decimal::add($orderDiscountTotal, $result->orderDiscountAmount));
            $aeTotal = Decimal::roundMoney(Decimal::add($aeTotal, $result->aeAmount));
            $nnInvest = Decimal::roundMoney(Decimal::add($nnInvest, $result->nnInvest));

            if ($this->exceedsDiscountLimit($result->effectiveDiscountPercent, $personalDiscountLimitPercent)) {
                $requiresSpecialApproval = true;
            }
        }

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
            requiresSpecialApproval: $requiresSpecialApproval,
            positions: $results,
        );
    }

    public function calculatePosition(PositionInput $position, string $orderDiscountPercent): PositionResult
    {
        return match ($position->spotMethod) {
            SpotCalculationMethod::Average => $this->calculateAveragePosition($position, $orderDiscountPercent),
            SpotCalculationMethod::Calendar, SpotCalculationMethod::FixedPrice => throw new \InvalidArgumentException(
                'Kalkulationsart '.$position->spotMethod->value.' ist in UX-GATE-B noch nicht implementiert.',
            ),
        };
    }

    /**
     * SPT-001–SPT-004: gleichgewichteter Durchschnitt eindeutiger Stunden × Gesamtspotanzahl.
     */
    private function calculateAveragePosition(PositionInput $position, string $orderDiscountPercent): PositionResult
    {
        $uniqueRows = $this->uniqueHourRows($position->rows);

        if ($uniqueRows === []) {
            return $this->emptyPositionResult($position, $orderDiscountPercent);
        }

        $averageSecondPrice = $this->averageSecondPrice($uniqueRows);
        $index = $position->lengthIndex ?? SpotLengthIndex::forSeconds($position->lengthSeconds);
        $lengthFactor = Decimal::div((string) $index, '100');
        $surchargeFactor = Decimal::add('1', Decimal::percentFactor($position->surchargePercent));
        $spotCount = max(0, $position->totalSpotCount);

        $spotPrice = Decimal::mulMany([
            $averageSecondPrice,
            (string) $position->lengthSeconds,
            $lengthFactor,
            $surchargeFactor,
        ]);
        $mediaGrossInternal = Decimal::mul($spotPrice, (string) $spotCount);

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
            $mediaGrossInternal,
            $spotCount,
            $index,
            $rowResults,
            $averageSecondPrice,
        );
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
        );

        return $this->calculateAveragePosition($averagePosition, $orderDiscountPercent)->nnInvest;
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
        );

        $result = $this->calculateAveragePosition($averagePosition, $orderDiscountPercent);

        return $result->nnInvest;
    }

    /**
     * @param  list<array{hour: int, day_group: string, spot_count: int, second_price: string, line_gross: string}>  $rowResults
     */
    private function finalizePosition(
        PositionInput $position,
        string $orderDiscountPercent,
        string $mediaGrossInternal,
        int $spotCount,
        int $index,
        array $rowResults,
        string $averageSecondPrice,
    ): PositionResult {
        $positionFactor = $position->isDiscountable
            ? Decimal::oneMinusPercent($position->positionDiscountPercent)
            : '1';
        $orderFactor = $position->isDiscountable
            ? Decimal::oneMinusPercent($orderDiscountPercent)
            : '1';

        $afterPosition = Decimal::mul($mediaGrossInternal, $positionFactor);
        $afterOrder = Decimal::mul($afterPosition, $orderFactor);
        $aeAmountInternal = $position->isAeEligible
            ? Decimal::mul($afterOrder, Decimal::percentFactor($position->aePercent))
            : '0';
        $nnInternal = Decimal::sub($afterOrder, $aeAmountInternal);

        $effectiveFactor = Decimal::mul($positionFactor, $orderFactor);
        $effectiveDiscount = Decimal::mul(Decimal::sub('1', $effectiveFactor), '100');

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
        );
    }

    private function emptyPositionResult(PositionInput $position, string $orderDiscountPercent): PositionResult
    {
        $index = $position->lengthIndex ?? SpotLengthIndex::forSeconds($position->lengthSeconds);

        return $this->finalizePosition(
            $position,
            $orderDiscountPercent,
            '0',
            0,
            $index,
            [],
            '0',
        );
    }

    private function exceedsDiscountLimit(string $effectiveDiscountPercent, ?string $personalLimitPercent): bool
    {
        if ($personalLimitPercent === null || $personalLimitPercent === '') {
            return false;
        }

        return Decimal::cmp($effectiveDiscountPercent, $personalLimitPercent, 4) === 1;
    }
}
