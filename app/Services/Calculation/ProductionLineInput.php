<?php

namespace App\Services\Calculation;

/**
 * BL-P5-02a: aufgelöste (gepinnte) Produktionszeile als Engine-Eingabe.
 *
 * Preis und Flags stammen ausschließlich aus dem Resolver (Pin / Live-Bindung), nie aus dem Sales-Payload.
 */
final readonly class ProductionLineInput
{
    public function __construct(
        public ?string $clientKey,
        public string $productionType,
        public string $label,
        public string $quantity,
        public string $unitPrice,
        public bool $isDiscountable,
        public bool $isAeEligible,
        public ?string $remark,
        public ?int $productionPriceListId,
        public string $productionPriceListVersion,
        public int $sort = 0,
    ) {}
}
