<?php

namespace App\Services\Calculation;

/**
 * Exklusive Endstunde: 08:00–18:00 umfasst die Preisstunden 8 bis 17 (bis 17:59).
 */
final class TimeRangeHours
{
    /**
     * @return list<int>
     */
    public static function expand(int $startHour, int $endHourExclusive): array
    {
        if ($endHourExclusive <= $startHour) {
            return [];
        }

        $hours = [];
        for ($hour = $startHour; $hour < $endHourExclusive; $hour++) {
            $hours[] = $hour;
        }

        return $hours;
    }

    public static function overlaps(
        int $startA,
        int $endExclusiveA,
        int $startB,
        int $endExclusiveB,
    ): bool {
        return $startA < $endExclusiveB && $startB < $endExclusiveA;
    }

    public static function formatHour(int $hour): string
    {
        return sprintf('%02d:00', $hour);
    }

    public static function formatInclusiveEnd(int $endHourExclusive): string
    {
        if ($endHourExclusive < 1) {
            return '00:00';
        }

        return sprintf('%02d:59', $endHourExclusive - 1);
    }

    public static function hint(int $startHour, int $endHourExclusive): string
    {
        return sprintf(
            'Berechnet werden die Preisstunden %s bis %s Uhr.',
            self::formatHour($startHour),
            self::formatInclusiveEnd($endHourExclusive),
        );
    }
}
