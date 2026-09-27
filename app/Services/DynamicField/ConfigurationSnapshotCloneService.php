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
 *
 * DF-3.3a2β: Ab Generation 3 klont der Aufrufer zusätzlich je Position den
 * Effektiv-Snapshot über {@see cloneEffectiveForDispoRevision}.
 *
 * BL-P4-03a / VER-004: Kalkulations-Basis und Positions-Effektiv-Snapshots
 * können aus einer eingefrorenen Standardangebotsversion übernommen werden
 * ({@see cloneCalculationBase}, {@see cloneCalculationPositionEffective}).
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
            $clone->parent_configuration_snapshot_id = null;
            $clone->format_version = (int) $predecessor->format_version;
            $clone->schema_fingerprint = $predecessor->schema_fingerprint;
            $clone->created_at = now();
            $clone->save();

            $sourceIdMap = $this->hasSourceGraph($clone)
                ? $this->cloneSources($predecessor, $clone)
                : [];

            foreach ($predecessor->fieldDefinitions as $definition) {
                $this->cloneDefinition($definition, $clone, $sourceIdMap);
            }

            foreach ($predecessor->rules as $rule) {
                $this->cloneRule($rule, $clone, $sourceIdMap);
            }

            $fresh = $clone->load(['fieldDefinitions', 'rules', 'sources']);
            $fresh->assertReadable();

            return $fresh;
        });
    }

    /**
     * BL-P4-03a / VER-004: unveränderlichen Kalkulations-Basissnapshot einer
     * Vorlagenversion in die Kundenkalkulation klonen (keine Live-Neuauflösung).
     */
    public function cloneCalculationBase(ConfigurationSnapshot $predecessor): ConfigurationSnapshot
    {
        $predecessor->assertReadable();

        if ($predecessor->source !== ConfigurationSnapshotSourceEnum::SeedActive) {
            throw new RuntimeException(
                "Snapshot {$predecessor->id} ist kein Kalkulations-Basissnapshot.",
            );
        }

        return DB::transaction(function () use ($predecessor): ConfigurationSnapshot {
            $predecessor->loadMissing(['fieldDefinitions', 'rules', 'sources.fields', 'sources.rules']);

            $clone = new ConfigurationSnapshot;
            $clone->field_set_id = $predecessor->field_set_id;
            $clone->field_set_version_id = $predecessor->field_set_version_id;
            $clone->source = ConfigurationSnapshotSourceEnum::SeedActive;
            $clone->source_configuration_snapshot_id = null;
            $clone->parent_configuration_snapshot_id = null;
            $clone->format_version = (int) $predecessor->format_version;
            $clone->schema_fingerprint = $predecessor->schema_fingerprint;
            $clone->created_at = now();
            $clone->save();

            $sourceIdMap = $this->hasSourceGraph($clone)
                ? $this->cloneSources($predecessor, $clone)
                : [];

            foreach ($predecessor->fieldDefinitions as $definition) {
                $this->cloneDefinition($definition, $clone, $sourceIdMap);
            }

            foreach ($predecessor->rules as $rule) {
                $this->cloneRule($rule, $clone, $sourceIdMap);
            }

            $fresh = $clone->load(['fieldDefinitions', 'rules', 'sources']);
            $fresh->assertReadable();

            return $fresh;
        });
    }

    /**
     * BL-P4-03a: Positions-Effektiv-Snapshot der Vorlage für die neue Kalkulation klonen.
     */
    public function cloneCalculationPositionEffective(
        ConfigurationSnapshot $predecessorEffective,
        ConfigurationSnapshot $newCalculationBase,
    ): ConfigurationSnapshot {
        if ($predecessorEffective->source !== ConfigurationSnapshotSourceEnum::CalculationPositionEffective) {
            throw new RuntimeException(
                "Snapshot {$predecessorEffective->id} ist kein Calc-Positions-Effektiv-Snapshot.",
            );
        }

        if ((int) $newCalculationBase->format_version !== ConfigurationSnapshot::FORMAT_VERSION_CONTEXTUAL_FREEZE) {
            throw new RuntimeException(
                "Ziel-Basissnapshot {$newCalculationBase->id} ist kein Snapshot der Generation 3.",
            );
        }

        return DB::transaction(function () use ($predecessorEffective, $newCalculationBase): ConfigurationSnapshot {
            $predecessorEffective->loadMissing(['fieldDefinitions', 'rules', 'sources.fields', 'sources.rules']);

            $clone = new ConfigurationSnapshot;
            $clone->field_set_id = $predecessorEffective->field_set_id;
            $clone->field_set_version_id = $predecessorEffective->field_set_version_id;
            $clone->source = ConfigurationSnapshotSourceEnum::CalculationPositionEffective;
            $clone->source_configuration_snapshot_id = null;
            $clone->parent_configuration_snapshot_id = (int) $newCalculationBase->id;
            $clone->format_version = (int) $predecessorEffective->format_version;
            $clone->schema_fingerprint = $predecessorEffective->schema_fingerprint;
            $clone->context_advertising_medium_id = $predecessorEffective->context_advertising_medium_id;
            $clone->context_advertising_medium_code = $predecessorEffective->context_advertising_medium_code;
            $clone->context_advertising_medium_name = $predecessorEffective->context_advertising_medium_name;
            $clone->context_advertising_category_id = $predecessorEffective->context_advertising_category_id;
            $clone->context_advertising_category_key = $predecessorEffective->context_advertising_category_key;
            $clone->context_advertising_category_name = $predecessorEffective->context_advertising_category_name;
            $clone->created_at = now();
            $clone->save();

            $sourceIdMap = $this->cloneSources($predecessorEffective, $clone);

            foreach ($predecessorEffective->fieldDefinitions as $definition) {
                $this->cloneDefinition($definition, $clone, $sourceIdMap);
            }

            foreach ($predecessorEffective->rules as $rule) {
                $this->cloneRule($rule, $clone, $sourceIdMap);
            }

            // Eigentümer-Bindung erfolgt erst an der CalculationPosition (Adopt);
            // assertReadable würde hier vorzeitig scheitern.
            return $clone->load(['fieldDefinitions', 'rules', 'sources']);
        });
    }

    /**
     * DF-3.3a2β / VER-003: Nachbesserung erbt auch die positionsscharfen
     * Effektiv-Snapshots. Der Klon behält den historischen Kontext und hängt am
     * neuen Dispo-Basissnapshot.
     *
     * Der Aufrufer (DispoOrderWriter) klont je Position einzeln und bindet den
     * Klon anschließend an die neue Positionszeile.
     */
    public function cloneEffectiveForDispoRevision(
        ConfigurationSnapshot $predecessorEffective,
        ConfigurationSnapshot $newDispoBase,
    ): ConfigurationSnapshot {
        if ($predecessorEffective->source !== ConfigurationSnapshotSourceEnum::DispoOrderPositionEffective) {
            throw new RuntimeException(
                "Snapshot {$predecessorEffective->id} ist kein Dispo-Positions-Effektiv-Snapshot.",
            );
        }

        if ((int) $newDispoBase->format_version !== ConfigurationSnapshot::FORMAT_VERSION_CONTEXTUAL_FREEZE) {
            throw new RuntimeException(
                "Ziel-Basissnapshot {$newDispoBase->id} ist kein Snapshot der Generation 3.",
            );
        }

        return DB::transaction(function () use ($predecessorEffective, $newDispoBase): ConfigurationSnapshot {
            $predecessorEffective->loadMissing(['fieldDefinitions', 'rules']);

            $clone = new ConfigurationSnapshot;
            $clone->field_set_id = $predecessorEffective->field_set_id;
            $clone->field_set_version_id = $predecessorEffective->field_set_version_id;
            $clone->source = ConfigurationSnapshotSourceEnum::DispoOrderPositionEffective;
            $clone->source_configuration_snapshot_id = $predecessorEffective->source_configuration_snapshot_id;
            $clone->parent_configuration_snapshot_id = (int) $newDispoBase->id;
            $clone->format_version = (int) $predecessorEffective->format_version;
            $clone->schema_fingerprint = $predecessorEffective->schema_fingerprint;
            $clone->context_advertising_medium_id = $predecessorEffective->context_advertising_medium_id;
            $clone->context_advertising_medium_code = $predecessorEffective->context_advertising_medium_code;
            $clone->context_advertising_medium_name = $predecessorEffective->context_advertising_medium_name;
            $clone->context_advertising_category_id = $predecessorEffective->context_advertising_category_id;
            $clone->context_advertising_category_key = $predecessorEffective->context_advertising_category_key;
            $clone->context_advertising_category_name = $predecessorEffective->context_advertising_category_name;
            $clone->created_at = now();
            $clone->save();

            $sourceIdMap = $this->cloneSources($predecessorEffective, $clone);

            foreach ($predecessorEffective->fieldDefinitions as $definition) {
                $this->cloneDefinition($definition, $clone, $sourceIdMap);
            }

            foreach ($predecessorEffective->rules as $rule) {
                $this->cloneRule($rule, $clone, $sourceIdMap);
            }

            $fresh = $clone->load(['fieldDefinitions', 'rules', 'sources']);
            // Eigentümerbindung entsteht erst mit der neuen Positionszeile.
            app(ConfigurationSnapshotIntegrity::class)->assertReadableInternal($fresh);

            return $fresh;
        });
    }

    private function hasSourceGraph(ConfigurationSnapshot $snapshot): bool
    {
        return in_array((int) $snapshot->format_version, [
            ConfigurationSnapshot::FORMAT_VERSION_GLOBAL_FREEZE,
            ConfigurationSnapshot::FORMAT_VERSION_CONTEXTUAL_FREEZE,
        ], true);
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
