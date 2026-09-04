<?php

namespace App\Services\DynamicField;

use App\Enums\ConfigurationSnapshotSource;
use App\Enums\FieldSetVersionStatus;
use App\Enums\FieldType;
use App\Models\ConfigurationSnapshot;
use App\Models\FieldSet;
use App\Models\FieldSetVersion;
use App\Models\SnapshotFieldDefinition;
use App\Models\SnapshotFieldRule;
use RuntimeException;

/**
 * VER-004 / DF-2: Compose eines Dispo-Config-Snapshots aus Calc-Snapshot + system_dispo_order_core.
 */
final class DispoConfigurationSnapshotComposer
{
    public const SYSTEM_DISPO_ORDER_CORE_KEY = 'system_dispo_order_core';

    /** @var list<string> */
    public const CALC_ORIGIN_KEYS = [
        'campaign_period',
        'period_open',
        'position_flight_period',
    ];

    /** @var list<string> */
    public const DISPO_TEXT_KEYS = [
        'billing_special_features',
        'disposition_notes',
    ];

    public function __construct(
        private readonly SnapshotFieldRuleEvaluator $rules,
    ) {}

    public function composeFromCalculationSnapshot(
        ConfigurationSnapshot $calculationSnapshot,
        ConfigurationSnapshotSource $source = ConfigurationSnapshotSource::DispoOrderCreate,
    ): ConfigurationSnapshot {
        if (! in_array($source, [
            ConfigurationSnapshotSource::DispoOrderCreate,
            ConfigurationSnapshotSource::DispoOrderLegacyBackfill,
        ], true)) {
            throw new RuntimeException('Ungültige Dispo-Snapshot-Source.');
        }

        $calculationSnapshot->loadMissing(['fieldDefinitions', 'rules']);

        $set = FieldSet::query()
            ->where('key', self::SYSTEM_DISPO_ORDER_CORE_KEY)
            ->firstOrFail();

        if ($set->active_version_id === null) {
            throw new RuntimeException('Feldset system_dispo_order_core hat keine aktive Version.');
        }

        /** @var FieldSetVersion $version */
        $version = FieldSetVersion::query()
            ->with(['fields.revision.definition', 'rules'])
            ->whereKey($set->active_version_id)
            ->firstOrFail();

        if ($version->status !== FieldSetVersionStatus::Active) {
            throw new RuntimeException('Aktive Version von system_dispo_order_core ist nicht aktiv.');
        }

        $calcDefs = $calculationSnapshot->fieldDefinitions
            ->whereIn('key', self::CALC_ORIGIN_KEYS)
            ->keyBy('key');

        foreach (self::CALC_ORIGIN_KEYS as $key) {
            if (! $calcDefs->has($key)) {
                throw new RuntimeException("Calc-Snapshot fehlt Definition „{$key}“.");
            }
        }

        $seenKeys = [];
        foreach ($calcDefs as $def) {
            $seenKeys[$def->key] = true;
        }

        $dispoMemberships = [];
        foreach ($version->fields as $membership) {
            $revision = $membership->revision;
            $definition = $revision?->definition;
            if ($revision === null || $definition === null) {
                throw new RuntimeException('Dispo-Feldset-Membership ohne gültige Revision.');
            }
            if (! in_array($definition->key, self::DISPO_TEXT_KEYS, true)) {
                throw new RuntimeException(
                    "Unerwartetes Feld „{$definition->key}“ in system_dispo_order_core.",
                );
            }
            if ($definition->field_type !== FieldType::LongText) {
                throw new RuntimeException(
                    "Dispo-Feld „{$definition->key}“ muss long_text sein.",
                );
            }
            if (isset($seenKeys[$definition->key])) {
                throw new RuntimeException("Doppelter Snapshot-Schlüssel „{$definition->key}“.");
            }
            $seenKeys[$definition->key] = true;
            $dispoMemberships[] = $membership;
        }

        foreach (self::DISPO_TEXT_KEYS as $key) {
            if (! isset($seenKeys[$key])) {
                throw new RuntimeException("system_dispo_order_core fehlt Definition „{$key}“.");
            }
        }

        $relevantRules = $calculationSnapshot->rules->filter(function (SnapshotFieldRule $rule) use ($seenKeys): bool {
            $conditionKey = (string) ($rule->condition_json['field_key'] ?? '');
            $actionKey = (string) ($rule->action_json['field_key'] ?? '');

            if ($conditionKey !== '' && ! isset($seenKeys[$conditionKey])) {
                return false;
            }
            if ($actionKey !== '' && ! isset($seenKeys[$actionKey])) {
                return false;
            }

            return true;
        })->values();

        $defsForRuleCheck = [];
        foreach ($calcDefs as $def) {
            $defsForRuleCheck[$def->key] = $def;
        }
        foreach ($dispoMemberships as $membership) {
            $defsForRuleCheck[$membership->revision->definition->key] = $membership->revision->definition;
        }
        $this->rules->assertRulesCompatibleWithDefinitions($defsForRuleCheck, $relevantRules);

        $snapshot = new ConfigurationSnapshot;
        $snapshot->field_set_id = $set->id;
        $snapshot->field_set_version_id = $version->id;
        $snapshot->source = $source;
        $snapshot->source_configuration_snapshot_id = $calculationSnapshot->id;
        $snapshot->created_at = now();
        $snapshot->save();

        foreach (self::CALC_ORIGIN_KEYS as $key) {
            /** @var SnapshotFieldDefinition $sourceDef */
            $sourceDef = $calcDefs->get($key);
            $snapDef = new SnapshotFieldDefinition;
            $snapDef->configuration_snapshot_id = $snapshot->id;
            $snapDef->field_definition_id = $sourceDef->field_definition_id;
            $snapDef->field_definition_revision_id = $sourceDef->field_definition_revision_id;
            $snapDef->key = $sourceDef->key;
            $snapDef->field_type = $sourceDef->field_type;
            $snapDef->label = $sourceDef->label;
            $snapDef->help_text = $sourceDef->help_text;
            $snapDef->scope = $sourceDef->scope;
            $snapDef->applies_to = $sourceDef->applies_to;
            $snapDef->sort = $sourceDef->sort;
            $snapDef->group_key = $sourceDef->group_key;
            $snapDef->reportable = $sourceDef->reportable;
            $snapDef->validation_json = $sourceDef->validation_json;
            $snapDef->save();
        }

        foreach ($relevantRules as $rule) {
            $snapRule = new SnapshotFieldRule;
            $snapRule->configuration_snapshot_id = $snapshot->id;
            $snapRule->source_field_rule_id = $rule->source_field_rule_id ?? $rule->id;
            $snapRule->sort = $rule->sort;
            $snapRule->condition_json = $rule->condition_json;
            $snapRule->action_json = $rule->action_json;
            $snapRule->save();
        }

        foreach ($dispoMemberships as $membership) {
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

        return $snapshot->load(['fieldDefinitions', 'rules']);
    }
}
