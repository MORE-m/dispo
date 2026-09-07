<?php

namespace App\Services\DynamicField\Assignment;

use App\Enums\FieldAppliesTo;
use App\Enums\FieldScope;
use App\Enums\FieldSetAssignmentTargetLayer;
use App\Enums\FieldSetVersionStatus;
use App\Models\AdvertisingMedium;
use App\Models\FieldSet;
use App\Models\FieldSetAssignment;
use App\Models\FieldSetVersion;
use App\Services\DynamicField\Admin\AdminFieldSetCatalog;
use App\Services\DynamicField\SnapshotFieldRuleEvaluator;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/**
 * DF-3.3a1 / DYN-002 / ADM-002: Kontext-Preview inkl. Fingerprint.
 * Keine produktive Runtime-Wirkung.
 */
final class FieldSetAssignmentPreviewService
{
    public function __construct(
        private readonly FieldSetAssignmentMergeResolver $resolver,
        private readonly SnapshotFieldRuleEvaluator $ruleEvaluator,
    ) {}

    /**
     * @param  array{
     *     process: FieldAppliesTo|string,
     *     scope: FieldScope|string,
     *     advertising_category_id?: int|null,
     *     advertising_medium_id?: int|null,
     *     candidate_assignment_id?: int|null
     * }  $input
     * @return array<string, mixed>
     */
    public function preview(array $input): array
    {
        $process = $this->normalizeProcess($input['process']);
        $scope = $this->normalizeScope($input['scope']);
        $categoryId = $this->nullablePositiveInt($input['advertising_category_id'] ?? null);
        $mediumId = $this->nullablePositiveInt($input['advertising_medium_id'] ?? null);
        $candidateId = $this->nullablePositiveInt($input['candidate_assignment_id'] ?? null);

        if ($mediumId !== null) {
            $medium = AdvertisingMedium::query()->whereKey($mediumId)->first();
            if ($medium === null) {
                throw new RuntimeException("Werbemittel {$mediumId} fehlt (Integrität).");
            }
            $categoryId = (int) $medium->category_id;
        }

        if ($scope === FieldScope::Header && ($categoryId !== null || $mediumId !== null)) {
            throw ValidationException::withMessages([
                'scope' => 'Header-Preview unterstützt keine Kategorie-/Werbemittel-Kontexte.',
            ]);
        }

        $sourcesPayload = [];
        $fingerprintParts = [
            'process' => $process->value,
            'scope' => $scope->value,
            'advertising_category_id' => $categoryId,
            'advertising_medium_id' => $mediumId,
            'medium_category_id' => $mediumId !== null ? $categoryId : null,
        ];

        $core = $this->loadPrimaryCoreSource($process);
        $sourcesPayload[] = $core['source'];
        $fingerprintParts['primary_core'] = $core['fingerprint'];

        $assignments = $this->loadAssignmentsForContext(
            $process,
            $scope,
            $categoryId,
            $mediumId,
            $candidateId,
        );

        $includedAssignments = [];
        foreach ($assignments as $assignment) {
            $built = $this->buildAssignmentSource($assignment);
            $sourcesPayload[] = $built['source'];
            $fingerprintParts['assignments'][] = $built['fingerprint'];
            $includedAssignments[] = [
                'id' => $assignment->id,
                'field_set_id' => $assignment->field_set_id,
                'field_set_key' => $assignment->fieldSet?->key,
                'target_layer' => $assignment->target_layer->value,
                'advertising_category_id' => $assignment->advertising_category_id,
                'advertising_medium_id' => $assignment->advertising_medium_id,
                'applies_to_process' => $assignment->applies_to_process->value,
                'sort' => $assignment->sort,
                'is_active' => $assignment->is_active,
                'lock_version' => $assignment->lock_version,
                'is_candidate' => $candidateId !== null && (int) $assignment->id === $candidateId,
            ];
        }

        $resolved = $this->resolver->resolve([
            'process' => $process,
            'scope' => $scope,
            'advertising_category_id' => $categoryId,
            'advertising_medium_id' => $mediumId,
            'sources' => $sourcesPayload,
        ]);

        try {
            $defsByKey = [];
            foreach ($resolved['fields'] as $field) {
                $defsByKey[(string) $field['field_key']] = (object) $field;
            }
            $ruleObjects = [];
            foreach ($resolved['rules'] as $rule) {
                $ruleObjects[] = (object) [
                    'condition_json' => $rule['condition_json'],
                    'action_json' => $rule['action_json'],
                ];
            }
            $this->ruleEvaluator->assertRulesCompatibleWithDefinitions($defsByKey, $ruleObjects);
        } catch (RuntimeException $exception) {
            $resolved['conflicts'][] = [
                'code' => 'rule_structure_invalid',
                'message' => $exception->getMessage(),
            ];
            $resolved['has_blocking_conflicts'] = true;
        }

        $fingerprint = $this->fingerprint($fingerprintParts, $resolved);

        return [
            'process' => $process->value,
            'scope' => $scope->value,
            'advertising_category_id' => $categoryId,
            'advertising_medium_id' => $mediumId,
            'primary_core' => [
                'field_set_id' => $core['source']['field_set_id'],
                'field_set_key' => $core['source']['field_set_key'],
                'field_set_version_id' => $core['source']['field_set_version_id'],
                'field_set_version_number' => $core['source']['field_set_version_number'],
            ],
            'included_assignments' => $includedAssignments,
            'sources' => $resolved['sources'],
            'merge_order' => array_column($resolved['sources'], 'merge_order'),
            'fields' => $resolved['fields'],
            'rules' => $resolved['rules'],
            'warnings' => $resolved['warnings'],
            'conflicts' => $resolved['conflicts'],
            'has_blocking_conflicts' => $resolved['has_blocking_conflicts'],
            'fingerprint' => $fingerprint,
        ];
    }

    /**
     * @param  array<string, mixed>  $fingerprintParts
     * @param  array<string, mixed>  $resolved
     */
    public function fingerprint(array $fingerprintParts, array $resolved): string
    {
        $fields = [];
        foreach ($resolved['fields'] ?? [] as $field) {
            $fields[] = [
                'field_definition_id' => $field['field_definition_id'],
                'field_definition_revision_id' => $field['field_definition_revision_id'],
                'field_key' => $field['field_key'],
                'sort' => $field['sort'],
                'group_key' => $field['group_key'] ?? null,
                'required_override' => $field['required_override'],
                'visible_override' => $field['visible_override'],
                'winning_merge_order' => $field['winning_merge_order'],
                'winning_layer' => $field['winning_layer'],
            ];
        }

        $rules = [];
        foreach ($resolved['rules'] ?? [] as $rule) {
            $rules[] = [
                'field_rule_id' => $rule['field_rule_id'],
                'sort' => $rule['sort'],
                'condition_json' => $rule['condition_json'],
                'action_json' => $rule['action_json'],
                'merge_order' => $rule['merge_order'],
            ];
        }

        $canonical = [
            'context' => $fingerprintParts,
            'fields' => $fields,
            'rules' => $rules,
            'conflicts' => $resolved['conflicts'] ?? [],
        ];

        return hash('sha256', json_encode($canonical, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
    }

    /**
     * @return array{source: array<string, mixed>, fingerprint: array<string, mixed>}
     */
    private function loadPrimaryCoreSource(FieldAppliesTo $process): array
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
            ->with(['fields.revision.definition', 'fields.definition', 'rules'])
            ->whereKey($fieldSet->active_version_id)
            ->firstOrFail();

        if ($version->status !== FieldSetVersionStatus::Active) {
            throw new RuntimeException("Primary-Core-Version von {$key} ist nicht aktiv.");
        }

        $source = $this->versionToSource(
            layer: FieldSetAssignmentMergeResolver::LAYER_PRIMARY_CORE,
            assignmentId: null,
            assignmentSort: null,
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
     * @return list<FieldSetAssignment>
     */
    private function loadAssignmentsForContext(
        FieldAppliesTo $process,
        FieldScope $scope,
        ?int $categoryId,
        ?int $mediumId,
        ?int $candidateId,
    ): array {
        $query = FieldSetAssignment::query()
            ->with(['fieldSet.activeVersion.fields.revision.definition', 'fieldSet.activeVersion.fields.definition', 'fieldSet.activeVersion.rules'])
            ->where(function ($q) use ($process): void {
                $q->where('applies_to_process', $process->value)
                    ->orWhere('applies_to_process', FieldAppliesTo::Both->value);
            });

        if ($scope === FieldScope::Header) {
            $query->where('target_layer', FieldSetAssignmentTargetLayer::Global->value);
        } else {
            $query->where(function ($q) use ($categoryId, $mediumId): void {
                $q->where('target_layer', FieldSetAssignmentTargetLayer::Global->value);
                if ($categoryId !== null) {
                    $q->orWhere(function ($inner) use ($categoryId): void {
                        $inner->where('target_layer', FieldSetAssignmentTargetLayer::AdvertisingCategory->value)
                            ->where('advertising_category_id', $categoryId);
                    });
                }
                if ($mediumId !== null) {
                    $q->orWhere(function ($inner) use ($mediumId): void {
                        $inner->where('target_layer', FieldSetAssignmentTargetLayer::AdvertisingMedium->value)
                            ->where('advertising_medium_id', $mediumId);
                    });
                }
            });
        }

        /** @var list<FieldSetAssignment> $rows */
        $rows = $query->orderBy('sort')->orderBy('id')->get()->all();

        $result = [];
        foreach ($rows as $assignment) {
            $isCandidate = $candidateId !== null && (int) $assignment->id === $candidateId;
            if (! $assignment->is_active && ! $isCandidate) {
                continue;
            }
            $result[] = $assignment;
        }

        if ($candidateId !== null && ! collect($result)->contains(fn (FieldSetAssignment $a): bool => (int) $a->id === $candidateId)) {
            /** @var FieldSetAssignment $candidate */
            $candidate = FieldSetAssignment::query()
                ->with(['fieldSet.activeVersion.fields.revision.definition', 'fieldSet.activeVersion.fields.definition', 'fieldSet.activeVersion.rules'])
                ->whereKey($candidateId)
                ->firstOrFail();
            if (! $candidate->appliesToProcess($process)) {
                throw ValidationException::withMessages([
                    'candidate_assignment_id' => 'Kandidat passt nicht zum Preview-Prozess.',
                ]);
            }
            $result[] = $candidate;
        }

        usort($result, static function (FieldSetAssignment $a, FieldSetAssignment $b): int {
            $rank = [
                FieldSetAssignmentTargetLayer::Global->value => 1,
                FieldSetAssignmentTargetLayer::AdvertisingCategory->value => 2,
                FieldSetAssignmentTargetLayer::AdvertisingMedium->value => 3,
            ];
            $ra = $rank[$a->target_layer->value];
            $rb = $rank[$b->target_layer->value];
            if ($ra !== $rb) {
                return $ra <=> $rb;
            }
            if ($a->sort !== $b->sort) {
                return $a->sort <=> $b->sort;
            }

            return $a->id <=> $b->id;
        });

        return $result;
    }

    /**
     * @return array{source: array<string, mixed>, fingerprint: array<string, mixed>}
     */
    private function buildAssignmentSource(FieldSetAssignment $assignment): array
    {
        $fieldSet = $assignment->fieldSet;
        if ($fieldSet === null) {
            throw new RuntimeException("Assignment {$assignment->id}: Feldset-FK beschädigt.");
        }

        if ($fieldSet->is_system || AdminFieldSetCatalog::isCoreKey($fieldSet->key)) {
            throw ValidationException::withMessages([
                'field_set_id' => 'Core-Feldsets dürfen nicht als Assignment-Quelle dienen.',
            ]);
        }

        if ($fieldSet->active_version_id === null) {
            // Wird als Konflikt im Merge/Preview ausgewiesen, Quelle ohne Memberships.
            $empty = [
                'layer' => FieldSetAssignmentMergeResolver::layerFromTarget($assignment->target_layer),
                'assignment_id' => $assignment->id,
                'assignment_sort' => $assignment->sort,
                'field_set_id' => $fieldSet->id,
                'field_set_key' => $fieldSet->key,
                'field_set_version_id' => 0,
                'field_set_version_number' => 0,
                'is_system_core' => false,
                'memberships' => [],
                'rules' => [],
            ];

            return [
                'source' => $empty,
                'fingerprint' => [
                    'assignment_id' => $assignment->id,
                    'lock_version' => $assignment->lock_version,
                    'is_active' => $assignment->is_active,
                    'sort' => $assignment->sort,
                    'applies_to_process' => $assignment->applies_to_process->value,
                    'target_layer' => $assignment->target_layer->value,
                    'target_identity' => $assignment->target_identity,
                    'field_set_id' => $fieldSet->id,
                    'active_version_id' => null,
                    'missing_active_version' => true,
                ],
            ];
        }

        /** @var FieldSetVersion|null $version */
        $version = $fieldSet->activeVersion;
        if ($version === null) {
            $version = FieldSetVersion::query()
                ->with(['fields.revision.definition', 'fields.definition', 'rules'])
                ->whereKey($fieldSet->active_version_id)
                ->first();
        }
        if ($version === null) {
            throw new RuntimeException("Assignment {$assignment->id}: active_version_id beschädigt.");
        }

        if ($version->status !== FieldSetVersionStatus::Active) {
            // Conflict via empty + explicit later; still include for fingerprint
        }

        $source = $this->versionToSource(
            layer: FieldSetAssignmentMergeResolver::layerFromTarget($assignment->target_layer),
            assignmentId: $assignment->id,
            assignmentSort: $assignment->sort,
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
                'active_version_id' => $fieldSet->active_version_id,
                'version_id' => $version->id,
                'version_status' => $version->status->value,
                'memberships' => $this->membershipFingerprint($version),
                'rules' => $this->ruleFingerprint($version),
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function versionToSource(
        string $layer,
        ?int $assignmentId,
        ?int $assignmentSort,
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
                'field_type' => $definition->field_type->value,
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

        return [
            'layer' => $layer,
            'assignment_id' => $assignmentId,
            'assignment_sort' => $assignmentSort,
            'field_set_id' => $fieldSet->id,
            'field_set_key' => $fieldSet->key,
            'field_set_version_id' => $version->id,
            'field_set_version_number' => $version->version,
            'is_system_core' => $isSystemCore,
            'memberships' => $memberships,
            'rules' => $rules,
        ];
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

    private function normalizeProcess(FieldAppliesTo|string $process): FieldAppliesTo
    {
        if ($process instanceof FieldAppliesTo) {
            if ($process === FieldAppliesTo::Both) {
                throw ValidationException::withMessages([
                    'process' => 'Prozess muss calculation oder dispo_order sein.',
                ]);
            }

            return $process;
        }

        $enum = FieldAppliesTo::tryFrom($process);
        if ($enum === null || $enum === FieldAppliesTo::Both) {
            throw ValidationException::withMessages([
                'process' => 'Prozess muss calculation oder dispo_order sein.',
            ]);
        }

        return $enum;
    }

    private function normalizeScope(FieldScope|string $scope): FieldScope
    {
        if ($scope instanceof FieldScope) {
            return $scope;
        }

        $enum = FieldScope::tryFrom($scope);
        if ($enum === null) {
            throw ValidationException::withMessages([
                'scope' => 'Scope muss header oder position sein.',
            ]);
        }

        return $enum;
    }

    private function nullablePositiveInt(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        $int = (int) $value;

        return $int > 0 ? $int : null;
    }
}
