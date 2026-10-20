<?php

namespace App\Support\OnlineAudio;

/**
 * PO-CALC-TKP-1A: kanonischer Scope der TKP-Kernmedien (Assignment + Import).
 *
 * native_ad bleibt OUT (FD-NATIVE). Keine Engine-Runtime-Verzweigung hier.
 */
final class OaTkpCoreMedia
{
    /** @var list<string> */
    public const CODES = [
        'pre_stream',
        'in_stream',
        'pre_stream_influencer',
        'in_stream_influencer',
        'midroll_music_environment',
    ];

    /**
     * @return list<string>
     */
    public static function codes(): array
    {
        return self::CODES;
    }

    public static function contains(string $code): bool
    {
        return in_array($code, self::CODES, true);
    }
}
