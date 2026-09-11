<?php

namespace App\Support\DynamicField;

use App\Enums\FieldType;
use App\Models\FieldDefinitionRevisionOption;
use Illuminate\Validation\ValidationException;

/**
 * DF-3-REST-A: kanonischer Vertrag für Auswahloptionen (Keys, Limits, Freeze-Shape).
 *
 * Multi-Select-Wertreihenfolge ist fachlich nicht relevant (PO); Options-`sort`
 * steuert nur die Admin-/Freeze-Darstellung.
 */
final class FieldDefinitionOptionContract
{
    public const KEY_PATTERN = '/^[a-z][a-z0-9_]{0,63}$/';

    public const MAX_OPTIONS_PER_DEFINITION = 100;

    public const MAX_LABEL_LENGTH = 255;

    /** Späterer Runtime-Limit (DF-3-REST-C); hier nur Vertragskonstanten. */
    public const MAX_MULTI_SELECT_SELECTED = 50;

    /**
     * @param  list<array{key: string, label: string, sort: int, is_active: bool}>  $options
     * @return list<array{key: string, label: string, sort: int, is_active: bool}>
     */
    public static function canonicalize(array $options): array
    {
        $rows = $options;
        usort(
            $rows,
            static function (array $left, array $right): int {
                $sortCmp = $left['sort'] <=> $right['sort'];
                if ($sortCmp !== 0) {
                    return $sortCmp;
                }

                return strcmp($left['key'], $right['key']);
            },
        );

        /** @var list<array{key: string, label: string, sort: int, is_active: bool}> $canonical */
        $canonical = [];
        foreach ($rows as $row) {
            $canonical[] = [
                'key' => $row['key'],
                'label' => $row['label'],
                'sort' => (int) $row['sort'],
                'is_active' => (bool) $row['is_active'],
            ];
        }

        return $canonical;
    }

    /**
     * @param  list<array{key: string, label: string, sort: int, is_active: bool}>  $left
     * @param  list<array{key: string, label: string, sort: int, is_active: bool}>  $right
     */
    public static function equalsCanonical(array $left, array $right): bool
    {
        return self::canonicalize($left) === self::canonicalize($right);
    }

    /**
     * @param  list<array{key: string, label: string, sort: int, is_active: bool}>  $options
     */
    public static function fingerprint(array $options): string
    {
        return hash('sha256', (string) json_encode(
            self::canonicalize($options),
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
        ));
    }

    /**
     * @param  list<mixed>  $rawOptions
     * @return list<array{key: string, label: string, sort: int, is_active: bool}>
     */
    public static function normalizeDesiredPayload(array $rawOptions): array
    {
        if (count($rawOptions) > self::MAX_OPTIONS_PER_DEFINITION) {
            throw ValidationException::withMessages([
                'options' => 'Es sind maximal '
                    .self::MAX_OPTIONS_PER_DEFINITION
                    .' Optionen pro Felddefinition zulässig.',
            ]);
        }

        /** @var array<string, true> $seen */
        $seen = [];
        /** @var list<array{key: string, label: string, sort: int, is_active: bool}> $normalized */
        $normalized = [];

        foreach ($rawOptions as $index => $raw) {
            if (! is_array($raw)) {
                throw ValidationException::withMessages([
                    "options.{$index}" => 'Jede Option muss ein Objekt mit key, label und sort sein.',
                ]);
            }

            $key = isset($raw['key']) ? trim((string) $raw['key']) : '';
            if ($key === '' || preg_match(self::KEY_PATTERN, $key) !== 1) {
                throw ValidationException::withMessages([
                    "options.{$index}.key" => 'Der Optionsschlüssel muss dem Muster '
                        .'[a-z][a-z0-9_]{0,63} entsprechen.',
                ]);
            }

            if (isset($seen[$key])) {
                throw ValidationException::withMessages([
                    "options.{$index}.key" => "Der Optionsschlüssel „{$key}“ ist im Desired State doppelt.",
                ]);
            }
            $seen[$key] = true;

            $label = isset($raw['label']) ? trim((string) $raw['label']) : '';
            if ($label === '') {
                throw ValidationException::withMessages([
                    "options.{$index}.label" => 'Das Optionslabel darf nicht leer sein.',
                ]);
            }
            if (mb_strlen($label) > self::MAX_LABEL_LENGTH) {
                throw ValidationException::withMessages([
                    "options.{$index}.label" => 'Das Optionslabel darf höchstens '
                        .self::MAX_LABEL_LENGTH
                        .' Zeichen lang sein.',
                ]);
            }

            if (! array_key_exists('sort', $raw) || ! is_numeric($raw['sort'])) {
                throw ValidationException::withMessages([
                    "options.{$index}.sort" => 'Die Optionssortierung muss eine ganze Zahl sein.',
                ]);
            }

            $isActive = array_key_exists('is_active', $raw)
                ? filter_var($raw['is_active'], FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE)
                : true;
            if ($isActive === null) {
                throw ValidationException::withMessages([
                    "options.{$index}.is_active" => 'is_active muss ein Wahrheitswert sein.',
                ]);
            }

            $normalized[] = [
                'key' => $key,
                'label' => $label,
                'sort' => (int) $raw['sort'],
                'is_active' => $isActive,
            ];
        }

        return self::canonicalize($normalized);
    }

    /**
     * Fehlende bisherige Keys werden deaktiviert und mitgeführt; physisch nie gelöscht.
     *
     * @param  list<array{key: string, label: string, sort: int, is_active: bool}>  $desired
     * @param  list<array{key: string, label: string, sort: int, is_active: bool}>  $previous
     * @return list<array{key: string, label: string, sort: int, is_active: bool}>
     */
    public static function mergeWithPrevious(array $desired, array $previous): array
    {
        /** @var array<string, array{key: string, label: string, sort: int, is_active: bool}> $byKey */
        $byKey = [];
        foreach ($previous as $row) {
            $byKey[$row['key']] = $row;
        }

        foreach ($desired as $row) {
            $byKey[$row['key']] = $row;
        }

        /** @var array<string, true> $desiredKeys */
        $desiredKeys = [];
        foreach ($desired as $row) {
            $desiredKeys[$row['key']] = true;
        }

        foreach ($previous as $row) {
            if (! isset($desiredKeys[$row['key']])) {
                $byKey[$row['key']] = [
                    'key' => $row['key'],
                    'label' => $row['label'],
                    'sort' => $row['sort'],
                    'is_active' => false,
                ];
            }
        }

        $merged = array_values($byKey);
        if (count($merged) > self::MAX_OPTIONS_PER_DEFINITION) {
            throw ValidationException::withMessages([
                'options' => 'Es sind maximal '
                    .self::MAX_OPTIONS_PER_DEFINITION
                    .' Optionen pro Felddefinition zulässig (inkl. deaktivierter).',
            ]);
        }

        return self::canonicalize($merged);
    }

    /**
     * @param  list<mixed>|null  $options
     * @return list<array{key: string, label: string, sort: int, is_active: bool}>|null
     */
    public static function assertFrozenOptions(?array $options, FieldType $fieldType, string $fieldKey): ?array
    {
        if (! $fieldType->isChoice()) {
            if ($options === null || $options === []) {
                return null;
            }

            throw new \RuntimeException(
                "Snapshot-Feld „{$fieldKey}“ vom Typ {$fieldType->value} darf keine Optionen tragen.",
            );
        }

        if ($options === null) {
            throw new \RuntimeException(
                "Auswahlfeld „{$fieldKey}“ ohne eingefrorene Optionen.",
            );
        }

        if ($options === []) {
            throw new \RuntimeException(
                "Auswahlfeld „{$fieldKey}“ hat eine leere Optionsstruktur.",
            );
        }

        if (count($options) > self::MAX_OPTIONS_PER_DEFINITION) {
            throw new \RuntimeException(
                "Auswahlfeld „{$fieldKey}“ überschreitet die maximale Optionsanzahl.",
            );
        }

        /** @var array<string, true> $seen */
        $seen = [];
        /** @var list<array{key: string, label: string, sort: int, is_active: bool}> $normalized */
        $normalized = [];
        $hasActive = false;

        foreach ($options as $index => $raw) {
            if (! is_array($raw)) {
                throw new \RuntimeException(
                    "Auswahlfeld „{$fieldKey}“: Option #{$index} ist ungültig.",
                );
            }

            $key = isset($raw['key']) ? (string) $raw['key'] : '';
            if ($key === '' || preg_match(self::KEY_PATTERN, $key) !== 1) {
                throw new \RuntimeException(
                    "Auswahlfeld „{$fieldKey}“: ungültiger Optionsschlüssel.",
                );
            }
            if (isset($seen[$key])) {
                throw new \RuntimeException(
                    "Auswahlfeld „{$fieldKey}“: doppelter Optionsschlüssel „{$key}“.",
                );
            }
            $seen[$key] = true;

            $label = isset($raw['label']) ? (string) $raw['label'] : '';
            if ($label === '' || mb_strlen($label) > self::MAX_LABEL_LENGTH) {
                throw new \RuntimeException(
                    "Auswahlfeld „{$fieldKey}“: ungültiges Optionslabel für „{$key}“.",
                );
            }

            if (! array_key_exists('sort', $raw) || ! is_numeric($raw['sort'])) {
                throw new \RuntimeException(
                    "Auswahlfeld „{$fieldKey}“: ungültige Sortierung für „{$key}“.",
                );
            }

            if (! array_key_exists('is_active', $raw) || ! is_bool($raw['is_active'])) {
                throw new \RuntimeException(
                    "Auswahlfeld „{$fieldKey}“: is_active fehlt oder ist ungültig für „{$key}“.",
                );
            }

            if ($raw['is_active'] === true) {
                $hasActive = true;
            }

            $normalized[] = [
                'key' => $key,
                'label' => $label,
                'sort' => (int) $raw['sort'],
                'is_active' => $raw['is_active'],
            ];
        }

        if (! $hasActive) {
            throw new \RuntimeException(
                "Auswahlfeld „{$fieldKey}“ benötigt mindestens eine aktive Option.",
            );
        }

        return self::canonicalize($normalized);
    }

    /**
     * @param  list<array{key: string, label: string, sort: int, is_active: bool}>  $options
     */
    public static function hasActiveOption(array $options): bool
    {
        foreach ($options as $row) {
            if ($row['is_active'] === true) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  iterable<FieldDefinitionRevisionOption>  $options
     * @return list<array{key: string, label: string, sort: int, is_active: bool}>
     */
    public static function fromRevisionOptions(iterable $options): array
    {
        $rows = [];
        foreach ($options as $option) {
            $rows[] = [
                'key' => (string) $option->key,
                'label' => (string) $option->label,
                'sort' => (int) $option->sort,
                'is_active' => (bool) $option->is_active,
            ];
        }

        return self::canonicalize($rows);
    }
}
