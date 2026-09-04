<?php

namespace App\Services\DynamicField;

use App\Enums\FieldScope;
use App\Enums\FieldType;
use App\Models\ConfigurationSnapshot;
use App\Models\SnapshotFieldDefinition;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * DYN-004 / DYN-006: wertet ausschließlich Snapshot-Regeln aus (nicht Live-Definitionen).
 */
final class SnapshotFieldRuleEvaluator
{
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
        $errors = [];

        foreach ($positions as $position) {
            $index = (int) $position['index'];
            $values = $position['values'];

            foreach ($snapshot->rules as $rule) {
                if (! $this->conditionMatches($rule->condition_json, $values, $headerValues)) {
                    continue;
                }

                $action = $rule->action_json;
                if (($action['op'] ?? null) !== 'require_field') {
                    continue;
                }

                $requiredKey = (string) ($action['field_key'] ?? '');
                /** @var SnapshotFieldDefinition|null $def */
                $def = $defsByKey->get($requiredKey);
                if ($def === null) {
                    continue;
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
                    if ($raw === null || ! is_bool($raw) && $raw !== 0 && $raw !== 1 && $raw !== '0' && $raw !== '1') {
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
     * @param  array<string, mixed>  $condition
     * @param  array<string, mixed>  $positionValues
     * @param  array<string, mixed>  $headerValues
     */
    private function conditionMatches(array $condition, array $positionValues, array $headerValues): bool
    {
        if (($condition['op'] ?? null) !== 'field_equals') {
            return false;
        }

        $key = (string) ($condition['field_key'] ?? '');
        $expected = $condition['value'] ?? null;
        $actual = $positionValues[$key] ?? $headerValues[$key] ?? null;

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
            default => $value === null || $value === '',
        };
    }

    /**
     * @param  array<string, mixed>  $period
     */
    private function periodIsEmpty(array $period): bool
    {
        $start = $period['start'] ?? $period['period_start'] ?? null;
        $end = $period['end'] ?? $period['period_end'] ?? null;

        // Pflicht-Zeitraum verlangt Start und Ende.
        return $start === null || $start === '' || $end === null || $end === '';
    }

    /**
     * Required field keys for UI from snapshot rules given current position values.
     *
     * @param  array<string, mixed>  $positionValues
     * @return list<string>
     */
    public function requiredPositionKeys(ConfigurationSnapshot $snapshot, array $positionValues): array
    {
        $snapshot->loadMissing('rules');
        $required = [];

        foreach ($snapshot->rules as $rule) {
            if (! $this->conditionMatches($rule->condition_json, $positionValues, [])) {
                continue;
            }
            if (($rule->action_json['op'] ?? null) !== 'require_field') {
                continue;
            }
            $key = (string) ($rule->action_json['field_key'] ?? '');
            if ($key !== '') {
                $required[] = $key;
            }
        }

        return array_values(array_unique($required));
    }

    /**
     * @return Collection<string, SnapshotFieldDefinition>
     */
    public function definitionsByKey(ConfigurationSnapshot $snapshot): Collection
    {
        $snapshot->loadMissing('fieldDefinitions');

        return $snapshot->fieldDefinitions->keyBy('key');
    }
}
