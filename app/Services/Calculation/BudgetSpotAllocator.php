<?php

namespace App\Services\Calculation;

/**
 * Deterministische gleichmäßige Spotverteilung über sortierte Stunden-Buckets.
 *
 * Der Budgetvorschlag ist preis- und verteilungsbasiert, nicht reichweitenoptimiert.
 */
final class BudgetSpotAllocator
{
    public const ALGORITHM_VERSION = '1.0.0';

    public const DISTRIBUTION_STRATEGY = 'even_distribution';

    /**
     * Verteilt Spots gleichmäßig auf Bucket-Indizes.
     *
     * @return list<int>
     */
    public function distribute(int $spotCount, int $bucketCount): array
    {
        if ($bucketCount <= 0) {
            return [];
        }

        if ($spotCount <= 0) {
            return array_fill(0, $bucketCount, 0);
        }

        $counts = array_fill(0, $bucketCount, 0);

        if ($spotCount < $bucketCount) {
            for ($index = 0; $index < $spotCount; $index++) {
                $bucketIndex = intdiv($index * $bucketCount, $spotCount);
                $counts[$bucketIndex]++;
            }

            return array_values($counts);
        }

        $base = intdiv($spotCount, $bucketCount);
        $remainder = $spotCount % $bucketCount;

        for ($index = 0; $index < $bucketCount; $index++) {
            $counts[$index] = $base;
        }

        if ($remainder > 0) {
            for ($rest = 0; $rest < $remainder; $rest++) {
                $bucketIndex = intdiv($rest * $bucketCount, $remainder);
                $counts[$bucketIndex]++;
            }
        }

        return array_values($counts);
    }
}
