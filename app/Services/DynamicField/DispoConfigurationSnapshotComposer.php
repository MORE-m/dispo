<?php

namespace App\Services\DynamicField;

use App\Enums\ConfigurationSnapshotSource;
use App\Enums\FieldAppliesTo;
use App\Enums\FieldScope;
use App\Enums\FieldSetVersionStatus;
use App\Enums\FieldType;
use App\Models\ConfigurationSnapshot;
use App\Models\FieldSet;
use App\Models\FieldSetVersion;
use App\Models\FieldSetVersionField;
use App\Models\SnapshotFieldDefinition;
use App\Models\SnapshotFieldRule;
use App\Support\DynamicField\FieldRuleDefinitionContext;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * VER-004 / DF-2 / DF-3.2b: Compose eines Dispo-Config-Snapshots aus Calc-Snapshot + system_dispo_order_core.
 *
 * Erzeugt Snapshots der Generation 1. Der produktive Dispo-Freeze läuft seit
 * DF-3.3a2α über den {@see ConfigurationSnapshotFreezeService}; diese Klasse
 * bleibt für Legacy-Backfill und die Calc-Origin-Semantik erhalten.
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

    /** @var array<string, array{type: FieldType, scope: FieldScope, applies_to: list<FieldAppliesTo>}> */
    public const CALC_ORIGIN_EXPECTATIONS = [
        'campaign_period' => [
            'type' => FieldType::Period,
            'scope' => FieldScope::Header,
            'applies_to' => [FieldAppliesTo::Calculation, FieldAppliesTo::Both],
        ],
        'period_open' => [
            'type' => FieldType::Boolean,
            'scope' => FieldScope::Position,
            'applies_to' => [FieldAppliesTo::Calculation, FieldAppliesTo::Both],
        ],
        'position_flight_period' => [
            'type' => FieldType::Period,
            'scope' => FieldScope::Position,
            'applies_to' => [FieldAppliesTo::Calculation, FieldAppliesTo::Both],
        ],
    ];

    public function __construct(
        private readonly SnapshotFieldRuleEvaluator $rules,
    ) {}

    public function composeFromCalculationSnapshot(
        ConfigurationSnapshot $calculationSnapshot,
        ConfigurationSnapshotSource $source = ConfigurationSnapshotSource::DispoOrderCreate,
        ?int $expectedCalculationSnapshotId = null,
    ): ConfigurationSnapshot {
        if (! in_array($source, [
            ConfigurationSnapshotSource::DispoOrderCreate,
            ConfigurationSnapshotSource::DispoOrderLegacyBackfill,
        ], true)) {
            throw new RuntimeException('Ungültige Dispo-Snapshot-Source.');
        }

        $this->assertCalculationSourceSnapshot($calculationSnapshot, $expectedCalculationSnapshotId);

        return DB::transaction(function () use ($calculationSnapshot, $source): ConfigurationSnapshot {
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

            $calcDefsByKey = $calculationSnapshot->fieldDefinitions->keyBy('key');

            foreach (self::CALC_ORIGIN_KEYS as $key) {
                if (! $calcDefsByKey->has($key)) {
                    throw new RuntimeException("Calc-Snapshot fehlt Definition „{$key}“.");
                }
                $this->assertCalcOriginShape($calcDefsByKey->get($key), $key);
            }

            /** @var array<string, true> $seenKeys */
            $seenKeys = [];
            /** @var list<array{source: 'calc'|'native', calc: SnapshotFieldDefinition|null, membership: FieldSetVersionField|null}> $toMaterialize */
            $toMaterialize = [];

            foreach (self::CALC_ORIGIN_KEYS as $key) {
                $seenKeys[$key] = true;
                /** @var SnapshotFieldDefinition $calcOriginDef */
                $calcOriginDef = $calcDefsByKey->get($key);
                $toMaterialize[] = [
                    'source' => 'calc',
                    'calc' => $calcOriginDef,
                    'membership' => null,
                ];
            }

            foreach ($version->fields as $membership) {
                $revision = $membership->revision;
                $definition = $revision?->definition;
                if ($revision === null || $definition === null) {
                    throw new RuntimeException('Dispo-Feldset-Membership ohne gültige Revision.');
                }

                if ($definition->applies_to === FieldAppliesTo::Calculation) {
                    throw new RuntimeException(
                        "Feld „{$definition->key}“ mit applies_to=calculation darf nicht im Dispo-Feldset stehen.",
                    );
                }

                if (! in_array($definition->scope, [FieldScope::Header, FieldScope::Position], true)) {
                    throw new RuntimeException(
                        "Dispo-Feld „{$definition->key}“ muss Header- oder Position-Scope haben.",
                    );
                }

                if (! in_array($definition->field_type, [FieldType::ShortText, FieldType::LongText], true)) {
                    throw new RuntimeException(
                        "Dispo-Feld „{$definition->key}“ muss short_text oder long_text sein.",
                    );
                }

                if (in_array($definition->key, self::DISPO_TEXT_KEYS, true)
                    && $definition->field_type !== FieldType::LongText) {
                    throw new RuntimeException(
                        "Dispo-Feld „{$definition->key}“ muss long_text sein.",
                    );
                }

                if (isset($seenKeys[$definition->key])) {
                    throw new RuntimeException("Doppelter Snapshot-Schlüssel „{$definition->key}“.");
                }

                /** @var SnapshotFieldDefinition|null $calcDef */
                $calcDef = $calcDefsByKey->get($definition->key);
                if ($calcDef !== null) {
                    $seenKeys[$definition->key] = true;
                    $toMaterialize[] = [
                        'source' => 'calc',
                        'calc' => $calcDef,
                        'membership' => null,
                    ];
                } else {
                    $seenKeys[$definition->key] = true;
                    $toMaterialize[] = [
                        'source' => 'native',
                        'calc' => null,
                        'membership' => $membership,
                    ];
                }
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
            /** @var array<string, true> $calcReadonly */
            $calcReadonly = [];
            foreach ($toMaterialize as $item) {
                if ($item['source'] === 'calc') {
                    $calc = $item['calc'];
                    if ($calc === null) {
                        throw new RuntimeException('Calc-Snapshot-Definition fehlt beim Materialisieren.');
                    }
                    $defsForRuleCheck[$calc->key] = $calc;
                    $calcReadonly[$calc->key] = true;
                } else {
                    $nativeMembership = $item['membership'];
                    if ($nativeMembership === null) {
                        throw new RuntimeException('Native Membership fehlt beim Materialisieren.');
                    }
                    $defsForRuleCheck[$nativeMembership->revision->definition->key] = $nativeMembership->revision->definition;
                }
            }
            $this->rules->assertRulesCompatibleWithDefinitions(
                FieldRuleDefinitionContext::withReadonlyKeys(
                    $defsForRuleCheck,
                    $calcReadonly,
                ),
                $relevantRules,
            );

            $snapshot = new ConfigurationSnapshot;
            $snapshot->field_set_id = $set->id;
            $snapshot->field_set_version_id = $version->id;
            $snapshot->source = $source;
            $snapshot->source_configuration_snapshot_id = $calculationSnapshot->id;
            $snapshot->format_version = ConfigurationSnapshot::FORMAT_VERSION_LEGACY;
            $snapshot->created_at = now();
            $snapshot->save();

            foreach ($toMaterialize as $item) {
                if ($item['source'] === 'calc') {
                    $sourceDef = $item['calc'];
                    if ($sourceDef === null) {
                        throw new RuntimeException('Calc-Snapshot-Definition fehlt beim Materialisieren.');
                    }
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
                    $snapDef->required = (bool) $sourceDef->required;
                    $snapDef->visible = (bool) $sourceDef->visible;
                    $snapDef->validation_json = $sourceDef->validation_json;
                    $snapDef->save();

                    continue;
                }

                $membership = $item['membership'];
                if ($membership === null) {
                    throw new RuntimeException('Native Membership fehlt beim Materialisieren.');
                }
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
                $snapDef->required = SnapshotFieldDefinition::effectiveRequired($membership->required_override);
                $snapDef->visible = SnapshotFieldDefinition::effectiveVisible($membership->visible_override);
                $snapDef->validation_json = $revision->validation_json;
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

            return $snapshot->load(['fieldDefinitions', 'rules']);
        });
    }

    private function assertCalculationSourceSnapshot(
        ConfigurationSnapshot $calculationSnapshot,
        ?int $expectedCalculationSnapshotId,
    ): void {
        if ($expectedCalculationSnapshotId !== null
            && (int) $calculationSnapshot->id !== $expectedCalculationSnapshotId) {
            throw new RuntimeException(
                'Quellsnapshot stimmt nicht mit der configuration_snapshot_id der Kalkulation überein.',
            );
        }

        match ($calculationSnapshot->source) {
            ConfigurationSnapshotSource::SeedActive,
            ConfigurationSnapshotSource::LegacyBackfill => null,
            ConfigurationSnapshotSource::DispoOrderCreate,
            ConfigurationSnapshotSource::DispoOrderLegacyBackfill,
            ConfigurationSnapshotSource::DispoOrderPositionEffective => throw new RuntimeException(
                'Quellsnapshot ist bereits ein Dispo-Snapshot und darf nicht erneut als Calc-Quelle dienen.',
            ),
            ConfigurationSnapshotSource::CalculationPositionEffective => throw new RuntimeException(
                'Quellsnapshot ist ein Positions-Effektiv-Snapshot; als Calc-Quelle dient nur die Basis.',
            ),
        };
    }

    private function assertCalcOriginShape(SnapshotFieldDefinition $def, string $key): void
    {
        $expectation = self::CALC_ORIGIN_EXPECTATIONS[$key];
        if ($def->field_type !== $expectation['type']) {
            throw new RuntimeException("Calc-Feld „{$key}“ hat falschen Typ.");
        }
        if ($def->scope !== $expectation['scope']) {
            throw new RuntimeException("Calc-Feld „{$key}“ hat falschen Scope.");
        }
        if (! in_array($def->applies_to, $expectation['applies_to'], true)) {
            throw new RuntimeException("Calc-Feld „{$key}“ hat falsches applies_to.");
        }
    }
}
