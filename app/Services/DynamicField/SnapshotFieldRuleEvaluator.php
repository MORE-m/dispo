<?php

namespace App\Services\DynamicField;

use App\Enums\FieldScope;
use App\Models\ConfigurationSnapshot;
use App\Models\SnapshotFieldDefinition;
use App\Support\DynamicField\FieldRuleContract;
use App\Support\DynamicField\FieldRuleDefinitionContext;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * DYN-004 / DYN-005 / DYN-006: wertet ausschließlich Snapshot-Regeln aus (nicht Live-Definitionen).
 *
 * DF-3-RULE-A: V1-Ops, flaches all/any, Header-Pass ohne Positionen, Effective Visible/Required.
 */
final class SnapshotFieldRuleEvaluator
{
    /** @deprecated use FieldRuleContract::CONDITION_FIELD_EQUALS */
    public const CONDITION_FIELD_EQUALS = FieldRuleContract::CONDITION_FIELD_EQUALS;

    /** @deprecated use FieldRuleContract::ACTION_REQUIRE_FIELD */
    public const ACTION_REQUIRE_FIELD = FieldRuleContract::ACTION_REQUIRE_FIELD;

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
        $snapshot->loadMissing(['rules', 'fieldDefinitions', 'sources']);
        $defsByKey = FieldRuleDefinitionContext::fromSnapshot($snapshot);

        // Historische Snapshots: inactive Options-Keys in eingefrorenen Regeln bleiben zulässig.
        $this->assertRulesCompatibleWithDefinitions(
            $defsByKey,
            $snapshot->rules,
            requireActiveOptionKeys: false,
        );

        $errors = [];

        $headerVisible = $this->effectiveVisibilityForScope(
            $snapshot->rules,
            $defsByKey,
            $headerValues,
            [],
            FieldScope::Header,
            basisVisible: $this->basisVisibleMap($snapshot->fieldDefinitions, FieldScope::Header),
        );
        // Nur regelbasiertes require_field – statisches Snapshot-required bleibt
        // in Writer-/Completeness-Pfaden (PO Calc-Draft / Partial-Save). RULE-B
        // verdrahtet DYN-005 für dynamisch ausgeblendete, statisch required Felder.
        $headerRequired = $this->effectiveRequiredForScope(
            $snapshot->rules,
            $defsByKey,
            $headerValues,
            [],
            FieldScope::Header,
            basisRequired: [],
            effectiveVisible: $headerVisible,
        );

        foreach ($headerRequired as $key => $isRequired) {
            if (! $isRequired) {
                continue;
            }
            if (! ($headerVisible[$key] ?? false)) {
                continue;
            }
            $def = $defsByKey[$key] ?? null;
            if ($def === null) {
                continue;
            }
            $snapDef = $snapshot->fieldDefinitions->firstWhere('key', $key);
            $label = $snapDef instanceof SnapshotFieldDefinition ? $snapDef->label : $key;
            if (FieldRuleContract::isEmpty($def, $headerValues[$key] ?? null)) {
                $errors["dynamic_field_values.{$key}"] = $label.' ist erforderlich.';
            }
        }

        foreach ($positions as $position) {
            $index = (int) $position['index'];
            $values = $position['values'];

            $positionVisible = $this->effectiveVisibilityForScope(
                $snapshot->rules,
                $defsByKey,
                $headerValues,
                $values,
                FieldScope::Position,
                basisVisible: $this->basisVisibleMap($snapshot->fieldDefinitions, FieldScope::Position),
            );
            $positionRequired = $this->effectiveRequiredForScope(
                $snapshot->rules,
                $defsByKey,
                $headerValues,
                $values,
                FieldScope::Position,
                basisRequired: [],
                effectiveVisible: $positionVisible,
            );

            foreach ($positionRequired as $key => $isRequired) {
                if (! $isRequired) {
                    continue;
                }
                if (! ($positionVisible[$key] ?? false)) {
                    continue;
                }
                $def = $defsByKey[$key] ?? null;
                if ($def === null) {
                    continue;
                }
                $snapDef = $snapshot->fieldDefinitions->firstWhere('key', $key);
                $label = $snapDef instanceof SnapshotFieldDefinition ? $snapDef->label : $key;
                if (FieldRuleContract::isEmpty($def, $values[$key] ?? null)) {
                    $errors["positions.{$index}.dynamic_field_values.{$key}"] =
                        $label.' ist erforderlich.';
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
     * @param  array<array-key, object|array<string, mixed>>  $defsByKey
     * @param  iterable<int, object>  $rules
     */
    public function assertRulesCompatibleWithDefinitions(
        array $defsByKey,
        iterable $rules,
        bool $requireActiveOptionKeys = true,
    ): void {
        $normalized = FieldRuleContract::normalizeDefinitions($defsByKey);
        FieldRuleContract::assertRuleset($normalized, $rules, $requireActiveOptionKeys);
    }

    /**
     * Effektive Sichtbarkeit für ein einzelnes Positions-/Header-Kontextpaar.
     * Validiert den Ruleset am Eintritt (fail-closed).
     *
     * @param  iterable<int, object>  $rules
     * @param  array<string, object>  $defsByKey
     * @param  array<string, mixed>  $headerValues
     * @param  array<string, mixed>  $positionValues
     * @param  array<string, bool>  $basisVisible
     * @return array<string, bool>
     */
    public function effectiveVisibilityForScope(
        iterable $rules,
        array $defsByKey,
        array $headerValues,
        array $positionValues,
        FieldScope $scope,
        array $basisVisible,
        bool $requireActiveOptionKeys = false,
    ): array {
        $defsByKey = FieldRuleContract::normalizeDefinitions($defsByKey);
        $ruleList = $this->rulesAsList($rules);
        FieldRuleContract::assertRuleset($defsByKey, $ruleList, $requireActiveOptionKeys);

        $effective = $basisVisible;

        foreach ($ruleList as $rule) {
            $condition = $this->ruleCondition($rule);
            $action = $this->ruleAction($rule);
            if (($action['op'] ?? null) !== FieldRuleContract::ACTION_SET_VISIBLE) {
                continue;
            }
            $targetKey = (string) ($action['field_key'] ?? '');
            $targetDef = $defsByKey[$targetKey] ?? null;
            if ($targetDef === null) {
                throw new \RuntimeException(
                    "Regelaktion referenziert unbekanntes Feld „{$targetKey}“.",
                );
            }
            if ($targetDef->scope !== $scope) {
                continue;
            }
            if (! FieldRuleContract::conditionMatches($condition, $headerValues, $positionValues, $defsByKey)) {
                continue;
            }
            $effective[$targetKey] = (bool) $action['value'];
        }

        return $effective;
    }

    /**
     * @param  iterable<int, object>  $rules
     * @param  array<string, object>  $defsByKey
     * @param  array<string, mixed>  $headerValues
     * @param  array<string, mixed>  $positionValues
     * @param  array<string, bool>  $basisRequired
     * @param  array<string, bool>  $effectiveVisible
     * @return array<string, bool>
     */
    public function effectiveRequiredForScope(
        iterable $rules,
        array $defsByKey,
        array $headerValues,
        array $positionValues,
        FieldScope $scope,
        array $basisRequired,
        array $effectiveVisible,
        bool $requireActiveOptionKeys = false,
    ): array {
        $defsByKey = FieldRuleContract::normalizeDefinitions($defsByKey);
        $ruleList = $this->rulesAsList($rules);
        FieldRuleContract::assertRuleset($defsByKey, $ruleList, $requireActiveOptionKeys);

        $effective = $basisRequired;

        foreach ($ruleList as $rule) {
            $condition = $this->ruleCondition($rule);
            $action = $this->ruleAction($rule);
            if (($action['op'] ?? null) !== FieldRuleContract::ACTION_REQUIRE_FIELD) {
                continue;
            }
            $targetKey = (string) ($action['field_key'] ?? '');
            $targetDef = $defsByKey[$targetKey] ?? null;
            if ($targetDef === null) {
                throw new \RuntimeException(
                    "Regelaktion referenziert unbekanntes Feld „{$targetKey}“.",
                );
            }
            if ($targetDef->scope !== $scope) {
                continue;
            }
            if (! FieldRuleContract::conditionMatches($condition, $headerValues, $positionValues, $defsByKey)) {
                continue;
            }
            $effective[$targetKey] = true;
        }

        // DYN-005: unsichtbar ⇒ kein Pflichtfehler (effective required für Validierung gefiltert in validate).
        foreach ($effective as $key => $isRequired) {
            if ($isRequired && ! ($effectiveVisible[$key] ?? false)) {
                $effective[$key] = false;
            }
        }

        return $effective;
    }

    /**
     * Effektive Sichtbarkeit eines Snapshot-Feldes (Basis ⊕ set_visible).
     *
     * Header: nur Basis-Snapshot.
     * Position: Basis-Regeln (Header→Position) plus optionaler Positions-Effektiv-Snapshot
     * (Position→Position), ohne Regeln anderer Positionen.
     *
     * @param  array<string, mixed>  $headerValues
     * @param  array<string, mixed>  $positionValues
     */
    public function isEffectivelyVisible(
        ConfigurationSnapshot $baseSnapshot,
        SnapshotFieldDefinition $def,
        array $headerValues,
        array $positionValues = [],
        ?ConfigurationSnapshot $positionScopeSnapshot = null,
    ): bool {
        $map = $this->effectiveVisibilityMapForContext(
            $baseSnapshot,
            $def->scope,
            $headerValues,
            $positionValues,
            $positionScopeSnapshot,
        );

        return (bool) ($map[$def->key] ?? false);
    }

    /**
     * @param  array<string, mixed>  $headerValues
     * @param  array<string, mixed>  $positionValues
     * @return array<string, bool>
     */
    public function effectiveVisibilityMapForContext(
        ConfigurationSnapshot $baseSnapshot,
        FieldScope $scope,
        array $headerValues,
        array $positionValues = [],
        ?ConfigurationSnapshot $positionScopeSnapshot = null,
    ): array {
        $baseSnapshot->loadMissing(['fieldDefinitions', 'rules', 'sources']);
        $scopeSnapshot = $positionScopeSnapshot ?? $baseSnapshot;
        if ($scopeSnapshot->id !== $baseSnapshot->id) {
            $scopeSnapshot->loadMissing(['fieldDefinitions', 'rules', 'sources']);
        }

        $defsByKey = $this->combinedDefinitionContext($baseSnapshot, $scopeSnapshot);
        $rules = $this->combinedRulesForScope($baseSnapshot, $scopeSnapshot, $scope);

        $definitions = $scope === FieldScope::Header
            ? $baseSnapshot->fieldDefinitions
            : $scopeSnapshot->fieldDefinitions;

        return $this->effectiveVisibilityForScope(
            $rules,
            $defsByKey,
            $headerValues,
            $scope === FieldScope::Header ? [] : $positionValues,
            $scope,
            $this->basisVisibleMap($definitions, $scope),
            requireActiveOptionKeys: false,
        );
    }

    /**
     * @return array<string, object>
     */
    private function combinedDefinitionContext(
        ConfigurationSnapshot $baseSnapshot,
        ConfigurationSnapshot $scopeSnapshot,
    ): array {
        if ($scopeSnapshot->id === $baseSnapshot->id) {
            return FieldRuleDefinitionContext::fromSnapshot($baseSnapshot);
        }

        $baseDefs = FieldRuleDefinitionContext::fromSnapshot($baseSnapshot);
        $scopeDefs = FieldRuleDefinitionContext::fromSnapshot($scopeSnapshot);

        return FieldRuleContract::normalizeDefinitions(array_merge($baseDefs, $scopeDefs));
    }

    /**
     * @return list<object>
     */
    private function combinedRulesForScope(
        ConfigurationSnapshot $baseSnapshot,
        ConfigurationSnapshot $scopeSnapshot,
        FieldScope $scope,
    ): array {
        if ($scope === FieldScope::Header || $scopeSnapshot->id === $baseSnapshot->id) {
            return $this->rulesAsList($baseSnapshot->rules);
        }

        /** @var array<string, true> $seen */
        $seen = [];
        /** @var list<object> $out */
        $out = [];
        foreach ([$baseSnapshot->rules, $scopeSnapshot->rules] as $ruleSet) {
            foreach ($ruleSet as $rule) {
                $dedupe = (string) ($rule->dedupe_key ?? '');
                if ($dedupe !== '') {
                    if (isset($seen[$dedupe])) {
                        continue;
                    }
                    $seen[$dedupe] = true;
                }
                $out[] = $rule;
            }
        }

        return $out;
    }

    /**
     * @param  Collection<int, SnapshotFieldDefinition>|iterable<int, SnapshotFieldDefinition>  $definitions
     * @return array<string, bool>
     */
    public function basisVisibleMap(iterable $definitions, FieldScope $scope): array
    {
        $map = [];
        foreach ($definitions as $def) {
            if ($def->scope !== $scope) {
                continue;
            }
            $map[$def->key] = (bool) $def->visible;
        }

        return $map;
    }

    /**
     * @param  Collection<int, SnapshotFieldDefinition>|iterable<int, SnapshotFieldDefinition>  $definitions
     * @return array<string, bool>
     */
    public function basisRequiredMap(iterable $definitions, FieldScope $scope): array
    {
        $map = [];
        foreach ($definitions as $def) {
            if ($def->scope !== $scope) {
                continue;
            }
            $map[$def->key] = (bool) $def->required;
        }

        return $map;
    }

    /**
     * @param  iterable<int, object>  $rules
     * @return list<object>
     */
    private function rulesAsList(iterable $rules): array
    {
        if (is_array($rules)) {
            /** @var list<object> $list */
            $list = array_is_list($rules) ? $rules : array_values($rules);

            return $list;
        }

        /** @var list<object> $list */
        $list = [];
        foreach ($rules as $rule) {
            $list[] = $rule;
        }

        return $list;
    }

    /**
     * @return array<string, mixed>
     */
    private function ruleCondition(object $rule): array
    {
        $condition = is_array($rule->condition_json ?? null)
            ? $rule->condition_json
            : (is_array($rule->condition ?? null) ? $rule->condition : null);
        if (! is_array($condition)) {
            throw new \RuntimeException('Feldregel hat ungültige JSON-Struktur.');
        }

        return $condition;
    }

    /**
     * @return array<string, mixed>
     */
    private function ruleAction(object $rule): array
    {
        $action = is_array($rule->action_json ?? null)
            ? $rule->action_json
            : (is_array($rule->action ?? null) ? $rule->action : null);
        if (! is_array($action)) {
            throw new \RuntimeException('Feldregel hat ungültige JSON-Struktur.');
        }

        return $action;
    }
}
