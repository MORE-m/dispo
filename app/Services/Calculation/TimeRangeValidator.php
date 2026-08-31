<?php

namespace App\Services\Calculation;

use App\Enums\DayGroup;
use Illuminate\Validation\ValidationException;

final class TimeRangeValidator
{
    /**
     * @param  array<int|string, mixed>  $ranges
     * @return list<array{start_hour: int, end_hour_exclusive: int, day_group: string, spot_count: int, sort: int}>
     */
    public function validated(
        array $ranges,
        string $prefix,
        bool $requireAtLeastOne,
        bool $requireSpotCount = true,
    ): array {
        $complete = [];
        $errors = [];

        foreach ($ranges as $index => $range) {
            if (! is_array($range)) {
                continue;
            }

            $start = $range['start_hour'] ?? null;
            $end = $range['end_hour_exclusive'] ?? null;
            $dayGroup = $range['day_group'] ?? null;
            $spots = $range['spot_count'] ?? null;

            $empty = $this->isEmptyValue($start)
                && $this->isEmptyValue($end)
                && $this->isEmptyValue($dayGroup)
                && $this->isEmptyValue($spots);

            if ($empty) {
                continue;
            }

            $field = $prefix.'.'.$index;
            $valid = true;

            if ($this->isEmptyValue($start) || ! $this->isWholeNumber($start) || (int) $start < 0 || (int) $start > 23) {
                $errors[$field.'.start_hour'] = ['Beginn ist erforderlich und muss zwischen 00:00 und 23:00 liegen.'];
                $valid = false;
            }

            if ($this->isEmptyValue($end) || ! $this->isWholeNumber($end) || (int) $end < 1 || (int) $end > 24) {
                $errors[$field.'.end_hour_exclusive'] = ['Ende ist erforderlich und muss zwischen 01:00 und 24:00 liegen.'];
                $valid = false;
            }

            if ($this->isEmptyValue($dayGroup) || DayGroup::tryFrom((string) $dayGroup) === null) {
                $errors[$field.'.day_group'] = ['Tagesgruppe ist erforderlich.'];
                $valid = false;
            }

            if ($requireSpotCount) {
                if ($this->isEmptyValue($spots) || ! $this->isWholeNumber($spots) || (int) $spots < 1) {
                    $errors[$field.'.spot_count'] = ['Spotanzahl muss eine ganze Zahl von mindestens 1 sein.'];
                    $valid = false;
                }
            } elseif (! $this->isEmptyValue($spots) && (! $this->isWholeNumber($spots) || (int) $spots < 0)) {
                $errors[$field.'.spot_count'] = ['Spotanzahl muss eine ganze Zahl von mindestens 0 sein.'];
                $valid = false;
            }

            if ($valid && (int) $end <= (int) $start) {
                $errors[$field.'.end_hour_exclusive'] = ['Das Ende muss nach dem Beginn liegen.'];
                $valid = false;
            }

            if (! $valid) {
                continue;
            }

            $complete[] = [
                'start_hour' => (int) $start,
                'end_hour_exclusive' => (int) $end,
                'day_group' => (string) $dayGroup,
                'spot_count' => $requireSpotCount ? (int) $spots : max(0, (int) ($spots ?? 0)),
                'sort' => count($complete),
            ];
        }

        if ($requireAtLeastOne && $complete === [] && $errors === []) {
            $errors[$prefix] = $requireSpotCount
                ? ['Mindestens ein vollständiger Preiszeitraum mit mindestens einem Spot ist erforderlich.']
                : ['Mindestens ein vollständiger Verteilungszeitraum ist erforderlich.'];
        }

        foreach ($complete as $leftIndex => $left) {
            foreach ($complete as $rightIndex => $right) {
                if ($rightIndex <= $leftIndex || $left['day_group'] !== $right['day_group']) {
                    continue;
                }

                if (TimeRangeHours::overlaps(
                    $left['start_hour'],
                    $left['end_hour_exclusive'],
                    $right['start_hour'],
                    $right['end_hour_exclusive'],
                )) {
                    $errors[$prefix.'.'.$rightIndex.'.start_hour'] = [
                        'Zeiträume derselben Tagesgruppe dürfen sich nicht überschneiden. Direkt angrenzende Zeiträume sind erlaubt.',
                    ];
                }
            }
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return $complete;
    }

    private function isEmptyValue(mixed $value): bool
    {
        return $value === null || $value === '';
    }

    private function isWholeNumber(mixed $value): bool
    {
        if (is_int($value)) {
            return true;
        }

        if (is_string($value) && preg_match('/^-?\d+$/', trim($value)) === 1) {
            return true;
        }

        return is_float($value) && $value === floor($value) && (string) $value === (string) (int) $value;
    }
}
