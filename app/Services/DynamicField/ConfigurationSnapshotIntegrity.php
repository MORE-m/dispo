<?php

namespace App\Services\DynamicField;

use App\Enums\ConfigurationSnapshotSource as ConfigurationSnapshotSourceEnum;
use App\Models\ConfigurationSnapshot;
use App\Models\ConfigurationSnapshotSource;
use App\Models\SnapshotFieldDefinition;
use App\Models\SnapshotFieldRule;
use App\Services\DynamicField\Assignment\FieldSetAssignmentMergeResolver;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * DF-3.3a2α: zentrale fail-closed Integritätsprüfung für Snapshots.
 *
 * Generation 1: nur bekannte format_version.
 * Generation 2: vollständiger Quellengraph und Property-Provenance.
 */
final class ConfigurationSnapshotIntegrity
{
    public const FINGERPRINT_PATTERN = '/^[a-f0-9]{64}$/';

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

        $allowedLayers = [
            FieldSetAssignmentMergeResolver::LAYER_PRIMARY_CORE => true,
            FieldSetAssignmentMergeResolver::LAYER_GLOBAL => true,
        ];

        $coreCount = 0;
        $calcOriginCount = 0;
        /** @var array<int, ConfigurationSnapshotSource> $sourcesById */
        $sourcesById = [];
        /** @var array<int, array<int, true>> $fieldsBySource */
        $fieldsBySource = [];
        /** @var array<int, array{by_dedupe: array<string, true>, by_rule_id: array<int, true>}> $rulesBySource */
        $rulesBySource = [];

        foreach ($sources as $source) {
            $sourceId = (int) $source->id;
            $sourcesById[$sourceId] = $source;
            $layer = (string) $source->layer;

            if (! isset($allowedLayers[$layer])) {
                $this->fail($snapshot, "unerlaubter Source-Layer „{$layer}“");
            }

            if ($source->role === ConfigurationSnapshotSource::ROLE_CORE) {
                if ($layer !== FieldSetAssignmentMergeResolver::LAYER_PRIMARY_CORE) {
                    $this->fail($snapshot, 'Core-Source muss Layer primary_core haben');
                }
                $coreCount++;
            }

            if ($source->role === ConfigurationSnapshotSource::ROLE_ASSIGNMENT) {
                $this->assertAssignmentSourceComplete($snapshot, $source);
                if ($layer !== FieldSetAssignmentMergeResolver::LAYER_GLOBAL) {
                    $this->fail($snapshot, 'v2-Assignment-Source muss Layer global haben');
                }
            }

            if ($this->isCalcOriginSource($source)) {
                $calcOriginCount++;
                if ($source->role !== ConfigurationSnapshotSource::ROLE_ADDITIONAL) {
                    $this->fail($snapshot, 'Calc-Origin-Source muss role=additional haben');
                }
            } elseif ($source->role === ConfigurationSnapshotSource::ROLE_ADDITIONAL) {
                $this->fail($snapshot, 'zusätzliche Source ohne gültige Calc-Origin-Identität');
            }

            $fieldsBySource[$sourceId] = [];
            foreach ($source->fields as $field) {
                $fieldsBySource[$sourceId][(int) $field->field_definition_id] = true;
            }

            $rulesBySource[$sourceId] = ['by_dedupe' => [], 'by_rule_id' => []];
            foreach ($source->rules as $sourceRule) {
                if ($sourceRule->dedupe_key !== '') {
                    $rulesBySource[$sourceId]['by_dedupe'][$sourceRule->dedupe_key] = true;
                }
                if ($sourceRule->source_field_rule_id !== null) {
                    $rulesBySource[$sourceId]['by_rule_id'][(int) $sourceRule->source_field_rule_id] = true;
                }
            }
        }

        if ($coreCount !== 1) {
            $this->fail($snapshot, "erwartet genau eine Core-Source, gefunden {$coreCount}");
        }

        $isDispo = in_array($snapshot->source, [
            ConfigurationSnapshotSourceEnum::DispoOrderCreate,
            ConfigurationSnapshotSourceEnum::DispoOrderLegacyBackfill,
        ], true);

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

    private function assertAssignmentSourceComplete(
        ConfigurationSnapshot $snapshot,
        ConfigurationSnapshotSource $source,
    ): void {
        if ($source->field_set_assignment_id === null
            || $source->assignment_lock_version === null
            || $source->assignment_applies_to_process === null
            || $source->assignment_sort === null
            || $source->assignment_is_active === null
            || $source->target_identity === ''
        ) {
            $this->fail(
                $snapshot,
                "Assignment-Source {$source->id} ohne vollständige eingefrorene Assignmentdaten",
            );
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
     * @param  array<int, array{by_dedupe: array<string, true>, by_rule_id: array<int, true>}>  $rulesBySource
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
        if (! is_string($rule->dedupe_key) || $rule->dedupe_key === '') {
            $this->fail($snapshot, "Regel {$rule->id} ohne dedupe_key");
        }

        $sourceId = (int) $rule->provenance_source_id;
        if (! isset($sourcesById[$sourceId])) {
            $this->fail($snapshot, "Regel {$rule->id}: provenance_source_id verweist auf fremde Quelle");
        }

        $index = $rulesBySource[$sourceId] ?? ['by_dedupe' => [], 'by_rule_id' => []];
        $matched = isset($index['by_dedupe'][$rule->dedupe_key]);
        if (! $matched && $rule->source_field_rule_id !== null) {
            $matched = isset($index['by_rule_id'][(int) $rule->source_field_rule_id]);
        }

        if (! $matched) {
            $this->fail($snapshot, "Regel {$rule->id}: Provenance-Source ohne passende Source-Rule");
        }
    }

    private function isCalcOriginSource(ConfigurationSnapshotSource $source): bool
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
