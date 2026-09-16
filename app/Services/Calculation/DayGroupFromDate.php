<?php

namespace App\Services\Calculation;

use App\Enums\DayGroup;
use DateTimeImmutable;
use DateTimeZone;

/**
 * SPT-005–SPT-008: Tagesgruppe aus Kalenderdatum (Europe/Berlin).
 */
final class DayGroupFromDate
{
    public const string TIMEZONE = 'Europe/Berlin';

    public static function resolve(string $date): DayGroup
    {
        $local = new DateTimeImmutable($date, new DateTimeZone(self::TIMEZONE));
        $weekday = (int) $local->format('N');

        return match ($weekday) {
            6 => DayGroup::Sa,
            7 => DayGroup::So,
            default => DayGroup::MoFr,
        };
    }
}
