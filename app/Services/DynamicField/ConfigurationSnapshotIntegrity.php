<?php

namespace App\Services\DynamicField;

use App\Enums\ConfigurationSnapshotSource as ConfigurationSnapshotSourceEnum;
use App\Enums\FieldAppliesTo;
use App\Enums\FieldSetAssignmentTargetLayer;
use App\Models\ConfigurationSnapshot;
use App\Models\ConfigurationSnapshotSource;
use App\Models\ConfigurationSnapshotSourceRule;
use App\Models\FieldSetAssignment;
use App\Models\SnapshotFieldDefinition;
use App\Models\SnapshotFieldRule;
use App\Services\DynamicField\Assignment\FieldSetAssignmentMergeResolver;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * DF-3.3a2α: zentrale fail-closed Integritätsprüfung für Snapshots.
 *
 * Generation 1: nur bekannte format_version.
 * Generation 2: strikte Source-Taxonomie, Quellengraph und Property-Provenance.
 */
final class ConfigurationSnapshotIntegrity
{
    public const FINGERPRINT_PATTERN = '/^[a-f0-9]{64}$/';

    /** @var list<string> */
    private const KNOWN_ROLES = [
        ConfigurationSnapshotSource::ROLE_CORE,
        ConfigurationSnapshotSource::ROLE_ASSIGNMENT,
        ConfigurationSnapshotSource::ROLE_ADDITIONAL,
    ];

    /**
     * Fail-closed Lesbarkeits- und Integritätsprüfung.
     */
    public function assertReadable(ConfigurationSnapshot $snapshot): void
    {
        $version = (int) $snapshot->format_version;

        if (! in_array($version, ConfigurationSnapshot::SUPPORTED_FORMAT_VERSIONS, true)) {
            $this->fail($snapshot, "unbekannte format_version {$version}");
        }

        if ($version === ConfigurationSnapshot::FORMAT_VERSION_LEGACY) {
            return;
        }

        $this->assertGenerationTwo($snapshot);
    }

    public static function isValidFingerprint(mixed $value): bool
    {
        return is_string($value) && preg_match(self::FINGERPRINT_PATTERN, $value) === 1;
    }

    private function assertGenerationTwo(ConfigurationSnapshot $snapshot): void
    {
        if (! self::isValidFingerprint($snapshot->schema_fingerprint)) {
            $this->fail($snapshot, 'schema_fingerprint fehlt oder ist kein SHA-256-Hexwert');
        }

        $snapshot->loadMissing([
            'sources.fields',
            'sources.rules',
            'fieldDefinitions',
            'rules',
        ]);

        $sources = $snapshot->sources;
        if ($sources->isEmpty()) {
            $this->fail($snapshot, 'v2-Sourcegraph fehlt');
        }

        $isDispo = $this->isDispoSnapshot($snapshot);
        $coreCount = 0;
        $calcOriginCount = 0;
        /** @var array<int, ConfigurationSnapshotSource> $sourcesById */
        $sourcesById = [];
        /** @var array<int, array<int, true>> $fieldsBySource */
        $fieldsBySource = [];
        /** @var array<int, list<ConfigurationSnapshotSourceRule>> $rulesBySource */
        $rulesBySource = [];

        foreach ($sources as $source) {
            $sourceId = (int) $source->id;
            $sourcesById[$sourceId] = $source;
            $role = (string) $source->role;

            if (! in_array($role, self::KNOWN_ROLES, true)) {
                $this->fail($snapshot, "unbekannte Source-Rolle „{$role}“");
            }

            $layer = (string) $source->layer;
            if (! in_array($layer, [
                FieldSetAssignmentMergeResolver::LAYER_PRIMARY_CORE,
                FieldSetAssignmentMergeResolver::LAYER_GLOBAL,
            ], true)) {
                $this->fail($snapshot, "unerlaubter Source-Layer „{$layer}“");
            }

            match ($role) {
                ConfigurationSnapshotSource::ROLE_CORE => $this->assertCoreSource($snapshot, $source, $coreCount),
                ConfigurationSnapshotSource::ROLE_ASSIGNMENT => $this->assertAssignmentSource($snapshot, $source, $isDispo),
                ConfigurationSnapshotSource::ROLE_ADDITIONAL => $this->assertAdditionalSource(
                    $snapshot,
                    $source,
                    $isDispo,
                    $calcOriginCount,
                ),
            };

            $fieldsBySource[$sourceId] = [];
            foreach ($source->fields as $field) {
                $fieldsBySource[$sourceId][(int) $field->field_definition_id] = true;
            }

            $rulesBySource[$sourceId] = [];
            foreach ($source->rules as $sourceRule) {
                $this->assertSourceRuleSelfConsistent($snapshot, $sourceRule);
                $rulesBySource[$sourceId][] = $sourceRule;
            }
        }

        if ($coreCount !== 1) {
            $this->fail($snapshot, "erwartet genau eine Core-Source, gefunden {$coreCount}");
        }

        if ($isDispo) {
            if ($calcOriginCount !== 1) {
                $this->fail($snapshot, "Dispo-v2 erwartet genau eine Calc-Origin-Source, gefunden {$calcOriginCount}");
            }
        } elseif ($calcOriginCount !== 0) {
            $this->fail($snapshot, 'Calc-v2 darf keine Calc-Origin-Source enthalten');
        }

        foreach ($snapshot->fieldDefinitions as $definition) {
            $this->assertDefinitionProvenance($snapshot, $definition, $sourcesById, $fieldsBySource);
        }

        foreach ($snapshot->rules as $rule) {
            $this->assertRuleProvenance($snapshot, $rule, $sourcesById, $rulesBySource);
        }
    }

    private function assertCoreSource(
        ConfigurationSnapshot $snapshot,
        ConfigurationSnapshotSource $source,
        int &$coreCount,
    ): void {
        $coreCount++;

        if ((string) $source->layer !== FieldSetAssignmentMergeResolver::LAYER_PRIMARY_CORE) {
            $this->fail($snapshot, 'Core-Source muss Layer primary_core haben');
        }

        if ((string) $source->target_layer !== FieldSetAssignmentTargetLayer::Global->value) {
            $this->fail($snapshot, 'Core-Source muss target_layer=global haben');
        }

        $expectedIdentity = FieldSetAssignment::buildTargetIdentity(
            FieldSetAssignmentTargetLayer::Global,
            null,
            null,
        );
        if ((string) $source->target_identity !== $expectedIdentity) {
            $this->fail($snapshot, 'Core-Source muss globale target_identity „g“ haben');
        }

        if ($this->isCalcOriginIdentity($source)) {
            $this->fail($snapshot, 'Core-Source darf keine Calc-Origin-Identität haben');
        }

        $this->assertNoAssignmentMetadata($snapshot, $source, 'Core-Source');
        $this->assertNoCategoryOrMediumTargetRefs($snapshot, $source, 'Core-Source');
    }

    private function assertAssignmentSource(
        ConfigurationSnapshot $snapshot,
        ConfigurationSnapshotSource $source,
        bool $isDispo,
    ): void {
        if ((string) $source->layer !== FieldSetAssignmentMergeResolver::LAYER_GLOBAL) {
            $this->fail($snapshot, "Assignment-Source {$source->id} muss Layer global haben");
        }

        if ((string) $source->target_layer !== FieldSetAssignmentTargetLayer::Global->value) {
            $this->fail(
                $snapshot,
                "Assignment-Source {$source->id} muss target_layer=global haben (keine Kategorie-/Werbemittel-Targets in v2)",
            );
        }

        $expectedIdentity = FieldSetAssignment::buildTargetIdentity(
            FieldSetAssignmentTargetLayer::Global,
            null,
            null,
        );
        if ((string) $source->target_identity !== $expectedIdentity) {
            $this->fail(
                $snapshot,
                "Assignment-Source {$source->id} muss target_identity „g“ haben",
            );
        }

        if ($source->target_id !== null || $source->target_key !== null || $source->target_name !== null) {
            $this->fail(
                $snapshot,
                "Assignment-Source {$source->id} darf keine Kategorie-/Werbemittel-Zielreferenzen tragen",
            );
        }

        if ($source->field_set_assignment_id === null
            || $source->assignment_lock_version === null
            || $source->assignment_applies_to_process === null
            || $source->assignment_sort === null
            || $source->assignment_is_active === null
        ) {
            $this->fail(
                $snapshot,
                "Assignment-Source {$source->id} ohne vollständige eingefrorene Assignmentdaten",
            );
        }

        if ($source->assignment_is_active !== true) {
            $this->fail(
                $snapshot,
                "Assignment-Source {$source->id} muss assignment_is_active=true haben",
            );
        }

        $process = (string) $source->assignment_applies_to_process;
        $allowed = $isDispo
            ? [FieldAppliesTo::DispoOrder->value, FieldAppliesTo::Both->value]
            : [FieldAppliesTo::Calculation->value, FieldAppliesTo::Both->value];

        if (! in_array($process, $allowed, true)) {
            $this->fail(
                $snapshot,
                "Assignment-Source {$source->id} mit prozessfremdem applies_to_process „{$process}“",
            );
        }
    }

    private function assertAdditionalSource(
        ConfigurationSnapshot $snapshot,
        ConfigurationSnapshotSource $source,
        bool $isDispo,
        int &$calcOriginCount,
    ): void {
        if (! $isDispo) {
            $this->fail($snapshot, 'Calc-v2 darf keine additional-Source enthalten');
        }

        if (! $this->isCalcOriginIdentity($source)) {
            $this->fail($snapshot, 'additional-Source ohne gültige Calc-Origin-Identität');
        }

        $calcOriginCount++;

        if ((string) $source->layer !== FieldSetAssignmentMergeResolver::LAYER_PRIMARY_CORE) {
            $this->fail($snapshot, 'Calc-Origin-Source muss Layer primary_core haben');
        }

        if ((string) $source->target_layer !== FieldSetAssignmentMergeResolver::LAYER_GLOBAL) {
            $this->fail($snapshot, 'Calc-Origin-Source muss target_layer=global haben');
        }

        if ((string) $source->target_identity !== ConfigurationSnapshotSource::TARGET_IDENTITY_CALC_ORIGIN) {
            $this->fail($snapshot, 'Calc-Origin-Source muss target_identity=calc_origin haben');
        }

        if ($source->target_name !== 'Calc-Origin') {
            $this->fail($snapshot, 'Calc-Origin-Source muss target_name „Calc-Origin“ haben');
        }

        $this->assertNoAssignmentMetadata($snapshot, $source, 'Calc-Origin-Source');
    }

    private function assertNoAssignmentMetadata(
        ConfigurationSnapshot $snapshot,
        ConfigurationSnapshotSource $source,
        string $label,
    ): void {
        if ($source->field_set_assignment_id !== null
            || $source->assignment_lock_version !== null
            || $source->assignment_applies_to_process !== null
            || $source->assignment_sort !== null
            || $source->assignment_is_active !== null
        ) {
            $this->fail($snapshot, "{$label} {$source->id} darf keine Assignment-Metadaten tragen");
        }
    }

    private function assertNoCategoryOrMediumTargetRefs(
        ConfigurationSnapshot $snapshot,
        ConfigurationSnapshotSource $source,
        string $label,
    ): void {
        if ($source->target_id !== null || $source->target_key !== null || $source->target_name !== null) {
            $this->fail(
                $snapshot,
                "{$label} {$source->id} darf keine Kategorie-/Werbemittel-Zielreferenzen tragen",
            );
        }
    }

    private function assertSourceRuleSelfConsistent(
        ConfigurationSnapshot $snapshot,
        ConfigurationSnapshotSourceRule $sourceRule,
    ): void {
        $expected = SnapshotFieldRuleDedupeKey::from(
            $sourceRule->condition_json,
            $sourceRule->action_json,
        );

        if ($sourceRule->dedupe_key !== $expected) {
            $this->fail(
                $snapshot,
                "Source-Rule {$sourceRule->id}: dedupe_key stimmt nicht mit condition/action überein",
            );
        }

        if ($sourceRule->source_field_rule_id === null) {
            $this->fail($snapshot, "Source-Rule {$sourceRule->id} ohne source_field_rule_id");
        }
    }

    /**
     * @param  array<int, ConfigurationSnapshotSource>  $sourcesById
     * @param  array<int, array<int, true>>  $fieldsBySource
     */
    private function assertDefinitionProvenance(
        ConfigurationSnapshot $snapshot,
        SnapshotFieldDefinition $definition,
        array $sourcesById,
        array $fieldsBySource,
    ): void {
        foreach (SnapshotFieldDefinition::PROVENANCE_COLUMNS as $column) {
            $sourceId = $definition->{$column};
            if ($sourceId === null) {
                $this->fail($snapshot, "Feld „{$definition->key}“ ohne {$column}");
            }

            $sourceId = (int) $sourceId;
            if (! isset($sourcesById[$sourceId])) {
                $this->fail($snapshot, "Feld „{$definition->key}“: {$column} verweist auf fremde Quelle");
            }

            if (! isset($fieldsBySource[$sourceId][(int) $definition->field_definition_id])) {
                $this->fail(
                    $snapshot,
                    "Feld „{$definition->key}“: {$column} ohne passende Source-Field-Zeile",
                );
            }
        }
    }

    /**
     * @param  array<int, ConfigurationSnapshotSource>  $sourcesById
     * @param  array<int, list<ConfigurationSnapshotSourceRule>>  $rulesBySource
     */
    private function assertRuleProvenance(
        ConfigurationSnapshot $snapshot,
        SnapshotFieldRule $rule,
        array $sourcesById,
        array $rulesBySource,
    ): void {
        if ($rule->provenance_source_id === null) {
            $this->fail($snapshot, "Regel {$rule->id} ohne provenance_source_id");
        }

        if ($rule->source_field_rule_id === null) {
            $this->fail($snapshot, "Regel {$rule->id} ohne source_field_rule_id");
        }

        if (! is_string($rule->dedupe_key) || $rule->dedupe_key === '') {
            $this->fail($snapshot, "Regel {$rule->id} ohne dedupe_key");
        }

        $canonical = SnapshotFieldRuleDedupeKey::from($rule->condition_json, $rule->action_json);
        if ($rule->dedupe_key !== $canonical) {
            $this->fail(
                $snapshot,
                "Regel {$rule->id}: dedupe_key stimmt nicht mit condition/action überein",
            );
        }

        $sourceId = (int) $rule->provenance_source_id;
        if (! isset($sourcesById[$sourceId])) {
            $this->fail($snapshot, "Regel {$rule->id}: provenance_source_id verweist auf fremde Quelle");
        }

        $matches = [];
        foreach ($rulesBySource[$sourceId] ?? [] as $sourceRule) {
            if ((int) $sourceRule->source_field_rule_id === (int) $rule->source_field_rule_id
                && $sourceRule->dedupe_key === $rule->dedupe_key
            ) {
                $matches[] = $sourceRule;
            }
        }

        if ($matches === []) {
            $this->fail(
                $snapshot,
                "Regel {$rule->id}: Provenance-Source ohne passende Source-Rule (Rule-ID und Dedupe-Key)",
            );
        }

        if (count($matches) !== 1) {
            $this->fail(
                $snapshot,
                "Regel {$rule->id}: Provenance-Source enthält mehrdeutige Source-Rules",
            );
        }
    }

    private function isDispoSnapshot(ConfigurationSnapshot $snapshot): bool
    {
        return in_array($snapshot->source, [
            ConfigurationSnapshotSourceEnum::DispoOrderCreate,
            ConfigurationSnapshotSourceEnum::DispoOrderLegacyBackfill,
        ], true);
    }

    private function isCalcOriginIdentity(ConfigurationSnapshotSource $source): bool
    {
        return $source->target_identity === ConfigurationSnapshotSource::TARGET_IDENTITY_CALC_ORIGIN;
    }

    private function fail(ConfigurationSnapshot $snapshot, string $reason): never
    {
        Log::error('Configuration snapshot integrity failure', [
            'configuration_snapshot_id' => $snapshot->id,
            'format_version' => $snapshot->format_version,
            'source' => $snapshot->source->value,
            'reason' => $reason,
        ]);

        throw new RuntimeException(
            "Konfigurationssnapshot {$snapshot->id} ist beschädigt: {$reason}.",
        );
    }
}
