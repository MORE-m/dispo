<?php

namespace App\Services\Calculation;

final readonly class PositionResult
{
    /**
     * @param  list<array{hour: int, day_group: string, spot_count: int, second_price: string, line_gross: string}>  $rows
     * @param  list<array{start_hour: int, end_hour_exclusive: int, day_group: string, spot_count: int, average_second_price: string, range_gross: string, hours: list<int>}>  $timeRanges
     * @param  list<array{type: string, label: string, percent: string, amount: string, remaining: string}>  $positionDiscounts
     * @param  list<array{type: string, label: string, percent: string, amount: string, remaining: string}>  $orderDiscounts
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
        public array $timeRanges = [],
        public array $positionDiscounts = [],
        public array $orderDiscounts = [],
        public bool $needsSpotRedistribution = false,
        public ?int $legacyTotalSpotCount = null,
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
            'time_ranges' => $this->timeRanges,
            'position_discounts' => $this->positionDiscounts,
            'order_discounts' => $this->orderDiscounts,
            'needs_spot_redistribution' => $this->needsSpotRedistribution,
            'legacy_total_spot_count' => $this->legacyTotalSpotCount,
        ];
    }
}
