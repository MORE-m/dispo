<?php

namespace App\Services\DynamicField\Admin;

use App\Models\FieldSet;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * DF-3.3-fs: Key aus Name (snake_case, ascii, ohne system_-Prefix, global unique, max. 64).
 */
final class FieldSetKeySlugger
{
    public const MAX_KEY_LENGTH = 64;

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
        $raw = $this->slugFromName($name);
        $base = $this->truncateToMaxLength($raw, self::MAX_KEY_LENGTH);
        $candidate = $base;
        $suffix = 2;

        while ($this->keyExists($candidate, $exceptFieldSetId)) {
            $suffixPart = '_'.$suffix;
            $maxBaseLength = self::MAX_KEY_LENGTH - strlen($suffixPart);
            if ($maxBaseLength < 1) {
                throw ValidationException::withMessages([
                    'name' => 'Aus dem Namen konnte kein eindeutiger Feldset-Schlüssel (max. 64 Zeichen) abgeleitet werden.',
                ]);
            }

            $candidate = $this->truncateToMaxLength($raw, $maxBaseLength).$suffixPart;
            $this->assertGeneratedCandidateShape($candidate);
            $suffix++;

            if ($suffix > 10_000) {
                throw ValidationException::withMessages([
                    'name' => 'Aus dem Namen konnte kein eindeutiger Feldset-Schlüssel (max. 64 Zeichen) abgeleitet werden.',
                ]);
            }
        }

        $this->assertGeneratedCandidateShape($candidate);

        return $candidate;
    }

    public function assertKeyAllowed(string $key, ?int $exceptFieldSetId = null): void
    {
        if ($key === '' || ! preg_match('/^[a-z][a-z0-9_]*$/', $key)) {
            throw ValidationException::withMessages([
                'key' => 'Der Feldset-Schlüssel muss mit einem Kleinbuchstaben beginnen und darf nur a–z, 0–9 und Unterstriche enthalten.',
            ]);
        }

        if (strlen($key) > self::MAX_KEY_LENGTH) {
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

    private function truncateToMaxLength(string $slug, int $maxLength): string
    {
        if ($maxLength < 1) {
            throw ValidationException::withMessages([
                'name' => 'Aus dem Namen konnte kein gültiger Feldset-Schlüssel (max. 64 Zeichen) abgeleitet werden.',
            ]);
        }

        if (strlen($slug) <= $maxLength) {
            $this->assertGeneratedCandidateShape($slug);

            return $slug;
        }

        $truncated = rtrim(substr($slug, 0, $maxLength), '_');
        if ($truncated === '' || ! preg_match('/^[a-z]/', $truncated)) {
            throw ValidationException::withMessages([
                'name' => 'Aus dem Namen konnte kein gültiger Feldset-Schlüssel (max. 64 Zeichen) abgeleitet werden.',
            ]);
        }

        $this->assertGeneratedCandidateShape($truncated);

        return $truncated;
    }

    private function assertGeneratedCandidateShape(string $key): void
    {
        if (strlen($key) > self::MAX_KEY_LENGTH) {
            throw ValidationException::withMessages([
                'name' => 'Aus dem Namen konnte kein gültiger Feldset-Schlüssel (max. 64 Zeichen) abgeleitet werden.',
            ]);
        }

        if ($key === '' || ! preg_match('/^[a-z][a-z0-9_]*$/', $key)) {
            throw ValidationException::withMessages([
                'name' => 'Aus dem Namen konnte kein gültiger Feldset-Schlüssel abgeleitet werden.',
            ]);
        }

        if (str_starts_with($key, 'system_')) {
            throw ValidationException::withMessages([
                'name' => 'Der Feldset-Schlüssel darf nicht mit „system_“ beginnen.',
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
