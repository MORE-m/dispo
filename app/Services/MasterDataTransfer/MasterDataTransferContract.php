<?php

namespace App\Services\MasterDataTransfer;

use App\Support\Advertising\CanonicalAdvertisingCategories;
use App\Support\OnlineAudio\OaTkpCoreMedia;

/**
 * EPIC-03-B: verbindlicher Scope und Formatvertrag für den selektiven Stammdaten-Transfer.
 */
final class MasterDataTransferContract
{
    public const FORMAT = 'dispo-master-data-transfer';

    public const SCHEMA_VERSION = '1.0.0';

    public const EPIC = 'EPIC-03-B';

    /** @var list<int> */
    public const PRICE_YEARS = [2026, 2027];

    /**
     * Acht Inventare mit aktiven Spotpreislisten 2026/2027 (EPIC-03-A / PO).
     *
     * @var list<string>
     */
    public const SPOT_INVENTORY_CODES = [
        'inv_more_hamburg_kombi',
        'inv_more_hamburg_kombi_plus',
        'inv_radio_hamburg',
        'inv_rock_antenne_hamburg',
        'inv_80er_90er_oldie_antenne_hamburg',
        'inv_caravan_fm',
        'inv_ffn_hamburg_plus',
        'inv_radio_bollerwagen_dab_plus_hamburg',
    ];

    /**
     * Erlaubte Oberkategorien im Spot-Transfer.
     *
     * Nur Spots + SWF: Social/Events/Barter sind nicht Teil des PO-Scopes EPIC-03-B
     * (Stufe 3 / separate Readiness), auch wenn lokal Matrix-Regeln auf den acht
     * Spot-Inventaren existieren.
     *
     * @var list<string>
     */
    public const ALLOWED_CATEGORY_KEYS = [
        CanonicalAdvertisingCategories::SPOTS,
        CanonicalAdvertisingCategories::SPECIAL_ADVERTISING_FORMATS,
    ];

    /**
     * Engine-Profile, die Online-Audio betreffen und ausgeschlossen sind.
     *
     * @var list<string>
     */
    public const EXCLUDED_ENGINE_PROFILE_KEYS = [
        'oa_tkp',
    ];

    /**
     * @var list<string>
     */
    public const DATA_FILES = [
        'data/organization.json',
        'data/advertising_categories.json',
        'data/calculation_methods.json',
        'data/inventories.json',
        'data/advertising_media.json',
        'data/inventory_medium_rules.json',
        'data/advertising_category_calculation_methods.json',
        'data/advertising_medium_calculation_methods.json',
        'data/price_lists.json',
        'data/production_price_lists.json',
    ];

    public const MANIFEST_FILE = 'manifest.json';

    public const CHECKSUMS_FILE = 'checksums.sha256';

    public static function isExcludedMediumCode(string $code): bool
    {
        return OaTkpCoreMedia::contains($code) || $code === 'native_ad';
    }

    public static function isAllowedCategoryKey(string $key): bool
    {
        return in_array($key, self::ALLOWED_CATEGORY_KEYS, true);
    }

    public static function isAllowedInventoryCode(string $code): bool
    {
        return in_array($code, self::SPOT_INVENTORY_CODES, true);
    }

    public static function isAllowedPriceYear(int $year): bool
    {
        return in_array($year, self::PRICE_YEARS, true);
    }

    public static function isExcludedEngineProfile(?string $key): bool
    {
        return $key !== null && $key !== '' && in_array($key, self::EXCLUDED_ENGINE_PROFILE_KEYS, true);
    }

    /**
     * @param  list<string>  $codes
     * @return list<string> Codes außerhalb des freigegebenen Spot-Scopes
     */
    public static function inventoryCodesOutsideScope(array $codes): array
    {
        $outside = [];
        foreach ($codes as $code) {
            $normalized = (string) $code;
            if ($normalized === '' || self::isAllowedInventoryCode($normalized)) {
                continue;
            }
            $outside[] = $normalized;
        }

        return array_values(array_unique($outside));
    }
}
