<?php

namespace App\Support\Advertising;

/**
 * ADV-001a: kanonische Oberkategorien (stabile technische Keys).
 * Keys sind Schnittstellenbestandteil und dürfen nicht beiläufig umbenannt werden.
 *
 * Historische Migrationsdaten leben bewusst separat in der ADV-001a-Migration
 * und werden nicht aus dieser Klasse gelesen.
 */
final class CanonicalAdvertisingCategories
{
    public const SPOTS = 'spots';

    public const SPECIAL_ADVERTISING_FORMATS = 'special_advertising_formats';

    public const ONLINE_AUDIO = 'online_audio';

    public const SOCIAL_ONLINE = 'social_online';

    public const EVENTS_PROMOTION = 'events_promotion';

    public const BARTER = 'barter';

    /**
     * @return list<array{key: string, name: string, sort: int}>
     */
    public static function definitions(): array
    {
        return [
            ['key' => self::SPOTS, 'name' => 'Spots', 'sort' => 10],
            ['key' => self::SPECIAL_ADVERTISING_FORMATS, 'name' => 'SWF / Sonderwerbeformen', 'sort' => 20],
            ['key' => self::ONLINE_AUDIO, 'name' => 'Online Audio', 'sort' => 30],
            ['key' => self::SOCIAL_ONLINE, 'name' => 'Social Media / Online', 'sort' => 40],
            ['key' => self::EVENTS_PROMOTION, 'name' => 'Events / Promotion', 'sort' => 50],
            ['key' => self::BARTER, 'name' => 'Gegengeschäft', 'sort' => 60],
        ];
    }

    /**
     * @return list<string>
     */
    public static function keys(): array
    {
        return array_column(self::definitions(), 'key');
    }
}
