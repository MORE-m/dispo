<?php

namespace App\Services\DynamicField\Admin;

use App\Enums\FieldScope;
use App\Enums\FieldType;
use App\Models\FieldSetVersion;
use App\Models\FieldSetVersionField;
use App\Services\DynamicField\SnapshotFieldRuleEvaluator;
use App\Support\DynamicField\FieldDefinitionOptionContract;
use App\Support\DynamicField\FieldRuleContract;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/**
 * DF-3.1 / ADM-002: statische Vorschau mit Beispielwerten; keine Persistenz.
 * DF-3-RULE-A: V1-Regelops inkl. all/any und set_visible in der Effektvorschau.
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
        $version->loadMissing(['fields.revision.definition', 'fields.revision.options', 'rules']);

        $exampleHeader = [];
        $examplePosition = [];
        $defsByKey = [];

        $fields = [];
        foreach ($version->fields->sortBy('sort')->values() as $membership) {
            /** @var FieldSetVersionField $membership */
            $revision = $membership->revision;
            $definition = $revision?->definition;
            if ($revision === null || $definition === null) {
                continue;
            }

            $optionsJson = $definition->field_type->isChoice()
                ? FieldDefinitionOptionContract::fromRevisionOptions($revision->options)
                : null;

            $defsByKey[$definition->key] = (object) [
                'key' => $definition->key,
                'field_type' => $definition->field_type,
                'scope' => $definition->scope,
                'options_json' => $optionsJson,
            ];

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

        $normalizedDefs = FieldRuleContract::normalizeDefinitions($defsByKey);

        // Fail-closed: ungültige Regeln nicht still überspringen.
        try {
            FieldRuleContract::assertRuleset($normalizedDefs, $version->rules, requireActiveOptionKeys: true);
        } catch (RuntimeException $exception) {
            throw ValidationException::withMessages([
                'rules' => $exception->getMessage(),
            ]);
        }

        $basisVisibleHeader = [];
        $basisVisiblePosition = [];
        $basisRequiredHeader = [];
        $basisRequiredPosition = [];
        foreach ($fields as $field) {
            if ($field['scope'] === FieldScope::Header->value) {
                $basisVisibleHeader[$field['key']] = (bool) $field['visible'];
                $basisRequiredHeader[$field['key']] = (bool) $field['required_by_override'];
            } else {
                $basisVisiblePosition[$field['key']] = (bool) $field['visible'];
                $basisRequiredPosition[$field['key']] = (bool) $field['required_by_override'];
            }
        }

        $evaluator = app(SnapshotFieldRuleEvaluator::class);
        $headerVisible = $evaluator->effectiveVisibilityForScope(
            $version->rules,
            $normalizedDefs,
            $exampleHeader,
            $examplePosition,
            FieldScope::Header,
            $basisVisibleHeader,
        );
        $positionVisible = $evaluator->effectiveVisibilityForScope(
            $version->rules,
            $normalizedDefs,
            $exampleHeader,
            $examplePosition,
            FieldScope::Position,
            $basisVisiblePosition,
        );
        $headerRequired = $evaluator->effectiveRequiredForScope(
            $version->rules,
            $normalizedDefs,
            $exampleHeader,
            $examplePosition,
            FieldScope::Header,
            $basisRequiredHeader,
            $headerVisible,
        );
        $positionRequired = $evaluator->effectiveRequiredForScope(
            $version->rules,
            $normalizedDefs,
            $exampleHeader,
            $examplePosition,
            FieldScope::Position,
            $basisRequiredPosition,
            $positionVisible,
        );

        $requiredByRules = $this->requiredKeysFromRules(
            $version,
            $normalizedDefs,
            $exampleHeader,
            $examplePosition,
        );

        foreach ($fields as &$field) {
            $key = $field['key'];
            $field['required_by_rule'] = in_array($key, $requiredByRules, true);
            if ($field['scope'] === FieldScope::Header->value) {
                $field['visible'] = $headerVisible[$key] ?? (bool) $field['visible'];
                $field['effective_required'] = $headerRequired[$key] ?? false;
            } else {
                $field['visible'] = $positionVisible[$key] ?? (bool) $field['visible'];
                $field['effective_required'] = $positionRequired[$key] ?? false;
            }
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
            FieldType::Select => 'option_a',
            FieldType::MultiSelect => ['option_a'],
        };
    }

    /**
     * @param  array<string, object>  $defsByKey
     * @param  array<string, mixed>  $headerValues
     * @param  array<string, mixed>  $positionValues
     * @return list<string>
     */
    private function requiredKeysFromRules(
        FieldSetVersion $version,
        array $defsByKey,
        array $headerValues,
        array $positionValues,
    ): array {
        $required = [];
        foreach ($version->rules as $rule) {
            /** @var array<string, mixed> $condition */
            $condition = $rule->condition_json;
            /** @var array<string, mixed> $action */
            $action = $rule->action_json;
            if (($action['op'] ?? null) !== FieldRuleContract::ACTION_REQUIRE_FIELD) {
                continue;
            }

            if (! FieldRuleContract::conditionMatches($condition, $headerValues, $positionValues, $defsByKey)) {
                continue;
            }

            $required[] = (string) ($action['field_key'] ?? '');
        }

        return array_values(array_filter($required));
    }

    /**
     * @param  array<string, mixed>  $condition
     * @param  array<string, mixed>  $action
     */
    private function ruleSummary(array $condition, array $action): string
    {
        $actionOp = (string) ($action['op'] ?? '');
        $actionKey = (string) ($action['field_key'] ?? '?');
        $conditionLabel = $this->conditionSummary($condition);

        if ($actionOp === FieldRuleContract::ACTION_SET_VISIBLE) {
            $visible = ($action['value'] ?? false) ? 'sichtbar' : 'unsichtbar';

            return "Wenn {$conditionLabel}, dann ist {$actionKey} {$visible}.";
        }

        return "Wenn {$conditionLabel}, dann ist {$actionKey} Pflicht.";
    }

    /**
     * @param  array<string, mixed>  $condition
     */
    private function conditionSummary(array $condition): string
    {
        $op = (string) ($condition['op'] ?? '');

        return match ($op) {
            FieldRuleContract::CONDITION_ALL => 'alle ('.implode(' UND ', array_map(
                fn (mixed $child): string => is_array($child) ? $this->conditionSummary($child) : '?',
                $condition['conditions'] ?? [],
            )).')',
            FieldRuleContract::CONDITION_ANY => 'eine von ('.implode(' ODER ', array_map(
                fn (mixed $child): string => is_array($child) ? $this->conditionSummary($child) : '?',
                $condition['conditions'] ?? [],
            )).')',
            FieldRuleContract::CONDITION_FIELD_EMPTY => ((string) ($condition['field_key'] ?? '?')).' leer',
            FieldRuleContract::CONDITION_FIELD_NOT_EMPTY => ((string) ($condition['field_key'] ?? '?')).' nicht leer',
            FieldRuleContract::CONDITION_FIELD_CONTAINS => ((string) ($condition['field_key'] ?? '?'))
                .' enthält '.((string) ($condition['value'] ?? '?')),
            default => $this->equalsSummary($condition),
        };
    }

    /**
     * @param  array<string, mixed>  $condition
     */
    private function equalsSummary(array $condition): string
    {
        $condKey = (string) ($condition['field_key'] ?? '?');
        $condVal = $condition['value'] ?? null;
        $condValLabel = is_bool($condVal) ? ($condVal ? 'ja' : 'nein') : (string) $condVal;

        return "{$condKey} = {$condValLabel}";
    }
}
