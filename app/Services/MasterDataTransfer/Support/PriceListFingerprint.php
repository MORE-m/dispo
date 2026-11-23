<?php

namespace App\Services\MasterDataTransfer\Support;

/**
 * Stabiler Inhalts-Fingerprint für Spot-/Produktionspreislisten (Idempotenz).
 */
final class PriceListFingerprint
{
    /**
     * @param  list<array{hour: int, day_group: string, second_price: string}>  $items
     */
    public static function forSpotItems(array $items): string
    {
        $normalized = collect($items)
            ->map(fn (array $i) => [
                'hour' => (int) $i['hour'],
                'day_group' => (string) $i['day_group'],
                'second_price' => (string) $i['second_price'],
            ])
            ->sortBy(fn (array $i) => $i['day_group'].'|'.$i['hour'])
            ->values()
            ->all();

        return hash('sha256', json_encode($normalized, JSON_THROW_ON_ERROR));
    }

    public static function forProduction(string $unitPrice, bool $isDiscountable, bool $isAeEligible): string
    {
        return hash('sha256', json_encode([
            'unit_price' => $unitPrice,
            'is_discountable' => $isDiscountable,
            'is_ae_eligible' => $isAeEligible,
        ], JSON_THROW_ON_ERROR));
    }
}
