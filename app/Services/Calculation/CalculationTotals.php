<?php

namespace App\Services\Calculation;

final readonly class CalculationTotals
{
    /**
     * @param  list<PositionResult>  $positions
     * @param  list<array{type: string, label: string, percent: string, amount: string, remaining: string}>  $orderDiscounts
     * @param  list<array<string, mixed>>  $specialApprovalReasons
     */
    public function __construct(
        public string $mediaGross,
        public string $positionDiscountTotal,
        public string $orderDiscountTotal,
        public string $aeTotal,
        public string $nnInvest,
        public ?string $targetBudgetNn,
        public ?string $budgetDelta,
        public bool $requiresSpecialApproval,
        public array $positions,
        public bool $aeEnabled = false,
        public array $orderDiscounts = [],
        public string $afterPositionDiscountTotal = '0.00',
        public string $afterOrderDiscountTotal = '0.00',
        public string $aeEligibleBase = '0.00',
        public array $specialApprovalReasons = [],
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'media_gross' => $this->mediaGross,
            'position_discount_total' => $this->positionDiscountTotal,
            'order_discount_total' => $this->orderDiscountTotal,
            'ae_total' => $this->aeTotal,
            'nn_invest' => $this->nnInvest,
            'target_budget_nn' => $this->targetBudgetNn,
            'budget_delta' => $this->budgetDelta,
            'requires_special_approval' => $this->requiresSpecialApproval,
            'special_approval_reasons' => $this->specialApprovalReasons,
            'ae_enabled' => $this->aeEnabled,
            'order_discounts' => $this->orderDiscounts,
            'after_position_discount_total' => $this->afterPositionDiscountTotal,
            'after_order_discount_total' => $this->afterOrderDiscountTotal,
            'ae_eligible_base' => $this->aeEligibleBase,
            'positions' => array_map(
                fn (PositionResult $position): array => $position->toArray(),
                $this->positions,
            ),
        ];
    }
}
