<?php

namespace App\Support\Advertising;

use App\Enums\CalculationMethodMode;
use App\Models\AdvertisingCategoryCalculationMethod;
use App\Models\AdvertisingMedium;
use App\Models\AdvertisingMediumCalculationMethod;
use App\Models\CalculationMethod;

/**
 * ADV-001c4a: auswählbare Berechnungsmethoden eines Werbemittels.
 *
 * Selectability ausschließlich über {@see AdvertisingMediumLiveBookability}
 * (keine parallele Released-/Buchbarkeitsdefinition). Unabhängig vom Inventar.
 */
final class AdvertisingMediumCalculationMethodOptionsResolver
{
    public function __construct(
        private readonly AdvertisingMediumLiveBookability $liveBookability = new AdvertisingMediumLiveBookability,
    ) {}

    public function resolve(AdvertisingMedium $medium): AdvertisingMediumCalculationMethodOptions
    {
        $medium->loadMissing([
            'category',
            'defaultCalculationMethod',
            'category.defaultCalculationMethod',
            'calculationMethodAssignments.calculationMethod',
            'category.calculationMethodAssignments.calculationMethod',
        ]);

        $source = $medium->calculation_method_mode === CalculationMethodMode::Override
            ? AdvertisingMediumCalculationMethodOptions::SOURCE_MEDIUM_OVERRIDE
            : AdvertisingMediumCalculationMethodOptions::SOURCE_CATEGORY;

        $methods = [];
        $seen = [];

        foreach ($this->candidateAssignments($medium) as $candidate) {
            $method = $candidate['method'];
            $key = $method->key;
            if ($key === '' || isset($seen[$key])) {
                continue;
            }

            $evaluation = $this->liveBookability->evaluate($medium, $key);
            if (! $evaluation->isBookableForNewPositions) {
                continue;
            }

            $seen[$key] = true;
            $methods[] = [
                'key' => (string) $evaluation->calculationMethodKey,
                'name' => (string) $evaluation->calculationMethodName,
                'help_text' => $this->nullableHelpText($method->help_text),
                'is_default' => false,
            ];
        }

        $defaultKey = null;
        $defaultEvaluation = $this->liveBookability->evaluate($medium, null);
        if ($defaultEvaluation->isBookableForNewPositions) {
            $candidateDefault = (string) $defaultEvaluation->calculationMethodKey;
            foreach ($methods as $index => $row) {
                if ($row['key'] === $candidateDefault) {
                    $defaultKey = $candidateDefault;
                    $methods[$index]['is_default'] = true;
                    break;
                }
            }
        }

        return new AdvertisingMediumCalculationMethodOptions(
            mediumId: (int) $medium->id,
            source: $source,
            defaultCalculationMethodKey: $defaultKey,
            methods: $methods,
        );
    }

    /**
     * Deterministisch: Assignment-Sort ASC, bei Gleichstand Methoden-Key ASC.
     *
     * @return list<array{method: CalculationMethod, sort: int}>
     */
    private function candidateAssignments(AdvertisingMedium $medium): array
    {
        $rows = [];

        foreach ($this->effectiveAssignments($medium) as $assignment) {
            if (! $assignment->is_active) {
                continue;
            }

            $method = $assignment->calculationMethod;
            if ($method === null || ! $method->is_active) {
                continue;
            }

            $key = trim((string) $method->key);
            if ($key === '') {
                continue;
            }

            $rows[] = [
                'method' => $method,
                'sort' => (int) $assignment->sort,
            ];
        }

        usort(
            $rows,
            static function (array $left, array $right): int {
                $sort = $left['sort'] <=> $right['sort'];
                if ($sort !== 0) {
                    return $sort;
                }

                return strcmp($left['method']->key, $right['method']->key);
            },
        );

        return $rows;
    }

    /**
     * @return iterable<int, AdvertisingCategoryCalculationMethod|AdvertisingMediumCalculationMethod>
     */
    private function effectiveAssignments(AdvertisingMedium $medium): iterable
    {
        if ($medium->calculation_method_mode === CalculationMethodMode::Override) {
            yield from $medium->calculationMethodAssignments;

            return;
        }

        $category = $medium->category;
        if ($category === null) {
            return;
        }

        yield from $category->calculationMethodAssignments;
    }

    private function nullableHelpText(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $string = trim((string) $value);

        return $string === '' ? null : $string;
    }
}
