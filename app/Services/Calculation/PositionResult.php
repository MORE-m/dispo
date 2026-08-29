<?php

namespace App\Services\Calculation;

final readonly class PositionResult
{
    /**
     * @param  list<array{hour: int, day_group: string, spot_count: int, second_price: string, line_gross: string}>  $rows
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
        public int $spotCount,
        public int $lengthIndex,
        public array $rows,
        public ?string $averageSecondPrice = null,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'media_gross' => $this->mediaGross,
            'position_discount_amount' => $this->positionDiscountAmount,
            'after_position_discount' => $this->afterPositionDiscount,
            'order_discount_amount' => $this->orderDiscountAmount,
            'after_order_discount' => $this->afterOrderDiscount,
            'ae_amount' => $this->aeAmount,
            'nn_invest' => $this->nnInvest,
            'effective_discount_percent' => $this->effectiveDiscountPercent,
            'spot_count' => $this->spotCount,
            'length_index' => $this->lengthIndex,
            'average_second_price' => $this->averageSecondPrice,
            'rows' => $this->rows,
        ];
    }
}
