<?php

namespace App\Support\Calculation;

use Illuminate\Validation\ValidationException;

/**
 * ADV-001c4a: presence-aware Normalisierung von calculation_method_key / spot_method.
 *
 * Fehlende Eigenschaft ≠ explizit null/leer.
 */
final class CalculationPositionMethodKeyNormalizer
{
    public const CONFLICT_MESSAGE = 'Die übermittelten Berechnungsmethoden widersprechen sich. Bitte laden Sie die Kalkulation neu und wählen Sie die Methode erneut.';

    public const EMPTY_KEY_MESSAGE = 'Die Berechnungsmethode darf nicht leer sein.';

    /**
     * @param  array<string, mixed>  $position
     * @return array{present: bool, key: string|null}
     */
    public function normalize(array $position): array
    {
        $keyPresent = array_key_exists('calculation_method_key', $position);
        $spotPresent = array_key_exists('spot_method', $position);

        $keyRaw = $keyPresent ? $position['calculation_method_key'] : null;
        $spotRaw = $spotPresent ? $position['spot_method'] : null;

        if ($keyPresent) {
            if ($keyRaw === null || $this->isBlank($keyRaw)) {
                throw ValidationException::withMessages([
                    'positions' => self::EMPTY_KEY_MESSAGE,
                ]);
            }

            $key = trim((string) $keyRaw);

            if ($spotPresent && ! $this->isBlank($spotRaw)) {
                $spotKey = trim((string) $spotRaw);
                if ($spotKey !== $key) {
                    throw ValidationException::withMessages([
                        'positions' => self::CONFLICT_MESSAGE,
                    ]);
                }
            }

            return ['present' => true, 'key' => $key];
        }

        if ($spotPresent) {
            // Explizit null/leer: Legacy-Vertrag = kein Methodenschlüssel (Default/Freeze-Erhalt).
            if ($this->isBlank($spotRaw)) {
                return ['present' => false, 'key' => null];
            }

            return ['present' => true, 'key' => trim((string) $spotRaw)];
        }

        return ['present' => false, 'key' => null];
    }

    private function isBlank(mixed $value): bool
    {
        if ($value === null) {
            return true;
        }

        return trim((string) $value) === '';
    }
}
