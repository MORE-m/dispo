<?php

namespace App\Services\DynamicField;

use App\Enums\FieldScope;
use App\Enums\FieldType;
use App\Models\ConfigurationSnapshot;
use App\Models\SnapshotFieldDefinition;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/**
 * DYN-004 / DYN-006: wertet ausschließlich Snapshot-Regeln aus (nicht Live-Definitionen).
 */
final class SnapshotFieldRuleEvaluator
{
    public const CONDITION_FIELD_EQUALS = 'field_equals';

    public const ACTION_REQUIRE_FIELD = 'require_field';

    /**
     * @param  array<string, mixed>  $headerValues  keyed by field key
     * @param  list<array{index: int, values: array<string, mixed>}>  $positions
     *
     * @throws ValidationException
     */
    public function validate(
        ConfigurationSnapshot $snapshot,
        array $headerValues,
        array $positions,
    ): void {
        $snapshot->loadMissing(['rules', 'fieldDefinitions']);
        $defsByKey = $snapshot->fieldDefinitions->keyBy('key');

        $this->assertRulesCompatibleWithDefinitions(
            $defsByKey->all(),
            $snapshot->rules,
        );

        $errors = [];

        foreach ($positions as $position) {
            $index = (int) $position['index'];
            $values = $position['values'];

            foreach ($snapshot->rules as $rule) {
                if (! $this->conditionMatches($rule->condition_json, $values, $headerValues, $defsByKey)) {
                    continue;
                }

                $action = $rule->action_json;
                $requiredKey = (string) ($action['field_key'] ?? '');
                /** @var SnapshotFieldDefinition|null $def */
                $def = $defsByKey->get($requiredKey);
                if ($def === null) {
                    throw new RuntimeException(
                        "Snapshot-Regel verlangt unbekanntes Feld „{$requiredKey}“.",
                    );
                }

                if ($def->scope === FieldScope::Position) {
                    if ($this->isEmpty($def, $values[$requiredKey] ?? null)) {
                        $errors["positions.{$index}.dynamic_field_values.{$requiredKey}"] =
                            $def->label.' ist erforderlich.';
                    }
                } elseif ($def->scope === FieldScope::Header) {
                    if ($this->isEmpty($def, $headerValues[$requiredKey] ?? null)) {
                        $errors["dynamic_field_values.{$requiredKey}"] =
                            $def->label.' ist erforderlich.';
                    }
                }
            }

            foreach ($snapshot->fieldDefinitions->where('scope', FieldScope::Position) as $def) {
                if ($def->key === 'period_open') {
                    $raw = $values['period_open'] ?? null;
                    if (! is_bool($raw)) {
                        $errors["positions.{$index}.dynamic_field_values.period_open"] =
                            'Zeitraum offen muss gesetzt sein.';
                    }
                }
            }
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }

    /**
     * @param  array<array-key, object>  $defsByKey
     * @param  iterable<int, object>  $rules
     */
    public function assertRulesCompatibleWithDefinitions(array $defsByKey, iterable $rules): void
    {
        foreach ($rules as $rule) {
            $condition = is_array($rule->condition_json ?? null)
                ? $rule->condition_json
                : (is_array($rule->condition ?? null) ? $rule->condition : null);
            $action = is_array($rule->action_json ?? null)
                ? $rule->action_json
                : (is_array($rule->action ?? null) ? $rule->action : null);

            if (! is_array($condition) || ! is_array($action)) {
                throw new RuntimeException('Feldregel hat ungültige JSON-Struktur.');
            }

            $conditionOp = $condition['op'] ?? null;
            if ($conditionOp !== self::CONDITION_FIELD_EQUALS) {
                throw new RuntimeException(
                    'Unbekannter Regel-Bedingungsoperator: '.((string) $conditionOp),
                );
            }

            $conditionKey = (string) ($condition['field_key'] ?? '');
            if ($conditionKey === '' || ! array_key_exists($conditionKey, $defsByKey)) {
                throw new RuntimeException(
                    "Regelbedingung referenziert unbekanntes Feld „{$conditionKey}“.",
                );
            }

            $actionOp = $action['op'] ?? null;
            if ($actionOp !== self::ACTION_REQUIRE_FIELD) {
                throw new RuntimeException(
                    'Unbekannter Regel-Aktionsoperator: '.((string) $actionOp),
                );
            }

            $actionKey = (string) ($action['field_key'] ?? '');
            if ($actionKey === '' || ! array_key_exists($actionKey, $defsByKey)) {
                throw new RuntimeException(
                    "Regelaktion referenziert unbekanntes Feld „{$actionKey}“.",
                );
            }
        }
    }

    /**
     * @param  array<string, mixed>  $condition
     * @param  array<string, mixed>  $positionValues
     * @param  array<string, mixed>  $headerValues
     * @param  Collection<string, SnapshotFieldDefinition>|null  $defsByKey
     */
    private function conditionMatches(
        array $condition,
        array $positionValues,
        array $headerValues,
        ?Collection $defsByKey = null,
    ): bool {
        $op = $condition['op'] ?? null;
        if ($op !== self::CONDITION_FIELD_EQUALS) {
            throw new RuntimeException(
                'Unbekannter Regel-Bedingungsoperator: '.((string) $op),
            );
        }

        $key = (string) ($condition['field_key'] ?? '');
        if ($defsByKey !== null && ! $defsByKey->has($key)) {
            throw new RuntimeException(
                "Regelbedingung referenziert unbekanntes Feld „{$key}“.",
            );
        }

        $expected = $condition['value'] ?? null;
        $def = $defsByKey?->get($key);
        $scope = $def?->scope;

        $actual = match (true) {
            $scope === FieldScope::Header => $headerValues[$key] ?? null,
            $scope === FieldScope::Position => $positionValues[$key] ?? null,
            default => $positionValues[$key] ?? $headerValues[$key] ?? null,
        };

        if (is_bool($expected)) {
            return $this->asBool($actual) === $expected;
        }

        return $actual === $expected;
    }

    private function asBool(mixed $value): ?bool
    {
        if (is_bool($value)) {
            return $value;
        }

        if ($value === 0 || $value === '0') {
            return false;
        }

        if ($value === 1 || $value === '1') {
            return true;
        }

        return null;
    }

    private function isEmpty(SnapshotFieldDefinition $def, mixed $value): bool
    {
        if ($value === null) {
            return true;
        }

        return match ($def->field_type) {
            FieldType::Boolean => $this->asBool($value) === null,
            FieldType::Period => $this->periodIsEmpty(is_array($value) ? $value : []),
            FieldType::ShortText, FieldType::LongText => trim((string) $value) === '',
            FieldType::Select, FieldType::MultiSelect => $value === '' || $value === [],
        };
    }

    /**
     * @param  array<string, mixed>  $period
     */
    private function periodIsEmpty(array $period): bool
    {
        $start = $period['start'] ?? $period['period_start'] ?? null;
        $end = $period['end'] ?? $period['period_end'] ?? null;

        return $start === null || $start === '' || $end === null || $end === '';
    }
}
