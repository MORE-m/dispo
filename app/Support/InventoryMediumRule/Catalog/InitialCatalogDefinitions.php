<?php

namespace App\Support\InventoryMediumRule\Catalog;

use App\Enums\CalculationKind;
use App\Enums\InventoryType;
use App\Enums\SpotComponentProfile;
use App\Support\Advertising\CanonicalAdvertisingCategories;

/**
 * PO-MAT-CORE-CATALOG-1 / BL-P2-02c: verbindlicher Initialkatalog
 * (14 Inventare, 42 Werbemittel) für Matrix-Import-Vorbereitung.
 *
 * Keine erfundenen Werte außerhalb der freigegebenen Mapping-Tabelle.
 */
final class InitialCatalogDefinitions
{
    /**
     * @return list<array{name: string, code: string, type: InventoryType}>
     */
    public static function inventories(): array
    {
        return [
            ['name' => 'MORE Hamburg-Kombi', 'code' => 'inv_more_hamburg_kombi', 'type' => InventoryType::Kombi],
            ['name' => 'MORE Hamburg-Kombi+', 'code' => 'inv_more_hamburg_kombi_plus', 'type' => InventoryType::Kombi],
            ['name' => 'Radio Hamburg', 'code' => 'inv_radio_hamburg', 'type' => InventoryType::Sender],
            ['name' => 'ROCK ANTENNE Hamburg', 'code' => 'inv_rock_antenne_hamburg', 'type' => InventoryType::Sender],
            ['name' => '80er 90er OLDIE ANTENNE Hamburg', 'code' => 'inv_80er_90er_oldie_antenne_hamburg', 'type' => InventoryType::Sender],
            ['name' => 'CARAVAN.fm', 'code' => 'inv_caravan_fm', 'type' => InventoryType::Sender],
            ['name' => 'MORE-Kombi Online Audio', 'code' => 'inv_more_kombi_online_audio', 'type' => InventoryType::Kombi],
            ['name' => 'MORE-Kombi Podcast', 'code' => 'inv_more_kombi_podcast', 'type' => InventoryType::Kombi],
            ['name' => 'ffn Hamburg Plus', 'code' => 'inv_ffn_hamburg_plus', 'type' => InventoryType::Sender],
            ['name' => 'RADIO BOLLERWAGEN DAB+ Hamburg', 'code' => 'inv_radio_bollerwagen_dab_plus_hamburg', 'type' => InventoryType::Sender],
            ['name' => 'MORE-Kombi Events Radio Hamburg', 'code' => 'inv_more_kombi_events_radio_hamburg', 'type' => InventoryType::Kombi],
            ['name' => 'MORE-Kombi Events 80er 90er OLDIE ANTENNE Hamburg', 'code' => 'inv_more_kombi_events_80er_90er_oldie_antenne_hamburg', 'type' => InventoryType::Kombi],
            ['name' => 'MORE-Kombi Events CARAVAN.fm', 'code' => 'inv_more_kombi_events_caravan_fm', 'type' => InventoryType::Kombi],
            ['name' => 'MORE-Kombi Events ROCK ANTENNE Hamburg', 'code' => 'inv_more_kombi_events_rock_antenne_hamburg', 'type' => InventoryType::Kombi],
        ];
    }

    /**
     * @return list<array{
     *     name: string,
     *     code: string,
     *     category_key: string,
     *     kind: CalculationKind|null,
     *     component_profile: SpotComponentProfile|null,
     *     is_discountable: bool,
     *     is_ae_eligible: bool,
     *     default_length_seconds: int,
     *     sort: int
     * }>
     */
    public static function media(): array
    {
        $social = static fn (string $name, string $code, int $sort): array => [
            'name' => $name,
            'code' => $code,
            'category_key' => CanonicalAdvertisingCategories::SOCIAL_ONLINE,
            'kind' => null,
            'component_profile' => null,
            'is_discountable' => false,
            'is_ae_eligible' => false,
            'default_length_seconds' => 30,
            'sort' => $sort,
        ];

        $row = static function (
            string $name,
            string $code,
            string $categoryKey,
            int $sort,
            ?CalculationKind $kind = null,
            ?SpotComponentProfile $profile = null,
            bool $discountable = true,
            bool $ae = true,
        ): array {
            return [
                'name' => $name,
                'code' => $code,
                'category_key' => $categoryKey,
                'kind' => $kind,
                'component_profile' => $profile,
                'is_discountable' => $discountable,
                'is_ae_eligible' => $ae,
                'default_length_seconds' => 30,
                'sort' => $sort,
            ];
        };

        return [
            $row('Werbespot', 'spot_classic', CanonicalAdvertisingCategories::SPOTS, 10, CalculationKind::SpotClassic),
            $row('Werbespot erstplatziert', 'spot_first', CanonicalAdvertisingCategories::SPOTS, 20),
            $row('Werbespot letztplatziert', 'spot_last', CanonicalAdvertisingCategories::SPOTS, 30),
            $row('Single-Spot', 'spot_single', CanonicalAdvertisingCategories::SPOTS, 40),
            $row('Showsponsoring-Single-Spot', 'spot_showsponsoring_single', CanonicalAdvertisingCategories::SPOTS, 50),
            $row('Tandem / Reminder', 'spot_tandem', CanonicalAdvertisingCategories::SPOTS, 60, CalculationKind::SpotClassic, SpotComponentProfile::Tandem),
            $row('Tridem', 'spot_tridem', CanonicalAdvertisingCategories::SPOTS, 70, CalculationKind::SpotClassic, SpotComponentProfile::Tridem),
            $row('Jobspot', 'jobspot', CanonicalAdvertisingCategories::SPOTS, 80),
            $row('Gegengeschäft', 'barter', CanonicalAdvertisingCategories::BARTER, 90),
            $row('Promo/Moderation', 'promo_moderation', CanonicalAdvertisingCategories::SPECIAL_ADVERTISING_FORMATS, 100),
            $row('Event-Tipp', 'event_tip', CanonicalAdvertisingCategories::SPECIAL_ADVERTISING_FORMATS, 110),
            $row('Veranstaltungstipp', 'event_announcement', CanonicalAdvertisingCategories::SPECIAL_ADVERTISING_FORMATS, 120),
            $row('Preseller', 'preseller', CanonicalAdvertisingCategories::SPECIAL_ADVERTISING_FORMATS, 130),
            $row('Trailer/Vorpr. Element Station Voice', 'trailer_station_voice', CanonicalAdvertisingCategories::SPECIAL_ADVERTISING_FORMATS, 140),
            $row('Abbinder', 'abbinder', CanonicalAdvertisingCategories::SPECIAL_ADVERTISING_FORMATS, 150),
            $row('Allonge', 'allonge', CanonicalAdvertisingCategories::SPECIAL_ADVERTISING_FORMATS, 160),
            $row('Opener', 'opener', CanonicalAdvertisingCategories::SPECIAL_ADVERTISING_FORMATS, 170),
            $row('Bumper', 'bumper', CanonicalAdvertisingCategories::SPECIAL_ADVERTISING_FORMATS, 180),
            $row('Stinger', 'stinger', CanonicalAdvertisingCategories::SPECIAL_ADVERTISING_FORMATS, 190),
            $row('Closer', 'closer', CanonicalAdvertisingCategories::SPECIAL_ADVERTISING_FORMATS, 200),
            $row('Gewinnspiel/Pay-Off', 'contest_payoff', CanonicalAdvertisingCategories::SPECIAL_ADVERTISING_FORMATS, 210),
            $row('Sondersendung (4x90Sek)', 'special_broadcast', CanonicalAdvertisingCategories::SPECIAL_ADVERTISING_FORMATS, 220),
            $row('Influencer-Spot', 'influencer_spot', CanonicalAdvertisingCategories::SPECIAL_ADVERTISING_FORMATS, 230),
            $row('Influencer-Spot als Single-Spot', 'influencer_single_spot', CanonicalAdvertisingCategories::SPECIAL_ADVERTISING_FORMATS, 240),
            $row('Infomercial / Profi-Tipp', 'infomercial_professional_tip', CanonicalAdvertisingCategories::SPECIAL_ADVERTISING_FORMATS, 250),
            $row('Visual-Spot als Single-Spot', 'visual_single_spot', CanonicalAdvertisingCategories::SPECIAL_ADVERTISING_FORMATS, 260),
            $row('Visual-Spot mit .de-Nennung', 'visual_dot_de_spot', CanonicalAdvertisingCategories::SPECIAL_ADVERTISING_FORMATS, 270),
            $row('Presenting-Spot', 'presenting_spot', CanonicalAdvertisingCategories::SPECIAL_ADVERTISING_FORMATS, 280),
            $row('Online Anzeigencontainer', 'online_ad_container', CanonicalAdvertisingCategories::SPECIAL_ADVERTISING_FORMATS, 290),
            $social('Online Facebook', 'online_facebook', 300),
            $row('Online GWS', 'online_gws', CanonicalAdvertisingCategories::SPECIAL_ADVERTISING_FORMATS, 310),
            $social('Online Instagram', 'online_instagram', 320),
            $social('Online Instagram (Influencer)', 'online_instagram_influencer', 330),
            $row('Online Sondersendung', 'online_special_broadcast', CanonicalAdvertisingCategories::SPECIAL_ADVERTISING_FORMATS, 340),
            $social('Online TikTok', 'online_tiktok', 350),
            $row('Off-Air', 'off_air', CanonicalAdvertisingCategories::EVENTS_PROMOTION, 360),
            $row('Pre-Stream', 'pre_stream', CanonicalAdvertisingCategories::ONLINE_AUDIO, 370),
            $row('In-Stream', 'in_stream', CanonicalAdvertisingCategories::ONLINE_AUDIO, 380),
            $row('Pre-Stream Influencer', 'pre_stream_influencer', CanonicalAdvertisingCategories::ONLINE_AUDIO, 390),
            $row('In-Stream Influencer', 'in_stream_influencer', CanonicalAdvertisingCategories::ONLINE_AUDIO, 400),
            $row('Mid-Roll Spotify / Deezer / Youtube Musikumfeld', 'midroll_music_environment', CanonicalAdvertisingCategories::ONLINE_AUDIO, 410),
            $row('Native-Ad', 'native_ad', CanonicalAdvertisingCategories::ONLINE_AUDIO, 420),
        ];
    }
}
