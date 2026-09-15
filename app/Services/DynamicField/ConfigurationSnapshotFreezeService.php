<?php

namespace App\Services\DynamicField;

use App\Enums\ConfigurationSnapshotSource as ConfigurationSnapshotSourceEnum;
use App\Enums\FieldAppliesTo;
use App\Enums\FieldScope;
use App\Exceptions\FieldSetAssignmentConflictException;
use App\Models\AdvertisingMedium;
use App\Models\CalculationPosition;
use App\Models\ConfigurationSnapshot;
use App\Models\ConfigurationSnapshotSource;
use App\Models\ConfigurationSnapshotSourceField;
use App\Models\ConfigurationSnapshotSourceRule;
use App\Models\DispoOrderPosition;
use App\Models\SnapshotFieldDefinition;
use App\Models\SnapshotFieldRule;
use App\Services\DynamicField\Assignment\AssignmentConfigurationLockCoordinator;
use App\Services\DynamicField\Assignment\AssignmentSourceLoader;
use App\Services\DynamicField\Assignment\FieldSetAssignmentMergeResolver;
use App\Support\DynamicField\FieldRuleDefinitionContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/**
 * DF-3.3a2α / VER-002: friert Core + globale Assignments als Snapshot der
 * Generation 2 ein (Quellengraph + Property-Provenance).
 *
 * DF-3.3a2β / VER-003: Generation 3 ergänzt Kategorie-/Werbemittelquellen und
 * trennt Basis-Snapshot (Header) von positionsscharfen Effektiv-Snapshots.
 */
final class ConfigurationSnapshotFreezeService
{
    public function __construct(
        private readonly AssignmentConfigurationLockCoordinator $locks,
        private readonly AssignmentSourceLoader $sourceLoader,
        private readonly FieldSetAssignmentMergeResolver $resolver,
        private readonly SnapshotFieldRuleEvaluator $ruleEvaluator,
        private readonly ConfigurationSnapshotIntegrity $integrity,
        private readonly PositionEffectiveValueRemapper $remapper,
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
                FieldRuleDefinitionContext::fromSnapshot($fresh),
                $fresh->rules,
                requireActiveOptionKeys: false,
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
     * DF-3.3a2β / VER-003: Kalkulations-Freeze der Generation 3.
     *
     * Der Basis-Snapshot trägt ausschließlich Headerfelder und den vollständigen
     * Quellenuniversum-Graphen; je Position entsteht ein Effektiv-Snapshot mit
     * den Positionsfeldern im eingefrorenen Werbemittelkontext.
     *
     * @param  list<array<string, mixed>>  $positions  je Position `advertising_medium_id`,
     *                                                 optional `client_key`/`id` und `schema_fingerprint`
     * @return array{base: ConfigurationSnapshot, effectives_by_client_key: array<string, ConfigurationSnapshot>}
     */
    public function freezeCalculationV3(string $expectedBaseFingerprint, array $positions): array
    {
        if (! ConfigurationSnapshotIntegrity::isValidFingerprint($expectedBaseFingerprint)) {
            throw ValidationException::withMessages([
                'schema_fingerprint' => 'Schema-Fingerprint fehlt oder ist ungültig.',
            ]);
        }

        return DB::transaction(function () use ($expectedBaseFingerprint, $positions): array {
            $this->locks->lockForProcess(FieldAppliesTo::Calculation, globalOnly: false);

            $universe = $this->loadUniverse(FieldAppliesTo::Calculation);
            $base = $this->resolveBaseLayer($universe);
            $this->assertFingerprint($expectedBaseFingerprint, $base['fingerprint']);

            /** @var array<string, array{context: array<string, mixed>, resolved: array<string, mixed>}> $plans */
            $plans = [];

            foreach ($positions as $index => $position) {
                $key = $this->positionKey($position, $index);
                if (isset($plans[$key])) {
                    throw ValidationException::withMessages([
                        "positions.{$index}.client_key" => 'Positionsschlüssel ist nicht eindeutig.',
                    ]);
                }

                $context = $this->freezePositionContext(
                    $this->requiredMediumId($position, $index),
                    "positions.{$index}.advertising_medium_id",
                );
                $resolved = $this->resolvePositionLayer(
                    $universe,
                    (int) $context['context_advertising_category_id'],
                    (int) $context['context_advertising_medium_id'],
                );
                $this->assertRequiredFingerprint(
                    isset($position['schema_fingerprint']) ? (string) $position['schema_fingerprint'] : null,
                    $resolved['fingerprint'],
                    "positions.{$index}.schema_fingerprint",
                );

                $plans[$key] = ['context' => $context, 'resolved' => $resolved];
            }

            $this->assertResolvable($base);
            foreach ($plans as $plan) {
                $this->assertResolvable($plan['resolved']);
            }

            $baseSnapshot = $this->persistGenerationThree([
                'source' => ConfigurationSnapshotSourceEnum::SeedActive,
                'parent' => null,
                'origin' => null,
                'context' => $this->emptyContext(),
                'fingerprint' => $base['fingerprint'],
                'raw_sources' => $universe['raw_sources'],
                'merge_order_by_key' => $universe['merge_order_by_key'],
                'resolved_sources' => $base['header']['sources'],
                'fields' => $base['header']['fields'],
                'rules' => $base['header']['rules'],
                'calc_origin' => null,
            ]);

            /** @var array<string, ConfigurationSnapshot> $effectives */
            $effectives = [];
            foreach ($plans as $key => $plan) {
                $effectives[$key] = $this->persistGenerationThree([
                    'source' => ConfigurationSnapshotSourceEnum::CalculationPositionEffective,
                    'parent' => $baseSnapshot,
                    'origin' => null,
                    'context' => $plan['context'],
                    'fingerprint' => $plan['resolved']['fingerprint'],
                    'raw_sources' => $plan['resolved']['raw_sources'],
                    'merge_order_by_key' => $universe['merge_order_by_key'],
                    'resolved_sources' => $plan['resolved']['position']['sources'],
                    'fields' => $plan['resolved']['fields'],
                    'rules' => $plan['resolved']['rules'],
                    'calc_origin' => null,
                ]);
            }

            return [
                'base' => $baseSnapshot,
                'effectives_by_client_key' => $effectives,
            ];
        });
    }

    /**
     * Live-Basisschema (Header plus globale Positionsfelder) für den Wizard ohne
     * gewähltes Werbemittel – ohne Persistenz und ohne Locks.
     *
     * @return array{
     *     fields: list<array<string, mixed>>,
     *     rules: list<array<string, mixed>>,
     *     conflicts: list<array<string, mixed>>,
     *     has_blocking_conflicts: bool,
     *     schema_fingerprint: string
     * }
     */
    public function resolveLiveSchemaForCalculationV3(): array
    {
        $universe = $this->loadUniverse(FieldAppliesTo::Calculation);
        $base = $this->resolveBaseLayer($universe);

        return [
            'fields' => $base['fields'],
            'rules' => $base['rules'],
            'conflicts' => $base['conflicts'],
            'has_blocking_conflicts' => $base['conflicts'] !== [],
            'schema_fingerprint' => $base['fingerprint'],
        ];
    }

    /**
     * Live-Positionsschema für ein Werbemittel – ohne Persistenz und ohne Locks.
     *
     * @return array{
     *     fields: list<array<string, mixed>>,
     *     rules: list<array<string, mixed>>,
     *     conflicts: list<array<string, mixed>>,
     *     has_blocking_conflicts: bool,
     *     schema_fingerprint: string,
     *     context: array<string, mixed>
     * }
     */
    public function resolveLivePositionSchema(int $mediumId): array
    {
        $universe = $this->loadUniverse(FieldAppliesTo::Calculation);
        $context = $this->freezePositionContext($mediumId, 'advertising_medium_id');
        $resolved = $this->resolvePositionLayer(
            $universe,
            (int) $context['context_advertising_category_id'],
            (int) $context['context_advertising_medium_id'],
        );

        return [
            'fields' => $resolved['fields'],
            'rules' => $resolved['rules'],
            'conflicts' => $resolved['conflicts'],
            'has_blocking_conflicts' => $resolved['conflicts'] !== [],
            'schema_fingerprint' => $resolved['fingerprint'],
            'context' => $context,
        ];
    }

    /**
     * Positionsschema einer bestehenden Generation-3-Basis. Gemergt wird
     * ausschließlich aus den **eingefrorenen** Quellen des Basis-Snapshots –
     * niemals aus der Live-Konfiguration.
     *
     * @return array{
     *     fields: list<array<string, mixed>>,
     *     rules: list<array<string, mixed>>,
     *     conflicts: list<array<string, mixed>>,
     *     has_blocking_conflicts: bool,
     *     schema_fingerprint: string,
     *     context: array<string, mixed>
     * }
     */
    public function resolvePositionSchemaFromBase(ConfigurationSnapshot $base, int $mediumId): array
    {
        $this->assertGenerationThreeBase($base);
        $resolved = $this->resolvePositionFromBase($base, $mediumId);

        return [
            'fields' => $resolved['fields'],
            'rules' => $resolved['rules'],
            'conflicts' => $resolved['conflicts'],
            'has_blocking_conflicts' => $resolved['conflicts'] !== [],
            'schema_fingerprint' => $resolved['fingerprint'],
            'context' => $resolved['context'],
        ];
    }

    /**
     * Neue Position auf einer bestehenden Generation-3-Kalkulation: der
     * Effektiv-Snapshot entsteht aus den eingefrorenen Basisquellen. Nur der
     * eingefrorene Kontext (Werbemittel → Oberkategorie) stammt aus den
     * aktuellen Stammdaten.
     */
    public function freezePositionEffectiveFromBase(
        ConfigurationSnapshot $base,
        int $mediumId,
        string $expectedFingerprint,
    ): ConfigurationSnapshot {
        if (! ConfigurationSnapshotIntegrity::isValidFingerprint($expectedFingerprint)) {
            throw ValidationException::withMessages([
                'schema_fingerprint' => 'Schema-Fingerprint fehlt oder ist ungültig.',
            ]);
        }

        $this->assertGenerationThreeBase($base);

        return DB::transaction(function () use ($base, $mediumId, $expectedFingerprint): ConfigurationSnapshot {
            $resolved = $this->resolvePositionFromBase($base, $mediumId);
            $this->assertFingerprint($expectedFingerprint, $resolved['fingerprint']);
            $this->assertResolvable($resolved);

            return $this->persistGenerationThree([
                'source' => $this->positionEffectiveSourceFor($base),
                'parent' => $base,
                'origin' => null,
                'context' => $resolved['context'],
                'fingerprint' => $resolved['fingerprint'],
                'raw_sources' => $resolved['raw_sources'],
                'merge_order_by_key' => $resolved['merge_order_by_key'],
                'resolved_sources' => $resolved['position']['sources'],
                'fields' => $resolved['fields'],
                'rules' => $resolved['rules'],
                'calc_origin' => null,
            ]);
        });
    }

    /**
     * Werbemittelwechsel einer Position: neuer Effektiv-Snapshot, Werteübernahme
     * über `field_definition_id`, Bindung an die Position, danach Aufräumen des
     * alten Snapshots.
     */
    public function replacePositionEffective(
        CalculationPosition $position,
        ConfigurationSnapshot $base,
        int $newMediumId,
        string $expectedFingerprint,
    ): ConfigurationSnapshot {
        return DB::transaction(function () use ($position, $base, $newMediumId, $expectedFingerprint): ConfigurationSnapshot {
            $position->loadMissing('effectiveConfigurationSnapshot');
            $previous = $position->effectiveConfigurationSnapshot;

            $next = $this->freezePositionEffectiveFromBase($base, $newMediumId, $expectedFingerprint);
            $this->integrity->assertReadableInternal($next);

            if ($previous !== null) {
                $this->remapper->remapCalculationPosition($position, $previous, $next);
            }

            $position->advertising_medium_id = (int) $next->context_advertising_medium_id;
            $position->advertising_medium_code = $next->context_advertising_medium_code;
            $position->advertising_medium_name = $next->context_advertising_medium_name;
            $position->advertising_category_id = (int) $next->context_advertising_category_id;
            $position->advertising_category_key = $next->context_advertising_category_key;
            $position->advertising_category_name = $next->context_advertising_category_name;
            $position->effective_configuration_snapshot_id = (int) $next->id;
            $position->save();
            $position->setRelation('effectiveConfigurationSnapshot', $next);

            $this->integrity->assertOwnership($next);

            if ($previous !== null) {
                $this->deleteEffectiveIfUnreferenced($previous);
            }

            return $next;
        });
    }

    /**
     * Löst den Effektiv-Snapshot von der Position und entfernt ihn, sobald ihn
     * niemand mehr referenziert. Positionswerte müssen vorher entfernt sein.
     */
    public function deletePositionEffective(CalculationPosition $position): void
    {
        DB::transaction(function () use ($position): void {
            $position->loadMissing('effectiveConfigurationSnapshot');
            $effective = $position->effectiveConfigurationSnapshot;
            if ($effective === null) {
                return;
            }

            if ($position->exists) {
                $position->effective_configuration_snapshot_id = null;
                $position->save();
            }
            $position->unsetRelation('effectiveConfigurationSnapshot');

            $this->deleteEffectiveIfUnreferenced($effective);
        });
    }

    /**
     * Dispo-Freeze der Generation 3: Dispo-Basis aus dem Dispo-Quellenuniversum
     * plus Calc-Origin-Header, dazu **nur für die ausgewählten** Kalkulations-
     * positionen Dispo-Effektiv-Snapshots im historischen Calc-Kontext.
     *
     * @param  list<int>  $selectedCalculationPositionIds
     */
    public function freezeDispoV3(
        ConfigurationSnapshot $calculationSnapshot,
        array $selectedCalculationPositionIds,
    ): ConfigurationSnapshot {
        $this->assertSupportedFormatVersion($calculationSnapshot);
        $this->assertCalculationOriginSnapshot($calculationSnapshot);
        $this->assertGenerationThreeBase($calculationSnapshot);

        $selectedEffectives = $this->selectedCalculationPositionEffectives(
            $calculationSnapshot,
            $selectedCalculationPositionIds,
        );

        return DB::transaction(function () use (
            $calculationSnapshot,
            $selectedEffectives,
        ): ConfigurationSnapshot {
            $this->locks->lockForProcess(FieldAppliesTo::DispoOrder, globalOnly: false);

            $calculationSnapshot->loadMissing(['fieldDefinitions', 'rules', 'fieldSet', 'fieldSetVersion']);

            $universe = $this->loadUniverse(FieldAppliesTo::DispoOrder, [
                'source_configuration_snapshot_id' => (int) $calculationSnapshot->id,
                'source_format_version' => (int) $calculationSnapshot->format_version,
                'source_schema_fingerprint' => $calculationSnapshot->schema_fingerprint,
            ]);
            $base = $this->resolveBaseLayer($universe);
            $this->assertResolvable($base);

            $headerPlan = $this->planDispoDefinitionsForScope(
                $calculationSnapshot,
                $base['header']['fields'],
                FieldScope::Header,
                DispoConfigurationSnapshotComposer::DISPO_TEXT_KEYS,
            );

            $dispoBase = $this->persistGenerationThree([
                'source' => ConfigurationSnapshotSourceEnum::DispoOrderCreate,
                'parent' => null,
                'origin' => $calculationSnapshot,
                'context' => $this->emptyContext(),
                'fingerprint' => $base['fingerprint'],
                'raw_sources' => $universe['raw_sources'],
                'merge_order_by_key' => $universe['merge_order_by_key'],
                'resolved_sources' => $base['header']['sources'],
                'fields' => $headerPlan['native_fields'],
                'rules' => $base['header']['rules'],
                'calc_origin' => [
                    'snapshot' => $calculationSnapshot,
                    'definitions' => $headerPlan['calc_definitions'],
                    'rules' => $headerPlan['calc_rules'],
                ],
            ]);

            foreach ($selectedEffectives as $calcEffective) {
                $resolved = $this->resolvePositionLayer(
                    $universe,
                    (int) $calcEffective->context_advertising_category_id,
                    (int) $calcEffective->context_advertising_medium_id,
                );
                $this->assertResolvable($resolved);

                $positionPlan = $this->planDispoDefinitionsForScope(
                    $calcEffective,
                    $resolved['fields'],
                    FieldScope::Position,
                    [],
                );

                $this->persistGenerationThree([
                    'source' => ConfigurationSnapshotSourceEnum::DispoOrderPositionEffective,
                    'parent' => $dispoBase,
                    'origin' => $calcEffective,
                    // Historischer Kontext der Kalkulationsposition, kein Live-Stand.
                    'context' => $this->contextFromSnapshot($calcEffective),
                    'fingerprint' => $resolved['fingerprint'],
                    'raw_sources' => $resolved['raw_sources'],
                    'merge_order_by_key' => $universe['merge_order_by_key'],
                    'resolved_sources' => $resolved['position']['sources'],
                    'fields' => $positionPlan['native_fields'],
                    'rules' => $resolved['rules'],
                    'calc_origin' => [
                        'snapshot' => $calcEffective,
                        'definitions' => $positionPlan['calc_definitions'],
                        'rules' => $positionPlan['calc_rules'],
                    ],
                ]);
            }

            return $this->reload($dispoBase);
        });
    }

    /**
     * Dispo-Effektiv-Snapshot zu einer Kalkulationsposition eines Dispo-Freezes.
     */
    public function findDispoPositionEffective(
        ConfigurationSnapshot $dispoBase,
        ConfigurationSnapshot $calculationPositionEffective,
    ): ?ConfigurationSnapshot {
        return ConfigurationSnapshot::query()
            ->where('parent_configuration_snapshot_id', $dispoBase->id)
            ->where('source', ConfigurationSnapshotSourceEnum::DispoOrderPositionEffective->value)
            ->where('source_configuration_snapshot_id', $calculationPositionEffective->id)
            ->first();
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
     * DF-3.3a2β: Quellenuniversum (Core + alle aktiven Assignments) plus
     * kanonische, ebenenstabile merge_order je Quelle.
     *
     * @param  array<string, mixed>  $extraFingerprintParts
     * @return array{
     *     process: FieldAppliesTo,
     *     raw_sources: list<array<string, mixed>>,
     *     fingerprint_parts: array<string, mixed>,
     *     merge_order_by_key: array<string, int>
     * }
     */
    private function loadUniverse(FieldAppliesTo $process, array $extraFingerprintParts = []): array
    {
        $loaded = $this->sourceLoader->loadUniverseSources($process);

        $fingerprintParts = array_merge(
            $loaded['fingerprint_parts'],
            ['target_format_version' => ConfigurationSnapshot::FORMAT_VERSION_CONTEXTUAL_FREEZE],
            $extraFingerprintParts,
        );

        return [
            'process' => $process,
            'raw_sources' => $loaded['sources'],
            'fingerprint_parts' => $fingerprintParts,
            'merge_order_by_key' => $this->canonicalMergeOrders($loaded['sources']),
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $rawSources
     * @return array<string, int>
     */
    private function canonicalMergeOrders(array $rawSources): array
    {
        /** @var array<string, int> $map */
        $map = [];
        $mergeOrder = 0;

        foreach ($rawSources as $raw) {
            $mergeOrder++;
            $map[$this->sourceKey((string) $raw['layer'], $raw['assignment_id'] ?? null)] = $mergeOrder;
        }

        return $map;
    }

    /**
     * Basisauflösung: Header **und** globale Positionsfelder aus Core + globaler
     * Ebene. Der Fingerprint deckt das gesamte Quellenuniversum ab, damit auch
     * Kategorie-/Werbemitteländerungen einen Konflikt erzeugen.
     *
     * @param  array<string, mixed>  $universe
     * @return array<string, mixed>
     */
    private function resolveBaseLayer(array $universe): array
    {
        $sources = $this->filterHeaderSources($universe['raw_sources']);

        $header = $this->resolver->resolve([
            'process' => $universe['process'],
            'scope' => FieldScope::Header,
            'sources' => $sources,
        ]);
        $position = $this->resolver->resolve([
            'process' => $universe['process'],
            'scope' => FieldScope::Position,
            'sources' => $sources,
        ]);

        return [
            'raw_sources' => $sources,
            'header' => $header,
            'position' => $position,
            'fields' => array_merge($header['fields'], $position['fields']),
            'rules' => $this->mergeRules($header['rules'], $position['rules']),
            'conflicts' => array_merge($header['conflicts'], $position['conflicts']),
            'fingerprint' => $this->sourceLoader->fingerprint(
                $universe['fingerprint_parts'],
                $header,
                $position,
            ),
        ];
    }

    /**
     * Positionsauflösung im Werbemittelkontext: Core + global + genau die
     * Kategorie- und Werbemittelquellen dieses Kontexts.
     *
     * @param  array<string, mixed>  $universe
     * @return array<string, mixed>
     */
    private function resolvePositionLayer(array $universe, int $categoryId, int $mediumId): array
    {
        $sources = $this->filterContextSources($universe['raw_sources'], $categoryId, $mediumId);

        $position = $this->resolver->resolve([
            'process' => $universe['process'],
            'scope' => FieldScope::Position,
            'advertising_category_id' => $categoryId,
            'advertising_medium_id' => $mediumId,
            'sources' => $sources,
        ]);

        $fingerprintParts = array_merge($universe['fingerprint_parts'], [
            'position_context' => [
                'advertising_category_id' => $categoryId,
                'advertising_medium_id' => $mediumId,
            ],
        ]);

        return [
            'raw_sources' => $sources,
            'position' => $position,
            'fields' => $position['fields'],
            'rules' => $position['rules'],
            'conflicts' => $position['conflicts'],
            'fingerprint' => $this->sourceLoader->fingerprintForResolved($fingerprintParts, $position),
        ];
    }

    /**
     * Positionsauflösung aus den eingefrorenen Quellen eines Basis-Snapshots.
     *
     * @return array<string, mixed>
     */
    private function resolvePositionFromBase(ConfigurationSnapshot $base, int $mediumId): array
    {
        $context = $this->freezePositionContext($mediumId, 'advertising_medium_id');
        $categoryId = (int) $context['context_advertising_category_id'];

        $process = $this->processOf($base);
        $sources = $this->filterContextSources($this->frozenRawSources($base), $categoryId, $mediumId);

        $position = $this->resolver->resolve([
            'process' => $process,
            'scope' => FieldScope::Position,
            'advertising_category_id' => $categoryId,
            'advertising_medium_id' => $mediumId,
            'sources' => $sources,
        ]);

        $fingerprintParts = [
            'process' => $process->value,
            'layers' => [
                FieldSetAssignmentMergeResolver::LAYER_PRIMARY_CORE,
                FieldSetAssignmentMergeResolver::LAYER_GLOBAL,
                FieldSetAssignmentMergeResolver::LAYER_ADVERTISING_CATEGORY,
                FieldSetAssignmentMergeResolver::LAYER_ADVERTISING_MEDIUM,
            ],
            'target_format_version' => ConfigurationSnapshot::FORMAT_VERSION_CONTEXTUAL_FREEZE,
            'base_configuration_snapshot_id' => (int) $base->id,
            'base_schema_fingerprint' => (string) $base->schema_fingerprint,
            'position_context' => [
                'advertising_category_id' => $categoryId,
                'advertising_medium_id' => $mediumId,
            ],
        ];

        return [
            'context' => $context,
            'raw_sources' => $sources,
            'merge_order_by_key' => $this->frozenMergeOrders($base),
            'position' => $position,
            'fields' => $position['fields'],
            'rules' => $position['rules'],
            'conflicts' => $position['conflicts'],
            'fingerprint' => $this->sourceLoader->fingerprintForResolved($fingerprintParts, $position),
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $rawSources
     * @return list<array<string, mixed>>
     */
    private function filterHeaderSources(array $rawSources): array
    {
        return array_values(array_filter(
            $rawSources,
            static fn (array $source): bool => in_array((string) $source['layer'], [
                FieldSetAssignmentMergeResolver::LAYER_PRIMARY_CORE,
                FieldSetAssignmentMergeResolver::LAYER_GLOBAL,
            ], true),
        ));
    }

    /**
     * @param  list<array<string, mixed>>  $rawSources
     * @return list<array<string, mixed>>
     */
    private function filterContextSources(array $rawSources, int $categoryId, int $mediumId): array
    {
        return array_values(array_filter(
            $rawSources,
            static function (array $source) use ($categoryId, $mediumId): bool {
                $targetId = $source['target_id'] ?? null;

                return match ((string) $source['layer']) {
                    FieldSetAssignmentMergeResolver::LAYER_PRIMARY_CORE,
                    FieldSetAssignmentMergeResolver::LAYER_GLOBAL => true,
                    FieldSetAssignmentMergeResolver::LAYER_ADVERTISING_CATEGORY => (int) $targetId === $categoryId,
                    FieldSetAssignmentMergeResolver::LAYER_ADVERTISING_MEDIUM => (int) $targetId === $mediumId,
                    default => false,
                };
            },
        ));
    }

    /**
     * Rekonstruiert Resolver-Quellen aus dem eingefrorenen Quellengraphen.
     * Calc-Origin-Quellen sind keine Mergequellen und bleiben außen vor.
     *
     * @return list<array<string, mixed>>
     */
    private function frozenRawSources(ConfigurationSnapshot $base): array
    {
        $base->loadMissing(['sources.fields', 'sources.rules']);

        /** @var list<array<string, mixed>> $rawSources */
        $rawSources = [];

        foreach ($base->sources as $source) {
            if ((string) $source->role === ConfigurationSnapshotSource::ROLE_ADDITIONAL) {
                continue;
            }

            $memberships = [];
            foreach ($source->fields as $field) {
                $memberships[] = [
                    'field_definition_id' => (int) $field->field_definition_id,
                    'field_definition_revision_id' => (int) $field->field_definition_revision_id,
                    'field_key' => (string) $field->field_key,
                    'field_scope' => $field->scope->value,
                    'field_applies_to' => $field->applies_to->value,
                    'definition_is_active' => (bool) $field->definition_is_active,
                    'definition_is_system' => (bool) $field->definition_is_system,
                    'group_key' => $field->group_key,
                    'sort' => (int) $field->membership_sort,
                    'required_override' => $field->required_override,
                    'visible_override' => $field->visible_override,
                    'label' => (string) $field->label,
                    'help_text' => $field->help_text,
                    'validation_json' => $field->validation_json,
                    'options_json' => $field->options_json,
                    'reportable' => (bool) $field->reportable,
                    'field_type' => $field->field_type->value,
                ];
            }

            $rules = [];
            foreach ($source->rules as $rule) {
                $rules[] = [
                    'field_rule_id' => (int) $rule->source_field_rule_id,
                    'sort' => (int) $rule->sort,
                    'condition_json' => $rule->condition_json,
                    'action_json' => $rule->action_json,
                ];
            }

            $rawSources[] = [
                'layer' => (string) $source->layer,
                'assignment_id' => $source->field_set_assignment_id,
                'assignment_sort' => $source->assignment_sort,
                'assignment_lock_version' => $source->assignment_lock_version,
                'assignment_applies_to_process' => $source->assignment_applies_to_process,
                'assignment_is_active' => $source->assignment_is_active,
                'target_layer' => (string) $source->target_layer,
                'target_identity' => (string) $source->target_identity,
                'target_id' => $source->target_id,
                'target_key' => $source->target_key,
                'target_name' => $source->target_name,
                'field_set_id' => (int) $source->field_set_id,
                'field_set_key' => (string) $source->field_set_key,
                'field_set_name' => (string) $source->field_set_name,
                'field_set_version_id' => (int) $source->field_set_version_id,
                'field_set_version_number' => (int) $source->field_set_version_number,
                'is_system_core' => (string) $source->role === ConfigurationSnapshotSource::ROLE_CORE,
                'memberships' => $memberships,
                'rules' => $rules,
            ];
        }

        return $rawSources;
    }

    /**
     * @return array<string, int>
     */
    private function frozenMergeOrders(ConfigurationSnapshot $base): array
    {
        $base->loadMissing('sources');

        /** @var array<string, int> $map */
        $map = [];

        foreach ($base->sources as $source) {
            if ((string) $source->role === ConfigurationSnapshotSource::ROLE_ADDITIONAL) {
                continue;
            }
            $key = $this->sourceKey((string) $source->layer, $source->field_set_assignment_id);
            $map[$key] = (int) $source->merge_order;
        }

        return $map;
    }

    /**
     * Persistenz eines Generation-3-Snapshots (Basis oder Positions-Effektiv).
     *
     * @param  array{
     *     source: ConfigurationSnapshotSourceEnum,
     *     parent: ConfigurationSnapshot|null,
     *     origin: ConfigurationSnapshot|null,
     *     context: array<string, mixed>,
     *     fingerprint: string,
     *     raw_sources: list<array<string, mixed>>,
     *     merge_order_by_key: array<string, int>,
     *     resolved_sources: list<array<string, mixed>>,
     *     fields: list<array<string, mixed>>,
     *     rules: list<array<string, mixed>>,
     *     calc_origin: array{
     *         snapshot: ConfigurationSnapshot,
     *         definitions: list<SnapshotFieldDefinition>,
     *         rules: list<SnapshotFieldRule>
     *     }|null
     * }  $plan
     */
    private function persistGenerationThree(array $plan): ConfigurationSnapshot
    {
        $core = $this->primaryCoreSource($plan['resolved_sources']);

        $snapshot = new ConfigurationSnapshot;
        $snapshot->field_set_id = $core['field_set_id'];
        $snapshot->field_set_version_id = $core['field_set_version_id'];
        $snapshot->source = $plan['source'];
        $snapshot->source_configuration_snapshot_id = $plan['origin']?->id;
        $snapshot->parent_configuration_snapshot_id = $plan['parent']?->id;
        $snapshot->format_version = ConfigurationSnapshot::FORMAT_VERSION_CONTEXTUAL_FREEZE;
        $snapshot->schema_fingerprint = $plan['fingerprint'];
        foreach ($plan['context'] as $column => $value) {
            $snapshot->{$column} = $value;
        }
        $snapshot->created_at = now();
        $snapshot->save();

        [$sourceIdByKey, $sourceFieldIndex] = $this->persistUniverseSources(
            $snapshot,
            $plan['raw_sources'],
            $plan['merge_order_by_key'],
        );
        $sourceIdByMergeOrder = $this->mapResolvedSources($plan['resolved_sources'], $sourceIdByKey);

        $calcOriginSourceId = null;
        if ($plan['calc_origin'] !== null) {
            $calcOriginSourceId = $this->persistCalcOriginSource(
                $snapshot,
                $plan['calc_origin']['snapshot'],
                $plan['calc_origin']['definitions'],
                $this->nextMergeOrder($sourceIdByMergeOrder),
            );

            foreach ($plan['calc_origin']['definitions'] as $definition) {
                $this->persistDefinitionFromCalcOrigin($snapshot, $definition, $calcOriginSourceId);
            }
        }

        foreach ($plan['fields'] as $field) {
            $this->persistDefinitionFromResolvedField(
                $snapshot,
                $field,
                $sourceIdByMergeOrder,
                $sourceFieldIndex,
            );
        }

        /** @var array<string, true> $seenRuleKeys */
        $seenRuleKeys = [];

        if ($calcOriginSourceId !== null) {
            foreach ($plan['calc_origin']['rules'] as $rule) {
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
        }

        foreach ($plan['rules'] as $rule) {
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
        // Vor der Bindung an eine Position gibt es noch keinen Eigentümer.
        $this->integrity->assertReadableInternal($fresh);
        $this->ruleEvaluator->assertRulesCompatibleWithDefinitions(
            FieldRuleDefinitionContext::fromSnapshot($fresh),
            $fresh->rules,
            requireActiveOptionKeys: false,
        );

        return $fresh;
    }

    /**
     * @param  list<array<string, mixed>>  $rawSources
     * @param  array<string, int>  $mergeOrderByKey
     * @return array{0: array<string, int>, 1: array<int, array<int, array<string, mixed>>>}
     */
    private function persistUniverseSources(
        ConfigurationSnapshot $snapshot,
        array $rawSources,
        array $mergeOrderByKey,
    ): array {
        /** @var array<string, int> $sourceIdByKey */
        $sourceIdByKey = [];
        /** @var array<int, array<int, array<string, mixed>>> $sourceFieldIndex */
        $sourceFieldIndex = [];

        foreach ($rawSources as $raw) {
            $key = $this->sourceKey((string) $raw['layer'], $raw['assignment_id'] ?? null);
            if (! isset($mergeOrderByKey[$key])) {
                throw new RuntimeException("Quelle {$key} ohne kanonische merge_order.");
            }

            $source = $this->persistSourceRow($snapshot, $raw, $mergeOrderByKey[$key]);
            $sourceIdByKey[$key] = (int) $source->id;
            $sourceFieldIndex[(int) $source->id] = $this->persistSourceFields($source, $raw['memberships']);
            $this->persistSourceRules($source, $raw['rules']);
        }

        return [$sourceIdByKey, $sourceFieldIndex];
    }

    /**
     * @param  list<array<string, mixed>>  $resolvedSources
     * @param  array<string, int>  $sourceIdByKey
     * @return array<int, int>
     */
    private function mapResolvedSources(array $resolvedSources, array $sourceIdByKey): array
    {
        /** @var array<int, int> $map */
        $map = [];

        foreach ($resolvedSources as $resolvedSource) {
            $key = $this->sourceKey(
                (string) $resolvedSource['layer'],
                $resolvedSource['assignment_id'] ?? null,
            );
            if (! isset($sourceIdByKey[$key])) {
                throw new RuntimeException("Quelle {$key} fehlt beim Einfrieren.");
            }
            $map[(int) $resolvedSource['merge_order']] = $sourceIdByKey[$key];
        }

        return $map;
    }

    /**
     * Historischer Positionskontext aus den aktuellen Stammdaten.
     *
     * @return array<string, mixed>
     */
    private function freezePositionContext(int $mediumId, string $errorKey): array
    {
        $medium = AdvertisingMedium::query()->with('category')->whereKey($mediumId)->first();
        if ($medium === null) {
            throw ValidationException::withMessages([
                $errorKey => 'Unbekanntes Werbemittel.',
            ]);
        }

        $category = $medium->category;
        if ($category === null) {
            throw new RuntimeException("Werbemittel {$mediumId}: Oberkategorie-FK beschädigt.");
        }

        return [
            'context_advertising_medium_id' => (int) $medium->id,
            'context_advertising_medium_code' => (string) $medium->code,
            'context_advertising_medium_name' => (string) $medium->name,
            'context_advertising_category_id' => (int) $category->id,
            'context_advertising_category_key' => (string) $category->key,
            'context_advertising_category_name' => (string) $category->name,
        ];
    }

    /**
     * @return array<string, null>
     */
    private function emptyContext(): array
    {
        return [
            'context_advertising_medium_id' => null,
            'context_advertising_medium_code' => null,
            'context_advertising_medium_name' => null,
            'context_advertising_category_id' => null,
            'context_advertising_category_key' => null,
            'context_advertising_category_name' => null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function contextFromSnapshot(ConfigurationSnapshot $effective): array
    {
        $context = [];
        foreach (array_keys($this->emptyContext()) as $column) {
            $context[$column] = $effective->{$column};
        }

        return $context;
    }

    /**
     * @param  array<string, mixed>  $position
     */
    private function positionKey(array $position, int $index): string
    {
        $clientKey = $position['client_key'] ?? null;
        if (is_string($clientKey) && trim($clientKey) !== '') {
            return trim($clientKey);
        }

        $id = $position['id'] ?? null;
        if (is_int($id) || (is_string($id) && $id !== '')) {
            return (string) $id;
        }

        return (string) $index;
    }

    /**
     * @param  array<string, mixed>  $position
     */
    private function requiredMediumId(array $position, int $index): int
    {
        $mediumId = $position['advertising_medium_id'] ?? null;

        if (! is_numeric($mediumId) || (int) $mediumId < 1) {
            throw ValidationException::withMessages([
                "positions.{$index}.advertising_medium_id" => 'Werbemittel fehlt.',
            ]);
        }

        return (int) $mediumId;
    }

    private function assertGenerationThreeBase(ConfigurationSnapshot $snapshot): void
    {
        $snapshot->assertReadable();

        if ((int) $snapshot->format_version !== ConfigurationSnapshot::FORMAT_VERSION_CONTEXTUAL_FREEZE) {
            throw new RuntimeException(
                "Snapshot {$snapshot->id} ist kein Snapshot der Generation 3.",
            );
        }

        if ($snapshot->isEffectiveSnapshot()) {
            throw new RuntimeException(
                "Snapshot {$snapshot->id} ist ein Effektiv-Snapshot und keine Basis.",
            );
        }
    }

    private function positionEffectiveSourceFor(ConfigurationSnapshot $base): ConfigurationSnapshotSourceEnum
    {
        return $this->processOf($base) === FieldAppliesTo::DispoOrder
            ? ConfigurationSnapshotSourceEnum::DispoOrderPositionEffective
            : ConfigurationSnapshotSourceEnum::CalculationPositionEffective;
    }

    private function processOf(ConfigurationSnapshot $snapshot): FieldAppliesTo
    {
        return in_array($snapshot->source, [
            ConfigurationSnapshotSourceEnum::DispoOrderCreate,
            ConfigurationSnapshotSourceEnum::DispoOrderLegacyBackfill,
            ConfigurationSnapshotSourceEnum::DispoOrderPositionEffective,
        ], true)
            ? FieldAppliesTo::DispoOrder
            : FieldAppliesTo::Calculation;
    }

    /**
     * Fail-closed Auswahl der Calc-Effektivs für einen Teil-Dispo-Freeze.
     *
     * @param  list<int>  $selectedCalculationPositionIds
     * @return list<ConfigurationSnapshot>
     */
    private function selectedCalculationPositionEffectives(
        ConfigurationSnapshot $calculationBase,
        array $selectedCalculationPositionIds,
    ): array {
        $uniqueIds = array_values(array_unique(array_map(
            static fn (mixed $id): int => (int) $id,
            $selectedCalculationPositionIds,
        )));

        if ($uniqueIds === [] || count($uniqueIds) !== count($selectedCalculationPositionIds)) {
            throw ValidationException::withMessages([
                'position_ids' => 'Positionsauswahl für den Dispo-Freeze ist leer oder nicht eindeutig.',
            ]);
        }

        $positions = CalculationPosition::query()
            ->whereIn('id', $uniqueIds)
            ->with(['calculation', 'effectiveConfigurationSnapshot'])
            ->orderBy('id')
            ->get()
            ->keyBy('id');

        if ($positions->count() !== count($uniqueIds)) {
            throw ValidationException::withMessages([
                'position_ids' => 'Eine oder mehrere Kalkulationspositionen existieren nicht.',
            ]);
        }

        /** @var list<ConfigurationSnapshot> $effectives */
        $effectives = [];

        foreach ($uniqueIds as $positionId) {
            /** @var CalculationPosition $position */
            $position = $positions->get($positionId);
            $calculation = $position->calculation;
            if ($calculation === null
                || (int) $calculation->configuration_snapshot_id !== (int) $calculationBase->id
            ) {
                throw ValidationException::withMessages([
                    'position_ids' => "Kalkulationsposition {$positionId} gehört nicht zur Quellkalkulation.",
                ]);
            }

            $effective = $position->effectiveConfigurationSnapshot;
            if ($effective === null) {
                throw new RuntimeException(
                    "Kalkulationsposition {$positionId} hat keinen lesbaren Effektiv-Snapshot.",
                );
            }

            if ((int) $effective->parent_configuration_snapshot_id !== (int) $calculationBase->id) {
                throw new RuntimeException(
                    "Effektiv-Snapshot {$effective->id} gehört nicht zur Calc-Basis {$calculationBase->id}.",
                );
            }

            $effective->assertReadable();
            $effectives[] = $effective;
        }

        return $effectives;
    }

    /**
     * Löscht einen Effektiv-Snapshot nur, wenn keine direkte Owner-/Parent-Referenz
     * und keine ungültige Herkunftsreferenz existiert.
     *
     * Historische Dispo-Effektiv-Snapshots dürfen den Calc-Effektiv über
     * `source_configuration_snapshot_id` weiterhin referenzieren (Retention).
     * In dem Fall wird der Snapshot behalten und nicht als Fehler gewertet.
     *
     * @return bool true = gelöscht, false = wegen legitimer historischer Origin-Retention behalten
     */
    public function deleteEffectiveIfUnreferenced(ConfigurationSnapshot $effective): bool
    {
        if (! $effective->isEffectiveSnapshot()) {
            throw new RuntimeException(
                "Snapshot {$effective->id} ist kein Effektiv-Snapshot und darf nicht entfernt werden.",
            );
        }

        $calculationRefs = CalculationPosition::query()
            ->where('effective_configuration_snapshot_id', $effective->id)
            ->pluck('id')
            ->all();
        $dispoRefs = DispoOrderPosition::query()
            ->where('effective_configuration_snapshot_id', $effective->id)
            ->pluck('id')
            ->all();
        $childParents = ConfigurationSnapshot::query()
            ->where('parent_configuration_snapshot_id', $effective->id)
            ->pluck('id')
            ->all();
        $originRefs = ConfigurationSnapshot::query()
            ->where('source_configuration_snapshot_id', $effective->id)
            ->pluck('id')
            ->all();

        if ($calculationRefs !== [] || $dispoRefs !== [] || $childParents !== []) {
            throw new RuntimeException(
                "Effektiv-Snapshot {$effective->id} besitzt unerwartete Restreferenzen "
                .'(calc=['.implode(',', $calculationRefs).'] '
                .'dispo=['.implode(',', $dispoRefs).'] '
                .'parent=['.implode(',', $childParents).'] '
                .'origin=['.implode(',', $originRefs).']).',
            );
        }

        if ($originRefs !== []) {
            $invalidOriginRefs = [];
            foreach ($originRefs as $originRefId) {
                if (! $this->isLegitimateHistoricalDispoOriginRetention(
                    $effective,
                    (int) $originRefId,
                )) {
                    $invalidOriginRefs[] = (int) $originRefId;
                }
            }

            if ($invalidOriginRefs !== []) {
                throw new RuntimeException(
                    "Effektiv-Snapshot {$effective->id} besitzt unerwartete Restreferenzen "
                    .'(calc=[] dispo=[] parent=[] '
                    .'origin=['.implode(',', $invalidOriginRefs).']).',
                );
            }

            // Ausschließlich legitime Dispo-Origin-Retention → Snapshot behalten.
            return false;
        }

        $sourceIds = ConfigurationSnapshotSource::query()
            ->where('configuration_snapshot_id', $effective->id)
            ->pluck('id')
            ->all();

        SnapshotFieldRule::query()->where('configuration_snapshot_id', $effective->id)->delete();
        SnapshotFieldDefinition::query()->where('configuration_snapshot_id', $effective->id)->delete();
        if ($sourceIds !== []) {
            ConfigurationSnapshotSource::query()->whereIn('id', $sourceIds)->delete();
        }
        $effective->delete();

        return true;
    }

    /**
     * DF-3.3a2β Hotfix: Dispo-Effektiv → Calc-Effektiv über
     * `source_configuration_snapshot_id` ist eine historische Abhängigkeit und
     * kein Cleanup-Fehler, solange Struktur und Ownership stimmen.
     */
    private function isLegitimateHistoricalDispoOriginRetention(
        ConfigurationSnapshot $calcEffective,
        int $referencingSnapshotId,
    ): bool {
        if ($calcEffective->source !== ConfigurationSnapshotSourceEnum::CalculationPositionEffective) {
            return false;
        }

        $referrer = ConfigurationSnapshot::query()->find($referencingSnapshotId);
        if ($referrer === null) {
            return false;
        }

        if ($referrer->source !== ConfigurationSnapshotSourceEnum::DispoOrderPositionEffective) {
            return false;
        }

        if ((int) $referrer->source_configuration_snapshot_id !== (int) $calcEffective->id) {
            return false;
        }

        if ((int) $referrer->id === (int) $calcEffective->id) {
            return false;
        }

        $dispoOwnerCount = DispoOrderPosition::query()
            ->where('effective_configuration_snapshot_id', $referrer->id)
            ->count();
        if ($dispoOwnerCount !== 1) {
            return false;
        }

        try {
            // Gen3-Vertrag inkl. Dispo-Basis-Parent, Kontext und Calc-Herkunft;
            // ohne Eigentümerpflicht am Calc-Origin (assertReadableInternal).
            $this->integrity->assertReadableInternal($referrer);
        } catch (RuntimeException) {
            return false;
        }

        return true;
    }

    /**
     * Scope-scharfe Variante von {@see planDispoDefinitions}: Calc-Origin gewinnt
     * bei Key-Kollisionen, native Dispofelder ergänzen.
     *
     * @param  list<array<string, mixed>>  $dispoFields
     * @param  list<string>  $requiredDispoKeys
     * @return array{
     *     calc_definitions: list<SnapshotFieldDefinition>,
     *     native_fields: list<array<string, mixed>>,
     *     calc_rules: list<SnapshotFieldRule>
     * }
     */
    private function planDispoDefinitionsForScope(
        ConfigurationSnapshot $calculationSnapshot,
        array $dispoFields,
        FieldScope $scope,
        array $requiredDispoKeys,
    ): array {
        $calculationSnapshot->loadMissing(['fieldDefinitions', 'rules']);
        $calcDefsByKey = $calculationSnapshot->fieldDefinitions->keyBy('key');

        /** @var list<SnapshotFieldDefinition> $calcDefinitions */
        $calcDefinitions = [];
        /** @var array<string, true> $seenKeys */
        $seenKeys = [];

        foreach (DispoConfigurationSnapshotComposer::CALC_ORIGIN_KEYS as $key) {
            if (DispoConfigurationSnapshotComposer::CALC_ORIGIN_EXPECTATIONS[$key]['scope'] !== $scope) {
                continue;
            }

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

        foreach ($requiredDispoKeys as $key) {
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

    private function assertRequiredFingerprint(?string $expected, string $actual, string $errorKey): void
    {
        if (! ConfigurationSnapshotIntegrity::isValidFingerprint($expected)) {
            throw ValidationException::withMessages([
                $errorKey => 'Schema-Fingerprint fehlt oder ist ungültig.',
            ]);
        }

        if (! hash_equals((string) $expected, $actual)) {
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
            $source = $this->persistSourceRow($snapshot, $raw, $mergeOrder);

            $sourceIdByMergeOrder[$mergeOrder] = (int) $source->id;
            $sourceFieldIndex[(int) $source->id] = $this->persistSourceFields($source, $raw['memberships']);
            $this->persistSourceRules($source, $raw['rules']);
        }

        return [$sourceIdByMergeOrder, $sourceFieldIndex];
    }

    /**
     * Eingefrorene Quellenzeile. Kategorie-/Werbemittelziele werden ab
     * Generation 3 mit `target_id`/`target_key`/`target_name` mitgeführt;
     * globale Quellen lassen sie bewusst leer.
     *
     * @param  array<string, mixed>  $raw
     */
    private function persistSourceRow(
        ConfigurationSnapshot $snapshot,
        array $raw,
        int $mergeOrder,
    ): ConfigurationSnapshotSource {
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
        $source->target_id = $raw['target_id'] ?? null;
        $source->target_key = $raw['target_key'] ?? null;
        $source->target_name = $raw['target_name'] ?? null;
        $source->created_at = now();
        $source->save();

        return $source;
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
                'options_json' => $membership['options_json'] ?? null,
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
                'options_json' => $definition->options_json,
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
        $definition->options_json = $frozen['options_json'] ?? ($field['options_json'] ?? null);
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
        $definition->options_json = $source->options_json;
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
            ConfigurationSnapshotSourceEnum::DispoOrderLegacyBackfill,
            ConfigurationSnapshotSourceEnum::DispoOrderPositionEffective => throw new RuntimeException(
                'Quellsnapshot ist bereits ein Dispo-Snapshot und darf nicht erneut als Calc-Quelle dienen.',
            ),
            ConfigurationSnapshotSourceEnum::CalculationPositionEffective => throw new RuntimeException(
                'Quellsnapshot ist ein Positions-Effektiv-Snapshot; als Calc-Quelle dient nur die Basis.',
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
        return SnapshotFieldRuleDedupeKey::from($condition, $action);
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
