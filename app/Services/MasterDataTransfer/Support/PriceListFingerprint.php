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

    /**
     * @param  list<array{inventory_code: string, medium_code: string, list_tkp: string, minimum_tkp: string}>  $items
     */
    public static function forOaTkpItems(array $items): string
    {
        $normalized = collect($items)
            ->map(fn (array $i) => [
                'inventory_code' => (string) $i['inventory_code'],
                'medium_code' => (string) $i['medium_code'],
                'list_tkp' => (string) $i['list_tkp'],
                'minimum_tkp' => (string) $i['minimum_tkp'],
            ])
            ->sortBy(fn (array $i) => $i['inventory_code'].'|'.$i['medium_code'])
            ->values()
            ->all();

        return hash('sha256', json_encode($normalized, JSON_THROW_ON_ERROR));
    }

    /**
     * @return array{name: string, version: string, valid_from: string|null}
     */
    public static function spotMetadataTuple(string $name, string $version, mixed $validFrom): array
    {
        return [
            'name' => $name,
            'version' => $version,
            'valid_from' => $validFrom === null || $validFrom === '' ? null : (string) $validFrom,
        ];
    }

    /**
     * @param  array{name: string, version: string, valid_from: string|null}  $package
     * @param  array{name: string, version: string, valid_from: string|null}  $existing
     */
    public static function spotMetadataMatches(array $package, array $existing): bool
    {
        return $package === $existing;
    }

    /**
     * @return array{name: string, version: string}
     */
    public static function productionMetadataTuple(string $name, string $version): array
    {
        return ['name' => $name, 'version' => $version];
    }

    /**
     * @param  array{name: string, version: string}  $package
     * @param  array{name: string, version: string}  $existing
     */
    public static function productionMetadataMatches(array $package, array $existing): bool
    {
        return $package === $existing;
    }

    /**
     * @param  array{name: string, version: string, valid_from: string|null}  $package
     * @param  array{name: string, version: string, valid_from: string|null}  $existing
     */
    public static function oaTkpMetadataMatches(array $package, array $existing): bool
    {
        return $package === $existing;
    }
}
