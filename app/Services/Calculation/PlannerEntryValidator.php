<?php

namespace App\Services\Calculation;

use Illuminate\Validation\ValidationException;

final class PlannerEntryValidator
{
    /**
     * @param  array<int|string, mixed>  $entries
     * @return list<array{date: string, hour: int, spot_count: int, sort: int}>
     */
    public function validated(
        array $entries,
        string $prefix,
        bool $requireAtLeastOne = true,
    ): array {
        $complete = [];
        $errors = [];
        $seen = [];

        foreach ($entries as $index => $entry) {
            if (! is_array($entry)) {
                continue;
            }

            $date = $entry['date'] ?? null;
            $hour = $entry['hour'] ?? null;
            $spots = $entry['spot_count'] ?? null;

            $empty = $this->isEmptyValue($date)
                && $this->isEmptyValue($hour)
                && $this->isEmptyValue($spots);

            if ($empty) {
                continue;
            }

            $field = $prefix.'.'.$index;
            $valid = true;

            if ($this->isEmptyValue($date) || ! is_string($date) || ! $this->isValidDate($date)) {
                $errors[$field.'.date'] = ['Datum ist erforderlich und muss im Format JJJJ-MM-TT vorliegen.'];
                $valid = false;
            }

            if ($this->isEmptyValue($hour) || ! $this->isWholeNumber($hour) || (int) $hour < 0 || (int) $hour > 23) {
                $errors[$field.'.hour'] = ['Preisstunde ist erforderlich und muss zwischen 0 und 23 liegen.'];
                $valid = false;
            }

            if ($this->isEmptyValue($spots)) {
                $errors[$field.'.spot_count'] = ['Die Spotanzahl ist erforderlich und muss mindestens 1 betragen.'];
                $valid = false;
            } elseif (! $this->isWholeNumber($spots)) {
                $errors[$field.'.spot_count'] = ['Die Spotanzahl muss eine ganze Zahl sein.'];
                $valid = false;
            } elseif ((int) $spots < 0) {
                $errors[$field.'.spot_count'] = ['Die Spotanzahl darf nicht negativ sein.'];
                $valid = false;
            } elseif ((int) $spots === 0) {
                continue;
            }

            if (! $valid) {
                continue;
            }

            $dateString = (string) $date;
            $hourInt = (int) $hour;
            $duplicateKey = $dateString.'|'.$hourInt;

            if (isset($seen[$duplicateKey])) {
                $errors[$field.'.date'] = ['Datum und Stunde dürfen pro Position nur einmal vorkommen.'];

                continue;
            }

            $seen[$duplicateKey] = true;

            $complete[] = [
                'date' => $dateString,
                'hour' => $hourInt,
                'spot_count' => (int) $spots,
                'sort' => count($complete),
            ];
        }

        if ($requireAtLeastOne && $complete === [] && $errors === []) {
            $errors[$prefix] = ['Mindestens ein vollständiger Kalendereintrag mit mindestens einem Spot ist erforderlich.'];
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

    private function isValidDate(string $date): bool
    {
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) !== 1) {
            return false;
        }

        $parts = explode('-', $date);

        return checkdate((int) $parts[1], (int) $parts[2], (int) $parts[0]);
    }
}
