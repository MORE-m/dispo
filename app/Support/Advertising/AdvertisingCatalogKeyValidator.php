<?php

namespace App\Support\Advertising;

use Illuminate\Validation\ValidationException;

/**
 * ADV-001b: technische Keys/Codes (snake_case, max. 64) – gleiches Muster wie Feld-Keys.
 */
final class AdvertisingCatalogKeyValidator
{
    public const MAX_LENGTH = 64;

    public const PATTERN = '/^[a-z][a-z0-9_]*$/';

    public static function assertValid(string $value, string $field): void
    {
        $trimmed = trim($value);
        if ($trimmed === '') {
            throw ValidationException::withMessages([
                $field => 'Der technische Wert ist erforderlich.',
            ]);
        }

        if (strlen($trimmed) > self::MAX_LENGTH) {
            throw ValidationException::withMessages([
                $field => 'Maximal '.self::MAX_LENGTH.' Zeichen erlaubt.',
            ]);
        }

        if (! preg_match(self::PATTERN, $trimmed)) {
            throw ValidationException::withMessages([
                $field => 'Nur Kleinbuchstaben, Ziffern und Unterstriche; muss mit einem Buchstaben beginnen.',
            ]);
        }
    }
}
