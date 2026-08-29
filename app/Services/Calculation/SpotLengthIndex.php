<?php

namespace App\Services\Calculation;

use InvalidArgumentException;

/**
 * SPT-009, SPT-015
 */
final class SpotLengthIndex
{
    public static function forSeconds(int $seconds): int
    {
        if ($seconds < 1) {
            throw new InvalidArgumentException('Spotlänge muss mindestens 1 Sekunde betragen.');
        }

        return match (true) {
            $seconds <= 15 => 110,
            $seconds <= 24 => 105,
            $seconds <= 34 => 100,
            default => 95,
        };
    }
}
