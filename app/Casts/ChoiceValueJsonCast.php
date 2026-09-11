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
 * Der Cast kennt keinen FieldType und rät nicht: zulässig sind ausschließlich
 * SQL-NULL, JSON-String (Select-Key) oder JSON-Array aus Strings (Multi).
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

        // Driver (MySQL/MariaDB) können JSON bereits als PHP-Wert liefern.
        if (is_string($value)) {
            try {
                $decoded = json_decode($value, true, 512, JSON_THROW_ON_ERROR);
            } catch (JsonException $exception) {
                throw new InvalidArgumentException(
                    "Ungültiges JSON in {$key}.",
                    0,
                    $exception,
                );
            }

            return self::assertDecodableChoicePayload($decoded, $key);
        }

        return self::assertDecodableChoicePayload($value, $key);
    }

    public function set(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        if ($value === null) {
            return null;
        }

        $payload = self::assertDecodableChoicePayload($value, $key);

        try {
            return json_encode(
                $payload,
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

    /**
     * @return string|list<string>
     */
    private static function assertDecodableChoicePayload(mixed $value, string $key): string|array
    {
        if (is_string($value)) {
            return $value;
        }

        if (! is_array($value)) {
            throw new InvalidArgumentException(
                "{$key} muss null, ein JSON-String oder ein JSON-Array aus Strings sein.",
            );
        }

        if (! array_is_list($value)) {
            throw new InvalidArgumentException(
                "{$key} darf kein assoziatives JSON-Objekt sein.",
            );
        }

        foreach ($value as $item) {
            if (! is_string($item)) {
                throw new InvalidArgumentException(
                    "{$key} darf nur String-Elemente im JSON-Array enthalten.",
                );
            }
        }

        /** @var list<string> $value */
        return $value;
    }
}
