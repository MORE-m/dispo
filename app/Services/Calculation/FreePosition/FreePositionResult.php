<?php

namespace App\Services\Calculation\FreePosition;

use App\Enums\PricingSettlementMode;
use App\Services\Calculation\DiscountInput;

/**
 * NATIVE-1: Ergebnis der Free-Position-Engine (Commercial-kompatibel).
 *
 * Settlement immer normal; kein Fixed-Price-N/N.
 *
 * @param  list<DiscountInput>  $appliedPositionDiscounts
 * @param  list<DiscountInput>  $appliedOrderDiscounts
 * @param  list<array{type: string, label: string, percent: string, amount: string, remaining: string}>  $appliedPositionDiscountSteps
 * @param  list<array{type: string, label: string, percent: string, amount: string, remaining: string}>  $appliedOrderDiscountSteps
 */
final readonly class FreePositionResult
{
    /**
     * @param  list<DiscountInput>  $appliedPositionDiscounts
     * @param  list<DiscountInput>  $appliedOrderDiscounts
     * @param  list<array{type: string, label: string, percent: string, amount: string, remaining: string}>  $appliedPositionDiscountSteps
     * @param  list<array{type: string, label: string, percent: string, amount: string, remaining: string}>  $appliedOrderDiscountSteps
     */
    public function __construct(
        public string $freePrice,
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
        public string $aePercent = '0',
        public bool $isDiscountable = true,
        public bool $isAeEligible = true,
        public ?string $positionKey = null,
        public PricingSettlementMode $pricingSettlementMode = PricingSettlementMode::Normal,
        public ?string $fixedPriceNn = null,
        public int $lengthSeconds = 0,
        public int $totalSpotCount = 0,
        public ?int $impressions = null,
    ) {}
}
