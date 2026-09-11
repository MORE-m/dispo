<?php

namespace App\Services\DynamicField\Assignment;

use App\Enums\FieldAppliesTo;
use App\Enums\FieldSetAssignmentTargetLayer;
use App\Enums\FieldSetVersionStatus;
use App\Models\FieldSet;
use App\Models\FieldSetAssignment;
use App\Models\FieldSetVersion;
use App\Models\FieldSetVersionField;
use App\Services\DynamicField\Admin\AdminFieldSetCatalog;
use App\Support\DynamicField\FieldDefinitionOptionContract;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/**
 * DF-3.3a2α / VER-002: lädt Resolver-Quellen (Primary-Core + Assignments) und
 * berechnet Fingerprint-Teile.
 *
 * Preview darf ungültige Quellen als Warnung überspringen; der Runtime-Freeze
 * (`loadGlobalSources`) blockiert stattdessen fail-closed.
 */
final class AssignmentSourceLoader
{
    /**
     * Globale Freeze-Ebene: Primary-Core + aktive globale Assignments.
     *
     * @return array{sources: list<array<string, mixed>>, fingerprint_parts: array<string, mixed>}
     */
    public function loadGlobalSources(FieldAppliesTo $process): array
    {
        $core = $this->loadPrimaryCoreSource($process);

        $sources = [$core['source']];
        $fingerprintParts = [
            'process' => $process->value,
            'layers' => [FieldSetAssignmentMergeResolver::LAYER_PRIMARY_CORE, FieldSetAssignmentMergeResolver::LAYER_GLOBAL],
            'primary_core' => $core['fingerprint'],
            'assignments' => [],
        ];

        foreach ($this->loadGlobalAssignments($process) as $assignment) {
            $validity = $this->assessAssignmentSourceValidity($assignment, $process);
            if (! $validity['valid']) {
                throw ValidationException::withMessages([
                    'configuration' => "Aktives Assignment {$assignment->id} ist nicht mehr auflösbar: {$validity['message']}",
                ]);
            }

            $built = $this->buildAssignmentSource($assignment);
            $sources[] = $built['source'];
            $fingerprintParts['assignments'][] = $built['fingerprint'];
        }

        return [
            'sources' => $sources,
            'fingerprint_parts' => $fingerprintParts,
        ];
    }

    /**
     * DF-3.3a2β / VER-003: vollständige Freeze-Ebene – Primary-Core plus **alle**
     * aktiven Assignments des Prozesses (global, Oberkategorie, Werbemittel).
     *
     * Reihenfolge und Fingerprint sind deterministisch: global vor Kategorie vor
     * Werbemittel, je Ebene `sort`/`id` ASC. Ungültige aktive Quellen blockieren
     * fail-closed – identisch zu {@see loadGlobalSources}.
     *
     * @return array{sources: list<array<string, mixed>>, fingerprint_parts: array<string, mixed>}
     */
    public function loadUniverseSources(FieldAppliesTo $process): array
    {
        $core = $this->loadPrimaryCoreSource($process);

        $sources = [$core['source']];
        $fingerprintParts = [
            'process' => $process->value,
            'layers' => [
                FieldSetAssignmentMergeResolver::LAYER_PRIMARY_CORE,
                FieldSetAssignmentMergeResolver::LAYER_GLOBAL,
                FieldSetAssignmentMergeResolver::LAYER_ADVERTISING_CATEGORY,
                FieldSetAssignmentMergeResolver::LAYER_ADVERTISING_MEDIUM,
            ],
            'primary_core' => $core['fingerprint'],
            'assignments' => [],
        ];

        foreach ($this->loadUniverseAssignments($process) as $assignment) {
            $validity = $this->assessAssignmentSourceValidity($assignment, $process);
            if (! $validity['valid']) {
                throw ValidationException::withMessages([
                    'configuration' => "Aktives Assignment {$assignment->id} ist nicht mehr auflösbar: {$validity['message']}",
                ]);
            }

            $built = $this->buildAssignmentSource($assignment);
            $sources[] = $built['source'];
            $fingerprintParts['assignments'][] = $built['fingerprint'];
        }

        return [
            'sources' => $sources,
            'fingerprint_parts' => $fingerprintParts,
        ];
    }

    /**
     * Aktive globale Assignments für den Prozess (inkl. `both`), sort/id ASC.
     *
     * @return list<FieldSetAssignment>
     */
    public function loadGlobalAssignments(FieldAppliesTo $process): array
    {
        return $this->loadAssignmentsForTargetLayer($process, FieldSetAssignmentTargetLayer::Global);
    }

    /**
     * Alle aktiven Assignments des Prozesses in kanonischer Ebenenreihenfolge.
     *
     * @return list<FieldSetAssignment>
     */
    public function loadUniverseAssignments(FieldAppliesTo $process): array
    {
        /** @var list<FieldSetAssignment> $rows */
        $rows = [];

        foreach ([
            FieldSetAssignmentTargetLayer::Global,
            FieldSetAssignmentTargetLayer::AdvertisingCategory,
            FieldSetAssignmentTargetLayer::AdvertisingMedium,
        ] as $targetLayer) {
            foreach ($this->loadAssignmentsForTargetLayer($process, $targetLayer) as $assignment) {
                $rows[] = $assignment;
            }
        }

        return $rows;
    }

    /**
     * @return list<FieldSetAssignment>
     */
    private function loadAssignmentsForTargetLayer(
        FieldAppliesTo $process,
        FieldSetAssignmentTargetLayer $targetLayer,
    ): array {
        /** @var list<FieldSetAssignment> $rows */
        $rows = FieldSetAssignment::query()
            ->with([
                'fieldSet.activeVersion.fields.revision.options',
                'fieldSet.activeVersion.fields.revision.definition',
                'fieldSet.activeVersion.fields.definition',
                'fieldSet.activeVersion.rules',
                'advertisingCategory',
                'advertisingMedium',
            ])
            ->where('target_layer', $targetLayer->value)
            ->where('is_active', true)
            ->where(function ($q) use ($process): void {
                $q->where('applies_to_process', $process->value)
                    ->orWhere('applies_to_process', FieldAppliesTo::Both->value);
            })
            ->orderBy('sort')
            ->orderBy('id')
            ->get()
            ->all();

        return $rows;
    }

    /**
     * Freeze-Fingerprint über Header- und Positionsauflösung.
     *
     * @param  array<string, mixed>  $fingerprintParts
     * @param  array<string, mixed>  $resolvedHeader
     * @param  array<string, mixed>  $resolvedPosition
     */
    public function fingerprint(
        array $fingerprintParts,
        array $resolvedHeader,
        array $resolvedPosition,
    ): string {
        $canonical = [
            'context' => $fingerprintParts,
            'header' => $this->canonicalResolved($resolvedHeader),
            'position' => $this->canonicalResolved($resolvedPosition),
        ];

        return hash('sha256', json_encode($canonical, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
    }

    /**
     * Preview-Fingerprint über eine einzelne Auflösung (DF-3.3a1-Verhalten).
     *
     * @param  array<string, mixed>  $fingerprintParts
     * @param  array<string, mixed>  $resolved
     */
    public function fingerprintForResolved(array $fingerprintParts, array $resolved): string
    {
        $canonical = [
            'context' => $fingerprintParts,
            'fields' => $this->canonicalFields($resolved['fields'] ?? []),
            'rules' => $this->canonicalRules($resolved['rules'] ?? []),
            'conflicts' => $resolved['conflicts'] ?? [],
        ];

        return hash('sha256', json_encode($canonical, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
    }

    /**
     * @return array{source: array<string, mixed>, fingerprint: array<string, mixed>}
     */
    public function loadPrimaryCoreSource(FieldAppliesTo $process): array
    {
        $key = $process === FieldAppliesTo::DispoOrder
            ? AdminFieldSetCatalog::DISPO_ORDER_CORE
            : AdminFieldSetCatalog::CALCULATION_CORE;

        /** @var FieldSet $fieldSet */
        $fieldSet = FieldSet::query()->where('key', $key)->firstOrFail();
        if ($fieldSet->active_version_id === null) {
            throw new RuntimeException("Primary-Core {$key} hat keine aktive Version.");
        }

        /** @var FieldSetVersion $version */
        $version = FieldSetVersion::query()
            ->with(['fields.revision.options', 'fields.revision.definition', 'fields.definition', 'rules'])
            ->whereKey($fieldSet->active_version_id)
            ->firstOrFail();

        if ($version->status !== FieldSetVersionStatus::Active) {
            throw new RuntimeException("Primary-Core-Version von {$key} ist nicht aktiv.");
        }

        $source = $this->versionToSource(
            layer: FieldSetAssignmentMergeResolver::LAYER_PRIMARY_CORE,
            assignment: null,
            fieldSet: $fieldSet,
            version: $version,
            isSystemCore: true,
        );

        return [
            'source' => $source,
            'fingerprint' => [
                'field_set_id' => $fieldSet->id,
                'field_set_key' => $fieldSet->key,
                'active_version_id' => $fieldSet->active_version_id,
                'version_id' => $version->id,
                'version_number' => $version->version,
                'memberships' => $this->membershipFingerprint($version),
                'rules' => $this->ruleFingerprint($version),
            ],
        ];
    }

    /**
     * @return array{source: array<string, mixed>, fingerprint: array<string, mixed>}
     */
    public function buildAssignmentSource(FieldSetAssignment $assignment): array
    {
        $fieldSet = $assignment->fieldSet;
        if ($fieldSet === null) {
            throw new RuntimeException("Assignment {$assignment->id}: Feldset-FK beschädigt.");
        }

        /** @var FieldSetVersion $version */
        $version = $fieldSet->activeVersion
            ?? FieldSetVersion::query()
                ->with(['fields.revision.options', 'fields.revision.definition', 'fields.definition', 'rules'])
                ->whereKey($fieldSet->active_version_id)
                ->firstOrFail();

        if ($fieldSet->activeVersion !== null) {
            $version->loadMissing(['fields.revision.options', 'fields.revision.definition', 'fields.definition', 'rules']);
        }

        $source = $this->versionToSource(
            layer: FieldSetAssignmentMergeResolver::layerFromTarget($assignment->target_layer),
            assignment: $assignment,
            fieldSet: $fieldSet,
            version: $version,
            isSystemCore: false,
        );

        return [
            'source' => $source,
            'fingerprint' => [
                'assignment_id' => $assignment->id,
                'lock_version' => $assignment->lock_version,
                'is_active' => $assignment->is_active,
                'sort' => $assignment->sort,
                'applies_to_process' => $assignment->applies_to_process->value,
                'target_layer' => $assignment->target_layer->value,
                'target_identity' => $assignment->target_identity,
                'field_set_id' => $fieldSet->id,
                'is_assignable' => $fieldSet->is_assignable,
                'active_version_id' => $fieldSet->active_version_id,
                'version_id' => $version->id,
                'version_status' => $version->status->value,
                'memberships' => $this->membershipFingerprint($version),
                'rules' => $this->ruleFingerprint($version),
            ],
        ];
    }

    /**
     * @return array{valid: bool, code: string, message: string}
     */
    public function assessAssignmentSourceValidity(FieldSetAssignment $assignment, FieldAppliesTo $process): array
    {
        $fieldSet = $assignment->fieldSet;
        if ($fieldSet === null) {
            throw new RuntimeException("Assignment {$assignment->id}: Feldset-FK beschädigt.");
        }

        if ($fieldSet->is_system || AdminFieldSetCatalog::isCoreKey($fieldSet->key)) {
            return [
                'valid' => false,
                'code' => 'system_fieldset',
                'message' => 'Core-/System-Feldsets dürfen nicht als Assignment-Quelle dienen.',
            ];
        }

        if (! $fieldSet->is_assignable) {
            return [
                'valid' => false,
                'code' => 'fieldset_not_assignable',
                'message' => 'Feldset ist nicht assignierbar.',
            ];
        }

        if (! $assignment->appliesToProcess($process)) {
            return [
                'valid' => false,
                'code' => 'process_incompatible',
                'message' => 'Assignment-Prozessgültigkeit passt nicht zum Preview-Prozess.',
            ];
        }

        if (! $this->processIsSubsetOfFieldSet($assignment->applies_to_process, $fieldSet->applies_to)) {
            return [
                'valid' => false,
                'code' => 'fieldset_process_incompatible',
                'message' => 'Feldset-Gültigkeit ist nicht mehr kompatibel zum Assignment.',
            ];
        }

        if ($fieldSet->active_version_id === null) {
            return [
                'valid' => false,
                'code' => 'missing_active_version',
                'message' => 'Feldset besitzt keine aktive Version.',
            ];
        }

        $version = $fieldSet->activeVersion;
        if ($version === null) {
            throw new RuntimeException("Assignment {$assignment->id}: active_version_id beschädigt.");
        }

        if ($version->status !== FieldSetVersionStatus::Active) {
            return [
                'valid' => false,
                'code' => 'active_version_not_active',
                'message' => 'Aktive Feldset-Version hat nicht den Status active.',
            ];
        }

        return [
            'valid' => true,
            'code' => 'ok',
            'message' => 'ok',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function versionToSource(
        string $layer,
        ?FieldSetAssignment $assignment,
        FieldSet $fieldSet,
        FieldSetVersion $version,
        bool $isSystemCore,
    ): array {
        $memberships = [];
        foreach ($version->fields as $membership) {
            $definition = $membership->revision !== null
                ? $membership->revision->definition
                : $membership->definition;
            $revision = $membership->revision;
            if ($definition === null || $revision === null) {
                throw new RuntimeException('Membership ohne Definition/Revision.');
            }

            $revision->loadMissing('options');
            $fieldType = $definition->field_type;
            $options = $fieldType->isChoice()
                ? FieldDefinitionOptionContract::fromRevisionOptions($revision->options)
                : null;

            if ($fieldType->isChoice() && ($options === null || $options === [])) {
                throw new RuntimeException(
                    "Auswahlfeld „{$definition->key}“ besitzt in Revision {$revision->id} keine Optionen.",
                );
            }

            if ($fieldType->isChoice() && $options !== null && ! FieldDefinitionOptionContract::hasActiveOption($options)) {
                throw new RuntimeException(
                    "Auswahlfeld „{$definition->key}“ benötigt mindestens eine aktive Option für den Freeze.",
                );
            }

            $memberships[] = [
                'field_definition_id' => $definition->id,
                'field_definition_revision_id' => $revision->id,
                'field_key' => $definition->key,
                'field_scope' => $definition->scope->value,
                'field_applies_to' => $definition->applies_to->value,
                'definition_is_active' => $definition->is_active,
                'definition_is_system' => $definition->is_system,
                'group_key' => $revision->group_key,
                'sort' => $membership->sort,
                'required_override' => $membership->required_override,
                'visible_override' => $membership->visible_override,
                'label' => $revision->label,
                'help_text' => $revision->help_text,
                'validation_json' => $revision->validation_json,
                'options_json' => $options,
                'reportable' => $revision->reportable,
                'field_type' => $fieldType->value,
            ];
        }

        $rules = [];
        foreach ($version->rules as $rule) {
            $rules[] = [
                'field_rule_id' => $rule->id,
                'sort' => $rule->sort,
                'condition_json' => $rule->condition_json,
                'action_json' => $rule->action_json,
            ];
        }

        $target = $this->targetReference($assignment);

        return [
            'layer' => $layer,
            'assignment_id' => $assignment?->id,
            'assignment_sort' => $assignment?->sort,
            'assignment_lock_version' => $assignment?->lock_version,
            'assignment_applies_to_process' => $assignment?->applies_to_process->value,
            'assignment_is_active' => $assignment?->is_active,
            'target_layer' => $assignment !== null
                ? $assignment->target_layer->value
                : FieldSetAssignmentTargetLayer::Global->value,
            'target_identity' => $assignment !== null
                ? $assignment->target_identity
                : FieldSetAssignment::buildTargetIdentity(FieldSetAssignmentTargetLayer::Global, null, null),
            'target_id' => $target['id'],
            'target_key' => $target['key'],
            'target_name' => $target['name'],
            'field_set_id' => $fieldSet->id,
            'field_set_key' => $fieldSet->key,
            'field_set_name' => $fieldSet->name,
            'field_set_version_id' => $version->id,
            'field_set_version_number' => $version->version,
            'is_system_core' => $isSystemCore,
            'memberships' => $memberships,
            'rules' => $rules,
        ];
    }

    /**
     * DF-3.3a2β: eingefrorene Zielreferenz einer Quelle. Global und Primary-Core
     * tragen bewusst keine Kategorie-/Werbemittelreferenz.
     *
     * @return array{id: int|null, key: string|null, name: string|null}
     */
    private function targetReference(?FieldSetAssignment $assignment): array
    {
        if ($assignment === null || $assignment->target_layer === FieldSetAssignmentTargetLayer::Global) {
            return ['id' => null, 'key' => null, 'name' => null];
        }

        if ($assignment->target_layer === FieldSetAssignmentTargetLayer::AdvertisingCategory) {
            $category = $assignment->advertisingCategory;
            if ($category === null) {
                throw new RuntimeException("Assignment {$assignment->id}: Oberkategorie-FK beschädigt.");
            }

            return [
                'id' => (int) $category->id,
                'key' => (string) $category->key,
                'name' => (string) $category->name,
            ];
        }

        $medium = $assignment->advertisingMedium;
        if ($medium === null) {
            throw new RuntimeException("Assignment {$assignment->id}: Werbemittel-FK beschädigt.");
        }

        return [
            'id' => (int) $medium->id,
            'key' => (string) $medium->code,
            'name' => (string) $medium->name,
        ];
    }

    /**
     * @param  array<string, mixed>  $resolved
     * @return array<string, mixed>
     */
    private function canonicalResolved(array $resolved): array
    {
        return [
            'scope' => $resolved['scope'] ?? null,
            'fields' => $this->canonicalFields($resolved['fields'] ?? []),
            'rules' => $this->canonicalRules($resolved['rules'] ?? []),
            'conflicts' => $resolved['conflicts'] ?? [],
        ];
    }

    /**
     * @param  iterable<array<string, mixed>>  $fields
     * @return list<array<string, mixed>>
     */
    private function canonicalFields(iterable $fields): array
    {
        $rows = [];
        foreach ($fields as $field) {
            $rows[] = [
                'field_definition_id' => $field['field_definition_id'],
                'field_definition_revision_id' => $field['field_definition_revision_id'],
                'field_key' => $field['field_key'],
                'sort' => $field['sort'],
                'group_key' => $field['group_key'] ?? null,
                'required_override' => $field['required_override'],
                'visible_override' => $field['visible_override'],
                'winning_merge_order' => $field['winning_merge_order'],
                'winning_layer' => $field['winning_layer'],
                'options_json' => $field['options_json'] ?? null,
            ];
        }

        return $rows;
    }

    /**
     * @param  iterable<array<string, mixed>>  $rules
     * @return list<array<string, mixed>>
     */
    private function canonicalRules(iterable $rules): array
    {
        $rows = [];
        foreach ($rules as $rule) {
            $rows[] = [
                'field_rule_id' => $rule['field_rule_id'],
                'sort' => $rule['sort'],
                'condition_json' => $rule['condition_json'],
                'action_json' => $rule['action_json'],
                'merge_order' => $rule['merge_order'],
            ];
        }

        return $rows;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function membershipFingerprint(FieldSetVersion $version): array
    {
        $rows = [];
        foreach ($version->fields as $membership) {
            $rows[] = [
                'id' => $membership->id,
                'field_definition_id' => $membership->field_definition_id,
                'field_definition_revision_id' => $membership->field_definition_revision_id,
                'sort' => $membership->sort,
                'required_override' => $membership->required_override,
                'visible_override' => $membership->visible_override,
                'options_json' => $this->membershipOptionsFingerprint($membership),
            ];
        }

        return $rows;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function ruleFingerprint(FieldSetVersion $version): array
    {
        $rows = [];
        foreach ($version->rules as $rule) {
            $rows[] = [
                'id' => $rule->id,
                'sort' => $rule->sort,
                'condition_json' => $rule->condition_json,
                'action_json' => $rule->action_json,
            ];
        }

        return $rows;
    }

    /**
     * @return list<array{key: string, label: string, sort: int, is_active: bool}>|null
     */
    private function membershipOptionsFingerprint(FieldSetVersionField $membership): ?array
    {
        $revision = $membership->revision;
        if ($revision === null) {
            return null;
        }

        $definition = $revision->definition ?? $membership->definition;
        if ($definition === null || ! $definition->field_type->isChoice()) {
            return null;
        }

        $revision->loadMissing('options');

        return FieldDefinitionOptionContract::fromRevisionOptions($revision->options);
    }

    private function processIsSubsetOfFieldSet(FieldAppliesTo $assignment, FieldAppliesTo $fieldSet): bool
    {
        return match ($fieldSet) {
            FieldAppliesTo::Calculation => $assignment === FieldAppliesTo::Calculation,
            FieldAppliesTo::DispoOrder => $assignment === FieldAppliesTo::DispoOrder,
            FieldAppliesTo::Both => true,
        };
    }
}
