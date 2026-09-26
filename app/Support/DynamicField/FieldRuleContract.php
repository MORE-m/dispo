<?php

namespace App\Support\DynamicField;

use App\Enums\FieldScope;
use App\Enums\FieldType;
use RuntimeException;

/**
 * DF-3-RULE-A: kanonischer V1-Regelvertrag (Parse, Allowlist, Typ/Scope, all/any).
 *
 * Serverseitig maßgeblich; TypeScript-Parity spiegelt dieselben Ops/Limits.
 * Action-Zielschutz für Calc-Origin erfolgt über `action_target_readonly`
 * am normalisierten Definitionskontext – nicht über statische Keylisten.
 */
final class FieldRuleContract
{
    public const CONDITION_FIELD_EQUALS = 'field_equals';

    public const CONDITION_FIELD_EMPTY = 'field_empty';

    public const CONDITION_FIELD_NOT_EMPTY = 'field_not_empty';

    public const CONDITION_FIELD_CONTAINS = 'field_contains';

    public const CONDITION_ALL = 'all';

    public const CONDITION_ANY = 'any';

    public const ACTION_REQUIRE_FIELD = 'require_field';

    public const ACTION_SET_VISIBLE = 'set_visible';

    public const MIN_GROUP_CONDITIONS = 2;

    public const MAX_GROUP_CONDITIONS = 8;

    public const MAX_CONDITION_JSON_BYTES = 8192;

    public const MAX_ACTION_JSON_BYTES = 2048;

    /** @var list<string> */
    public const ATOMIC_CONDITION_OPS = [
        self::CONDITION_FIELD_EQUALS,
        self::CONDITION_FIELD_EMPTY,
        self::CONDITION_FIELD_NOT_EMPTY,
        self::CONDITION_FIELD_CONTAINS,
    ];

    /**
     * @param  array<string, mixed>  $condition
     * @return array<string, mixed>
     */
    public static function canonicalizeCondition(array $condition): array
    {
        $op = (string) ($condition['op'] ?? '');

        return match ($op) {
            self::CONDITION_ALL, self::CONDITION_ANY => self::canonicalizeGroup($op, $condition),
            self::CONDITION_FIELD_EQUALS => [
                'op' => self::CONDITION_FIELD_EQUALS,
                'field_key' => (string) ($condition['field_key'] ?? ''),
                'value' => $condition['value'] ?? null,
            ],
            self::CONDITION_FIELD_EMPTY => [
                'op' => self::CONDITION_FIELD_EMPTY,
                'field_key' => (string) ($condition['field_key'] ?? ''),
            ],
            self::CONDITION_FIELD_NOT_EMPTY => [
                'op' => self::CONDITION_FIELD_NOT_EMPTY,
                'field_key' => (string) ($condition['field_key'] ?? ''),
            ],
            self::CONDITION_FIELD_CONTAINS => [
                'op' => self::CONDITION_FIELD_CONTAINS,
                'field_key' => (string) ($condition['field_key'] ?? ''),
                'value' => (string) ($condition['value'] ?? ''),
            ],
            default => throw new RuntimeException(
                'Unbekannter Regel-Bedingungsoperator: '.$op,
            ),
        };
    }

    /**
     * @param  array<string, mixed>  $action
     * @return array<string, mixed>
     */
    public static function canonicalizeAction(array $action): array
    {
        $op = (string) ($action['op'] ?? '');

        return match ($op) {
            self::ACTION_REQUIRE_FIELD => [
                'op' => self::ACTION_REQUIRE_FIELD,
                'field_key' => (string) ($action['field_key'] ?? ''),
            ],
            self::ACTION_SET_VISIBLE => [
                'op' => self::ACTION_SET_VISIBLE,
                'field_key' => (string) ($action['field_key'] ?? ''),
                'value' => (bool) ($action['value'] ?? false),
            ],
            default => throw new RuntimeException(
                'Unbekannter Regel-Aktionsoperator: '.$op,
            ),
        };
    }

    /**
     * @param  array<string, mixed>  $condition
     * @param  array<string, mixed>  $action
     */
    public static function dedupePayload(array $condition, array $action): string
    {
        // Kanonische Key-Reihenfolge: op, field_key, [value] bzw. op, conditions.
        // Nur Kinder von all/any werden lexikographisch sortiert – kein generelles ksort.
        return hash('sha256', json_encode([
            'condition' => self::canonicalizeCondition($condition),
            'action' => self::canonicalizeAction($action),
        ], JSON_THROW_ON_ERROR));
    }

    /**
     * Exakter Legacy-SHA-256 der DF-1-Seed-Regel (period_open → position_flight_period).
     * Muss für alle bestehenden Snapshots unverändert bleiben.
     */
    public const SEED_RULE_DEDUPE_SHA256 = 'e306809641bbbd5158bdae6f89787d2edbf93de9b484b91269ee6f9ec200b9e6';

    /**
     * @param  array<string, mixed>  $condition
     * @return list<string>
     */
    public static function conditionFieldKeys(array $condition): array
    {
        $op = (string) ($condition['op'] ?? '');
        if (in_array($op, [self::CONDITION_ALL, self::CONDITION_ANY], true)) {
            $keys = [];
            foreach ($condition['conditions'] ?? [] as $child) {
                if (! is_array($child)) {
                    continue;
                }
                foreach (self::conditionFieldKeys($child) as $key) {
                    $keys[] = $key;
                }
            }

            return array_values(array_unique($keys));
        }

        $key = (string) ($condition['field_key'] ?? '');

        return $key === '' ? [] : [$key];
    }

    /**
     * @param  array<string, object>  $defsByKey  normalisierte Defs via {@see normalizeDefinition}
     * @param  array<string, mixed>  $condition
     * @param  array<string, mixed>  $action
     */
    public static function assertRuleStructure(
        array $defsByKey,
        array $condition,
        array $action,
        bool $requireActiveOptionKeys = true,
    ): void {
        self::assertJsonSize($condition, self::MAX_CONDITION_JSON_BYTES, 'Bedingung');
        self::assertJsonSize($action, self::MAX_ACTION_JSON_BYTES, 'Aktion');
        self::assertNoUnknownKeys($condition, self::allowedConditionKeys($condition), 'Bedingung');
        self::assertNoUnknownKeys($action, self::allowedActionKeys($action), 'Aktion');

        self::assertConditionNode($defsByKey, $condition, $requireActiveOptionKeys, allowGroup: true);
        self::assertActionNode($defsByKey, $action);
        self::assertActionTargetWritable($defsByKey, $condition, $action);
        self::assertScopeCompatibility($defsByKey, $condition, $action);
        self::assertSetVisibleNotSelfReferential($condition, $action);
    }

    /**
     * @param  array<string, object>  $defsByKey
     * @param  iterable<int, object>  $rules
     */
    public static function assertRuleset(
        array $defsByKey,
        iterable $rules,
        bool $requireActiveOptionKeys = true,
    ): void {
        /** @var array<string, true> $setVisibleTargets */
        $setVisibleTargets = [];

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

            self::assertRuleStructure($defsByKey, $condition, $action, $requireActiveOptionKeys);

            $actionOp = (string) ($action['op'] ?? '');
            if ($actionOp === self::ACTION_SET_VISIBLE) {
                $target = (string) ($action['field_key'] ?? '');
                if (isset($setVisibleTargets[$target])) {
                    throw new RuntimeException(
                        "Mehrere Sichtbarkeitsregeln auf Feld „{$target}“ sind in V1 nicht erlaubt.",
                    );
                }
                $setVisibleTargets[$target] = true;
            }
        }
    }

    /**
     * @param  array<string, mixed>|object  $def
     * @return object{
     *     key: string,
     *     field_type: FieldType,
     *     scope: FieldScope,
     *     options_json: list<array<string, mixed>>|null,
     *     action_target_readonly: bool
     * }
     */
    public static function normalizeDefinition(array|object $def): object
    {
        if (is_array($def)) {
            $def = (object) $def;
        }

        $key = (string) ($def->key ?? $def->field_key ?? '');
        if ($key === '') {
            throw new RuntimeException('Felddefinition ohne Key.');
        }

        $rawType = $def->field_type ?? null;
        $fieldType = $rawType instanceof FieldType
            ? $rawType
            : FieldType::from((string) $rawType);

        $rawScope = $def->scope ?? $def->field_scope ?? null;
        $scope = $rawScope instanceof FieldScope
            ? $rawScope
            : FieldScope::from((string) $rawScope);

        $options = $def->options_json ?? null;
        if ($options !== null && ! is_array($options)) {
            throw new RuntimeException("Options-JSON für Feld „{$key}“ ist ungültig.");
        }

        /** @var list<array<string, mixed>>|null $optionsList */
        $optionsList = null;
        if (is_array($options)) {
            $optionsList = array_values($options);
        }

        $readonly = (bool) ($def->action_target_readonly ?? $def->calc_origin ?? false);

        return (object) [
            'key' => $key,
            'field_type' => $fieldType,
            'scope' => $scope,
            'options_json' => $optionsList,
            'action_target_readonly' => $readonly,
        ];
    }

    /**
     * @param  array<array-key, object|array<string, mixed>>  $defsByKey
     * @return array<string, object{
     *     key: string,
     *     field_type: FieldType,
     *     scope: FieldScope,
     *     options_json: list<array<string, mixed>>|null,
     *     action_target_readonly: bool
     * }>
     */
    public static function normalizeDefinitions(array $defsByKey): array
    {
        $out = [];
        foreach ($defsByKey as $def) {
            $normalized = self::normalizeDefinition($def);
            $out[$normalized->key] = $normalized;
        }

        return $out;
    }

    /**
     * Exakte DF-1-Seed-Regel: field_equals(period_open, false) → require_field(position_flight_period).
     *
     * @param  array<string, mixed>  $condition
     * @param  array<string, mixed>  $action
     */
    public static function isExactDf1SeedRule(array $condition, array $action): bool
    {
        if (($action['op'] ?? null) !== self::ACTION_REQUIRE_FIELD) {
            return false;
        }
        if ((string) ($action['field_key'] ?? '') !== 'position_flight_period') {
            return false;
        }
        if (count($action) !== 2) {
            return false;
        }

        if (($condition['op'] ?? null) !== self::CONDITION_FIELD_EQUALS) {
            return false;
        }
        if ((string) ($condition['field_key'] ?? '') !== 'period_open') {
            return false;
        }
        if (! array_key_exists('value', $condition) || $condition['value'] !== false) {
            return false;
        }

        return count($condition) === 3;
    }

    /**
     * @param  array<string, mixed>  $condition
     * @param  array<string, mixed>  $headerValues
     * @param  array<string, mixed>  $positionValues
     * @param  array<string, object>  $defsByKey
     */
    public static function conditionMatches(
        array $condition,
        array $headerValues,
        array $positionValues,
        array $defsByKey,
    ): bool {
        $op = (string) ($condition['op'] ?? '');

        if ($op === self::CONDITION_ALL) {
            foreach ($condition['conditions'] as $child) {
                if (! self::conditionMatches($child, $headerValues, $positionValues, $defsByKey)) {
                    return false;
                }
            }

            return true;
        }

        if ($op === self::CONDITION_ANY) {
            foreach ($condition['conditions'] as $child) {
                if (self::conditionMatches($child, $headerValues, $positionValues, $defsByKey)) {
                    return true;
                }
            }

            return false;
        }

        return self::atomicConditionMatches($condition, $headerValues, $positionValues, $defsByKey);
    }

    /**
     * @param  object{field_type: FieldType}  $def
     */
    public static function isEmpty(object $def, mixed $value): bool
    {
        if ($value === null) {
            return true;
        }

        return match ($def->field_type) {
            FieldType::Boolean => self::asBool($value) === null,
            FieldType::Period => self::periodIsEmpty(is_array($value) ? $value : []),
            FieldType::ShortText, FieldType::LongText => trim((string) $value) === '',
            FieldType::Select => $value === '',
            FieldType::MultiSelect => $value === [] || $value === '',
            FieldType::File => FileFieldValueContract::isEmpty($value),
        };
    }

    public static function asBool(mixed $value): ?bool
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

    /**
     * @param  array<string, mixed>  $condition
     * @return array<string, mixed>
     */
    private static function canonicalizeGroup(string $op, array $condition): array
    {
        $children = $condition['conditions'] ?? null;
        if (! is_array($children)) {
            throw new RuntimeException('Gruppenbedingung benötigt ein Conditions-Array.');
        }

        $canonicalChildren = [];
        foreach ($children as $child) {
            if (! is_array($child)) {
                throw new RuntimeException('Gruppenbedingung enthält ungültige Kindbedingung.');
            }
            $childOp = (string) ($child['op'] ?? '');
            if ($childOp === self::CONDITION_ALL || $childOp === self::CONDITION_ANY) {
                throw new RuntimeException('Verschachtelte UND-/ODER-Gruppen sind in V1 nicht erlaubt.');
            }
            $canonicalChildren[] = self::canonicalizeCondition($child);
        }

        usort(
            $canonicalChildren,
            static fn (array $left, array $right): int => strcmp(
                (string) json_encode($left, JSON_THROW_ON_ERROR),
                (string) json_encode($right, JSON_THROW_ON_ERROR),
            ),
        );

        return [
            'op' => $op,
            'conditions' => $canonicalChildren,
        ];
    }

    /**
     * @param  array<string, object>  $defsByKey
     * @param  array<string, mixed>  $condition
     */
    private static function assertConditionNode(
        array $defsByKey,
        array $condition,
        bool $requireActiveOptionKeys,
        bool $allowGroup,
    ): void {
        $op = $condition['op'] ?? null;
        if (! is_string($op) || $op === '') {
            throw new RuntimeException('Regelbedingung ohne Operator.');
        }

        if ($op === self::CONDITION_ALL || $op === self::CONDITION_ANY) {
            if (! $allowGroup) {
                throw new RuntimeException('Verschachtelte UND-/ODER-Gruppen sind in V1 nicht erlaubt.');
            }
            $children = $condition['conditions'] ?? null;
            if (! is_array($children)) {
                throw new RuntimeException('UND-/ODER-Gruppe benötigt ein Conditions-Array.');
            }
            $count = count($children);
            if ($count < self::MIN_GROUP_CONDITIONS) {
                throw new RuntimeException(
                    'UND-/ODER-Gruppe braucht mindestens '.self::MIN_GROUP_CONDITIONS.' Bedingungen.',
                );
            }
            if ($count > self::MAX_GROUP_CONDITIONS) {
                throw new RuntimeException(
                    'UND-/ODER-Gruppe darf höchstens '.self::MAX_GROUP_CONDITIONS.' Bedingungen haben.',
                );
            }
            foreach ($children as $child) {
                if (! is_array($child)) {
                    throw new RuntimeException('UND-/ODER-Gruppe enthält ungültige Kindbedingung.');
                }
                self::assertNoUnknownKeys($child, self::allowedConditionKeys($child), 'Bedingung');
                self::assertConditionNode($defsByKey, $child, $requireActiveOptionKeys, allowGroup: false);
            }

            return;
        }

        if (! in_array($op, self::ATOMIC_CONDITION_OPS, true)) {
            throw new RuntimeException('Unbekannter Regel-Bedingungsoperator: '.$op);
        }

        $fieldKey = (string) ($condition['field_key'] ?? '');
        if ($fieldKey === '' || ! array_key_exists($fieldKey, $defsByKey)) {
            throw new RuntimeException(
                "Regelbedingung referenziert unbekanntes Feld „{$fieldKey}“.",
            );
        }

        /** @var object{field_type: FieldType, options_json: list<array<string, mixed>>|null} $def */
        $def = $defsByKey[$fieldKey];

        match ($op) {
            self::CONDITION_FIELD_EQUALS => self::assertEqualsValue($def, $condition, $requireActiveOptionKeys),
            self::CONDITION_FIELD_EMPTY, self::CONDITION_FIELD_NOT_EMPTY => self::assertEmptyOp($condition),
            self::CONDITION_FIELD_CONTAINS => self::assertContainsValue($def, $condition, $requireActiveOptionKeys),
        };
    }

    /**
     * @param  object{field_type: FieldType, options_json: list<array<string, mixed>>|null}  $def
     * @param  array<string, mixed>  $condition
     */
    private static function assertEqualsValue(object $def, array $condition, bool $requireActiveOptionKeys): void
    {
        if (! array_key_exists('value', $condition)) {
            throw new RuntimeException('field_equals benötigt einen Vergleichswert.');
        }

        $type = $def->field_type;
        $value = $condition['value'];

        if ($type === FieldType::Boolean) {
            if (! is_bool($value)) {
                throw new RuntimeException('field_equals auf Boolean erwartet einen echten Boolean-Wert.');
            }

            return;
        }

        if ($type === FieldType::ShortText || $type === FieldType::LongText) {
            if (! is_string($value)) {
                throw new RuntimeException('field_equals auf Text erwartet einen String.');
            }

            return;
        }

        if ($type === FieldType::Select) {
            if (! is_string($value) || $value === '') {
                throw new RuntimeException('field_equals auf Select erwartet einen Options-Key.');
            }
            self::assertOptionKeyAllowed($def, $value, $requireActiveOptionKeys);

            return;
        }

        if ($type === FieldType::MultiSelect) {
            throw new RuntimeException(
                'field_equals ist für Multi-Select nicht erlaubt; field_contains verwenden.',
            );
        }

        if ($type === FieldType::File) {
            throw new RuntimeException(
                'field_equals ist für Datei-Felder nicht erlaubt; field_empty / field_not_empty verwenden.',
            );
        }

        throw new RuntimeException(
            'field_equals ist für Zeitraum-Felder nicht erlaubt; field_empty / field_not_empty verwenden.',
        );
    }

    /**
     * @param  array<string, mixed>  $condition
     */
    private static function assertEmptyOp(array $condition): void
    {
        if (array_key_exists('value', $condition)) {
            throw new RuntimeException('field_empty / field_not_empty dürfen keinen value tragen.');
        }
    }

    /**
     * @param  object{field_type: FieldType, options_json: list<array<string, mixed>>|null}  $def
     * @param  array<string, mixed>  $condition
     */
    private static function assertContainsValue(object $def, array $condition, bool $requireActiveOptionKeys): void
    {
        if ($def->field_type !== FieldType::MultiSelect) {
            throw new RuntimeException('field_contains ist nur für Multi-Select erlaubt.');
        }
        $value = $condition['value'] ?? null;
        if (! is_string($value) || $value === '') {
            throw new RuntimeException('field_contains erwartet genau einen Options-Key als String.');
        }
        self::assertOptionKeyAllowed($def, $value, $requireActiveOptionKeys);
    }

    /**
     * @param  object{key?: string, options_json: list<array<string, mixed>>|null}  $def
     */
    private static function assertOptionKeyAllowed(object $def, string $optionKey, bool $requireActive): void
    {
        $options = $def->options_json;
        if (! is_array($options)) {
            throw new RuntimeException(
                'Auswahlfeld „'.((string) ($def->key ?? '')).'“ hat keinen Optionskatalog für die Regelprüfung.',
            );
        }

        $found = null;
        foreach ($options as $row) {
            if ((string) ($row['key'] ?? '') === $optionKey) {
                $found = $row;
                break;
            }
        }

        if ($found === null) {
            throw new RuntimeException(
                "Options-Key „{$optionKey}“ ist im Feldkatalog nicht vorhanden.",
            );
        }

        if ($requireActive && ! ($found['is_active'] ?? false)) {
            throw new RuntimeException(
                "Options-Key „{$optionKey}“ ist inaktiv und darf in neuen Regeln nicht verwendet werden.",
            );
        }
    }

    /**
     * @param  array<string, object>  $defsByKey
     * @param  array<string, mixed>  $action
     */
    private static function assertActionNode(array $defsByKey, array $action): void
    {
        $op = $action['op'] ?? null;
        if ($op !== self::ACTION_REQUIRE_FIELD && $op !== self::ACTION_SET_VISIBLE) {
            throw new RuntimeException(
                'Unbekannter Regel-Aktionsoperator: '.((string) $op),
            );
        }

        $fieldKey = (string) ($action['field_key'] ?? '');
        if ($fieldKey === '' || ! array_key_exists($fieldKey, $defsByKey)) {
            throw new RuntimeException(
                "Regelaktion referenziert unbekanntes Feld „{$fieldKey}“.",
            );
        }

        if ($op === self::ACTION_SET_VISIBLE) {
            if (! array_key_exists('value', $action) || ! is_bool($action['value'])) {
                throw new RuntimeException('set_visible benötigt value als Boolean.');
            }
        }

        if ($op === self::ACTION_REQUIRE_FIELD) {
            /** @var object{field_type: FieldType} $targetDef */
            $targetDef = $defsByKey[$fieldKey];
            if ($targetDef->field_type === FieldType::File) {
                throw new RuntimeException(
                    'Datei-Felder können in V1/01c nicht per require_field-Regel verpflichtet werden.',
                );
            }
        }
    }

    /**
     * Calc-Origin (action_target_readonly): Condition-Quelle ok, Action-Ziel verboten.
     * Einzige Kompatibilitätsausnahme: exakte DF-1-Seed-Regel.
     *
     * @param  array<string, object{action_target_readonly?: bool}>  $defsByKey
     * @param  array<string, mixed>  $condition
     * @param  array<string, mixed>  $action
     */
    private static function assertActionTargetWritable(
        array $defsByKey,
        array $condition,
        array $action,
    ): void {
        $fieldKey = (string) ($action['field_key'] ?? '');
        $def = $defsByKey[$fieldKey] ?? null;
        if ($def === null || ! ($def->action_target_readonly ?? false)) {
            return;
        }

        $op = (string) ($action['op'] ?? '');
        if ($op === self::ACTION_SET_VISIBLE) {
            throw new RuntimeException(
                "Calc-Origin-Feld „{$fieldKey}“ darf nicht Ziel einer Sichtbarkeitsregel sein.",
            );
        }

        if (self::isExactDf1SeedRule($condition, $action)) {
            return;
        }

        throw new RuntimeException(
            "Calc-Origin-Feld „{$fieldKey}“ darf nicht Ziel einer Pflichtregel sein.",
        );
    }

    /**
     * @param  array<string, mixed>  $condition
     * @param  array<string, mixed>  $action
     */
    private static function assertSetVisibleNotSelfReferential(array $condition, array $action): void
    {
        if (($action['op'] ?? null) !== self::ACTION_SET_VISIBLE) {
            return;
        }

        $target = (string) ($action['field_key'] ?? '');
        if ($target === '') {
            return;
        }

        if (in_array($target, self::conditionFieldKeys($condition), true)) {
            throw new RuntimeException(
                "set_visible darf das Zielfeld „{$target}“ nicht in der eigenen Bedingung referenzieren.",
            );
        }
    }

    /**
     * @param  array<string, object>  $defsByKey
     * @param  array<string, mixed>  $condition
     * @param  array<string, mixed>  $action
     */
    private static function assertScopeCompatibility(array $defsByKey, array $condition, array $action): void
    {
        $actionKey = (string) ($action['field_key'] ?? '');
        /** @var object{scope: FieldScope} $actionDef */
        $actionDef = $defsByKey[$actionKey];

        if ($actionDef->scope === FieldScope::Header) {
            foreach (self::conditionFieldKeys($condition) as $key) {
                /** @var object{scope: FieldScope} $def */
                $def = $defsByKey[$key];
                if ($def->scope !== FieldScope::Header) {
                    throw new RuntimeException(
                        'Position→Header-Regeln sind in V1 nicht erlaubt; Header-Aktionen brauchen ausschließlich Header-Bedingungen.',
                    );
                }
            }
        }

        // Position-Action: Header- und Positionsatome in derselben flachen Gruppe erlaubt.
        // Positionsatome werden zur Laufzeit nur gegen die aktuelle Position gelesen.
    }

    /**
     * @param  array<string, mixed>  $condition
     * @param  array<string, mixed>  $headerValues
     * @param  array<string, mixed>  $positionValues
     * @param  array<string, object>  $defsByKey
     */
    private static function atomicConditionMatches(
        array $condition,
        array $headerValues,
        array $positionValues,
        array $defsByKey,
    ): bool {
        $op = (string) ($condition['op'] ?? '');
        $key = (string) ($condition['field_key'] ?? '');
        if ($key === '' || ! array_key_exists($key, $defsByKey)) {
            throw new RuntimeException(
                "Regelbedingung referenziert unbekanntes Feld „{$key}“.",
            );
        }

        /** @var object{field_type: FieldType, scope: FieldScope} $def */
        $def = $defsByKey[$key];
        $actual = match ($def->scope) {
            FieldScope::Header => $headerValues[$key] ?? null,
            FieldScope::Position => $positionValues[$key] ?? null,
        };

        return match ($op) {
            self::CONDITION_FIELD_EQUALS => self::equalsMatches($def, $actual, $condition['value'] ?? null),
            self::CONDITION_FIELD_EMPTY => self::isEmpty($def, $actual),
            self::CONDITION_FIELD_NOT_EMPTY => ! self::isEmpty($def, $actual),
            self::CONDITION_FIELD_CONTAINS => self::containsMatches($actual, $condition['value'] ?? null),
            default => throw new RuntimeException('Unbekannter Regel-Bedingungsoperator: '.$op),
        };
    }

    /**
     * @param  object{field_type: FieldType}  $def
     */
    private static function equalsMatches(object $def, mixed $actual, mixed $expected): bool
    {
        if ($def->field_type === FieldType::Boolean) {
            if (! is_bool($expected)) {
                return false;
            }

            return self::asBool($actual) === $expected;
        }

        // Text/Select: exakt, case-sensitive, ohne Trim.
        return $actual === $expected;
    }

    private static function containsMatches(mixed $actual, mixed $expected): bool
    {
        if (! is_string($expected) || $expected === '') {
            return false;
        }
        if (! is_array($actual)) {
            return false;
        }

        foreach ($actual as $item) {
            if ((string) $item === $expected) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $period
     */
    private static function periodIsEmpty(array $period): bool
    {
        $start = $period['start'] ?? $period['period_start'] ?? null;
        $end = $period['end'] ?? $period['period_end'] ?? null;

        return $start === null || $start === '' || $end === null || $end === '';
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  list<string>  $allowed
     */
    private static function assertNoUnknownKeys(array $payload, array $allowed, string $label): void
    {
        foreach (array_keys($payload) as $key) {
            if (! in_array((string) $key, $allowed, true)) {
                throw new RuntimeException(
                    "{$label} enthält unzulässiges Attribut „{$key}“.",
                );
            }
        }
    }

    /**
     * @param  array<string, mixed>  $condition
     * @return list<string>
     */
    private static function allowedConditionKeys(array $condition): array
    {
        $op = (string) ($condition['op'] ?? '');

        return match ($op) {
            self::CONDITION_ALL, self::CONDITION_ANY => ['op', 'conditions'],
            self::CONDITION_FIELD_EQUALS, self::CONDITION_FIELD_CONTAINS => ['op', 'field_key', 'value'],
            self::CONDITION_FIELD_EMPTY, self::CONDITION_FIELD_NOT_EMPTY => ['op', 'field_key'],
            default => ['op', 'field_key', 'value', 'conditions'],
        };
    }

    /**
     * @param  array<string, mixed>  $action
     * @return list<string>
     */
    private static function allowedActionKeys(array $action): array
    {
        $op = (string) ($action['op'] ?? '');

        return match ($op) {
            self::ACTION_SET_VISIBLE => ['op', 'field_key', 'value'],
            default => ['op', 'field_key'],
        };
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private static function assertJsonSize(array $payload, int $maxBytes, string $label): void
    {
        $encoded = json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (strlen($encoded) > $maxBytes) {
            throw new RuntimeException(
                "{$label} überschreitet das Größenlimit von {$maxBytes} Byte.",
            );
        }
    }
}
