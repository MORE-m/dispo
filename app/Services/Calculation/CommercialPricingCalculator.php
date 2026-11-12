<?php

namespace App\Services\Calculation;

use App\Enums\DiscountType;

/**
 * Gemeinsame Normalpreis-Commercial-Pipeline (NATIVE-1).
 *
 * Kapselt ausschließlich:
 *   gross → Positionsrabatte → Auftragsrabatte → AE → N/N
 *
 * Kein Spotpreis, kein TKP, kein Targeting, kein Festpreis-Settlement,
 * keine Produktion/Planner/Komponenten.
 *
 * Präzision: interne BCMath bis zu den finalen Money-/Price-Rundungen (OA-Semantik).
 */
final class CommercialPricingCalculator
{
    /**
     * @param  list<DiscountInput>  $positionDiscounts
     * @param  list<DiscountInput>  $orderDiscounts
     */
    public function calculate(
        string $mediaGross,
        array $positionDiscounts = [],
        string $legacyPositionDiscountPercent = '0',
        array $orderDiscounts = [],
        string $legacyOrderDiscountPercent = '0',
        bool $isDiscountable = true,
        string $aePercent = '0',
        bool $isAeEligible = false,
        bool $aeEnabled = true,
        bool $resolveOrderLegacyPercent = true,
    ): CommercialPricingResult {
        $mediaGrossInternal = Decimal::of($mediaGross);

        $appliedPosition = $isDiscountable
            ? $this->resolveDiscountList($positionDiscounts, $legacyPositionDiscountPercent)
            : [];
        $appliedOrder = $isDiscountable
            ? (
                $resolveOrderLegacyPercent
                    ? $this->resolveDiscountList($orderDiscounts, $legacyOrderDiscountPercent)
                    : $orderDiscounts
            )
            : [];

        $positionSequence = $this->applyDiscountSequence($mediaGrossInternal, $appliedPosition);
        $afterPosition = $positionSequence === []
            ? $mediaGrossInternal
            : $positionSequence[array_key_last($positionSequence)]['remaining_internal'];

        $orderSequence = $this->applyDiscountSequence($afterPosition, $appliedOrder);
        $afterOrder = $orderSequence === []
            ? $afterPosition
            : $orderSequence[array_key_last($orderSequence)]['remaining_internal'];

        $aeAmountInternal = ($aeEnabled && $isAeEligible)
            ? Decimal::mul($afterOrder, Decimal::percentFactor($aePercent))
            : '0';
        $nnInternal = Decimal::sub($afterOrder, $aeAmountInternal);

        $effectiveDiscount = '0';
        if (Decimal::cmp($mediaGrossInternal, '0') !== 0) {
            $effectiveDiscount = Decimal::mul(
                Decimal::sub('1', Decimal::div($afterOrder, $mediaGrossInternal)),
                '100',
            );
        }

        $payFactor = null;
        if (Decimal::cmp($mediaGrossInternal, '0') !== 0) {
            $payFactor = Decimal::roundPrice(
                Decimal::mul(Decimal::div($nnInternal, $mediaGrossInternal), '100'),
            );
        }

        return new CommercialPricingResult(
            mediaGross: Decimal::roundMoney($mediaGrossInternal),
            positionDiscountAmount: Decimal::roundMoney(Decimal::sub($mediaGrossInternal, $afterPosition)),
            afterPositionDiscount: Decimal::roundMoney($afterPosition),
            orderDiscountAmount: Decimal::roundMoney(Decimal::sub($afterPosition, $afterOrder)),
            afterOrderDiscount: Decimal::roundMoney($afterOrder),
            aeAmount: Decimal::roundMoney($aeAmountInternal),
            nnInvest: Decimal::roundMoney($nnInternal),
            effectiveDiscountPercent: Decimal::roundPrice($effectiveDiscount),
            effectivePayFactorPercent: $payFactor,
            appliedPositionDiscounts: $appliedPosition,
            appliedOrderDiscounts: $appliedOrder,
            appliedPositionDiscountSteps: $this->publicSteps($positionSequence),
            appliedOrderDiscountSteps: $this->publicSteps($orderSequence),
            effectivePositionDiscountPercent: $this->effectivePercentFromDiscounts($appliedPosition),
            effectiveOrderDiscountPercent: $this->effectivePercentFromDiscounts($appliedOrder),
        );
    }

    /**
     * @param  list<DiscountInput>  $discounts
     * @return list<DiscountInput>
     */
    public function resolveDiscountList(array $discounts, string $legacyPercent): array
    {
        if ($discounts !== []) {
            return $discounts;
        }

        if (Decimal::cmp($legacyPercent, '0') === 0) {
            return [];
        }

        return [new DiscountInput(type: DiscountType::Quantity, percent: $legacyPercent)];
    }

    /**
     * @param  list<DiscountInput>  $discounts
     * @return list<array{
     *     type: string,
     *     label: string,
     *     percent: string,
     *     amount: string,
     *     remaining: string,
     *     remaining_internal: string
     * }>
     */
    public function applyDiscountSequence(string $startAmount, array $discounts): array
    {
        $remaining = $startAmount;
        $steps = [];

        foreach ($discounts as $discount) {
            $next = Decimal::mul($remaining, Decimal::oneMinusPercent($discount->percent));
            $amount = Decimal::sub($remaining, $next);
            $steps[] = [
                'type' => $discount->type->value,
                'label' => $discount->displayName(),
                'percent' => Decimal::roundPrice($discount->percent),
                'amount' => Decimal::roundMoney($amount),
                'remaining' => Decimal::roundMoney($next),
                'remaining_internal' => $next,
            ];
            $remaining = $next;
        }

        return $steps;
    }

    /**
     * @param  list<DiscountInput>  $discounts
     */
    public function effectivePercentFromDiscounts(array $discounts): string
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

    /**
     * @param  list<array{
     *     type: string,
     *     label: string,
     *     percent: string,
     *     amount: string,
     *     remaining: string,
     *     remaining_internal: string
     * }>  $steps
     * @return list<array{type: string, label: string, percent: string, amount: string, remaining: string}>
     */
    private function publicSteps(array $steps): array
    {
        return array_map(
            static fn (array $step): array => [
                'type' => $step['type'],
                'label' => $step['label'],
                'percent' => $step['percent'],
                'amount' => $step['amount'],
                'remaining' => $step['remaining'],
            ],
            $steps,
        );
    }
}
