<?php

namespace App\Services\Calculation;

final readonly class CalculationTotals
{
    /**
     * @param  list<PositionResult>  $positions
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
            'positions' => array_map(
                fn (PositionResult $position): array => $position->toArray(),
                $this->positions,
            ),
        ];
    }
}
