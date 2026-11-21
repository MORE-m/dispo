<?php

namespace App\Services\MasterDataTransfer\Support;

use App\Services\MasterDataTransfer\MasterDataTransferContract;
use App\Services\MasterDataTransfer\MasterDataTransferException;

/**
 * Prüft Paketinhalt gegen den freigegebenen Spot-Scope (kein OA, nur 8 Inventare, 2026/2027).
 */
final class SpotScopeGuard
{
    /**
     * @param  array<string, mixed>  $data  relative file => decoded payload
     * @param  array<string, mixed>  $manifest
     */
    public function assertPackage(array $data, array $manifest = []): void
    {
        $errors = [];

        $packageInventoryCodes = [];
        foreach ($data['data/inventories.json'] ?? [] as $row) {
            $code = (string) ($row['code'] ?? '');
            if ($code === '') {
                $errors[] = 'Inventar ohne code.';

                continue;
            }
            if (! MasterDataTransferContract::isAllowedInventoryCode($code)) {
                $errors[] = 'Inventar außerhalb Spot-Scope: '.$code;
            }
            $packageInventoryCodes[] = $code;
        }
        $packageInventoryCodes = array_values(array_unique($packageInventoryCodes));
        sort($packageInventoryCodes);

        $manifestInventoryCodes = [];
        $scope = $manifest['scope'] ?? null;
        if (is_array($scope)) {
            $rawManifestInventories = $scope['inventory_codes'] ?? null;
            if (! is_array($rawManifestInventories)) {
                $errors[] = 'Manifest scope.inventory_codes fehlt oder ist ungültig.';
            } else {
                foreach ($rawManifestInventories as $code) {
                    $normalized = (string) $code;
                    if ($normalized === '') {
                        $errors[] = 'Manifest scope.inventory_codes enthält leeren Code.';

                        continue;
                    }
                    if (! MasterDataTransferContract::isAllowedInventoryCode($normalized)) {
                        $errors[] = 'Manifest-Inventar außerhalb Spot-Scope: '.$normalized;
                    }
                    $manifestInventoryCodes[] = $normalized;
                }
                $manifestInventoryCodes = array_values(array_unique($manifestInventoryCodes));
                sort($manifestInventoryCodes);
                if ($manifestInventoryCodes !== $packageInventoryCodes) {
                    $errors[] = 'Manifest scope.inventory_codes stimmt nicht mit data/inventories.json überein.';
                }
            }

            $rawYears = $scope['price_years'] ?? null;
            if (! is_array($rawYears)) {
                $errors[] = 'Manifest scope.price_years fehlt oder ist ungültig.';
            } else {
                $years = array_values(array_map('intval', $rawYears));
                sort($years);
                $expected = MasterDataTransferContract::PRICE_YEARS;
                $sortedExpected = $expected;
                sort($sortedExpected);
                if ($years !== $sortedExpected) {
                    $errors[] = 'Manifest scope.price_years muss exakt '
                        .implode('/', MasterDataTransferContract::PRICE_YEARS).' sein.';
                }
            }
        } elseif ($manifest !== []) {
            $errors[] = 'Manifest scope fehlt.';
        }

        foreach ($data['data/advertising_categories.json'] ?? [] as $row) {
            $key = (string) ($row['key'] ?? '');
            if ($key !== '' && ! MasterDataTransferContract::isAllowedCategoryKey($key)) {
                $errors[] = 'Kategorie außerhalb Spot-Scope: '.$key;
            }
        }

        $packageMediumCodes = [];
        foreach ($data['data/advertising_media.json'] ?? [] as $row) {
            $code = (string) ($row['code'] ?? '');
            $categoryKey = (string) ($row['category_key'] ?? '');
            if ($code !== '' && MasterDataTransferContract::isExcludedMediumCode($code)) {
                $errors[] = 'Ausgeschlossenes Werbemittel im Paket: '.$code;
            }
            if ($categoryKey !== '' && ! MasterDataTransferContract::isAllowedCategoryKey($categoryKey)) {
                $errors[] = 'Werbemittel-Kategorie außerhalb Spot-Scope: '.$code.' → '.$categoryKey;
            }
            if ($code !== '') {
                $packageMediumCodes[$code] = true;
            }
        }

        foreach ($data['data/inventory_medium_rules.json'] ?? [] as $row) {
            $inventory = (string) ($row['inventory_code'] ?? '');
            $medium = (string) ($row['advertising_medium_code'] ?? '');
            if ($inventory !== '' && ! MasterDataTransferContract::isAllowedInventoryCode($inventory)) {
                $errors[] = 'Regel-Inventar außerhalb Spot-Scope: '.$inventory;
            }
            if ($inventory !== '' && ! in_array($inventory, $packageInventoryCodes, true)) {
                $errors[] = 'Regel referenziert Inventar außerhalb Paketdatei: '.$inventory;
            }
            if ($medium !== '' && MasterDataTransferContract::isExcludedMediumCode($medium)) {
                $errors[] = 'Regel mit ausgeschlossenem Medium: '.$medium;
            }
            if ($medium !== '' && ! isset($packageMediumCodes[$medium])) {
                $errors[] = 'Regel referenziert Medium außerhalb Paketdatei: '.$medium;
            }
        }

        foreach ($data['data/advertising_medium_calculation_methods.json'] ?? [] as $row) {
            $engine = (string) ($row['engine_profile_key'] ?? '');
            $medium = (string) ($row['advertising_medium_code'] ?? '');
            if (MasterDataTransferContract::isExcludedEngineProfile($engine)) {
                $errors[] = 'OA-Engine-Zuordnung im Paket: '.$medium.' / '.$engine;
            }
            if ($medium !== '' && MasterDataTransferContract::isExcludedMediumCode($medium)) {
                $errors[] = 'Medium-Methodenzuordnung für ausgeschlossenes Medium: '.$medium;
            }
            if ($medium !== '' && ! isset($packageMediumCodes[$medium])) {
                $errors[] = 'Medium-Methode referenziert Medium außerhalb Paketdatei: '.$medium;
            }
        }

        foreach ($data['data/advertising_category_calculation_methods.json'] ?? [] as $row) {
            $engine = (string) ($row['engine_profile_key'] ?? '');
            $category = (string) ($row['category_key'] ?? '');
            if (MasterDataTransferContract::isExcludedEngineProfile($engine)) {
                $errors[] = 'OA-Kategorie-Methode im Paket: '.$category.' / '.$engine;
            }
            if ($category !== '' && ! MasterDataTransferContract::isAllowedCategoryKey($category)) {
                $errors[] = 'Kategorie-Methode außerhalb Spot-Scope: '.$category;
            }
        }

        foreach ($data['data/price_lists.json'] ?? [] as $row) {
            $inventory = (string) ($row['inventory_code'] ?? '');
            $year = (int) ($row['year'] ?? 0);
            if ($inventory !== '' && ! MasterDataTransferContract::isAllowedInventoryCode($inventory)) {
                $errors[] = 'Spotpreisliste außerhalb Spot-Scope: '.$inventory;
            }
            if ($inventory !== '' && ! in_array($inventory, $packageInventoryCodes, true)) {
                $errors[] = 'Spotpreisliste referenziert Inventar außerhalb Paketdatei: '.$inventory;
            }
            if (! MasterDataTransferContract::isAllowedPriceYear($year)) {
                $errors[] = 'Spotpreis-Jahr außerhalb Scope: '.$inventory.' / '.$year;
            }
        }

        foreach ($data['data/production_price_lists.json'] ?? [] as $row) {
            $inventory = (string) ($row['inventory_code'] ?? '');
            $year = (int) ($row['year'] ?? 0);
            if ($inventory !== '' && ! MasterDataTransferContract::isAllowedInventoryCode($inventory)) {
                $errors[] = 'Produktionspreisliste außerhalb Spot-Scope: '.$inventory;
            }
            if ($inventory !== '' && ! in_array($inventory, $packageInventoryCodes, true)) {
                $errors[] = 'Produktionspreisliste referenziert Inventar außerhalb Paketdatei: '.$inventory;
            }
            if (! MasterDataTransferContract::isAllowedPriceYear($year)) {
                $errors[] = 'Produktionspreis-Jahr außerhalb Scope: '.$inventory.' / '.$year;
            }
        }

        if ($errors !== []) {
            throw new MasterDataTransferException('Paket verletzt Spot-Scope.', $errors);
        }
    }
}
