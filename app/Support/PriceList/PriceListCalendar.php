<?php

namespace App\Support\PriceList;

use Carbon\CarbonInterface;

/**
 * Kalenderjahr für Preislisten-Vorauswahl (Europe/Berlin).
 */
final class PriceListCalendar
{
    public const TIMEZONE = 'Europe/Berlin';

    public static function currentYear(?CarbonInterface $now = null): int
    {
        $clock = $now ?? now();

        return (int) $clock->copy()->timezone(self::TIMEZONE)->year;
    }
}
