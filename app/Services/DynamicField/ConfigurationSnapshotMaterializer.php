<?php

namespace App\Services\DynamicField;

use App\Enums\ConfigurationSnapshotSource;
use App\Enums\FieldSetVersionStatus;
use App\Models\ConfigurationSnapshot;
use App\Models\FieldSet;
use App\Models\FieldSetVersion;
use App\Models\SnapshotFieldDefinition;
use App\Models\SnapshotFieldRule;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * VER-002: materialisiert aktive Feldset-Version in unveränderlichen Snapshot.
 */
final class ConfigurationSnapshotMaterializer
{
    public const SYSTEM_CALCULATION_CORE_KEY = 'system_calculation_core';

    public function materializeFromActiveSet(
        string $fieldSetKey = self::SYSTEM_CALCULATION_CORE_KEY,
        ConfigurationSnapshotSource $source = ConfigurationSnapshotSource::SeedActive,
    ): ConfigurationSnapshot {
        $set = FieldSet::query()
            ->where('key', $fieldSetKey)
            ->firstOrFail();

        if ($set->active_version_id === null) {
            throw new RuntimeException("Feldset {$fieldSetKey} hat keine aktive Version.");
        }

        /** @var FieldSetVersion $version */
        $version = FieldSetVersion::query()
            ->with(['fields.revision.definition', 'rules'])
            ->whereKey($set->active_version_id)
            ->firstOrFail();

        if ($version->status !== FieldSetVersionStatus::Active) {
            throw new RuntimeException("Aktive Feldset-Version von {$fieldSetKey} ist nicht aktiv.");
        }

        return DB::transaction(function () use ($set, $version, $source): ConfigurationSnapshot {
            $snapshot = new ConfigurationSnapshot;
            $snapshot->field_set_id = $set->id;
            $snapshot->field_set_version_id = $version->id;
            $snapshot->source = $source;
            $snapshot->created_at = now();
            $snapshot->save();

            foreach ($version->fields as $membership) {
                $revision = $membership->revision;
                $definition = $revision->definition;

                $snapDef = new SnapshotFieldDefinition;
                $snapDef->configuration_snapshot_id = $snapshot->id;
                $snapDef->field_definition_id = $definition->id;
                $snapDef->field_definition_revision_id = $revision->id;
                $snapDef->key = $definition->key;
                $snapDef->field_type = $definition->field_type;
                $snapDef->label = $revision->label;
                $snapDef->help_text = $revision->help_text;
                $snapDef->scope = $definition->scope;
                $snapDef->applies_to = $definition->applies_to;
                $snapDef->sort = $membership->sort;
                $snapDef->group_key = $revision->group_key;
                $snapDef->reportable = $revision->reportable;
                $snapDef->validation_json = $revision->validation_json;
                $snapDef->save();
            }

            foreach ($version->rules as $rule) {
                $snapRule = new SnapshotFieldRule;
                $snapRule->configuration_snapshot_id = $snapshot->id;
                $snapRule->source_field_rule_id = $rule->id;
                $snapRule->sort = $rule->sort;
                $snapRule->condition_json = $rule->condition_json;
                $snapRule->action_json = $rule->action_json;
                $snapRule->save();
            }

            return $snapshot->load(['fieldDefinitions', 'rules']);
        });
    }
}
