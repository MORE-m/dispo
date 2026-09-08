<?php

namespace App\Services\DynamicField;

use App\Enums\ConfigurationSnapshotSource as ConfigurationSnapshotSourceEnum;
use App\Enums\FieldAppliesTo;
use App\Enums\FieldScope;
use App\Exceptions\FieldSetAssignmentConflictException;
use App\Models\ConfigurationSnapshot;
use App\Models\ConfigurationSnapshotSource;
use App\Models\ConfigurationSnapshotSourceField;
use App\Models\ConfigurationSnapshotSourceRule;
use App\Models\SnapshotFieldDefinition;
use App\Models\SnapshotFieldRule;
use App\Services\DynamicField\Assignment\AssignmentConfigurationLockCoordinator;
use App\Services\DynamicField\Assignment\AssignmentSourceLoader;
use App\Services\DynamicField\Assignment\FieldSetAssignmentMergeResolver;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/**
 * DF-3.3a2α / VER-002: friert Core + globale Assignments als Snapshot der
 * Generation 2 ein (Quellengraph + Property-Provenance).
 *
 * Bewusst nicht enthalten: Kategorie-/Werbemittelquellen und VER-003.
 */
final class ConfigurationSnapshotFreezeService
{
    public function __construct(
        private readonly AssignmentConfigurationLockCoordinator $locks,
        private readonly AssignmentSourceLoader $sourceLoader,
        private readonly FieldSetAssignmentMergeResolver $resolver,
        private readonly SnapshotFieldRuleEvaluator $ruleEvaluator,
    ) {}

    /**
     * Kalkulations-Freeze: Calculation-Core + aktive globale Calc-Assignments.
     *
     * Der erwartete Fingerprint ist im produktiven Create-Pfad zwingend und wird
     * unter den kanonischen Locks erneut gegen die Live-Auflösung geprüft.
     */
    public function freezeCalculationV2(string $expectedFingerprint): ConfigurationSnapshot
    {
        if (! ConfigurationSnapshotIntegrity::isValidFingerprint($expectedFingerprint)) {
            throw ValidationException::withMessages([
                'schema_fingerprint' => 'Schema-Fingerprint fehlt oder ist ungültig.',
            ]);
        }

        return DB::transaction(function () use ($expectedFingerprint): ConfigurationSnapshot {
            $this->locks->lockForProcess(FieldAppliesTo::Calculation, globalOnly: true);

            $resolved = $this->resolveGlobalLayer(FieldAppliesTo::Calculation);
            $this->assertResolvable($resolved);
            $this->assertFingerprint($expectedFingerprint, $resolved['fingerprint']);

            $core = $this->primaryCoreSource($resolved['resolved_sources']);

            $snapshot = new ConfigurationSnapshot;
            $snapshot->field_set_id = $core['field_set_id'];
            $snapshot->field_set_version_id = $core['field_set_version_id'];
            $snapshot->source = ConfigurationSnapshotSourceEnum::SeedActive;
            $snapshot->source_configuration_snapshot_id = null;
            $snapshot->format_version = ConfigurationSnapshot::FORMAT_VERSION_GLOBAL_FREEZE;
            $snapshot->schema_fingerprint = $resolved['fingerprint'];
            $snapshot->created_at = now();
            $snapshot->save();

            [$sourceIdByMergeOrder, $sourceFieldIndex] = $this->persistSources($snapshot, $resolved);

            foreach ($resolved['fields'] as $field) {
                $this->persistDefinitionFromResolvedField(
                    $snapshot,
                    $field,
                    $sourceIdByMergeOrder,
                    $sourceFieldIndex,
                );
            }

            foreach ($resolved['rules'] as $rule) {
                $this->persistRule(
                    $snapshot,
                    (int) $rule['field_rule_id'],
                    (int) $rule['sort'],
                    $rule['condition_json'],
                    $rule['action_json'],
                    $this->requireSourceId($sourceIdByMergeOrder, (int) $rule['merge_order']),
                );
            }

            $fresh = $this->reload($snapshot);
            $fresh->assertReadable();

            return $fresh;
        });
    }

    /**
     * Dispo-Freeze: Dispo-Core + aktive globale Dispo-Assignments plus
     * Calc-Origin-Import aus dem Kalkulationssnapshot.
     */
    public function freezeDispoV2(
        ConfigurationSnapshot $calculationSnapshot,
        ?string $expectedFingerprint = null,
    ): ConfigurationSnapshot {
        $this->assertSupportedFormatVersion($calculationSnapshot);
        $this->assertCalculationOriginSnapshot($calculationSnapshot);

        return DB::transaction(function () use ($calculationSnapshot, $expectedFingerprint): ConfigurationSnapshot {
            $this->locks->lockForProcess(FieldAppliesTo::DispoOrder, globalOnly: true);

            $calculationSnapshot->loadMissing(['fieldDefinitions', 'rules', 'fieldSet', 'fieldSetVersion']);

            $resolved = $this->resolveGlobalLayer(FieldAppliesTo::DispoOrder, [
                'source_configuration_snapshot_id' => (int) $calculationSnapshot->id,
                'source_format_version' => (int) $calculationSnapshot->format_version,
                'source_schema_fingerprint' => $calculationSnapshot->schema_fingerprint,
            ]);
            $this->assertResolvable($resolved);
            $this->assertFingerprint($expectedFingerprint, $resolved['fingerprint']);

            $core = $this->primaryCoreSource($resolved['resolved_sources']);
            $plan = $this->planDispoDefinitions($calculationSnapshot, $resolved['fields']);

            $snapshot = new ConfigurationSnapshot;
            $snapshot->field_set_id = $core['field_set_id'];
            $snapshot->field_set_version_id = $core['field_set_version_id'];
            $snapshot->source = ConfigurationSnapshotSourceEnum::DispoOrderCreate;
            $snapshot->source_configuration_snapshot_id = $calculationSnapshot->id;
            $snapshot->format_version = ConfigurationSnapshot::FORMAT_VERSION_GLOBAL_FREEZE;
            $snapshot->schema_fingerprint = $resolved['fingerprint'];
            $snapshot->created_at = now();
            $snapshot->save();

            [$sourceIdByMergeOrder, $sourceFieldIndex] = $this->persistSources($snapshot, $resolved);

            $calcOriginSourceId = $this->persistCalcOriginSource(
                $snapshot,
                $calculationSnapshot,
                $plan['calc_definitions'],
                $this->nextMergeOrder($sourceIdByMergeOrder),
            );

            foreach ($plan['calc_definitions'] as $calcDefinition) {
                $this->persistDefinitionFromCalcOrigin($snapshot, $calcDefinition, $calcOriginSourceId);
            }

            foreach ($plan['native_fields'] as $field) {
                $this->persistDefinitionFromResolvedField(
                    $snapshot,
                    $field,
                    $sourceIdByMergeOrder,
                    $sourceFieldIndex,
                );
            }

            /** @var array<string, true> $seenRuleKeys */
            $seenRuleKeys = [];

            foreach ($plan['calc_rules'] as $rule) {
                $dedupeKey = $this->ruleDedupeKey($rule->condition_json, $rule->action_json);
                $seenRuleKeys[$dedupeKey] = true;
                $sourceFieldRuleId = (int) ($rule->source_field_rule_id ?? $rule->id);
                ConfigurationSnapshotSourceRule::query()->create([
                    'configuration_snapshot_source_id' => $calcOriginSourceId,
                    'source_field_rule_id' => $sourceFieldRuleId,
                    'sort' => (int) $rule->sort,
                    'condition_json' => $rule->condition_json,
                    'action_json' => $rule->action_json,
                    'dedupe_key' => $dedupeKey,
                ]);
                $this->persistRule(
                    $snapshot,
                    $sourceFieldRuleId,
                    (int) $rule->sort,
                    $rule->condition_json,
                    $rule->action_json,
                    $calcOriginSourceId,
                );
            }

            foreach ($resolved['rules'] as $rule) {
                $dedupeKey = $this->ruleDedupeKey($rule['condition_json'], $rule['action_json']);
                if (isset($seenRuleKeys[$dedupeKey])) {
                    continue;
                }
                $seenRuleKeys[$dedupeKey] = true;
                $this->persistRule(
                    $snapshot,
                    (int) $rule['field_rule_id'],
                    (int) $rule['sort'],
                    $rule['condition_json'],
                    $rule['action_json'],
                    $this->requireSourceId($sourceIdByMergeOrder, (int) $rule['merge_order']),
                );
            }

            $fresh = $this->reload($snapshot);
            $fresh->assertReadable();
            $this->ruleEvaluator->assertRulesCompatibleWithDefinitions(
                $fresh->fieldDefinitions->keyBy('key')->all(),
                $fresh->rules,
            );

            return $fresh;
        });
    }

    /**
     * Live-Schema für den Wizard – ohne Persistenz und ohne Locks.
     *
     * @return array{
     *     fields: list<array<string, mixed>>,
     *     rules: list<array<string, mixed>>,
     *     conflicts: list<array<string, mixed>>,
     *     has_blocking_conflicts: bool,
     *     schema_fingerprint: string
     * }
     */
    public function resolveLiveSchemaForCalculation(): array
    {
        $resolved = $this->resolveGlobalLayer(FieldAppliesTo::Calculation);

        return [
            'fields' => $resolved['fields'],
            'rules' => $resolved['rules'],
            'conflicts' => $resolved['conflicts'],
            'has_blocking_conflicts' => $resolved['conflicts'] !== [],
            'schema_fingerprint' => $resolved['fingerprint'],
        ];
    }

    /**
     * Fail-closed gegen unbekannte Snapshot-Generationen.
     */
    public function assertSupportedFormatVersion(ConfigurationSnapshot $snapshot): void
    {
        $snapshot->assertReadable();
    }

    /**
     * @param  array<string, mixed>  $extraFingerprintParts
     * @return array{
     *     process: FieldAppliesTo,
     *     raw_sources: list<array<string, mixed>>,
     *     resolved_sources: list<array<string, mixed>>,
     *     fields: list<array<string, mixed>>,
     *     rules: list<array<string, mixed>>,
     *     conflicts: list<array<string, mixed>>,
     *     fingerprint: string
     * }
     */
    private function resolveGlobalLayer(FieldAppliesTo $process, array $extraFingerprintParts = []): array
    {
        $loaded = $this->sourceLoader->loadGlobalSources($process);

        $header = $this->resolver->resolve([
            'process' => $process,
            'scope' => FieldScope::Header,
            'sources' => $loaded['sources'],
        ]);
        $position = $this->resolver->resolve([
            'process' => $process,
            'scope' => FieldScope::Position,
            'sources' => $loaded['sources'],
        ]);

        $fingerprintParts = array_merge($loaded['fingerprint_parts'], $extraFingerprintParts);

        return [
            'process' => $process,
            'raw_sources' => $loaded['sources'],
            'resolved_sources' => $header['sources'],
            'fields' => array_merge($header['fields'], $position['fields']),
            'rules' => $this->mergeRules($header['rules'], $position['rules']),
            'conflicts' => array_merge($header['conflicts'], $position['conflicts']),
            'fingerprint' => $this->sourceLoader->fingerprint($fingerprintParts, $header, $position),
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $headerRules
     * @param  list<array<string, mixed>>  $positionRules
     * @return list<array<string, mixed>>
     */
    private function mergeRules(array $headerRules, array $positionRules): array
    {
        $merged = [];
        /** @var array<string, true> $seen */
        $seen = [];

        foreach ([$headerRules, $positionRules] as $bucket) {
            foreach ($bucket as $rule) {
                $key = $this->ruleDedupeKey($rule['condition_json'], $rule['action_json']);
                if (isset($seen[$key])) {
                    continue;
                }
                $seen[$key] = true;
                $merged[] = $rule;
            }
        }

        return $merged;
    }

    /**
     * @param  array<string, mixed>  $resolved
     */
    private function assertResolvable(array $resolved): void
    {
        /** @var list<array<string, mixed>> $conflicts */
        $conflicts = $resolved['conflicts'];

        if ($conflicts !== []) {
            throw ValidationException::withMessages([
                'configuration' => 'Die aktive Feldkonfiguration ist widersprüchlich und kann nicht eingefroren werden.',
                'conflicts' => array_map(
                    static fn (array $conflict): string => (string) $conflict['message'],
                    $conflicts,
                ),
            ]);
        }

        $defsByKey = [];
        foreach ($resolved['fields'] as $field) {
            $defsByKey[(string) $field['field_key']] = (object) $field;
        }
        $ruleObjects = [];
        foreach ($resolved['rules'] as $rule) {
            $ruleObjects[] = (object) [
                'condition_json' => $rule['condition_json'],
                'action_json' => $rule['action_json'],
            ];
        }

        try {
            $this->ruleEvaluator->assertRulesCompatibleWithDefinitions($defsByKey, $ruleObjects);
        } catch (RuntimeException $exception) {
            throw ValidationException::withMessages([
                'configuration' => $exception->getMessage(),
            ]);
        }
    }

    private function assertFingerprint(?string $expected, string $actual): void
    {
        // Dispo-Freeze darf ohne Client-Fingerprint laufen; Calc-Create übergibt
        // immer einen formal gültigen Fingerprint (siehe freezeCalculationV2).
        if ($expected === null) {
            return;
        }

        if (! hash_equals($expected, $actual)) {
            throw new FieldSetAssignmentConflictException(
                'Die Feldkonfiguration hat sich geändert. Bitte neu laden und erneut speichern.',
            );
        }
    }

    /**
     * @param  list<array<string, mixed>>  $resolvedSources
     * @return array<string, mixed>
     */
    private function primaryCoreSource(array $resolvedSources): array
    {
        foreach ($resolvedSources as $source) {
            if ((string) $source['layer'] === FieldSetAssignmentMergeResolver::LAYER_PRIMARY_CORE) {
                return $source;
            }
        }

        throw new RuntimeException('Freeze ohne Primary-Core-Quelle.');
    }

    /**
     * @param  array<string, mixed>  $resolved
     * @return array{0: array<int, int>, 1: array<int, array<int, array<string, mixed>>>}
     */
    private function persistSources(ConfigurationSnapshot $snapshot, array $resolved): array
    {
        /** @var array<string, array<string, mixed>> $rawByKey */
        $rawByKey = [];
        foreach ($resolved['raw_sources'] as $raw) {
            $rawByKey[$this->sourceKey((string) $raw['layer'], $raw['assignment_id'] ?? null)] = $raw;
        }

        /** @var array<int, int> $sourceIdByMergeOrder */
        $sourceIdByMergeOrder = [];
        /** @var array<int, array<int, array<string, mixed>>> $sourceFieldIndex */
        $sourceFieldIndex = [];

        foreach ($resolved['resolved_sources'] as $resolvedSource) {
            $key = $this->sourceKey(
                (string) $resolvedSource['layer'],
                $resolvedSource['assignment_id'] ?? null,
            );
            $raw = $rawByKey[$key] ?? null;
            if ($raw === null) {
                throw new RuntimeException("Quelle {$key} fehlt beim Einfrieren.");
            }

            $mergeOrder = (int) $resolvedSource['merge_order'];
            $isCore = (bool) ($raw['is_system_core'] ?? false);

            $source = new ConfigurationSnapshotSource;
            $source->configuration_snapshot_id = $snapshot->id;
            $source->merge_order = $mergeOrder;
            $source->layer = (string) $raw['layer'];
            $source->role = $isCore
                ? ConfigurationSnapshotSource::ROLE_CORE
                : ConfigurationSnapshotSource::ROLE_ASSIGNMENT;
            $source->field_set_id = (int) $raw['field_set_id'];
            $source->field_set_key = (string) $raw['field_set_key'];
            $source->field_set_name = (string) $raw['field_set_name'];
            $source->field_set_version_id = (int) $raw['field_set_version_id'];
            $source->field_set_version_number = (int) $raw['field_set_version_number'];
            $source->field_set_assignment_id = $raw['assignment_id'] ?? null;
            $source->assignment_lock_version = $raw['assignment_lock_version'] ?? null;
            $source->assignment_applies_to_process = $raw['assignment_applies_to_process'] ?? null;
            $source->assignment_sort = $raw['assignment_sort'] ?? null;
            $source->assignment_is_active = $raw['assignment_is_active'] ?? null;
            $source->target_layer = (string) $raw['target_layer'];
            $source->target_identity = (string) $raw['target_identity'];
            $source->target_id = null;
            $source->target_key = null;
            $source->target_name = null;
            $source->created_at = now();
            $source->save();

            $sourceIdByMergeOrder[$mergeOrder] = (int) $source->id;
            $sourceFieldIndex[(int) $source->id] = $this->persistSourceFields($source, $raw['memberships']);
            $this->persistSourceRules($source, $raw['rules']);
        }

        return [$sourceIdByMergeOrder, $sourceFieldIndex];
    }

    /**
     * @param  list<array<string, mixed>>  $memberships
     * @return array<int, array<string, mixed>>
     */
    private function persistSourceFields(ConfigurationSnapshotSource $source, array $memberships): array
    {
        $index = [];

        foreach ($memberships as $membership) {
            $payload = [
                'configuration_snapshot_source_id' => (int) $source->id,
                'field_definition_id' => (int) $membership['field_definition_id'],
                'field_definition_revision_id' => (int) $membership['field_definition_revision_id'],
                'field_key' => (string) $membership['field_key'],
                'field_type' => (string) $membership['field_type'],
                'scope' => (string) $membership['field_scope'],
                'applies_to' => (string) $membership['field_applies_to'],
                'label' => (string) $membership['label'],
                'help_text' => $membership['help_text'] ?? null,
                'group_key' => $membership['group_key'] ?? null,
                'membership_sort' => (int) $membership['sort'],
                'required_override' => $membership['required_override'] ?? null,
                'visible_override' => $membership['visible_override'] ?? null,
                'validation_json' => $membership['validation_json'] ?? null,
                'reportable' => (bool) ($membership['reportable'] ?? true),
                'definition_is_system' => (bool) ($membership['definition_is_system'] ?? false),
                'definition_is_active' => (bool) ($membership['definition_is_active'] ?? true),
            ];

            ConfigurationSnapshotSourceField::query()->create($payload);
            $index[(int) $membership['field_definition_id']] = $payload;
        }

        return $index;
    }

    /**
     * @param  list<array<string, mixed>>  $rules
     */
    private function persistSourceRules(ConfigurationSnapshotSource $source, array $rules): void
    {
        foreach ($rules as $rule) {
            ConfigurationSnapshotSourceRule::query()->create([
                'configuration_snapshot_source_id' => (int) $source->id,
                'source_field_rule_id' => (int) $rule['field_rule_id'],
                'sort' => (int) $rule['sort'],
                'condition_json' => $rule['condition_json'],
                'action_json' => $rule['action_json'],
                'dedupe_key' => $this->ruleDedupeKey($rule['condition_json'], $rule['action_json']),
            ]);
        }
    }

    /**
     * Calc-Origin-Quelle: Import der historischen Kalkulationsdefinitionen.
     *
     * @param  list<SnapshotFieldDefinition>  $calcDefinitions
     */
    private function persistCalcOriginSource(
        ConfigurationSnapshot $snapshot,
        ConfigurationSnapshot $calculationSnapshot,
        array $calcDefinitions,
        int $mergeOrder,
    ): int {
        $fieldSet = $calculationSnapshot->fieldSet;
        $version = $calculationSnapshot->fieldSetVersion;
        if ($fieldSet === null || $version === null) {
            throw new RuntimeException('Calc-Snapshot ohne Feldset-/Versionsreferenz.');
        }

        $source = new ConfigurationSnapshotSource;
        $source->configuration_snapshot_id = $snapshot->id;
        $source->merge_order = $mergeOrder;
        $source->layer = FieldSetAssignmentMergeResolver::LAYER_PRIMARY_CORE;
        $source->role = ConfigurationSnapshotSource::ROLE_ADDITIONAL;
        $source->field_set_id = (int) $calculationSnapshot->field_set_id;
        $source->field_set_key = (string) $fieldSet->key;
        $source->field_set_name = (string) $fieldSet->name;
        $source->field_set_version_id = (int) $calculationSnapshot->field_set_version_id;
        $source->field_set_version_number = (int) $version->version;
        $source->target_layer = FieldSetAssignmentMergeResolver::LAYER_GLOBAL;
        $source->target_identity = ConfigurationSnapshotSource::TARGET_IDENTITY_CALC_ORIGIN;
        $source->target_name = 'Calc-Origin';
        $source->created_at = now();
        $source->save();

        foreach ($calcDefinitions as $definition) {
            // Der Kalkulationssnapshot führt nur effektive Werte; Overrides
            // werden verlustfrei aus required/visible zurückgeschrieben.
            ConfigurationSnapshotSourceField::query()->create([
                'configuration_snapshot_source_id' => (int) $source->id,
                'field_definition_id' => (int) $definition->field_definition_id,
                'field_definition_revision_id' => (int) $definition->field_definition_revision_id,
                'field_key' => $definition->key,
                'field_type' => $definition->field_type->value,
                'scope' => $definition->scope->value,
                'applies_to' => $definition->applies_to->value,
                'label' => $definition->label,
                'help_text' => $definition->help_text,
                'group_key' => $definition->group_key,
                'membership_sort' => (int) $definition->sort,
                'required_override' => (bool) $definition->required,
                'visible_override' => (bool) $definition->visible,
                'validation_json' => $definition->validation_json,
                'reportable' => (bool) $definition->reportable,
                'definition_is_system' => false,
                'definition_is_active' => true,
            ]);
        }

        return (int) $source->id;
    }

    /**
     * @param  array<string, mixed>  $field
     * @param  array<int, int>  $sourceIdByMergeOrder
     * @param  array<int, array<int, array<string, mixed>>>  $sourceFieldIndex
     */
    private function persistDefinitionFromResolvedField(
        ConfigurationSnapshot $snapshot,
        array $field,
        array $sourceIdByMergeOrder,
        array $sourceFieldIndex,
    ): void {
        $definitionSourceId = $this->requireSourceId(
            $sourceIdByMergeOrder,
            (int) $field['provenance_definition_merge_order'],
        );
        $revisionSourceId = $this->requireSourceId(
            $sourceIdByMergeOrder,
            (int) $field['provenance_revision_merge_order'],
        );

        $frozen = $sourceFieldIndex[$revisionSourceId][(int) $field['field_definition_id']] ?? null;

        $definition = new SnapshotFieldDefinition;
        $definition->configuration_snapshot_id = $snapshot->id;
        $definition->field_definition_id = (int) $field['field_definition_id'];
        $definition->field_definition_revision_id = (int) $field['field_definition_revision_id'];
        $definition->key = (string) $field['field_key'];
        $definition->field_type = $frozen['field_type'] ?? (string) $field['field_type'];
        $definition->label = $frozen['label'] ?? (string) $field['label'];
        $definition->help_text = $frozen['help_text'] ?? ($field['help_text'] ?? null);
        $definition->scope = $frozen['scope'] ?? (string) $field['field_scope'];
        $definition->applies_to = $frozen['applies_to'] ?? (string) $field['applies_to'];
        $definition->sort = (int) $field['sort'];
        $definition->group_key = $field['group_key'] ?? null;
        $definition->reportable = (bool) ($frozen['reportable'] ?? ($field['reportable'] ?? true));
        $definition->required = (bool) $field['effective_required'];
        $definition->visible = (bool) $field['effective_visible'];
        $definition->validation_json = $frozen['validation_json'] ?? ($field['validation_json'] ?? null);
        $definition->provenance_definition_source_id = $definitionSourceId;
        $definition->provenance_revision_source_id = $revisionSourceId;
        $definition->provenance_required_source_id = $this->requireSourceId(
            $sourceIdByMergeOrder,
            (int) $field['provenance_required_merge_order'],
        );
        $definition->provenance_visible_source_id = $this->requireSourceId(
            $sourceIdByMergeOrder,
            (int) $field['provenance_visible_merge_order'],
        );
        $definition->provenance_sort_source_id = $this->requireSourceId(
            $sourceIdByMergeOrder,
            (int) $field['provenance_sort_merge_order'],
        );
        $definition->provenance_group_source_id = $this->requireSourceId(
            $sourceIdByMergeOrder,
            (int) $field['provenance_group_merge_order'],
        );
        $definition->save();
    }

    private function persistDefinitionFromCalcOrigin(
        ConfigurationSnapshot $snapshot,
        SnapshotFieldDefinition $source,
        int $calcOriginSourceId,
    ): void {
        $definition = new SnapshotFieldDefinition;
        $definition->configuration_snapshot_id = $snapshot->id;
        $definition->field_definition_id = $source->field_definition_id;
        $definition->field_definition_revision_id = $source->field_definition_revision_id;
        $definition->key = $source->key;
        $definition->field_type = $source->field_type;
        $definition->label = $source->label;
        $definition->help_text = $source->help_text;
        $definition->scope = $source->scope;
        $definition->applies_to = $source->applies_to;
        $definition->sort = $source->sort;
        $definition->group_key = $source->group_key;
        $definition->reportable = (bool) $source->reportable;
        $definition->required = (bool) $source->required;
        $definition->visible = (bool) $source->visible;
        $definition->validation_json = $source->validation_json;
        $definition->provenance_definition_source_id = $calcOriginSourceId;
        $definition->provenance_revision_source_id = $calcOriginSourceId;
        $definition->provenance_required_source_id = $calcOriginSourceId;
        $definition->provenance_visible_source_id = $calcOriginSourceId;
        $definition->provenance_sort_source_id = $calcOriginSourceId;
        $definition->provenance_group_source_id = $calcOriginSourceId;
        $definition->save();
    }

    /**
     * @param  array<string, mixed>  $condition
     * @param  array<string, mixed>  $action
     */
    private function persistRule(
        ConfigurationSnapshot $snapshot,
        int $sourceFieldRuleId,
        int $sort,
        array $condition,
        array $action,
        int $provenanceSourceId,
    ): void {
        $rule = new SnapshotFieldRule;
        $rule->configuration_snapshot_id = $snapshot->id;
        $rule->source_field_rule_id = $sourceFieldRuleId;
        $rule->sort = $sort;
        $rule->condition_json = $condition;
        $rule->action_json = $action;
        $rule->provenance_source_id = $provenanceSourceId;
        $rule->dedupe_key = $this->ruleDedupeKey($condition, $action);
        $rule->save();
    }

    /**
     * Calc-Origin gewinnt bei Key-Kollisionen (Semantik des Legacy-Composers).
     *
     * @param  list<array<string, mixed>>  $dispoFields
     * @return array{
     *     calc_definitions: list<SnapshotFieldDefinition>,
     *     native_fields: list<array<string, mixed>>,
     *     calc_rules: list<SnapshotFieldRule>
     * }
     */
    private function planDispoDefinitions(ConfigurationSnapshot $calculationSnapshot, array $dispoFields): array
    {
        $calcDefsByKey = $calculationSnapshot->fieldDefinitions->keyBy('key');

        /** @var list<SnapshotFieldDefinition> $calcDefinitions */
        $calcDefinitions = [];
        /** @var array<string, true> $seenKeys */
        $seenKeys = [];

        foreach (DispoConfigurationSnapshotComposer::CALC_ORIGIN_KEYS as $key) {
            /** @var SnapshotFieldDefinition|null $calcDefinition */
            $calcDefinition = $calcDefsByKey->get($key);
            if ($calcDefinition === null) {
                throw new RuntimeException("Calc-Snapshot fehlt Definition „{$key}“.");
            }
            $this->assertCalcOriginShape($calcDefinition, $key);
            $seenKeys[$key] = true;
            $calcDefinitions[] = $calcDefinition;
        }

        /** @var list<array<string, mixed>> $nativeFields */
        $nativeFields = [];

        foreach ($dispoFields as $field) {
            $key = (string) $field['field_key'];
            if (isset($seenKeys[$key])) {
                continue;
            }

            /** @var SnapshotFieldDefinition|null $calcDefinition */
            $calcDefinition = $calcDefsByKey->get($key);
            $seenKeys[$key] = true;

            if ($calcDefinition !== null) {
                $calcDefinitions[] = $calcDefinition;

                continue;
            }

            $nativeFields[] = $field;
        }

        foreach (DispoConfigurationSnapshotComposer::DISPO_TEXT_KEYS as $key) {
            if (! isset($seenKeys[$key])) {
                throw new RuntimeException("Dispo-Konfiguration fehlt Definition „{$key}“.");
            }
        }

        $calcRules = array_values($calculationSnapshot->rules
            ->filter(function (SnapshotFieldRule $rule) use ($seenKeys): bool {
                $conditionKey = (string) ($rule->condition_json['field_key'] ?? '');
                $actionKey = (string) ($rule->action_json['field_key'] ?? '');

                if ($conditionKey !== '' && ! isset($seenKeys[$conditionKey])) {
                    return false;
                }

                return $actionKey === '' || isset($seenKeys[$actionKey]);
            })
            ->all());

        return [
            'calc_definitions' => $calcDefinitions,
            'native_fields' => $nativeFields,
            'calc_rules' => $calcRules,
        ];
    }

    private function assertCalcOriginShape(SnapshotFieldDefinition $definition, string $key): void
    {
        $expectation = DispoConfigurationSnapshotComposer::CALC_ORIGIN_EXPECTATIONS[$key];

        if ($definition->field_type !== $expectation['type']) {
            throw new RuntimeException("Calc-Feld „{$key}“ hat falschen Typ.");
        }
        if ($definition->scope !== $expectation['scope']) {
            throw new RuntimeException("Calc-Feld „{$key}“ hat falschen Scope.");
        }
        if (! in_array($definition->applies_to, $expectation['applies_to'], true)) {
            throw new RuntimeException("Calc-Feld „{$key}“ hat falsches applies_to.");
        }
    }

    private function assertCalculationOriginSnapshot(ConfigurationSnapshot $snapshot): void
    {
        match ($snapshot->source) {
            ConfigurationSnapshotSourceEnum::SeedActive,
            ConfigurationSnapshotSourceEnum::LegacyBackfill => null,
            ConfigurationSnapshotSourceEnum::DispoOrderCreate,
            ConfigurationSnapshotSourceEnum::DispoOrderLegacyBackfill => throw new RuntimeException(
                'Quellsnapshot ist bereits ein Dispo-Snapshot und darf nicht erneut als Calc-Quelle dienen.',
            ),
        };
    }

    /**
     * @param  array<int, int>  $sourceIdByMergeOrder
     */
    private function nextMergeOrder(array $sourceIdByMergeOrder): int
    {
        if ($sourceIdByMergeOrder === []) {
            throw new RuntimeException('Freeze ohne eingefrorene Quellen.');
        }

        return max(array_keys($sourceIdByMergeOrder)) + 1;
    }

    /**
     * @param  array<int, int>  $sourceIdByMergeOrder
     */
    private function requireSourceId(array $sourceIdByMergeOrder, int $mergeOrder): int
    {
        if (! isset($sourceIdByMergeOrder[$mergeOrder])) {
            throw new RuntimeException("Keine eingefrorene Quelle für merge_order {$mergeOrder}.");
        }

        return $sourceIdByMergeOrder[$mergeOrder];
    }

    private function sourceKey(string $layer, int|string|null $assignmentId): string
    {
        return $layer.'|'.($assignmentId === null ? 'core' : (string) $assignmentId);
    }

    /**
     * @param  array<string, mixed>  $condition
     * @param  array<string, mixed>  $action
     */
    private function ruleDedupeKey(array $condition, array $action): string
    {
        return hash('sha256', json_encode([
            'condition' => $condition,
            'action' => $action,
        ], JSON_THROW_ON_ERROR));
    }

    private function reload(ConfigurationSnapshot $snapshot): ConfigurationSnapshot
    {
        return $snapshot->load([
            'fieldDefinitions',
            'rules',
            'sources.fields',
            'sources.rules',
        ]);
    }
}
