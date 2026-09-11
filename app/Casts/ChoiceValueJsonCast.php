<?php

namespace App\Casts;

use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;
use JsonException;

/**
 * DF-3-REST-C1: JSON-Codec für Choice-Werte (Select = JSON-String, Multi = JSON-Array).
 * Kein Array-Zwang wie beim Eloquent-Cast `array`.
 *
 * @implements CastsAttributes<string|list<string>|null, string|list<string>|null>
 */
final class ChoiceValueJsonCast implements CastsAttributes
{
    public function get(Model $model, string $key, mixed $value, array $attributes): mixed
    {
        if ($value === null) {
            return null;
        }

        if (! is_string($value)) {
            return $value;
        }

        try {
            return json_decode($value, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new InvalidArgumentException(
                "Ungültiges JSON in {$key}.",
                0,
                $exception,
            );
        }
    }

    public function set(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        if ($value === null) {
            return null;
        }

        try {
            return json_encode(
                $value,
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
            );
        } catch (JsonException $exception) {
            throw new InvalidArgumentException(
                "Wert für {$key} ist nicht JSON-kodierbar.",
                0,
                $exception,
            );
        }
    }
}
