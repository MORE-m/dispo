<?php

namespace App\Services\Calculation;

use App\Enums\DiscountType;
use Illuminate\Validation\ValidationException;

final class DiscountValidator
{
    /**
     * @param  array<int|string, mixed>  $discounts
     * @return list<array{type: string, custom_label: string|null, percent: string, sort: int}>
     */
    public function validated(array $discounts, string $prefix): array
    {
        $complete = [];
        $errors = [];

        foreach ($discounts as $index => $discount) {
            if (! is_array($discount)) {
                continue;
            }

            $typeValue = $discount['type'] ?? null;
            $percent = $discount['percent'] ?? null;
            $customLabel = isset($discount['custom_label']) ? trim((string) $discount['custom_label']) : '';

            $empty = ($typeValue === null || $typeValue === '')
                && ($percent === null || $percent === '')
                && $customLabel === '';

            if ($empty) {
                continue;
            }

            $field = $prefix.'.'.$index;
            $type = is_string($typeValue) ? DiscountType::tryFrom($typeValue) : null;
            $valid = true;

            if ($type === null) {
                $errors[$field.'.type'] = ['Rabattart ist erforderlich.'];
                $valid = false;
            }

            if ($type?->requiresCustomLabel() && $customLabel === '') {
                $errors[$field.'.custom_label'] = ['Eigene Bezeichnung ist bei „Sonstiger Rabatt“ erforderlich.'];
                $valid = false;
            }

            if ($percent === null || $percent === '' || ! is_numeric($percent)) {
                $errors[$field.'.percent'] = ['Prozentsatz muss größer als 0 und höchstens 100 sein.'];
                $valid = false;
            } else {
                $numeric = (string) $percent;
                if (Decimal::cmp($numeric, '0') <= 0 || Decimal::cmp($numeric, '100') > 0) {
                    $errors[$field.'.percent'] = ['Prozentsatz muss größer als 0 und höchstens 100 sein.'];
                    $valid = false;
                }
            }

            if (! $valid) {
                continue;
            }

            $complete[] = [
                'type' => $type->value,
                'custom_label' => $type->requiresCustomLabel() ? $customLabel : null,
                'percent' => $numeric ?? (string) $percent,
                'sort' => count($complete),
            ];
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return $complete;
    }
}
