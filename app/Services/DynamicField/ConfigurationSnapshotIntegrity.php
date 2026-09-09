<?php

namespace App\Services\DynamicField;

use App\Enums\ConfigurationSnapshotSource as ConfigurationSnapshotSourceEnum;
use App\Enums\FieldAppliesTo;
use App\Enums\FieldScope;
use App\Enums\FieldSetAssignmentTargetLayer;
use App\Models\CalculationPosition;
use App\Models\ConfigurationSnapshot;
use App\Models\ConfigurationSnapshotSource;
use App\Models\ConfigurationSnapshotSourceRule;
use App\Models\DispoOrderPosition;
use App\Models\FieldSetAssignment;
use App\Models\SnapshotFieldDefinition;
use App\Models\SnapshotFieldRule;
use App\Services\DynamicField\Assignment\FieldSetAssignmentMergeResolver;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * DF-3.3a2α / DF-3.3a2β: zentrale fail-closed Integritätsprüfung für Snapshots.
 *
 * Generation 1: nur bekannte format_version.
 * Generation 2: strikte Source-Taxonomie, Quellengraph und Property-Provenance.
 * Generation 3: zusätzlich Kategorie-/Werbemittelquellen sowie Basis- und
 * Positions-Effektiv-Snapshots mit eingefrorenem Kontext.
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

    /** @var list<string> */
    private const GENERATION_THREE_LAYERS = [
        FieldSetAssignmentMergeResolver::LAYER_PRIMARY_CORE,
        FieldSetAssignmentMergeResolver::LAYER_GLOBAL,
        FieldSetAssignmentMergeResolver::LAYER_ADVERTISING_CATEGORY,
        FieldSetAssignmentMergeResolver::LAYER_ADVERTISING_MEDIUM,
    ];

    /** @var list<string> */
    private const CONTEXT_COLUMNS = [
        'context_advertising_medium_id',
        'context_advertising_medium_code',
        'context_advertising_medium_name',
        'context_advertising_category_id',
        'context_advertising_category_key',
        'context_advertising_category_name',
    ];

    /**
     * Fail-closed Lesbarkeits- und Integritätsprüfung.
     */
    public function assertReadable(ConfigurationSnapshot $snapshot): void
    {
        $this->assertGeneration($snapshot, requireOwnership: true);
    }

    /**
     * DF-3.3a2β: Prüfung vor der Bindung an eine Position. Identisch zu
     * {@see assertReadable}, nur ohne Eigentümerbindung des Effektiv-Snapshots.
     */
    public function assertReadableInternal(ConfigurationSnapshot $snapshot): void
    {
        $this->assertGeneration($snapshot, requireOwnership: false);
    }

    /**
     * DF-3.3a2β: Ein Effektiv-Snapshot gehört genau einer Position – entweder
     * einer Kalkulations- oder einer Dispopositionszeile, passend zur Source und
     * zur Prozessfamilie des Basis-Snapshots.
     */
    public function assertOwnership(ConfigurationSnapshot $effective): void
    {
        $calculationOwners = CalculationPosition::query()
            ->where('effective_configuration_snapshot_id', $effective->id)
            ->with('calculation')
            ->get();
        $dispoOwners = DispoOrderPosition::query()
            ->where('effective_configuration_snapshot_id', $effective->id)
            ->with('dispoOrder')
            ->get();

        $owners = $calculationOwners->count() + $dispoOwners->count();
        if ($owners !== 1) {
            $this->fail($effective, "Effektiv-Snapshot erwartet genau einen Eigentümer, gefunden {$owners}");
        }

        if ($calculationOwners->count() === 1) {
            if ($effective->source !== ConfigurationSnapshotSourceEnum::CalculationPositionEffective) {
                $this->fail($effective, 'Kalkulationsposition besitzt einen Snapshot fremder Source');
            }

            $owner = $calculationOwners->first();
            $calculation = $owner->calculation;
            if ($calculation === null) {
                $this->fail($effective, 'Kalkulationsposition ohne zugehörige Kalkulation');
            }

            if ((int) $calculation->configuration_snapshot_id !== (int) $effective->parent_configuration_snapshot_id) {
                $this->fail(
                    $effective,
                    'Calc-Position gehört nicht zur Kalkulation des Parent-Basissnapshots',
                );
            }
        }

        if ($dispoOwners->count() === 1) {
            if ($effective->source !== ConfigurationSnapshotSourceEnum::DispoOrderPositionEffective) {
                $this->fail($effective, 'Dispoposition besitzt einen Snapshot fremder Source');
            }

            $owner = $dispoOwners->first();
            $order = $owner->dispoOrder;
            if ($order === null) {
                $this->fail($effective, 'Dispoposition ohne zugehörigen Dispoauftrag');
            }

            if ((int) $order->configuration_snapshot_id !== (int) $effective->parent_configuration_snapshot_id) {
                $this->fail(
                    $effective,
                    'Dispo-Position gehört nicht zum Dispoauftrag des Parent-Basissnapshots',
                );
            }
        }

        $this->assertProcessFamily($effective);
    }

    public static function isValidFingerprint(mixed $value): bool
    {
        return is_string($value) && preg_match(self::FINGERPRINT_PATTERN, $value) === 1;
    }

    private function assertGeneration(ConfigurationSnapshot $snapshot, bool $requireOwnership): void
    {
        $version = (int) $snapshot->format_version;

        if (! in_array($version, ConfigurationSnapshot::SUPPORTED_FORMAT_VERSIONS, true)) {
            $this->fail($snapshot, "unbekannte format_version {$version}");
        }

        if ($version === ConfigurationSnapshot::FORMAT_VERSION_LEGACY) {
            return;
        }

        if ($version === ConfigurationSnapshot::FORMAT_VERSION_GLOBAL_FREEZE) {
            $this->assertGenerationTwo($snapshot);

            return;
        }

        $this->assertGenerationThree($snapshot, $requireOwnership);
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

    /**
     * DF-3.3a2β / VER-003: Basis- und Positions-Effektiv-Snapshots.
     */
    private function assertGenerationThree(ConfigurationSnapshot $snapshot, bool $requireOwnership): void
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
            $this->fail($snapshot, 'v3-Sourcegraph fehlt');
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
        /** @var array<string, list<int>> $frozenTargetIds */
        $frozenTargetIds = [
            FieldSetAssignmentMergeResolver::LAYER_ADVERTISING_CATEGORY => [],
            FieldSetAssignmentMergeResolver::LAYER_ADVERTISING_MEDIUM => [],
        ];

        foreach ($sources as $source) {
            $sourceId = (int) $source->id;
            $sourcesById[$sourceId] = $source;
            $role = (string) $source->role;

            if (! in_array($role, self::KNOWN_ROLES, true)) {
                $this->fail($snapshot, "unbekannte Source-Rolle „{$role}“");
            }

            $layer = (string) $source->layer;
            if (! in_array($layer, self::GENERATION_THREE_LAYERS, true)) {
                $this->fail($snapshot, "unerlaubter Source-Layer „{$layer}“");
            }

            match ($role) {
                ConfigurationSnapshotSource::ROLE_CORE => $this->assertCoreSource($snapshot, $source, $coreCount),
                ConfigurationSnapshotSource::ROLE_ASSIGNMENT => $this->assertContextualAssignmentSource(
                    $snapshot,
                    $source,
                    $isDispo,
                    $frozenTargetIds,
                ),
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
                $this->fail($snapshot, "Dispo-v3 erwartet genau eine Calc-Origin-Source, gefunden {$calcOriginCount}");
            }
        } elseif ($calcOriginCount !== 0) {
            $this->fail($snapshot, 'Calc-v3 darf keine Calc-Origin-Source enthalten');
        }

        foreach ($snapshot->fieldDefinitions as $definition) {
            $this->assertDefinitionProvenance($snapshot, $definition, $sourcesById, $fieldsBySource);
        }

        foreach ($snapshot->rules as $rule) {
            $this->assertRuleProvenance($snapshot, $rule, $sourcesById, $rulesBySource);
        }

        if ($snapshot->isEffectiveSnapshot()) {
            $this->assertEffectiveSnapshot($snapshot, $frozenTargetIds, $requireOwnership);

            return;
        }

        $this->assertBaseSnapshot($snapshot);
    }

    /**
     * @param  array<string, list<int>>  $frozenTargetIds
     */
    private function assertEffectiveSnapshot(
        ConfigurationSnapshot $snapshot,
        array $frozenTargetIds,
        bool $requireOwnership,
    ): void {
        if (! in_array($snapshot->source, [
            ConfigurationSnapshotSourceEnum::CalculationPositionEffective,
            ConfigurationSnapshotSourceEnum::DispoOrderPositionEffective,
        ], true)) {
            $this->fail($snapshot, 'Effektiv-Snapshot mit unzulässiger Source '.$snapshot->source->value);
        }

        foreach (self::CONTEXT_COLUMNS as $column) {
            $value = $snapshot->{$column};
            if ($value === null || (is_string($value) && trim($value) === '')) {
                $this->fail($snapshot, "Effektiv-Snapshot ohne vollständigen eingefrorenen Kontext ({$column})");
            }
        }

        if ((int) $snapshot->context_advertising_medium_id < 1
            || (int) $snapshot->context_advertising_category_id < 1
        ) {
            $this->fail($snapshot, 'Effektiv-Snapshot mit ungültigen Kontext-IDs');
        }

        if ($snapshot->parent_configuration_snapshot_id === null) {
            $this->fail($snapshot, 'Effektiv-Snapshot ohne parent_configuration_snapshot_id');
        }

        $parent = $snapshot->parentConfigurationSnapshot;
        if ($parent === null) {
            $this->fail($snapshot, 'parent_configuration_snapshot_id verweist ins Leere');
        }

        if ((int) $parent->format_version !== ConfigurationSnapshot::FORMAT_VERSION_CONTEXTUAL_FREEZE) {
            $this->fail($snapshot, 'Parent-Snapshot ist kein Snapshot der Generation 3');
        }

        if ($parent->isEffectiveSnapshot()) {
            $this->fail($snapshot, 'Parent-Snapshot ist selbst ein Effektiv-Snapshot');
        }

        // Parent muss selbst ein vollständig lesbarer Gen-3-Basissnapshot sein.
        $this->assertReadableInternal($parent);

        if ((int) $snapshot->source_configuration_snapshot_id === (int) $snapshot->id) {
            $this->fail($snapshot, 'Effektiv-Snapshot darf nicht auf sich selbst als Herkunft verweisen');
        }

        if ($snapshot->source === ConfigurationSnapshotSourceEnum::CalculationPositionEffective) {
            if ($snapshot->source_configuration_snapshot_id !== null) {
                $this->fail($snapshot, 'Calc-Effektiv darf keine Herkunft tragen');
            }

            if ($this->isDispoSnapshot($parent) || $parent->source !== ConfigurationSnapshotSourceEnum::SeedActive) {
                $this->fail($snapshot, 'Calc-Effektiv braucht eine Calc-Basis (seed_active) als Parent');
            }
        }

        if ($snapshot->source === ConfigurationSnapshotSourceEnum::DispoOrderPositionEffective) {
            if ($snapshot->source_configuration_snapshot_id === null) {
                $this->fail($snapshot, 'Dispo-Effektiv ohne Calc-Effektiv-Herkunft');
            }

            if (! $this->isDispoSnapshot($parent)
                || $parent->source !== ConfigurationSnapshotSourceEnum::DispoOrderCreate
            ) {
                $this->fail($snapshot, 'Dispo-Effektiv braucht eine Dispo-Basis als Parent');
            }

            $snapshot->loadMissing('sourceConfigurationSnapshot');
            $origin = $snapshot->sourceConfigurationSnapshot;
            if ($origin === null) {
                $this->fail($snapshot, 'source_configuration_snapshot_id verweist ins Leere');
            }

            if ((int) $origin->format_version !== ConfigurationSnapshot::FORMAT_VERSION_CONTEXTUAL_FREEZE
                || $origin->source !== ConfigurationSnapshotSourceEnum::CalculationPositionEffective
            ) {
                $this->fail($snapshot, 'Dispo-Effektiv-Herkunft muss ein Calc-Effektiv der Generation 3 sein');
            }

            foreach (self::CONTEXT_COLUMNS as $column) {
                if ((string) $snapshot->{$column} !== (string) $origin->{$column}) {
                    $this->fail(
                        $snapshot,
                        "Dispo-Effektiv-Kontext ({$column}) weicht von der Calc-Herkunft ab",
                    );
                }
            }

            $this->assertReadableInternal($origin);
        }

        $this->assertProcessFamily($snapshot);

        // Bewusst kein Live-Abgleich Werbemittel → Oberkategorie: der Kontext ist
        // historisch eingefroren und darf sich in den Stammdaten ändern.
        $this->assertFrozenTargetsMatchContext($snapshot, $frozenTargetIds);

        foreach ($snapshot->fieldDefinitions as $definition) {
            if ($definition->scope !== FieldScope::Position) {
                $this->fail(
                    $snapshot,
                    "Effektiv-Snapshot enthält Feld „{$definition->key}“ außerhalb des Positionsscopes",
                );
            }
        }

        if ($requireOwnership) {
            $this->assertOwnership($snapshot);
        }
    }

    private function assertBaseSnapshot(ConfigurationSnapshot $snapshot): void
    {
        foreach (self::CONTEXT_COLUMNS as $column) {
            if ($snapshot->{$column} !== null) {
                $this->fail($snapshot, "Basis-Snapshot darf keinen Positionskontext tragen ({$column})");
            }
        }

        if ($snapshot->parent_configuration_snapshot_id !== null) {
            $this->fail($snapshot, 'Basis-Snapshot darf keinen Parent besitzen');
        }

        if ((int) $snapshot->source_configuration_snapshot_id === (int) $snapshot->id) {
            $this->fail($snapshot, 'Basis-Snapshot darf nicht auf sich selbst als Herkunft verweisen');
        }

        $isDispo = $this->isDispoSnapshot($snapshot);

        if ($isDispo) {
            if ($snapshot->source !== ConfigurationSnapshotSourceEnum::DispoOrderCreate) {
                $this->fail(
                    $snapshot,
                    'Dispo-Basis der Generation 3 muss source=dispo_order_create haben',
                );
            }

            if ($snapshot->source_configuration_snapshot_id === null) {
                $this->fail($snapshot, 'Dispo-Basis der Generation 3 ohne Calc-Herkunft');
            }

            $snapshot->loadMissing('sourceConfigurationSnapshot');
            $origin = $snapshot->sourceConfigurationSnapshot;
            if ($origin === null) {
                $this->fail($snapshot, 'source_configuration_snapshot_id verweist ins Leere');
            }

            if ((int) $origin->format_version !== ConfigurationSnapshot::FORMAT_VERSION_CONTEXTUAL_FREEZE) {
                $this->fail($snapshot, 'Dispo-Basis-Herkunft ist kein Snapshot der Generation 3');
            }

            if ($origin->isEffectiveSnapshot() || $this->isDispoSnapshot($origin)) {
                $this->fail($snapshot, 'Dispo-Basis-Herkunft muss eine Calc-Basis der Generation 3 sein');
            }

            if ($origin->source !== ConfigurationSnapshotSourceEnum::SeedActive) {
                $this->fail($snapshot, 'Dispo-Basis-Herkunft muss source=seed_active haben');
            }

            $this->assertReadableInternal($origin);
        } else {
            if ($snapshot->source !== ConfigurationSnapshotSourceEnum::SeedActive) {
                $this->fail(
                    $snapshot,
                    'Calc-Basis der Generation 3 muss source=seed_active haben',
                );
            }

            if ($snapshot->source_configuration_snapshot_id !== null) {
                $this->fail($snapshot, 'Calc-Basis der Generation 3 darf keine Herkunft tragen');
            }
        }

        foreach ($snapshot->fieldDefinitions as $definition) {
            if ($definition->scope !== FieldScope::Header) {
                $this->fail(
                    $snapshot,
                    "Basis-Snapshot enthält Feld „{$definition->key}“ außerhalb des Headerscopes",
                );
            }
        }
    }

    /**
     * Eingefrorene Kategorie-/Werbemittelquellen müssen zum eingefrorenen
     * Positionskontext passen (ID + Key/Code + Name) – ohne Live-Stammdaten.
     *
     * @param  array<string, list<int>>  $frozenTargetIds
     */
    private function assertFrozenTargetsMatchContext(
        ConfigurationSnapshot $snapshot,
        array $frozenTargetIds,
    ): void {
        $categoryId = (int) $snapshot->context_advertising_category_id;
        $mediumId = (int) $snapshot->context_advertising_medium_id;
        $categoryKey = (string) $snapshot->context_advertising_category_key;
        $categoryName = (string) $snapshot->context_advertising_category_name;
        $mediumCode = (string) $snapshot->context_advertising_medium_code;
        $mediumName = (string) $snapshot->context_advertising_medium_name;

        foreach ($frozenTargetIds[FieldSetAssignmentMergeResolver::LAYER_ADVERTISING_CATEGORY] as $targetId) {
            if ($targetId !== $categoryId) {
                $this->fail(
                    $snapshot,
                    "Kategorie-Quelle {$targetId} passt nicht zum eingefrorenen Kontext {$categoryId}",
                );
            }
        }

        foreach ($frozenTargetIds[FieldSetAssignmentMergeResolver::LAYER_ADVERTISING_MEDIUM] as $targetId) {
            if ($targetId !== $mediumId) {
                $this->fail(
                    $snapshot,
                    "Werbemittel-Quelle {$targetId} passt nicht zum eingefrorenen Kontext {$mediumId}",
                );
            }
        }

        $snapshot->loadMissing('sources');
        foreach ($snapshot->sources as $source) {
            if ((string) $source->role !== ConfigurationSnapshotSource::ROLE_ASSIGNMENT) {
                continue;
            }

            $layer = (string) $source->layer;
            if ($layer === FieldSetAssignmentMergeResolver::LAYER_ADVERTISING_CATEGORY) {
                if ((int) $source->target_id !== $categoryId
                    || (string) $source->target_key !== $categoryKey
                    || (string) $source->target_name !== $categoryName
                ) {
                    $this->fail(
                        $snapshot,
                        "Kategorie-Quelle {$source->id} weicht vom eingefrorenen Kontext (ID/Key/Name) ab",
                    );
                }
            }

            if ($layer === FieldSetAssignmentMergeResolver::LAYER_ADVERTISING_MEDIUM) {
                if ((int) $source->target_id !== $mediumId
                    || (string) $source->target_key !== $mediumCode
                    || (string) $source->target_name !== $mediumName
                ) {
                    $this->fail(
                        $snapshot,
                        "Werbemittel-Quelle {$source->id} weicht vom eingefrorenen Kontext (ID/Code/Name) ab",
                    );
                }
            }
        }
    }

    private function assertProcessFamily(ConfigurationSnapshot $snapshot): void
    {
        $parent = $snapshot->parentConfigurationSnapshot;
        if ($parent === null) {
            return;
        }

        $expected = $this->isDispoSnapshot($parent)
            ? ConfigurationSnapshotSourceEnum::DispoOrderPositionEffective
            : ConfigurationSnapshotSourceEnum::CalculationPositionEffective;

        if ($snapshot->source !== $expected) {
            $this->fail(
                $snapshot,
                "Effektiv-Snapshot gehört nicht zur Prozessfamilie des Basis-Snapshots {$parent->id}",
            );
        }
    }

    /**
     * DF-3.3a2β: Assignment-Quelle auf globaler, Kategorie- oder Werbemittelebene.
     *
     * @param  array<string, list<int>>  $frozenTargetIds
     */
    private function assertContextualAssignmentSource(
        ConfigurationSnapshot $snapshot,
        ConfigurationSnapshotSource $source,
        bool $isDispo,
        array &$frozenTargetIds,
    ): void {
        $layer = (string) $source->layer;
        $targetLayer = (string) $source->target_layer;

        $expectedLayer = match ($targetLayer) {
            FieldSetAssignmentTargetLayer::Global->value => FieldSetAssignmentMergeResolver::LAYER_GLOBAL,
            FieldSetAssignmentTargetLayer::AdvertisingCategory->value => FieldSetAssignmentMergeResolver::LAYER_ADVERTISING_CATEGORY,
            FieldSetAssignmentTargetLayer::AdvertisingMedium->value => FieldSetAssignmentMergeResolver::LAYER_ADVERTISING_MEDIUM,
            default => null,
        };

        if ($expectedLayer === null) {
            $this->fail($snapshot, "Assignment-Source {$source->id} mit unbekanntem target_layer „{$targetLayer}“");
        }

        if ($layer !== $expectedLayer) {
            $this->fail(
                $snapshot,
                "Assignment-Source {$source->id}: Layer „{$layer}“ passt nicht zu target_layer „{$targetLayer}“",
            );
        }

        if ($targetLayer === FieldSetAssignmentTargetLayer::Global->value) {
            $expectedIdentity = FieldSetAssignment::buildTargetIdentity(
                FieldSetAssignmentTargetLayer::Global,
                null,
                null,
            );
            if ((string) $source->target_identity !== $expectedIdentity) {
                $this->fail($snapshot, "Assignment-Source {$source->id} muss target_identity „g“ haben");
            }

            $this->assertNoCategoryOrMediumTargetRefs($snapshot, $source, 'Globale Assignment-Source');
        } else {
            if ($source->target_id === null || $source->target_key === null || $source->target_name === null) {
                $this->fail(
                    $snapshot,
                    "Assignment-Source {$source->id} ohne vollständige eingefrorene Zielreferenz",
                );
            }

            $expectedIdentity = $targetLayer === FieldSetAssignmentTargetLayer::AdvertisingCategory->value
                ? FieldSetAssignment::buildTargetIdentity(
                    FieldSetAssignmentTargetLayer::AdvertisingCategory,
                    (int) $source->target_id,
                    null,
                )
                : FieldSetAssignment::buildTargetIdentity(
                    FieldSetAssignmentTargetLayer::AdvertisingMedium,
                    null,
                    (int) $source->target_id,
                );

            if ((string) $source->target_identity !== $expectedIdentity) {
                $this->fail(
                    $snapshot,
                    "Assignment-Source {$source->id}: target_identity passt nicht zu target_id",
                );
            }

            $frozenTargetIds[$layer][] = (int) $source->target_id;
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
            $this->fail($snapshot, 'Calc-Snapshot darf keine additional-Source enthalten');
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
            ConfigurationSnapshotSourceEnum::DispoOrderPositionEffective,
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
