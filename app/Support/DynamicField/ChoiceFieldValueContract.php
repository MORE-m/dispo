<?php

namespace App\Support\DynamicField;

use App\Enums\FieldType;
use App\Models\CalculationFieldValue;
use App\Models\CalculationPositionFieldValue;
use App\Models\DispoOrderFieldValue;
use App\Models\DispoOrderPositionFieldValue;
use App\Models\SnapshotFieldDefinition;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/**
 * DF-3-REST-C1: kanonischer Vertrag für Select-/Multi-Select-Laufzeitwerte.
 *
 * Speicherung ausschließlich in `value_json` (Select = JSON-String, Multi = JSON-Array).
 * Optionsquelle ist immer das eingefrorene `options_json` der Snapshot-Definition.
 * Historisch inaktive Keys dürfen nur anhand des serverseitigen Vorzustands bleiben.
 */
final class ChoiceFieldValueContract
{
    public const MAX_ENCODED_BYTES = 8192;

    /**
     * @param  list<array{key: string, label: string, sort: int, is_active: bool}>|null  $optionsJson
     * @return string|list<string>|null
     */
    public static function normalizeIncoming(
        FieldType $fieldType,
        ?array $optionsJson,
        mixed $incoming,
        mixed $previousStored,
        string $errorKey,
        string $label,
    ): string|array|null {
        if (! $fieldType->isChoice()) {
            throw new RuntimeException('ChoiceFieldValueContract nur für select/multi_select.');
        }

        $options = self::requireFrozenOptions($optionsJson, $fieldType, $errorKey, $label);
        $previous = self::normalizePrevious($fieldType, $previousStored, $options, $errorKey, $label);

        if ($fieldType === FieldType::Select) {
            return self::normalizeSelect(
                $incoming,
                is_string($previous) || $previous === null ? $previous : null,
                $options,
                $errorKey,
                $label,
            );
        }

        if ($fieldType === FieldType::MultiSelect) {
            return self::normalizeMultiSelect(
                $incoming,
                is_array($previous) ? $previous : [],
                $options,
                $errorKey,
                $label,
            );
        }

        throw new RuntimeException('Unerwarteter Choice-Typ.');
    }

    /**
     * @param  list<array{key: string, label: string, sort: int, is_active: bool}>|null  $optionsJson
     * @return list<array{key: string, label: string, sort: int, is_active: bool}>
     */
    public static function requireFrozenOptions(
        ?array $optionsJson,
        FieldType $fieldType,
        string $errorKey,
        string $label,
    ): array {
        try {
            $options = FieldDefinitionOptionContract::assertFrozenOptions(
                $optionsJson,
                $fieldType,
                $label,
            );
        } catch (RuntimeException $exception) {
            throw ValidationException::withMessages([
                $errorKey => $label.' hat keine gültigen eingefrorenen Auswahloptionen.',
            ]);
        }

        if ($options === null) {
            throw ValidationException::withMessages([
                $errorKey => $label.' hat keine gültigen eingefrorenen Auswahloptionen.',
            ]);
        }

        return $options;
    }

    /**
     * @param  list<array{key: string, label: string, sort: int, is_active: bool}>  $options
     * @return array<string, array{key: string, label: string, sort: int, is_active: bool}>
     */
    public static function optionsByKey(array $options): array
    {
        $byKey = [];
        foreach ($options as $option) {
            $byKey[$option['key']] = $option;
        }

        return $byKey;
    }

    /**
     * @param  list<array{key: string, label: string, sort: int, is_active: bool}>  $options
     * @return list<string>
     */
    public static function activeKeys(array $options): array
    {
        $keys = [];
        foreach ($options as $option) {
            if ($option['is_active'] === true) {
                $keys[] = $option['key'];
            }
        }

        return $keys;
    }

    public static function isEmpty(FieldType $fieldType, mixed $value): bool
    {
        if ($value === null) {
            return true;
        }

        return match ($fieldType) {
            FieldType::Select => $value === '',
            FieldType::MultiSelect => $value === [] || $value === '',
            default => true,
        };
    }

    public static function emptyValue(FieldType $fieldType): mixed
    {
        return match ($fieldType) {
            FieldType::Select => null,
            FieldType::MultiSelect => [],
            default => null,
        };
    }

    /**
     * Liest den typisierten Choice-Wert aus einer Wertzeile (fail-closed bei Kanalverletzung).
     *
     * @return string|list<string>|null
     */
    public static function readStored(
        SnapshotFieldDefinition $definition,
        CalculationFieldValue|CalculationPositionFieldValue|DispoOrderFieldValue|DispoOrderPositionFieldValue $row,
    ): string|array|null {
        if (! $definition->field_type->isChoice()) {
            throw new RuntimeException('readStored nur für Choice-Felder.');
        }

        self::assertChoiceChannelExclusive($definition, $row);

        $decoded = $row->value_json;
        if ($decoded === null) {
            return self::emptyValue($definition->field_type);
        }

        // Roh-JSON-String aus Attributen, falls Cast noch nicht geladen / abweichend.
        if (is_string($decoded) && ($row->getAttributes()['value_json'] ?? null) === $decoded) {
            $decoded = self::decodeAttribute($decoded);
        }

        if ($definition->field_type === FieldType::Select) {
            if ($decoded === null) {
                return null;
            }
            if (! is_string($decoded)) {
                throw new RuntimeException(
                    "Wertkanal von „{$definition->key}“ enthält keinen gültigen Select-Wert.",
                );
            }

            return $decoded;
        }

        if ($decoded === null) {
            return [];
        }
        if (! self::isListOfStrings($decoded)) {
            throw new RuntimeException(
                "Wertkanal von „{$definition->key}“ enthält keinen gültigen Multi-Select-Wert.",
            );
        }

        /** @var list<string> $decoded */
        return self::canonicalizeKeys($decoded);
    }

    /**
     * Schreibt einen normalisierten Choice-Wert und nullt skalare Kanäle.
     *
     * @param  string|list<string>|null  $value
     */
    public static function writeStored(
        SnapshotFieldDefinition $definition,
        CalculationFieldValue|CalculationPositionFieldValue|DispoOrderFieldValue|DispoOrderPositionFieldValue $row,
        string|array|null $value,
    ): void {
        if (! $definition->field_type->isChoice()) {
            throw new RuntimeException('writeStored nur für Choice-Felder.');
        }

        self::clearScalarChannels($row);

        if ($definition->field_type === FieldType::Select) {
            if ($value !== null && ! is_string($value)) {
                throw new RuntimeException('Select-Wert muss string|null sein.');
            }
            self::assertEncodedSize($value);
            $row->value_json = $value;

            return;
        }

        if ($value === null) {
            $encoded = [];
            self::assertEncodedSize($encoded);
            $row->value_json = $encoded;

            return;
        }

        if (! self::isListOfStrings($value)) {
            throw new RuntimeException('Multi-Select-Wert muss list<string> sein.');
        }

        /** @var list<string> $value */
        $encoded = self::canonicalizeKeys($value);
        self::assertEncodedSize($encoded);
        $row->value_json = $encoded;
    }

    /**
     * Nullt value_json bei nicht-Choice-Persistenz.
     */
    public static function clearChoiceChannel(
        CalculationFieldValue|CalculationPositionFieldValue|DispoOrderFieldValue|DispoOrderPositionFieldValue $row,
    ): void {
        $row->value_json = null;
    }

    public static function clearScalarChannels(
        CalculationFieldValue|CalculationPositionFieldValue|DispoOrderFieldValue|DispoOrderPositionFieldValue $row,
    ): void {
        $row->value_string = null;
        $row->value_text = null;
        if ($row instanceof CalculationFieldValue
            || $row instanceof CalculationPositionFieldValue
            || $row instanceof DispoOrderPositionFieldValue
        ) {
            $row->value_boolean = null;
        }
        $row->value_period_start = null;
        $row->value_period_end = null;
    }

    public static function assertChoiceChannelExclusive(
        SnapshotFieldDefinition $definition,
        CalculationFieldValue|CalculationPositionFieldValue|DispoOrderFieldValue|DispoOrderPositionFieldValue $row,
    ): void {
        $scalarsPresent = self::hasScalarChannel($row);
        $jsonRaw = $row->getAttributes()['value_json'] ?? null;
        $jsonPresent = $jsonRaw !== null;

        if ($definition->field_type->isChoice()) {
            if ($scalarsPresent) {
                throw new RuntimeException(
                    "Choice-Feld „{$definition->key}“ hat belegte skalare Wertkanäle.",
                );
            }

            return;
        }

        if ($jsonPresent) {
            throw new RuntimeException(
                "Nicht-Choice-Feld „{$definition->key}“ hat belegten value_json-Kanal.",
            );
        }
    }

    public static function hasScalarChannel(
        CalculationFieldValue|CalculationPositionFieldValue|DispoOrderFieldValue|DispoOrderPositionFieldValue $row,
    ): bool {
        if ($row->value_string !== null || $row->value_text !== null) {
            return true;
        }
        if (($row instanceof CalculationFieldValue
            || $row instanceof CalculationPositionFieldValue
            || $row instanceof DispoOrderPositionFieldValue)
            && $row->value_boolean !== null
        ) {
            return true;
        }

        return $row->value_period_start !== null || $row->value_period_end !== null;
    }

    /**
     * @param  list<string>  $keys
     * @return list<string>
     */
    public static function canonicalizeKeys(array $keys): array
    {
        $unique = [];
        foreach ($keys as $key) {
            $unique[$key] = true;
        }
        /** @var list<string> $list */
        $list = array_keys($unique);
        sort($list, SORT_STRING);

        return $list;
    }

    /**
     * @param  array<mixed>|null  $optionsJson
     * @return list<array{key: string, label: string, sort: int, is_active: bool}>|null
     */
    public static function optionsForSchemaProp(?array $optionsJson, FieldType $fieldType): ?array
    {
        if (! $fieldType->isChoice()) {
            return null;
        }

        if ($optionsJson === null) {
            return null;
        }

        try {
            return FieldDefinitionOptionContract::assertFrozenOptions(
                array_values($optionsJson),
                $fieldType,
                'schema',
            );
        } catch (RuntimeException) {
            return null;
        }
    }

    /**
     * @return string|list<string>|null
     */
    public static function assertNormalizedStoredValue(FieldType $fieldType, mixed $value): string|array|null
    {
        if ($value === null) {
            return null;
        }

        if ($fieldType === FieldType::Select) {
            if (! is_string($value)) {
                throw new RuntimeException('Select-Wert muss string|null sein.');
            }

            return $value;
        }

        if ($fieldType === FieldType::MultiSelect) {
            if (! self::isListOfStrings($value)) {
                throw new RuntimeException('Multi-Select-Wert muss list<string> sein.');
            }

            /** @var list<string> $value */
            return self::canonicalizeKeys($value);
        }

        throw new RuntimeException('assertNormalizedStoredValue nur für Choice-Typen.');
    }

    /**
     * @param  list<array{key: string, label: string, sort: int, is_active: bool}>  $options
     */
    private static function normalizeSelect(
        mixed $incoming,
        ?string $previous,
        array $options,
        string $errorKey,
        string $label,
    ): ?string {
        if ($incoming === null || $incoming === '') {
            return null;
        }

        if (! is_string($incoming)) {
            throw ValidationException::withMessages([
                $errorKey => $label.' muss ein Optionsschlüssel (Text) sein.',
            ]);
        }

        $key = $incoming;
        $byKey = self::optionsByKey($options);
        if (! isset($byKey[$key])) {
            throw ValidationException::withMessages([
                $errorKey => $label.' enthält einen unbekannten Optionsschlüssel.',
            ]);
        }

        if ($byKey[$key]['is_active'] === true) {
            return $key;
        }

        if ($previous !== null && $previous === $key) {
            return $key;
        }

        throw ValidationException::withMessages([
            $errorKey => $label.': die Option „'.$key.'“ ist nicht mehr auswählbar.',
        ]);
    }

    /**
     * @param  list<array{key: string, label: string, sort: int, is_active: bool}>  $options
     * @param  list<string>  $previous
     * @return list<string>
     */
    private static function normalizeMultiSelect(
        mixed $incoming,
        array $previous,
        array $options,
        string $errorKey,
        string $label,
    ): array {
        if ($incoming === null) {
            return [];
        }

        if (! self::isListOfStrings($incoming)) {
            throw ValidationException::withMessages([
                $errorKey => $label.' muss eine Liste von Optionsschlüsseln sein.',
            ]);
        }

        /** @var list<string> $incoming */
        if (count($incoming) > FieldDefinitionOptionContract::MAX_MULTI_SELECT_SELECTED) {
            throw ValidationException::withMessages([
                $errorKey => $label.' darf höchstens '
                    .FieldDefinitionOptionContract::MAX_MULTI_SELECT_SELECTED
                    .' Werte enthalten.',
            ]);
        }

        $seen = [];
        foreach ($incoming as $key) {
            if (isset($seen[$key])) {
                throw ValidationException::withMessages([
                    $errorKey => $label.' darf keine doppelten Optionsschlüssel enthalten.',
                ]);
            }
            $seen[$key] = true;
        }

        $byKey = self::optionsByKey($options);
        $previousLookup = array_fill_keys($previous, true);
        $canonical = [];

        foreach ($incoming as $key) {
            if (! isset($byKey[$key])) {
                throw ValidationException::withMessages([
                    $errorKey => $label.' enthält einen unbekannten Optionsschlüssel.',
                ]);
            }

            if ($byKey[$key]['is_active'] === true) {
                $canonical[] = $key;

                continue;
            }

            if (isset($previousLookup[$key])) {
                $canonical[] = $key;

                continue;
            }

            throw ValidationException::withMessages([
                $errorKey => $label.': die Option „'.$key.'“ ist nicht mehr auswählbar.',
            ]);
        }

        return self::canonicalizeKeys($canonical);
    }

    /**
     * @param  list<array{key: string, label: string, sort: int, is_active: bool}>  $options
     * @return string|list<string>|null
     */
    private static function normalizePrevious(
        FieldType $fieldType,
        mixed $previousStored,
        array $options,
        string $errorKey,
        string $label,
    ): string|array|null {
        if ($fieldType === FieldType::Select) {
            if ($previousStored === null || $previousStored === '') {
                return null;
            }
            if (! is_string($previousStored)) {
                throw ValidationException::withMessages([
                    $errorKey => $label.' hat einen ungültigen gespeicherten Vorzustand.',
                ]);
            }
            $byKey = self::optionsByKey($options);
            if (! isset($byKey[$previousStored])) {
                throw ValidationException::withMessages([
                    $errorKey => $label.' hat einen gespeicherten Wert außerhalb des Freezes.',
                ]);
            }

            return $previousStored;
        }

        if ($previousStored === null) {
            return [];
        }
        if (! self::isListOfStrings($previousStored)) {
            throw ValidationException::withMessages([
                $errorKey => $label.' hat einen ungültigen gespeicherten Vorzustand.',
            ]);
        }

        /** @var list<string> $previousStored */
        $byKey = self::optionsByKey($options);
        foreach ($previousStored as $key) {
            if (! isset($byKey[$key])) {
                throw ValidationException::withMessages([
                    $errorKey => $label.' hat einen gespeicherten Wert außerhalb des Freezes.',
                ]);
            }
        }

        return self::canonicalizeKeys($previousStored);
    }

    private static function isListOfStrings(mixed $value): bool
    {
        if (! is_array($value)) {
            return false;
        }

        if ($value !== [] && array_is_list($value) === false) {
            return false;
        }

        foreach ($value as $item) {
            if (! is_string($item)) {
                return false;
            }
        }

        return true;
    }

    private static function decodeAttribute(mixed $raw): mixed
    {
        if (is_string($raw)) {
            try {
                return json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
            } catch (\JsonException $exception) {
                throw new RuntimeException('value_json ist kein gültiges JSON.', 0, $exception);
            }
        }

        return $raw;
    }

    private static function assertEncodedSize(mixed $value): void
    {
        if ($value === null) {
            return;
        }

        $encoded = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($encoded === false || strlen($encoded) > self::MAX_ENCODED_BYTES) {
            throw ValidationException::withMessages([
                'value_json' => 'Der Auswahlwert überschreitet die zulässige Speichergröße.',
            ]);
        }
    }
}
