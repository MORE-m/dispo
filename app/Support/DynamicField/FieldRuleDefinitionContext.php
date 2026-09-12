<?php

namespace App\Support\DynamicField;

use App\Enums\FieldScope;
use App\Enums\FieldType;
use App\Models\ConfigurationSnapshot;
use App\Models\ConfigurationSnapshotSource;
use App\Models\SnapshotFieldDefinition;

/**
 * Baut normalisierte Regel-Definitionen inkl. Action-Ziel-Schutz aus
 * eingefrorener Snapshot-Provenance (keine Live-Katalog-Abhängigkeit).
 */
final class FieldRuleDefinitionContext
{
    /**
     * @return array<string, object{
     *     key: string,
     *     field_type: FieldType,
     *     scope: FieldScope,
     *     options_json: list<array<string, mixed>>|null,
     *     action_target_readonly: bool
     * }>
     */
    public static function fromSnapshot(ConfigurationSnapshot $snapshot): array
    {
        $snapshot->loadMissing(['fieldDefinitions', 'sources']);

        $readonlyKeys = self::readonlyKeysFromProvenance($snapshot);
        if ($readonlyKeys === []) {
            $readonlyKeys = self::readonlyKeysFromSourceSnapshot($snapshot);
        }

        $raw = [];
        foreach ($snapshot->fieldDefinitions as $definition) {
            /** @var SnapshotFieldDefinition $definition */
            $raw[$definition->key] = (object) [
                'key' => $definition->key,
                'field_type' => $definition->field_type,
                'scope' => $definition->scope,
                'options_json' => $definition->options_json,
                'action_target_readonly' => isset($readonlyKeys[$definition->key]),
            ];
        }

        return FieldRuleContract::normalizeDefinitions($raw);
    }

    /**
     * @param  array<string, true>  $readonlyKeys
     * @param  array<array-key, object|array<string, mixed>>  $defsByKey
     * @return array<string, object{
     *     key: string,
     *     field_type: FieldType,
     *     scope: FieldScope,
     *     options_json: list<array<string, mixed>>|null,
     *     action_target_readonly: bool
     * }>
     */
    public static function withReadonlyKeys(array $defsByKey, array $readonlyKeys): array
    {
        $raw = [];
        foreach ($defsByKey as $def) {
            $normalized = FieldRuleContract::normalizeDefinition($def);
            $raw[$normalized->key] = (object) [
                'key' => $normalized->key,
                'field_type' => $normalized->field_type,
                'scope' => $normalized->scope,
                'options_json' => $normalized->options_json,
                'action_target_readonly' => isset($readonlyKeys[$normalized->key])
                    || (bool) ($normalized->action_target_readonly ?? false),
            ];
        }

        return FieldRuleContract::normalizeDefinitions($raw);
    }

    /**
     * @return array<string, true>
     */
    private static function readonlyKeysFromProvenance(ConfigurationSnapshot $snapshot): array
    {
        /** @var array<int, true> $calcOriginSourceIds */
        $calcOriginSourceIds = [];
        foreach ($snapshot->sources as $source) {
            if ($source->target_identity === ConfigurationSnapshotSource::TARGET_IDENTITY_CALC_ORIGIN) {
                $calcOriginSourceIds[(int) $source->id] = true;
            }
        }

        if ($calcOriginSourceIds === []) {
            return [];
        }

        /** @var array<string, true> $keys */
        $keys = [];
        foreach ($snapshot->fieldDefinitions as $definition) {
            $sourceId = $definition->provenance_definition_source_id;
            if ($sourceId !== null && isset($calcOriginSourceIds[(int) $sourceId])) {
                $keys[$definition->key] = true;
            }
        }

        return $keys;
    }

    /**
     * Gen1 / Fallback: Keys aus dem referenzierten Calc-Snapshot (und Gen3-Effektivs).
     *
     * @return array<string, true>
     */
    private static function readonlyKeysFromSourceSnapshot(ConfigurationSnapshot $snapshot): array
    {
        $sourceId = $snapshot->source_configuration_snapshot_id;
        if ($sourceId === null) {
            return [];
        }

        $calcSnapshot = ConfigurationSnapshot::query()
            ->with('fieldDefinitions')
            ->find($sourceId);
        if ($calcSnapshot === null) {
            return [];
        }

        /** @var array<string, true> $keys */
        $keys = [];
        foreach ($calcSnapshot->fieldDefinitions as $definition) {
            $keys[$definition->key] = true;
        }

        if ((int) $calcSnapshot->format_version !== ConfigurationSnapshot::FORMAT_VERSION_CONTEXTUAL_FREEZE) {
            return $keys;
        }

        if ($calcSnapshot->parent_configuration_snapshot_id !== null) {
            return $keys;
        }

        $effectiveDefs = SnapshotFieldDefinition::query()
            ->whereIn(
                'configuration_snapshot_id',
                ConfigurationSnapshot::query()
                    ->where('parent_configuration_snapshot_id', $calcSnapshot->id)
                    ->select('id'),
            )
            ->get(['key']);

        foreach ($effectiveDefs as $definition) {
            $keys[$definition->key] = true;
        }

        return $keys;
    }
}
