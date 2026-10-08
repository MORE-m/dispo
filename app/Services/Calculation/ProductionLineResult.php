<?php

namespace App\Services\Calculation;

/**
 * BL-P5-02a: berechnete Produktionszeile (Brutto, Rabatt-/AE-Beiträge, N/N).
 */
final readonly class ProductionLineResult
{
    public function __construct(
        public ?string $clientKey,
        public string $productionType,
        public string $label,
        public string $quantity,
        public string $unitPrice,
        public string $lineGross,
        public bool $isDiscountable,
        public bool $isAeEligible,
        public ?string $remark,
        public ?int $productionPriceListId,
        public string $productionPriceListVersion,
        public string $positionDiscountAmount,
        public string $afterPositionDiscount,
        public string $orderDiscountAmount,
        public string $afterOrderDiscount,
        public string $aePercent,
        public string $aeAmount,
        public string $nnInvest,
        public int $sort = 0,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'client_key' => $this->clientKey,
            'production_type' => $this->productionType,
            'label' => $this->label,
            'quantity' => $this->quantity,
            'unit_price' => $this->unitPrice,
            'line_gross' => $this->lineGross,
            'is_discountable' => $this->isDiscountable,
            'is_ae_eligible' => $this->isAeEligible,
            'remark' => $this->remark,
            'production_price_list_id' => $this->productionPriceListId,
            'production_price_list_version' => $this->productionPriceListVersion,
            'position_discount_amount' => $this->positionDiscountAmount,
            'order_discount_amount' => $this->orderDiscountAmount,
            'ae_percent' => $this->aePercent,
            'ae_amount' => $this->aeAmount,
            'nn_invest' => $this->nnInvest,
            'sort' => $this->sort,
        ];
    }
}
