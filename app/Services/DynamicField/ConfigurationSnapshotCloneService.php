<?php

namespace App\Services\DynamicField;

use App\Enums\ConfigurationSnapshotSource as ConfigurationSnapshotSourceEnum;
use App\Models\ConfigurationSnapshot;
use App\Models\ConfigurationSnapshotSource;
use App\Models\SnapshotFieldDefinition;
use App\Models\SnapshotFieldRule;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * DF-3.3a2α: Nachbesserung erbt die historisch eingefrorene Konfiguration.
 *
 * Es findet bewusst **keine** Neuauflösung aktueller Assignments statt; der
 * Klon behält `format_version`, Feldset-Referenz und Calc-Herkunft.
 */
final class ConfigurationSnapshotCloneService
{
    public function cloneForDispoRevision(ConfigurationSnapshot $predecessor): ConfigurationSnapshot
    {
        $predecessor->assertReadable();

        if (! in_array($predecessor->source, [
            ConfigurationSnapshotSourceEnum::DispoOrderCreate,
            ConfigurationSnapshotSourceEnum::DispoOrderLegacyBackfill,
        ], true)) {
            throw new RuntimeException(
                "Snapshot {$predecessor->id} ist kein Dispo-Snapshot und kann nicht geklont werden.",
            );
        }

        return DB::transaction(function () use ($predecessor): ConfigurationSnapshot {
            $predecessor->loadMissing(['fieldDefinitions', 'rules']);

            $clone = new ConfigurationSnapshot;
            $clone->field_set_id = $predecessor->field_set_id;
            $clone->field_set_version_id = $predecessor->field_set_version_id;
            $clone->source = ConfigurationSnapshotSourceEnum::DispoOrderCreate;
            $clone->source_configuration_snapshot_id = $predecessor->source_configuration_snapshot_id;
            $clone->format_version = (int) $predecessor->format_version;
            $clone->schema_fingerprint = $predecessor->schema_fingerprint;
            $clone->created_at = now();
            $clone->save();

            $sourceIdMap = $clone->format_version === ConfigurationSnapshot::FORMAT_VERSION_GLOBAL_FREEZE
                ? $this->cloneSources($predecessor, $clone)
                : [];

            foreach ($predecessor->fieldDefinitions as $definition) {
                $this->cloneDefinition($definition, $clone, $sourceIdMap);
            }

            foreach ($predecessor->rules as $rule) {
                $this->cloneRule($rule, $clone, $sourceIdMap);
            }

            return $clone->load(['fieldDefinitions', 'rules', 'sources']);
        });
    }

    /**
     * @return array<int, int> alte Quellen-ID → neue Quellen-ID
     */
    private function cloneSources(ConfigurationSnapshot $predecessor, ConfigurationSnapshot $clone): array
    {
        $sources = ConfigurationSnapshotSource::query()
            ->where('configuration_snapshot_id', $predecessor->id)
            ->with(['fields', 'rules'])
            ->orderBy('merge_order')
            ->get();

        /** @var array<int, int> $map */
        $map = [];

        foreach ($sources as $source) {
            $copy = $source->replicate(['id', 'configuration_snapshot_id']);
            $copy->configuration_snapshot_id = $clone->id;
            $copy->created_at = now();
            $copy->save();

            $map[(int) $source->id] = (int) $copy->id;

            foreach ($source->fields as $field) {
                $fieldCopy = $field->replicate(['id', 'configuration_snapshot_source_id']);
                $fieldCopy->configuration_snapshot_source_id = $copy->id;
                $fieldCopy->save();
            }

            foreach ($source->rules as $rule) {
                $ruleCopy = $rule->replicate(['id', 'configuration_snapshot_source_id']);
                $ruleCopy->configuration_snapshot_source_id = $copy->id;
                $ruleCopy->save();
            }
        }

        return $map;
    }

    /**
     * @param  array<int, int>  $sourceIdMap
     */
    private function cloneDefinition(
        SnapshotFieldDefinition $definition,
        ConfigurationSnapshot $clone,
        array $sourceIdMap,
    ): void {
        $copy = $definition->replicate(['id', 'configuration_snapshot_id']);
        $copy->configuration_snapshot_id = $clone->id;

        foreach (SnapshotFieldDefinition::PROVENANCE_COLUMNS as $column) {
            $copy->{$column} = $this->remap($definition->{$column}, $sourceIdMap);
        }

        $copy->save();
    }

    /**
     * @param  array<int, int>  $sourceIdMap
     */
    private function cloneRule(
        SnapshotFieldRule $rule,
        ConfigurationSnapshot $clone,
        array $sourceIdMap,
    ): void {
        $copy = $rule->replicate(['id', 'configuration_snapshot_id']);
        $copy->configuration_snapshot_id = $clone->id;
        $copy->provenance_source_id = $this->remap($rule->provenance_source_id, $sourceIdMap);
        $copy->save();
    }

    /**
     * @param  array<int, int>  $sourceIdMap
     */
    private function remap(int|string|null $sourceId, array $sourceIdMap): ?int
    {
        if ($sourceId === null) {
            return null;
        }

        $sourceId = (int) $sourceId;

        if (! isset($sourceIdMap[$sourceId])) {
            throw new RuntimeException("Provenance-Quelle {$sourceId} fehlt im Klon.");
        }

        return $sourceIdMap[$sourceId];
    }
}
