<?php

namespace App\Services\Calculation;

/**
 * Ergebnis der gemeinsamen Normalpreis-Commercial-Pipeline.
 *
 * @param  list<DiscountInput>  $appliedPositionDiscounts
 * @param  list<DiscountInput>  $appliedOrderDiscounts
 * @param  list<array{type: string, label: string, percent: string, amount: string, remaining: string}>  $appliedPositionDiscountSteps
 * @param  list<array{type: string, label: string, percent: string, amount: string, remaining: string}>  $appliedOrderDiscountSteps
 */
final readonly class CommercialPricingResult
{
    /**
     * @param  list<DiscountInput>  $appliedPositionDiscounts
     * @param  list<DiscountInput>  $appliedOrderDiscounts
     * @param  list<array{type: string, label: string, percent: string, amount: string, remaining: string}>  $appliedPositionDiscountSteps
     * @param  list<array{type: string, label: string, percent: string, amount: string, remaining: string}>  $appliedOrderDiscountSteps
     */
    public function __construct(
        public string $mediaGross,
        public string $positionDiscountAmount,
        public string $afterPositionDiscount,
        public string $orderDiscountAmount,
        public string $afterOrderDiscount,
        public string $aeAmount,
        public string $nnInvest,
        public string $effectiveDiscountPercent,
        public ?string $effectivePayFactorPercent,
        public array $appliedPositionDiscounts = [],
        public array $appliedOrderDiscounts = [],
        public array $appliedPositionDiscountSteps = [],
        public array $appliedOrderDiscountSteps = [],
        public string $effectivePositionDiscountPercent = '0',
        public string $effectiveOrderDiscountPercent = '0',
    ) {}
}
