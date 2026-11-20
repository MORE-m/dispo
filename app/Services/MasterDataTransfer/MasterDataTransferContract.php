<?php

namespace App\Services\MasterDataTransfer;

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
     * Engine-Profile, die Online-Audio betreffen und in diesem Slice nicht importiert werden.
     *
     * @var list<string>
     */
    public const EXCLUDED_ENGINE_PROFILE_KEYS = [
        'oa_tkp',
    ];

    /**
     * Dateien im Paket (relativ zum Paketroot).
     *
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
}
