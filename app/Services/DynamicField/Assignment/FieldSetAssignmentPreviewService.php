<?php

namespace App\Services\DynamicField\Assignment;

use App\Enums\FieldAppliesTo;
use App\Enums\FieldScope;
use App\Enums\FieldSetAssignmentTargetLayer;
use App\Models\AdvertisingMedium;
use App\Models\FieldSetAssignment;
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
        private readonly AssignmentSourceLoader $sources,
    ) {}

    /**
     * @param  array{
     *     process: FieldAppliesTo|string,
     *     scope: FieldScope|string,
     *     advertising_category_id?: int|null,
     *     advertising_medium_id?: int|null,
     *     candidate_assignment_id?: int|null,
     *     strict?: bool
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
        $strict = (bool) ($input['strict'] ?? ($candidateId !== null));

        if ($mediumId !== null) {
            $medium = AdvertisingMedium::query()->whereKey($mediumId)->first();
            if ($medium === null) {
                throw new RuntimeException("Werbemittel {$mediumId} fehlt (Integrität).");
            }
            $mediumCategoryId = (int) $medium->category_id;
            if ($categoryId !== null && $categoryId !== $mediumCategoryId) {
                throw ValidationException::withMessages([
                    'advertising_category_id' => 'Die angegebene Kategorie passt nicht zum Werbemittel.',
                ]);
            }
            $categoryId = $mediumCategoryId;
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
            'assignments' => [],
            'skipped_assignments' => [],
        ];

        $core = $this->sources->loadPrimaryCoreSource($process);
        $sourcesPayload[] = $core['source'];
        $fingerprintParts['primary_core'] = $core['fingerprint'];

        $loaded = $this->loadAssignmentsForContext(
            $process,
            $scope,
            $categoryId,
            $mediumId,
            $candidateId,
        );

        $includedAssignments = [];
        $skippedSources = [];
        $sourceConflicts = [];
        $sourceWarnings = [];

        foreach ($loaded as $assignment) {
            $isCandidate = $candidateId !== null && (int) $assignment->id === $candidateId;
            $validity = $this->sources->assessAssignmentSourceValidity($assignment, $process);

            if ($validity['valid']) {
                $built = $this->sources->buildAssignmentSource($assignment);
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
                    'is_candidate' => $isCandidate,
                ];

                continue;
            }

            $skip = [
                'assignment_id' => $assignment->id,
                'field_set_id' => $assignment->field_set_id,
                'field_set_key' => $assignment->fieldSet?->key,
                'reason_code' => $validity['code'],
                'reason' => $validity['message'],
                'is_candidate' => $isCandidate,
            ];
            $skippedSources[] = $skip;
            $fingerprintParts['skipped_assignments'][] = [
                'assignment_id' => $assignment->id,
                'lock_version' => $assignment->lock_version,
                'is_active' => $assignment->is_active,
                'field_set_id' => $assignment->field_set_id,
                'is_assignable' => $assignment->fieldSet?->is_assignable,
                'active_version_id' => $assignment->fieldSet?->active_version_id,
                'active_version_status' => $assignment->fieldSet?->activeVersion?->status?->value,
                'reason_code' => $validity['code'],
            ];

            if ($strict) {
                $sourceConflicts[] = [
                    'code' => $validity['code'],
                    'message' => $validity['message'],
                    'details' => [
                        'assignment_id' => $assignment->id,
                        'field_set_id' => $assignment->field_set_id,
                    ],
                ];
            } else {
                $sourceWarnings[] = [
                    'code' => 'skipped_assignment_source',
                    'message' => "Assignment {$assignment->id} übersprungen: {$validity['message']}",
                    'details' => [
                        'assignment_id' => $assignment->id,
                        'reason_code' => $validity['code'],
                    ],
                ];
            }
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

        $conflicts = array_merge($sourceConflicts, $resolved['conflicts']);
        $warnings = array_merge($sourceWarnings, $resolved['warnings']);

        $fingerprint = $this->fingerprint($fingerprintParts, [
            'fields' => $resolved['fields'],
            'rules' => $resolved['rules'],
            'conflicts' => $conflicts,
        ]);

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
            'skipped_sources' => $skippedSources,
            'sources' => $resolved['sources'],
            'merge_order' => array_column($resolved['sources'], 'merge_order'),
            'fields' => $resolved['fields'],
            'rules' => $resolved['rules'],
            'warnings' => $warnings,
            'conflicts' => $conflicts,
            'has_blocking_conflicts' => $conflicts !== [],
            'fingerprint' => $fingerprint,
        ];
    }

    /**
     * @param  array<string, mixed>  $fingerprintParts
     * @param  array<string, mixed>  $resolved
     */
    public function fingerprint(array $fingerprintParts, array $resolved): string
    {
        return $this->sources->fingerprintForResolved($fingerprintParts, $resolved);
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
        $candidateIncluded = false;
        foreach ($rows as $assignment) {
            $isCandidate = $candidateId !== null && (int) $assignment->id === $candidateId;
            if (! $assignment->is_active && ! $isCandidate) {
                continue;
            }
            if ($isCandidate) {
                $this->assertCandidateFitsContext($assignment, $process, $scope, $categoryId, $mediumId);
                $candidateIncluded = true;
            }
            $result[] = $assignment;
        }

        if ($candidateId !== null && ! $candidateIncluded) {
            /** @var FieldSetAssignment $candidate */
            $candidate = FieldSetAssignment::query()
                ->with(['fieldSet.activeVersion.fields.revision.definition', 'fieldSet.activeVersion.fields.definition', 'fieldSet.activeVersion.rules'])
                ->whereKey($candidateId)
                ->firstOrFail();

            // Fremde / kontextfremde Kandidaten nicht still anhängen.
            $this->assertCandidateFitsContext($candidate, $process, $scope, $categoryId, $mediumId);

            // Wenn die Query den Kandidaten nicht gefunden hat, obwohl er passt
            // (z. B. inaktiv und Layer passte nicht in die OR-Filter), ablehnen –
            // assertCandidateFitsContext sollte das bereits abfangen. Zusätzliche
            // Sicherheit: nur anhängen, wenn Layer/Ziel exakt zum Kontext passen.
            if (! $this->assignmentMatchesContextFilters($candidate, $scope, $categoryId, $mediumId)) {
                throw ValidationException::withMessages([
                    'candidate_assignment_id' => 'Kandidat passt nicht zum angefragten Preview-Kontext.',
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

    private function assertCandidateFitsContext(
        FieldSetAssignment $candidate,
        FieldAppliesTo $process,
        FieldScope $scope,
        ?int $categoryId,
        ?int $mediumId,
    ): void {
        if (! $candidate->appliesToProcess($process)) {
            throw ValidationException::withMessages([
                'candidate_assignment_id' => 'Kandidat passt nicht zum Preview-Prozess.',
            ]);
        }

        match ($candidate->target_layer) {
            FieldSetAssignmentTargetLayer::Global => null,
            FieldSetAssignmentTargetLayer::AdvertisingCategory => $this->assertCategoryCandidateContext(
                $candidate,
                $scope,
                $categoryId,
            ),
            FieldSetAssignmentTargetLayer::AdvertisingMedium => $this->assertMediumCandidateContext(
                $candidate,
                $scope,
                $mediumId,
            ),
        };
    }

    private function assertCategoryCandidateContext(
        FieldSetAssignment $candidate,
        FieldScope $scope,
        ?int $categoryId,
    ): void {
        if ($scope !== FieldScope::Position) {
            throw ValidationException::withMessages([
                'candidate_assignment_id' => 'Kategorie-Kandidaten sind nur in Positions-Previews zulässig.',
            ]);
        }
        if ($categoryId === null || (int) $candidate->advertising_category_id !== $categoryId) {
            throw ValidationException::withMessages([
                'candidate_assignment_id' => 'Kategorie-Kandidat passt nicht zur angefragten Kategorie.',
            ]);
        }
    }

    private function assertMediumCandidateContext(
        FieldSetAssignment $candidate,
        FieldScope $scope,
        ?int $mediumId,
    ): void {
        if ($scope !== FieldScope::Position) {
            throw ValidationException::withMessages([
                'candidate_assignment_id' => 'Werbemittel-Kandidaten sind nur in Positions-Previews zulässig.',
            ]);
        }
        if ($mediumId === null || (int) $candidate->advertising_medium_id !== $mediumId) {
            throw ValidationException::withMessages([
                'candidate_assignment_id' => 'Werbemittel-Kandidat passt nicht zum angefragten Werbemittel.',
            ]);
        }
    }

    private function assignmentMatchesContextFilters(
        FieldSetAssignment $assignment,
        FieldScope $scope,
        ?int $categoryId,
        ?int $mediumId,
    ): bool {
        if ($scope === FieldScope::Header) {
            return $assignment->target_layer === FieldSetAssignmentTargetLayer::Global;
        }

        return match ($assignment->target_layer) {
            FieldSetAssignmentTargetLayer::Global => true,
            FieldSetAssignmentTargetLayer::AdvertisingCategory => $categoryId !== null
                && (int) $assignment->advertising_category_id === $categoryId,
            FieldSetAssignmentTargetLayer::AdvertisingMedium => $mediumId !== null
                && (int) $assignment->advertising_medium_id === $mediumId,
        };
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
