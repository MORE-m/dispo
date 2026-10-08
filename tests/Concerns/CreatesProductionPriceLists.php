<?php

namespace Tests\Concerns;

use App\Enums\PriceListStatus;
use App\Enums\ProductionType;
use App\Models\Inventory;
use App\Models\ProductionPriceList;
use App\Support\PriceList\PriceListCalendar;

/**
 * BL-P5-02a: Fixture-Helfer für aktive Produktionspreislisten.
 */
trait CreatesProductionPriceLists
{
    protected function createActiveProductionPriceList(
        Inventory $inventory,
        string $unitPrice,
        bool $discountable = false,
        bool $aeEligible = false,
        ?int $year = null,
    ): ProductionPriceList {
        return ProductionPriceList::factory()->create([
            'inventory_id' => $inventory->id,
            'production_type' => ProductionType::SpotProduction,
            'year' => $year ?? PriceListCalendar::currentYear(),
            'status' => PriceListStatus::Active,
            'unit_price' => $unitPrice,
            'is_discountable' => $discountable,
            'is_ae_eligible' => $aeEligible,
        ]);
    }
}
