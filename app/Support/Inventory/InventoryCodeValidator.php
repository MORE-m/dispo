<?php

namespace App\Support\Inventory;

use Illuminate\Validation\ValidationException;

/**
 * BL-P2-01a: Inventarcodes analog bestehender Kurzcodes (RH, RAH, OAH).
 * Kein snake_case-Zwang – das Katalog-Key-Muster gilt hier nicht.
 */
final class InventoryCodeValidator
{
    public const MAX_LENGTH = 64;

    public const PATTERN = '/^[A-Za-z][A-Za-z0-9_-]{0,63}$/';

    public static function assertValid(string $value): string
    {
        $trimmed = trim($value);
        if ($trimmed === '') {
            throw ValidationException::withMessages([
                'code' => 'Der Kurzcode ist erforderlich.',
            ]);
        }

        if (strlen($trimmed) > self::MAX_LENGTH) {
            throw ValidationException::withMessages([
                'code' => 'Der Kurzcode darf maximal '.self::MAX_LENGTH.' Zeichen lang sein.',
            ]);
        }

        if (! preg_match(self::PATTERN, $trimmed)) {
            throw ValidationException::withMessages([
                'code' => 'Der Kurzcode muss mit einem Buchstaben beginnen und darf nur Buchstaben, Ziffern, Unterstriche und Bindestriche enthalten.',
            ]);
        }

        return $trimmed;
    }
}
