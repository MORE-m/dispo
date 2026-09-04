<?php

namespace App\Services\DynamicField\Admin;

use App\Enums\FieldScope;
use App\Enums\FieldType;
use App\Models\FieldSetVersion;
use App\Models\FieldSetVersionField;
use App\Services\DynamicField\SnapshotFieldRuleEvaluator;

/**
 * DF-3.1 / ADM-002: statische Vorschau mit Beispielwerten; keine Persistenz.
 */
final class FieldSetVersionPreviewService
{
    /**
     * @return array{
     *     version_id: int,
     *     version: int,
     *     status: string,
     *     fields: list<array<string, mixed>>,
     *     rules: list<array<string, mixed>>,
     *     example_values: array{header: array<string, mixed>, position: array<string, mixed>}
     * }
     */
    public function preview(FieldSetVersion $version): array
    {
        $version->load(['fields.revision.definition', 'rules']);

        $exampleHeader = [];
        $examplePosition = [];

        $fields = [];
        foreach ($version->fields->sortBy('sort')->values() as $membership) {
            /** @var FieldSetVersionField $membership */
            $revision = $membership->revision;
            $definition = $revision?->definition;
            if ($revision === null || $definition === null) {
                continue;
            }

            $example = $this->exampleValueFor($definition->field_type, $definition->key);
            if ($definition->scope === FieldScope::Header) {
                $exampleHeader[$definition->key] = $example;
            } else {
                $examplePosition[$definition->key] = $example;
            }

            $requiredByOverride = $membership->required_override === true;
            $visible = $membership->visible_override ?? true;

            $fields[] = [
                'membership_id' => $membership->id,
                'key' => $definition->key,
                'label' => $revision->label,
                'help_text' => $revision->help_text,
                'field_type' => $definition->field_type->value,
                'scope' => $definition->scope->value,
                'group_key' => $revision->group_key,
                'sort' => $membership->sort,
                'required_override' => $membership->required_override,
                'visible_override' => $membership->visible_override,
                'visible' => $visible,
                'required_by_override' => $requiredByOverride,
                'is_system' => $definition->is_system,
                'example_value' => $example,
                'revision' => $revision->revision,
            ];
        }

        $requiredByRules = $this->requiredKeysFromRules(
            $version,
            $exampleHeader,
            $examplePosition,
        );

        foreach ($fields as &$field) {
            $field['required_by_rule'] = in_array($field['key'], $requiredByRules, true);
            $field['effective_required'] = ($field['required_by_override'] || $field['required_by_rule'])
                && $field['visible'];
        }
        unset($field);

        $rules = [];
        foreach ($version->rules as $rule) {
            $rules[] = [
                'id' => $rule->id,
                'sort' => $rule->sort,
                'condition' => $rule->condition_json,
                'action' => $rule->action_json,
                'summary' => $this->ruleSummary($rule->condition_json, $rule->action_json),
            ];
        }

        return [
            'version_id' => $version->id,
            'version' => $version->version,
            'status' => $version->status->value,
            'fields' => $fields,
            'rules' => $rules,
            'example_values' => [
                'header' => $exampleHeader,
                'position' => $examplePosition,
            ],
        ];
    }

    private function exampleValueFor(FieldType $type, string $key): mixed
    {
        return match ($type) {
            FieldType::Boolean => $key === 'period_open' ? false : true,
            FieldType::Period => [
                'start' => '2026-01-01',
                'end' => '2026-01-31',
            ],
            FieldType::ShortText => 'Beispiel',
            FieldType::LongText => 'Beispieltext für die Admin-Vorschau.',
        };
    }

    /**
     * @param  array<string, mixed>  $headerValues
     * @param  array<string, mixed>  $positionValues
     * @return list<string>
     */
    private function requiredKeysFromRules(
        FieldSetVersion $version,
        array $headerValues,
        array $positionValues,
    ): array {
        $defsByKey = [];
        foreach ($version->fields as $membership) {
            $definition = $membership->revision?->definition;
            if ($definition !== null) {
                $defsByKey[$definition->key] = $definition;
            }
        }

        $required = [];
        foreach ($version->rules as $rule) {
            $condition = $rule->condition_json;
            $action = $rule->action_json;
            if (($condition['op'] ?? null) !== SnapshotFieldRuleEvaluator::CONDITION_FIELD_EQUALS) {
                continue;
            }
            if (($action['op'] ?? null) !== SnapshotFieldRuleEvaluator::ACTION_REQUIRE_FIELD) {
                continue;
            }

            $conditionKey = (string) ($condition['field_key'] ?? '');
            $definition = $defsByKey[$conditionKey] ?? null;
            $actual = match ($definition?->scope) {
                FieldScope::Header => $headerValues[$conditionKey] ?? null,
                FieldScope::Position => $positionValues[$conditionKey] ?? null,
                default => $positionValues[$conditionKey] ?? $headerValues[$conditionKey] ?? null,
            };

            $expected = $condition['value'] ?? null;
            $matches = is_bool($expected)
                ? $this->asBool($actual) === $expected
                : $actual === $expected;

            if ($matches) {
                $required[] = (string) ($action['field_key'] ?? '');
            }
        }

        return array_values(array_filter($required));
    }

    /**
     * @param  array<string, mixed>  $condition
     * @param  array<string, mixed>  $action
     */
    private function ruleSummary(array $condition, array $action): string
    {
        $condKey = (string) ($condition['field_key'] ?? '?');
        $condVal = $condition['value'] ?? null;
        $condValLabel = is_bool($condVal) ? ($condVal ? 'ja' : 'nein') : (string) $condVal;
        $actionKey = (string) ($action['field_key'] ?? '?');

        return "Wenn {$condKey} = {$condValLabel}, dann ist {$actionKey} Pflicht.";
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
}
