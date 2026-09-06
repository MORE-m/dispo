<?php

namespace App\Services\DynamicField\Admin;

use App\Models\FieldDefinition;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * DF-3.2a: Key aus Label (snake_case, ascii, ohne system_-Prefix, global unique).
 */
final class FieldKeySlugger
{
    public function slugFromLabel(string $label): string
    {
        $slug = Str::of($label)
            ->ascii()
            ->lower()
            ->replaceMatches('/[^a-z0-9]+/', '_')
            ->trim('_')
            ->toString();

        if ($slug === '') {
            throw ValidationException::withMessages([
                'label' => 'Aus dem Anzeigenamen konnte kein gültiger Feldschlüssel abgeleitet werden.',
            ]);
        }

        if (str_starts_with($slug, 'system_')) {
            throw ValidationException::withMessages([
                'label' => 'Der Feldschlüssel darf nicht mit „system_“ beginnen.',
            ]);
        }

        return $slug;
    }

    public function uniqueSlugFromLabel(string $label, ?int $exceptDefinitionId = null): string
    {
        $base = $this->slugFromLabel($label);
        $candidate = $base;
        $suffix = 2;

        while ($this->keyExists($candidate, $exceptDefinitionId)) {
            $candidate = $base.'_'.$suffix;
            $suffix++;
            if (str_starts_with($candidate, 'system_')) {
                throw ValidationException::withMessages([
                    'label' => 'Der Feldschlüssel darf nicht mit „system_“ beginnen.',
                ]);
            }
        }

        return $candidate;
    }

    public function assertKeyAllowed(string $key, ?int $exceptDefinitionId = null): void
    {
        if ($key === '' || ! preg_match('/^[a-z][a-z0-9_]*$/', $key)) {
            throw ValidationException::withMessages([
                'key' => 'Der Feldschlüssel muss mit einem Kleinbuchstaben beginnen und darf nur a–z, 0–9 und Unterstriche enthalten.',
            ]);
        }

        if (str_starts_with($key, 'system_')) {
            throw ValidationException::withMessages([
                'key' => 'Der Feldschlüssel darf nicht mit „system_“ beginnen.',
            ]);
        }

        if ($this->keyExists($key, $exceptDefinitionId)) {
            throw ValidationException::withMessages([
                'key' => 'Dieser Feldschlüssel ist bereits vergeben.',
            ]);
        }
    }

    private function keyExists(string $key, ?int $exceptDefinitionId): bool
    {
        $query = FieldDefinition::query()->where('key', $key);
        if ($exceptDefinitionId !== null) {
            $query->where('id', '!=', $exceptDefinitionId);
        }

        return $query->exists();
    }
}
