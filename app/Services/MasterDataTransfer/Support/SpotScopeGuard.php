<?php

namespace App\Services\MasterDataTransfer\Support;

use App\Services\MasterDataTransfer\MasterDataTransferContract;
use App\Services\MasterDataTransfer\MasterDataTransferException;

/**
 * Prüft Paketinhalt gegen den freigegebenen Spot-Scope (kein OA).
 */
final class SpotScopeGuard
{
    /**
     * @param  array<string, mixed>  $data  relative file => decoded payload
     */
    public function assertPackage(array $data): void
    {
        $errors = [];

        foreach ($data['data/advertising_categories.json'] ?? [] as $row) {
            $key = (string) ($row['key'] ?? '');
            if ($key !== '' && ! MasterDataTransferContract::isAllowedCategoryKey($key)) {
                $errors[] = 'Kategorie außerhalb Spot-Scope: '.$key;
            }
        }

        foreach ($data['data/advertising_media.json'] ?? [] as $row) {
            $code = (string) ($row['code'] ?? '');
            $categoryKey = (string) ($row['category_key'] ?? '');
            if ($code !== '' && MasterDataTransferContract::isExcludedMediumCode($code)) {
                $errors[] = 'Ausgeschlossenes Werbemittel im Paket: '.$code;
            }
            if ($categoryKey !== '' && ! MasterDataTransferContract::isAllowedCategoryKey($categoryKey)) {
                $errors[] = 'Werbemittel-Kategorie außerhalb Spot-Scope: '.$code.' → '.$categoryKey;
            }
        }

        foreach ($data['data/inventory_medium_rules.json'] ?? [] as $row) {
            $medium = (string) ($row['advertising_medium_code'] ?? '');
            if ($medium !== '' && MasterDataTransferContract::isExcludedMediumCode($medium)) {
                $errors[] = 'Regel mit ausgeschlossenem Medium: '.$medium;
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

        if ($errors !== []) {
            throw new MasterDataTransferException('Paket verletzt Spot-Scope.', $errors);
        }
    }
}
