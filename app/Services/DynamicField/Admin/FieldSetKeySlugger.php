<?php

namespace App\Services\DynamicField\Admin;

use App\Models\FieldSet;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * DF-3.3-fs: Key aus Name (snake_case, ascii, ohne system_-Prefix, global unique).
 */
final class FieldSetKeySlugger
{
    public function slugFromName(string $name): string
    {
        $slug = Str::of($name)
            ->ascii()
            ->lower()
            ->replaceMatches('/[^a-z0-9]+/', '_')
            ->trim('_')
            ->toString();

        if ($slug === '') {
            throw ValidationException::withMessages([
                'name' => 'Aus dem Namen konnte kein gültiger Feldset-Schlüssel abgeleitet werden.',
            ]);
        }

        if (str_starts_with($slug, 'system_')) {
            throw ValidationException::withMessages([
                'name' => 'Der Feldset-Schlüssel darf nicht mit „system_“ beginnen.',
            ]);
        }

        return $slug;
    }

    public function uniqueSlugFromName(string $name, ?int $exceptFieldSetId = null): string
    {
        $base = $this->slugFromName($name);
        $candidate = $base;
        $suffix = 2;

        while ($this->keyExists($candidate, $exceptFieldSetId)) {
            $candidate = $base.'_'.$suffix;
            $suffix++;
            if (str_starts_with($candidate, 'system_')) {
                throw ValidationException::withMessages([
                    'name' => 'Der Feldset-Schlüssel darf nicht mit „system_“ beginnen.',
                ]);
            }
            if (strlen($candidate) > 64) {
                throw ValidationException::withMessages([
                    'name' => 'Aus dem Namen konnte kein eindeutiger Feldset-Schlüssel (max. 64 Zeichen) abgeleitet werden.',
                ]);
            }
        }

        return $candidate;
    }

    public function assertKeyAllowed(string $key, ?int $exceptFieldSetId = null): void
    {
        if ($key === '' || ! preg_match('/^[a-z][a-z0-9_]*$/', $key)) {
            throw ValidationException::withMessages([
                'key' => 'Der Feldset-Schlüssel muss mit einem Kleinbuchstaben beginnen und darf nur a–z, 0–9 und Unterstriche enthalten.',
            ]);
        }

        if (strlen($key) > 64) {
            throw ValidationException::withMessages([
                'key' => 'Der Feldset-Schlüssel darf höchstens 64 Zeichen lang sein.',
            ]);
        }

        if (str_starts_with($key, 'system_')) {
            throw ValidationException::withMessages([
                'key' => 'Der Feldset-Schlüssel darf nicht mit „system_“ beginnen.',
            ]);
        }

        if ($this->keyExists($key, $exceptFieldSetId)) {
            throw ValidationException::withMessages([
                'key' => 'Dieser Feldset-Schlüssel ist bereits vergeben.',
            ]);
        }
    }

    private function keyExists(string $key, ?int $exceptFieldSetId): bool
    {
        $query = FieldSet::query()->where('key', $key);
        if ($exceptFieldSetId !== null) {
            $query->where('id', '!=', $exceptFieldSetId);
        }

        return $query->exists();
    }
}
