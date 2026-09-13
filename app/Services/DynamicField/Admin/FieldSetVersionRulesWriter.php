<?php

namespace App\Services\DynamicField\Admin;

use App\Enums\FieldScope;
use App\Enums\FieldSetVersionStatus;
use App\Enums\FieldType;
use App\Exceptions\FieldSetConflictException;
use App\Models\FieldRule;
use App\Models\FieldSet;
use App\Models\FieldSetVersion;
use App\Models\FieldSetVersionField;
use App\Models\SnapshotFieldDefinition;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Services\DynamicField\SnapshotFieldRuleEvaluator;
use App\Support\DynamicField\FieldDefinitionOptionContract;
use App\Support\DynamicField\FieldRuleContract;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use stdClass;

/**
 * DF-3-RULE-C: atomarer Desired-State für Regeln einer Feldset-Draft-Version.
 *
 * Preview ist schreibfrei. Apply ersetzt field_rules vollständig, vergibt
 * deterministische Sortwerte und schützt die DF-1-Seed-Regel in
 * system_calculation_core ohne Client-Metadaten und ohne Migration.
 */
final class FieldSetVersionRulesWriter
{
    public const SORT_STEP = 10;

    public function __construct(
        private readonly AuditLogger $audit,
        private readonly SnapshotFieldRuleEvaluator $evaluator,
    ) {}

    /**
     * @param  list<array<string, mixed>>  $desiredRules
     * @param  array<string, mixed>  $exampleHeader
     * @param  array<string, mixed>  $examplePosition
     * @return array<string, mixed>
     */
    public function preview(
        FieldSet $fieldSet,
        FieldSetVersion $version,
        array $desiredRules,
        int $expectedLockVersion,
        array $exampleHeader = [],
        array $examplePosition = [],
    ): array {
        $this->assertAdminFieldSet($fieldSet);
        $this->assertVersionOfSet($fieldSet, $version);

        if ((int) $fieldSet->lock_version !== $expectedLockVersion) {
            throw new FieldSetConflictException;
        }

        $version->loadMissing(['fields.revision.definition', 'fields.revision.options', 'rules']);
        $defsByKey = $this->definitionsFromMemberships($fieldSet, $version->fields);
        $previous = $this->serializeStoredRules($fieldSet, $version);
        $this->assertStoredSystemCoreInvariant($fieldSet, $previous);

        $normalized = $this->normalizeDesiredRules($fieldSet, $desiredRules, $defsByKey);
        $fingerprint = $this->fingerprint($normalized);
        $hasChanges = ! $this->equalsCanonical($previous, $normalized);

        $examples = $this->resolveExampleValues($version, $defsByKey, $exampleHeader, $examplePosition);
        $effects = $this->evaluateEffects(
            $normalized,
            $defsByKey,
            $version->fields,
            $examples['header'],
            $examples['position'],
        );

        return [
            'lock_version' => (int) $fieldSet->lock_version,
            'fingerprint' => $fingerprint,
            'has_changes' => $hasChanges,
            'version_id' => (int) $version->id,
            'version_status' => $version->status->value,
            'writable' => $version->status === FieldSetVersionStatus::Draft,
            'rules' => $normalized,
            'previous_rules' => $previous,
            'example_values' => $examples,
            'matched' => $effects['matched'],
            'effective_visible' => $effects['effective_visible'],
            'effective_required' => $effects['effective_required'],
            'warnings' => $effects['warnings'],
            'field_catalog' => $this->fieldCatalog($fieldSet, $version->fields),
        ];
    }

    /**
     * @param  array{
     *     rules: list<array<string, mixed>>,
     *     lock_version: int,
     *     fingerprint?: string
     * }  $payload
     * @return array{
     *     field_set: FieldSet,
     *     version: FieldSetVersion,
     *     has_changes: bool,
     *     rules: list<array<string, mixed>>,
     *     fingerprint: string,
     *     lock_version: int
     * }
     */
    public function replace(FieldSet $fieldSet, FieldSetVersion $version, array $payload, User $actor): array
    {
        $this->assertAdminFieldSet($fieldSet);
        $this->assertVersionOfSet($fieldSet, $version);

        return DB::transaction(function () use ($fieldSet, $version, $payload, $actor): array {
            /** @var FieldSet $locked */
            $locked = FieldSet::query()->whereKey($fieldSet->id)->lockForUpdate()->firstOrFail();
            $expectedLock = (int) $payload['lock_version'];
            if ((int) $locked->lock_version !== $expectedLock) {
                throw new FieldSetConflictException;
            }

            /** @var FieldSetVersion $lockedVersion */
            $lockedVersion = FieldSetVersion::query()->whereKey($version->id)->lockForUpdate()->firstOrFail();
            if ((int) $lockedVersion->field_set_id !== (int) $locked->id) {
                throw ValidationException::withMessages([
                    'version' => 'Die Version gehört nicht zu diesem Feldset.',
                ]);
            }
            if ($lockedVersion->status !== FieldSetVersionStatus::Draft) {
                throw ValidationException::withMessages([
                    'version' => 'Nur Entwurfsversionen dürfen Regeln erhalten.',
                ]);
            }

            FieldRule::query()
                ->where('field_set_version_id', $lockedVersion->id)
                ->orderBy('id')
                ->lockForUpdate()
                ->get(['id']);

            $lockedVersion->load(['fields.revision.definition', 'fields.revision.options', 'rules']);
            $defsByKey = $this->definitionsFromMemberships($locked, $lockedVersion->fields);
            $previous = $this->serializeStoredRules($locked, $lockedVersion);
            $this->assertStoredSystemCoreInvariant($locked, $previous);

            $normalized = $this->normalizeDesiredRules($locked, $payload['rules'], $defsByKey);
            $fingerprint = $this->fingerprint($normalized);

            if (array_key_exists('fingerprint', $payload)) {
                $expectedFingerprint = (string) $payload['fingerprint'];
                if (! hash_equals($fingerprint, $expectedFingerprint)) {
                    throw new FieldSetConflictException(
                        'Die Regelvorschau ist veraltet. Bitte die Vorschau erneut ausführen.',
                    );
                }
            }

            if ($this->equalsCanonical($previous, $normalized)) {
                return [
                    'field_set' => $locked,
                    'version' => $lockedVersion,
                    'has_changes' => false,
                    'rules' => $previous,
                    'fingerprint' => $fingerprint,
                    'lock_version' => (int) $locked->lock_version,
                ];
            }

            FieldRule::query()->where('field_set_version_id', $lockedVersion->id)->delete();

            foreach ($normalized as $row) {
                $rule = new FieldRule;
                $rule->field_set_version_id = $lockedVersion->id;
                $rule->sort = $row['sort'];
                $rule->condition_json = $row['condition'];
                $rule->action_json = $row['action'];
                $rule->save();
            }

            $locked->lock_version = $locked->lock_version + 1;
            $locked->save();

            $this->audit->record(
                $locked,
                'field_set.rules_replaced',
                $actor,
                [
                    'field_set_key' => $locked->key,
                    'version_id' => $lockedVersion->id,
                    'lock_version' => $expectedLock,
                    'rules' => array_map(
                        static fn (array $rule): array => [
                            'sort' => $rule['sort'],
                            'condition' => $rule['condition'],
                            'action' => $rule['action'],
                        ],
                        $previous,
                    ),
                ],
                [
                    'field_set_key' => $locked->key,
                    'version_id' => $lockedVersion->id,
                    'lock_version' => $locked->lock_version,
                    'rules' => array_map(
                        static fn (array $rule): array => [
                            'sort' => $rule['sort'],
                            'condition' => $rule['condition'],
                            'action' => $rule['action'],
                        ],
                        $normalized,
                    ),
                ],
            );

            $lockedVersion->load('rules');

            return [
                'field_set' => $locked->fresh() ?? $locked,
                'version' => $lockedVersion->fresh(['rules']) ?? $lockedVersion,
                'has_changes' => true,
                'rules' => $normalized,
                'fingerprint' => $fingerprint,
                'lock_version' => (int) $locked->lock_version,
            ];
        });
    }

    /**
     * Fail-closed für Activate: system_calculation_core braucht exakt einen Seed.
     *
     * @param  iterable<int, object>  $rules
     */
    public function assertSystemCoreRulesForActivation(FieldSet $fieldSet, iterable $rules): void
    {
        if ($fieldSet->key !== AdminFieldSetCatalog::CALCULATION_CORE) {
            return;
        }

        $serialized = [];
        foreach ($rules as $rule) {
            $condition = is_array($rule->condition_json ?? null)
                ? $rule->condition_json
                : (is_array($rule->condition ?? null) ? $rule->condition : null);
            $action = is_array($rule->action_json ?? null)
                ? $rule->action_json
                : (is_array($rule->action ?? null) ? $rule->action : null);
            if (! is_array($condition) || ! is_array($action)) {
                throw ValidationException::withMessages([
                    'rules' => 'Die System-Kernregeln sind beschädigt und können nicht aktiviert werden.',
                ]);
            }
            $serialized[] = [
                'sort' => (int) ($rule->sort ?? 0),
                'condition' => FieldRuleContract::canonicalizeCondition($condition),
                'action' => FieldRuleContract::canonicalizeAction($action),
                'dedupe_key' => FieldRuleContract::dedupePayload($condition, $action),
                'is_system_seed' => false,
            ];
        }

        usort($serialized, static fn (array $a, array $b): int => $a['sort'] <=> $b['sort']);
        $this->assertSystemCoreSeedShape($serialized, 'rules');
    }

    /**
     * @param  array<int, mixed>  $desiredRules
     * @param  array<string, object{
     *     key: string,
     *     field_type: FieldType,
     *     scope: FieldScope,
     *     options_json: list<array<string, mixed>>|null,
     *     action_target_readonly: bool
     * }>  $defsByKey
     * @return list<array{
     *     sort: int,
     *     condition: array<string, mixed>,
     *     action: array<string, mixed>,
     *     dedupe_key: string,
     *     is_system_seed: bool,
     *     summary: string
     * }>
     */
    private function normalizeDesiredRules(FieldSet $fieldSet, array $desiredRules, array $defsByKey): array
    {
        $normalized = [];
        /** @var array<string, int> $seenDedupe */
        $seenDedupe = [];

        $index = 0;
        foreach ($desiredRules as $row) {
            if (! is_array($row)) {
                throw ValidationException::withMessages([
                    "rules.{$index}" => 'Jede Regel muss ein Objekt mit condition und action sein.',
                ]);
            }

            foreach (array_keys($row) as $key) {
                if (! in_array((string) $key, ['condition', 'action'], true)) {
                    throw ValidationException::withMessages([
                        "rules.{$index}.{$key}" => 'Unzulässiges Attribut in der Regel. Nur condition und action sind erlaubt.',
                    ]);
                }
            }

            if (! array_key_exists('condition', $row) || ! is_array($row['condition'])) {
                throw ValidationException::withMessages([
                    "rules.{$index}.condition" => 'Die Regelbedingung muss ein Objekt sein.',
                ]);
            }
            if (! array_key_exists('action', $row) || ! is_array($row['action'])) {
                throw ValidationException::withMessages([
                    "rules.{$index}.action" => 'Die Regelaktion muss ein Objekt sein.',
                ]);
            }

            try {
                $condition = FieldRuleContract::canonicalizeCondition($row['condition']);
                $action = FieldRuleContract::canonicalizeAction($row['action']);
                FieldRuleContract::assertRuleStructure($defsByKey, $condition, $action, requireActiveOptionKeys: true);
            } catch (RuntimeException $exception) {
                throw ValidationException::withMessages([
                    "rules.{$index}" => $exception->getMessage(),
                ]);
            }

            $dedupe = FieldRuleContract::dedupePayload($condition, $action);
            if (isset($seenDedupe[$dedupe])) {
                throw ValidationException::withMessages([
                    "rules.{$index}" => 'Identische Regeln sind nicht erlaubt. Bitte Duplikate vor dem Speichern ändern oder entfernen.',
                ]);
            }
            $seenDedupe[$dedupe] = $index;

            $isSeed = $fieldSet->key === AdminFieldSetCatalog::CALCULATION_CORE
                && $dedupe === FieldRuleContract::SEED_RULE_DEDUPE_SHA256;

            $normalized[] = [
                'sort' => $index * self::SORT_STEP,
                'condition' => $condition,
                'action' => $action,
                'dedupe_key' => $dedupe,
                'is_system_seed' => $isSeed,
                'summary' => $this->ruleSummary($condition, $action),
            ];
            $index++;
        }

        try {
            FieldRuleContract::assertRuleset(
                $defsByKey,
                array_map(
                    static fn (array $rule): object => (object) [
                        'condition_json' => $rule['condition'],
                        'action_json' => $rule['action'],
                    ],
                    $normalized,
                ),
                requireActiveOptionKeys: true,
            );
        } catch (RuntimeException $exception) {
            throw ValidationException::withMessages([
                'rules' => $exception->getMessage(),
            ]);
        }

        if ($fieldSet->key === AdminFieldSetCatalog::CALCULATION_CORE) {
            $this->assertSystemCoreSeedShape($normalized, 'rules');
        }

        return $normalized;
    }

    /**
     * @param  list<array{sort: int, condition: array<string, mixed>, action: array<string, mixed>, dedupe_key: string, is_system_seed?: bool}>  $rules
     */
    private function assertSystemCoreSeedShape(array $rules, string $errorKey): void
    {
        $seedIndexes = [];
        foreach ($rules as $index => $rule) {
            if ($rule['dedupe_key'] === FieldRuleContract::SEED_RULE_DEDUPE_SHA256) {
                $seedIndexes[] = $index;
            }
        }

        if ($seedIndexes === []) {
            throw ValidationException::withMessages([
                $errorKey => 'Die Systemregel „Zeitraum offen → Flugzeitraum Pflicht“ fehlt und muss erhalten bleiben.',
            ]);
        }

        if (count($seedIndexes) > 1) {
            throw ValidationException::withMessages([
                $errorKey => 'Die Systemregel darf nur einmal vorkommen und nicht dupliziert werden.',
            ]);
        }

        if ($seedIndexes[0] !== 0) {
            throw ValidationException::withMessages([
                $errorKey => 'Die Systemregel muss an erster Stelle stehen und darf nicht umsortiert werden.',
            ]);
        }

        $seed = $rules[0];
        if (! FieldRuleContract::isExactDf1SeedRule($seed['condition'], $seed['action'])) {
            throw ValidationException::withMessages([
                $errorKey => 'Die Systemregel wurde verändert und ist ungültig.',
            ]);
        }

        if ((int) $seed['sort'] !== 0) {
            throw ValidationException::withMessages([
                $errorKey => 'Die Systemregel muss den Sortwert 0 behalten.',
            ]);
        }
    }

    /**
     * @param  list<array{sort: int, condition: array<string, mixed>, action: array<string, mixed>, dedupe_key: string, is_system_seed?: bool}>  $previous
     */
    private function assertStoredSystemCoreInvariant(FieldSet $fieldSet, array $previous): void
    {
        if ($fieldSet->key !== AdminFieldSetCatalog::CALCULATION_CORE) {
            return;
        }

        try {
            $this->assertSystemCoreSeedShape($previous, 'rules');
        } catch (ValidationException) {
            throw ValidationException::withMessages([
                'rules' => 'Der bestehende Regelbestand des System-Kernfeldsets ist inkonsistent. Speichern und Vorschau sind gesperrt.',
            ]);
        }
    }

    /**
     * @param  iterable<int, FieldSetVersionField>  $memberships
     * @return array<string, object{
     *     key: string,
     *     field_type: FieldType,
     *     scope: FieldScope,
     *     options_json: list<array<string, mixed>>|null,
     *     action_target_readonly: bool
     * }>
     */
    private function definitionsFromMemberships(FieldSet $fieldSet, iterable $memberships): array
    {
        $defsByKey = [];
        foreach ($memberships as $membership) {
            $revision = $membership->revision;
            $definition = $revision?->definition;
            if ($definition === null) {
                throw ValidationException::withMessages([
                    'fields' => 'Mindestens eine Membership hat keine gültige Definition.',
                ]);
            }
            $options = null;
            if ($definition->field_type->isChoice()) {
                $options = FieldDefinitionOptionContract::fromRevisionOptions($revision->options);
            }
            $defsByKey[$definition->key] = FieldRuleContract::normalizeDefinition((object) [
                'key' => $definition->key,
                'field_type' => $definition->field_type,
                'scope' => $definition->scope,
                'options_json' => $options,
                'action_target_readonly' => AdminFieldSetCatalog::isCalcOriginActionTarget(
                    $fieldSet->key,
                    $definition->key,
                ),
            ]);
        }

        return $defsByKey;
    }

    /**
     * @return list<array{
     *     sort: int,
     *     condition: array<string, mixed>,
     *     action: array<string, mixed>,
     *     dedupe_key: string,
     *     is_system_seed: bool,
     *     summary: string
     * }>
     */
    private function serializeStoredRules(FieldSet $fieldSet, FieldSetVersion $version): array
    {
        $out = [];
        foreach ($version->rules as $rule) {
            /** @var array<string, mixed> $condition */
            $condition = $rule->condition_json;
            /** @var array<string, mixed> $action */
            $action = $rule->action_json;
            $dedupe = FieldRuleContract::dedupePayload($condition, $action);
            $out[] = [
                'sort' => (int) $rule->sort,
                'condition' => FieldRuleContract::canonicalizeCondition($condition),
                'action' => FieldRuleContract::canonicalizeAction($action),
                'dedupe_key' => $dedupe,
                'is_system_seed' => $fieldSet->key === AdminFieldSetCatalog::CALCULATION_CORE
                    && $dedupe === FieldRuleContract::SEED_RULE_DEDUPE_SHA256,
                'summary' => $this->ruleSummary($condition, $action),
            ];
        }

        return $out;
    }

    /**
     * @param  list<array{sort: int, condition: array<string, mixed>, action: array<string, mixed>}>  $rules
     */
    private function fingerprint(array $rules): string
    {
        $canonical = array_map(
            static fn (array $rule): array => [
                'sort' => $rule['sort'],
                'condition' => $rule['condition'],
                'action' => $rule['action'],
            ],
            $rules,
        );

        return hash('sha256', json_encode($canonical, JSON_THROW_ON_ERROR));
    }

    /**
     * @param  list<array{sort: int, condition: array<string, mixed>, action: array<string, mixed>}>  $left
     * @param  list<array{sort: int, condition: array<string, mixed>, action: array<string, mixed>}>  $right
     */
    private function equalsCanonical(array $left, array $right): bool
    {
        return $this->fingerprint($left) === $this->fingerprint($right);
    }

    /**
     * @param  array<string, object>  $defsByKey
     * @param  array<string, mixed>  $exampleHeader
     * @param  array<string, mixed>  $examplePosition
     * @return array{header: array<string, mixed>, position: array<string, mixed>}
     */
    private function resolveExampleValues(
        FieldSetVersion $version,
        array $defsByKey,
        array $exampleHeader,
        array $examplePosition,
    ): array {
        $header = [];
        $position = [];
        foreach ($version->fields as $membership) {
            $revision = $membership->revision;
            $definition = $revision?->definition;
            if ($definition === null) {
                continue;
            }
            $example = $this->defaultExampleValue($definition->field_type, $definition->key, $revision);
            if ($definition->scope === FieldScope::Header) {
                $header[$definition->key] = $example;
            } else {
                $position[$definition->key] = $example;
            }
        }

        foreach ($exampleHeader as $key => $value) {
            if (array_key_exists($key, $defsByKey)) {
                $header[$key] = $value;
            }
        }
        foreach ($examplePosition as $key => $value) {
            if (array_key_exists($key, $defsByKey)) {
                $position[$key] = $value;
            }
        }

        return [
            'header' => $header,
            'position' => $position,
        ];
    }

    private function defaultExampleValue(FieldType $type, string $key, mixed $revision): mixed
    {
        if ($type->isChoice()) {
            $options = FieldDefinitionOptionContract::fromRevisionOptions($revision->options ?? []);
            foreach ($options as $option) {
                if ($option['is_active'] === true) {
                    return $type === FieldType::MultiSelect ? [$option['key']] : $option['key'];
                }
            }

            return $type === FieldType::MultiSelect ? [] : null;
        }

        return match ($type) {
            FieldType::Boolean => $key === 'period_open' ? false : true,
            FieldType::Period => [
                'start' => '2026-01-01',
                'end' => '2026-01-31',
            ],
            FieldType::ShortText => 'Beispiel',
            FieldType::LongText => 'Beispieltext für die Admin-Vorschau.',
            FieldType::Select, FieldType::MultiSelect => null,
        };
    }

    private function assertAdminFieldSet(FieldSet $fieldSet): void
    {
        if (! AdminFieldSetCatalog::isAdministrable($fieldSet)) {
            throw ValidationException::withMessages([
                'field_set' => 'Dieses Feldset ist nicht administrierbar.',
            ]);
        }
    }

    /**
     * @param  list<array{condition: array<string, mixed>, action: array<string, mixed>, sort: int}>  $rules
     * @param  array<string, object{
     *     key: string,
     *     field_type: FieldType,
     *     scope: FieldScope,
     *     options_json: list<array<string, mixed>>|null,
     *     action_target_readonly: bool
     * }>  $defsByKey
     * @param  iterable<int, FieldSetVersionField>  $memberships
     * @param  array<string, mixed>  $headerValues
     * @param  array<string, mixed>  $positionValues
     * @return array{
     *     matched: list<bool>,
     *     effective_visible: array{header: array<string, bool>, position: array<string, bool>},
     *     effective_required: array{header: array<string, bool>, position: array<string, bool>},
     *     warnings: list<array{code: string, message: string, field_key?: string}>
     * }
     */
    private function evaluateEffects(
        array $rules,
        array $defsByKey,
        iterable $memberships,
        array $headerValues,
        array $positionValues,
    ): array {
        $ruleObjects = [];
        foreach ($rules as $rule) {
            $obj = new stdClass;
            $obj->condition_json = $rule['condition'];
            $obj->action_json = $rule['action'];
            $obj->sort = $rule['sort'];
            $ruleObjects[] = $obj;
        }

        $basisVisibleHeader = [];
        $basisVisiblePosition = [];
        $basisRequiredHeader = [];
        $basisRequiredPosition = [];
        foreach ($memberships as $membership) {
            $revision = $membership->revision;
            $definition = $revision?->definition;
            if ($definition === null) {
                continue;
            }
            $key = $definition->key;
            if (! array_key_exists($key, $defsByKey)) {
                continue;
            }
            $basisVisible = SnapshotFieldDefinition::effectiveVisible($membership->visible_override);
            $basisRequired = SnapshotFieldDefinition::effectiveRequired($membership->required_override);
            if ($definition->scope === FieldScope::Header) {
                $basisVisibleHeader[$key] = $basisVisible;
                $basisRequiredHeader[$key] = $basisRequired;
            } else {
                $basisVisiblePosition[$key] = $basisVisible;
                $basisRequiredPosition[$key] = $basisRequired;
            }
        }

        $headerVisible = $this->evaluator->effectiveVisibilityForScope(
            $ruleObjects,
            $defsByKey,
            $headerValues,
            $positionValues,
            FieldScope::Header,
            $basisVisibleHeader,
            requireActiveOptionKeys: true,
        );
        $positionVisible = $this->evaluator->effectiveVisibilityForScope(
            $ruleObjects,
            $defsByKey,
            $headerValues,
            $positionValues,
            FieldScope::Position,
            $basisVisiblePosition,
            requireActiveOptionKeys: true,
        );
        $headerRequired = $this->evaluator->effectiveRequiredForScope(
            $ruleObjects,
            $defsByKey,
            $headerValues,
            $positionValues,
            FieldScope::Header,
            $basisRequiredHeader,
            $headerVisible,
            requireActiveOptionKeys: true,
        );
        $positionRequired = $this->evaluator->effectiveRequiredForScope(
            $ruleObjects,
            $defsByKey,
            $headerValues,
            $positionValues,
            FieldScope::Position,
            $basisRequiredPosition,
            $positionVisible,
            requireActiveOptionKeys: true,
        );

        $matched = [];
        foreach ($rules as $rule) {
            $matched[] = FieldRuleContract::conditionMatches(
                $rule['condition'],
                $headerValues,
                $positionValues,
                $defsByKey,
            );
        }

        $warnings = $this->buildWarnings($rules, $headerVisible, $positionVisible, $defsByKey);

        return [
            'matched' => $matched,
            'effective_visible' => [
                'header' => $headerVisible,
                'position' => $positionVisible,
            ],
            'effective_required' => [
                'header' => $headerRequired,
                'position' => $positionRequired,
            ],
            'warnings' => $warnings,
        ];
    }

    /**
     * @param  list<array{condition: array<string, mixed>, action: array<string, mixed>}>  $rules
     * @param  array<string, bool>  $headerVisible
     * @param  array<string, bool>  $positionVisible
     * @param  array<string, object{
     *     key: string,
     *     field_type: FieldType,
     *     scope: FieldScope,
     *     options_json: list<array<string, mixed>>|null,
     *     action_target_readonly: bool
     * }>  $defsByKey
     * @return list<array{code: string, message: string, field_key?: string}>
     */
    private function buildWarnings(
        array $rules,
        array $headerVisible,
        array $positionVisible,
        array $defsByKey,
    ): array {
        $warnings = [];
        /** @var array<string, true> $requiredTargets */
        $requiredTargets = [];
        /** @var array<string, true> $hiddenTargets */
        $hiddenTargets = [];

        foreach ($rules as $rule) {
            $action = $rule['action'];
            $target = (string) ($action['field_key'] ?? '');
            if ($target === '') {
                continue;
            }
            if (($action['op'] ?? null) === FieldRuleContract::ACTION_REQUIRE_FIELD) {
                $requiredTargets[$target] = true;
            }
            if (($action['op'] ?? null) === FieldRuleContract::ACTION_SET_VISIBLE
                && array_key_exists('value', $action)
                && $action['value'] === false
            ) {
                $hiddenTargets[$target] = true;
            }
        }

        foreach ($requiredTargets as $key => $_) {
            if (! isset($hiddenTargets[$key])) {
                continue;
            }
            $warnings[] = [
                'code' => 'require_and_hidden',
                'field_key' => $key,
                'message' => "Feld „{$key}“ wird per Regel Pflicht und zugleich unsichtbar gesetzt. Die Pflicht greift nur, solange das Feld sichtbar ist.",
            ];
        }

        foreach ($defsByKey as $key => $def) {
            $visible = $def->scope === FieldScope::Header
                ? ($headerVisible[$key] ?? true)
                : ($positionVisible[$key] ?? true);
            if (isset($requiredTargets[$key]) && ! $visible) {
                $already = false;
                foreach ($warnings as $warning) {
                    if ($warning['code'] === 'require_and_hidden' && $warning['field_key'] === $key) {
                        $already = true;
                        break;
                    }
                }
                if (! $already) {
                    $warnings[] = [
                        'code' => 'require_ineffective_when_hidden',
                        'field_key' => $key,
                        'message' => "Feld „{$key}“ ist im Beispiel effektiv unsichtbar; eine Pflichtregel wirkt dann nicht.",
                    ];
                }
            }
        }

        return $warnings;
    }

    /**
     * @param  iterable<int, FieldSetVersionField>  $memberships
     * @return list<array<string, mixed>>
     */
    private function fieldCatalog(FieldSet $fieldSet, iterable $memberships): array
    {
        $catalog = [];
        foreach ($memberships as $membership) {
            $revision = $membership->revision;
            $definition = $revision?->definition;
            if ($revision === null || $definition === null) {
                continue;
            }
            $options = [];
            if ($definition->field_type->isChoice()) {
                foreach (FieldDefinitionOptionContract::fromRevisionOptions($revision->options) as $option) {
                    $options[] = [
                        'key' => $option['key'],
                        'label' => $option['label'],
                        'sort' => $option['sort'],
                        'is_active' => $option['is_active'],
                    ];
                }
            }
            $catalog[] = [
                'key' => $definition->key,
                'label' => $revision->label,
                'field_type' => $definition->field_type->value,
                'scope' => $definition->scope->value,
                'is_system' => (bool) $definition->is_system,
                'action_target_readonly' => AdminFieldSetCatalog::isCalcOriginActionTarget(
                    $fieldSet->key,
                    $definition->key,
                ),
                'options' => $options,
            ];
        }

        return $catalog;
    }

    private function assertVersionOfSet(FieldSet $fieldSet, FieldSetVersion $version): void
    {
        if ((int) $version->field_set_id !== (int) $fieldSet->id) {
            throw ValidationException::withMessages([
                'version' => 'Die Version gehört nicht zu diesem Feldset.',
            ]);
        }
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
