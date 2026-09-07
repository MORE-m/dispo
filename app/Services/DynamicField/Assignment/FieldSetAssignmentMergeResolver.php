<?php

namespace App\Services\DynamicField\Assignment;

use App\Enums\FieldAppliesTo;
use App\Enums\FieldScope;
use App\Enums\FieldSetAssignmentTargetLayer;
use App\Models\SnapshotFieldDefinition;

/**
 * DF-3.3a1 / DYN-002 – deterministischer Merge von Primary-Core + Assignments.
 *
 * Effektive Feldsortierung (stabil, nie Insert-Order):
 * 1. `membership_sort` der gewinnenden Membership aufsteigend
 * 2. `winning_merge_order` aufsteigend
 * 3. `field_definition_id` aufsteigend
 *
 * Quellenreihenfolge / merge_order:
 * 1. Primary-Core
 * 2. globale Assignments (sort ASC, assignment.id ASC)
 * 3. Oberkategorie-Assignments (sort ASC, id ASC)
 * 4. Werbemittel-Assignments (sort ASC, id ASC)
 */
final class FieldSetAssignmentMergeResolver
{
    public const LAYER_PRIMARY_CORE = 'primary_core';

    public const LAYER_GLOBAL = 'global';

    public const LAYER_ADVERTISING_CATEGORY = 'advertising_category';

    public const LAYER_ADVERTISING_MEDIUM = 'advertising_medium';

    /**
     * @param  array{
     *     process: FieldAppliesTo|string,
     *     scope: FieldScope|string,
     *     advertising_category_id?: int|null,
     *     advertising_medium_id?: int|null,
     *     sources: list<array<string, mixed>>
     * }  $input
     * @return array{
     *     process: string,
     *     scope: string,
     *     advertising_category_id: int|null,
     *     advertising_medium_id: int|null,
     *     sources: list<array<string, mixed>>,
     *     fields: list<array<string, mixed>>,
     *     rules: list<array<string, mixed>>,
     *     warnings: list<array{code: string, message: string}>,
     *     conflicts: list<array{code: string, message: string, details?: array<string, mixed>}>,
     *     has_blocking_conflicts: bool
     * }
     */
    public function resolve(array $input): array
    {
        $process = $this->normalizeProcess($input['process']);
        $scope = $this->normalizeScope($input['scope']);
        $categoryId = isset($input['advertising_category_id']) ? (int) $input['advertising_category_id'] : null;
        $mediumId = isset($input['advertising_medium_id']) ? (int) $input['advertising_medium_id'] : null;
        if ($categoryId !== null && $categoryId < 1) {
            $categoryId = null;
        }
        if ($mediumId !== null && $mediumId < 1) {
            $mediumId = null;
        }

        $warnings = [];
        $conflicts = [];

        /** @var list<array<string, mixed>> $rawSources */
        $rawSources = $input['sources'];
        $orderedSources = $this->orderSources($rawSources);
        $sourcesOut = [];
        $mergeOrder = 0;

        /** @var array<int, array<string, mixed>> $fieldsByDefinitionId */
        $fieldsByDefinitionId = [];
        /** @var array<string, int> $definitionIdByKey */
        $definitionIdByKey = [];
        /** @var array<string, true> $keysSeenInSources */
        $keysSeenInSources = [];
        /** @var array<string, array<string, mixed>> $rulesByFingerprint */
        $rulesByFingerprint = [];

        foreach ($orderedSources as $source) {
            $mergeOrder++;
            $layer = (string) $source['layer'];
            $assignmentId = $source['assignment_id'] ?? null;
            $isCore = (bool) ($source['is_system_core'] ?? false);

            $sourcesOut[] = [
                'merge_order' => $mergeOrder,
                'layer' => $layer,
                'assignment_id' => $assignmentId,
                'assignment_sort' => $source['assignment_sort'] ?? null,
                'field_set_id' => (int) $source['field_set_id'],
                'field_set_key' => (string) $source['field_set_key'],
                'field_set_version_id' => (int) $source['field_set_version_id'],
                'field_set_version_number' => (int) $source['field_set_version_number'],
                'is_system_core' => $isCore,
            ];

            foreach ($source['memberships'] as $membership) {
                $keysSeenInSources[(string) $membership['field_key']] = true;
                $this->mergeMembership(
                    $membership,
                    $mergeOrder,
                    $layer,
                    $assignmentId,
                    (int) $source['field_set_id'],
                    (string) $source['field_set_key'],
                    (int) $source['field_set_version_id'],
                    $isCore,
                    $process,
                    $scope,
                    $fieldsByDefinitionId,
                    $definitionIdByKey,
                    $conflicts,
                );
            }

            foreach ($source['rules'] as $rule) {
                $this->collectRule(
                    $rule,
                    $mergeOrder,
                    $layer,
                    $assignmentId,
                    (int) $source['field_set_id'],
                    (string) $source['field_set_key'],
                    (int) $source['field_set_version_id'],
                    $rulesByFingerprint,
                    $warnings,
                );
            }
        }

        $effectiveKeys = [];
        foreach ($fieldsByDefinitionId as $field) {
            if (! ($field['blocked'] ?? false)) {
                $effectiveKeys[(string) $field['field_key']] = true;
            }
        }

        $rulesOut = [];
        foreach ($rulesByFingerprint as $rule) {
            $conditionKey = (string) ($rule['condition_json']['field_key'] ?? '');
            $actionKey = (string) ($rule['action_json']['field_key'] ?? '');

            // Regeln, die nur Out-of-Scope-Felder referenzieren (z. B. Position
            // bei Header-Preview), werden übersprungen – kein Konflikt.
            $conditionInScope = $conditionKey === '' || isset($effectiveKeys[$conditionKey]);
            $actionInScope = $actionKey === '' || isset($effectiveKeys[$actionKey]);
            if (! $conditionInScope || ! $actionInScope) {
                $conditionKnown = $conditionKey === '' || isset($keysSeenInSources[$conditionKey]);
                $actionKnown = $actionKey === '' || isset($keysSeenInSources[$actionKey]);
                if ($conditionKnown && $actionKnown) {
                    continue;
                }
            }

            if ($conditionKey !== '' && ! isset($effectiveKeys[$conditionKey]) && ! isset($keysSeenInSources[$conditionKey])) {
                $conflicts[] = [
                    'code' => 'rule_unknown_condition_field',
                    'message' => "Regel referenziert unbekanntes Bedingungsfeld „{$conditionKey}“.",
                    'details' => ['field_rule_id' => $rule['field_rule_id']],
                ];

                continue;
            }
            if ($actionKey !== '' && ! isset($effectiveKeys[$actionKey]) && ! isset($keysSeenInSources[$actionKey])) {
                $conflicts[] = [
                    'code' => 'rule_unknown_action_field',
                    'message' => "Regel referenziert unbekanntes Aktionsfeld „{$actionKey}“.",
                    'details' => ['field_rule_id' => $rule['field_rule_id']],
                ];

                continue;
            }
            if (! $conditionInScope || ! $actionInScope) {
                continue;
            }
            $rulesOut[] = $rule;
        }

        /** @var array<string, list<string>> $actionSignaturesByTarget */
        $actionSignaturesByTarget = [];
        foreach ($rulesOut as $rule) {
            $actionKey = (string) ($rule['action_json']['field_key'] ?? '');
            if ($actionKey === '') {
                continue;
            }
            $actionSignaturesByTarget[$actionKey][] = hash(
                'sha256',
                json_encode($rule['action_json'], JSON_THROW_ON_ERROR),
            );
        }
        foreach ($actionSignaturesByTarget as $targetKey => $signatures) {
            if (count(array_unique($signatures)) > 1) {
                $conflicts[] = [
                    'code' => 'rule_conflicting_actions',
                    'message' => "Widersprüchliche Regelaktionen auf Feld „{$targetKey}“.",
                    'details' => ['field_key' => $targetKey],
                ];
            }
        }

        $fieldsOut = array_values(array_filter(
            $fieldsByDefinitionId,
            static fn (array $field): bool => ! ($field['blocked'] ?? false),
        ));

        usort($fieldsOut, static function (array $a, array $b): int {
            return [$a['sort'], $a['winning_merge_order'], $a['field_definition_id']]
                <=> [$b['sort'], $b['winning_merge_order'], $b['field_definition_id']];
        });

        return [
            'process' => $process->value,
            'scope' => $scope->value,
            'advertising_category_id' => $categoryId,
            'advertising_medium_id' => $mediumId,
            'sources' => $sourcesOut,
            'fields' => $fieldsOut,
            'rules' => $rulesOut,
            'warnings' => $warnings,
            'conflicts' => $conflicts,
            'has_blocking_conflicts' => $conflicts !== [],
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $sources
     * @return list<array<string, mixed>>
     */
    private function orderSources(array $sources): array
    {
        $rank = [
            self::LAYER_PRIMARY_CORE => 1,
            self::LAYER_GLOBAL => 2,
            self::LAYER_ADVERTISING_CATEGORY => 3,
            self::LAYER_ADVERTISING_MEDIUM => 4,
        ];

        usort($sources, static function (array $a, array $b) use ($rank): int {
            $ra = $rank[(string) $a['layer']] ?? 99;
            $rb = $rank[(string) $b['layer']] ?? 99;
            if ($ra !== $rb) {
                return $ra <=> $rb;
            }

            $sortA = (int) ($a['assignment_sort'] ?? 0);
            $sortB = (int) ($b['assignment_sort'] ?? 0);
            if ($sortA !== $sortB) {
                return $sortA <=> $sortB;
            }

            $idA = (int) ($a['assignment_id'] ?? 0);
            $idB = (int) ($b['assignment_id'] ?? 0);

            return $idA <=> $idB;
        });

        return $sources;
    }

    /**
     * @param  array<string, mixed>  $membership
     * @param  array<int, array<string, mixed>>  $fieldsByDefinitionId
     * @param  array<string, int>  $definitionIdByKey
     * @param  list<array{code: string, message: string, details?: array<string, mixed>}>  $conflicts
     */
    private function mergeMembership(
        array $membership,
        int $mergeOrder,
        string $layer,
        ?int $assignmentId,
        int $fieldSetId,
        string $fieldSetKey,
        int $fieldSetVersionId,
        bool $isCore,
        FieldAppliesTo $process,
        FieldScope $scope,
        array &$fieldsByDefinitionId,
        array &$definitionIdByKey,
        array &$conflicts,
    ): void {
        $definitionId = (int) $membership['field_definition_id'];
        $revisionId = (int) $membership['field_definition_revision_id'];
        $fieldKey = (string) $membership['field_key'];
        $fieldScope = FieldScope::from((string) $membership['field_scope']);
        $fieldAppliesTo = FieldAppliesTo::from((string) $membership['field_applies_to']);

        if (! (bool) $membership['definition_is_active']) {
            $conflicts[] = [
                'code' => 'inactive_definition',
                'message' => "Deaktivierte Definition „{$fieldKey}“ darf nicht neu gemergt werden.",
                'details' => ['field_definition_id' => $definitionId, 'field_key' => $fieldKey],
            ];

            return;
        }

        if (! $this->definitionAppliesToProcess($fieldAppliesTo, $process)) {
            $conflicts[] = [
                'code' => 'process_incompatible_definition',
                'message' => "Feld „{$fieldKey}“ passt nicht zum Prozess {$process->value}.",
                'details' => ['field_key' => $fieldKey],
            ];

            return;
        }

        if ($fieldScope !== $scope) {
            // Membership außerhalb des angefragten Scopes wird übersprungen
            // (Header-Preview sieht keine Positionfelder). Ausnahme: Header-Feld
            // in Kategorie-/Medium-Quelle → Aktivierungsblockade.
            if (
                $fieldScope === FieldScope::Header
                && in_array($layer, [self::LAYER_ADVERTISING_CATEGORY, self::LAYER_ADVERTISING_MEDIUM], true)
            ) {
                $conflicts[] = [
                    'code' => 'header_field_on_contextual_assignment',
                    'message' => "Header-Feld „{$fieldKey}“ ist in Kategorie-/Werbemittel-Assignments unzulässig.",
                    'details' => [
                        'field_key' => $fieldKey,
                        'layer' => $layer,
                        'assignment_id' => $assignmentId,
                    ],
                ];
            }

            return;
        }

        if (
            $scope === FieldScope::Header
            && in_array($layer, [self::LAYER_ADVERTISING_CATEGORY, self::LAYER_ADVERTISING_MEDIUM], true)
        ) {
            $conflicts[] = [
                'code' => 'contextual_assignment_in_header_scope',
                'message' => 'Kategorie-/Werbemittel-Assignments gelten nicht für Header-Scope.',
                'details' => ['layer' => $layer, 'assignment_id' => $assignmentId],
            ];

            return;
        }

        $requiredOverride = array_key_exists('required_override', $membership)
            ? $membership['required_override']
            : null;
        $visibleOverride = array_key_exists('visible_override', $membership)
            ? $membership['visible_override']
            : null;
        if ($requiredOverride !== null) {
            $requiredOverride = (bool) $requiredOverride;
        }
        if ($visibleOverride !== null) {
            $visibleOverride = (bool) $visibleOverride;
        }

        if (isset($definitionIdByKey[$fieldKey]) && $definitionIdByKey[$fieldKey] !== $definitionId) {
            $conflicts[] = [
                'code' => 'key_definition_mismatch',
                'message' => "Technischer Key „{$fieldKey}“ gehört zu unterschiedlichen Definitionen.",
                'details' => [
                    'field_key' => $fieldKey,
                    'existing_definition_id' => $definitionIdByKey[$fieldKey],
                    'incoming_definition_id' => $definitionId,
                ],
            ];

            return;
        }

        if (! isset($fieldsByDefinitionId[$definitionId])) {
            $definitionIdByKey[$fieldKey] = $definitionId;
            $fieldsByDefinitionId[$definitionId] = [
                'field_definition_id' => $definitionId,
                'field_definition_revision_id' => $revisionId,
                'field_key' => $fieldKey,
                'field_scope' => $fieldScope->value,
                'field_type' => (string) $membership['field_type'],
                'label' => (string) $membership['label'],
                'definition_is_system' => (bool) $membership['definition_is_system'],
                'group_key' => $membership['group_key'] ?? null,
                'sort' => (int) $membership['sort'],
                'required_override' => $requiredOverride,
                'visible_override' => $visibleOverride,
                'effective_required' => SnapshotFieldDefinition::effectiveRequired($requiredOverride),
                'effective_visible' => SnapshotFieldDefinition::effectiveVisible($visibleOverride),
                'winning_layer' => $layer,
                'winning_merge_order' => $mergeOrder,
                'winning_assignment_id' => $assignmentId,
                'winning_field_set_id' => $fieldSetId,
                'winning_field_set_key' => $fieldSetKey,
                'winning_field_set_version_id' => $fieldSetVersionId,
                'origin' => [
                    [
                        'merge_order' => $mergeOrder,
                        'layer' => $layer,
                        'assignment_id' => $assignmentId,
                        'field_set_id' => $fieldSetId,
                        'field_set_key' => $fieldSetKey,
                        'field_set_version_id' => $fieldSetVersionId,
                        'required_override' => $requiredOverride,
                        'visible_override' => $visibleOverride,
                        'sort' => (int) $membership['sort'],
                        'group_key' => $membership['group_key'] ?? null,
                    ],
                ],
                'override_chain' => [
                    [
                        'merge_order' => $mergeOrder,
                        'layer' => $layer,
                        'assignment_id' => $assignmentId,
                        'required_override' => $requiredOverride,
                        'visible_override' => $visibleOverride,
                    ],
                ],
                'same_layer_required' => [$layer => $requiredOverride],
                'same_layer_visible' => [$layer => $visibleOverride],
                'blocked' => false,
            ];

            return;
        }

        $existing = &$fieldsByDefinitionId[$definitionId];
        if ((int) $existing['field_definition_revision_id'] !== $revisionId) {
            $conflicts[] = [
                'code' => 'revision_mismatch',
                'message' => "Feld „{$fieldKey}“ kommt mit unterschiedlichen Revisions vor.",
                'details' => [
                    'field_key' => $fieldKey,
                    'existing_revision_id' => $existing['field_definition_revision_id'],
                    'incoming_revision_id' => $revisionId,
                ],
            ];
            $existing['blocked'] = true;

            return;
        }

        $existing['origin'][] = [
            'merge_order' => $mergeOrder,
            'layer' => $layer,
            'assignment_id' => $assignmentId,
            'field_set_id' => $fieldSetId,
            'field_set_key' => $fieldSetKey,
            'field_set_version_id' => $fieldSetVersionId,
            'required_override' => $requiredOverride,
            'visible_override' => $visibleOverride,
            'sort' => (int) $membership['sort'],
            'group_key' => $membership['group_key'] ?? null,
        ];
        $existing['override_chain'][] = [
            'merge_order' => $mergeOrder,
            'layer' => $layer,
            'assignment_id' => $assignmentId,
            'required_override' => $requiredOverride,
            'visible_override' => $visibleOverride,
        ];

        // Widersprüchliche non-null Overrides derselben Ebene
        if (
            array_key_exists($layer, $existing['same_layer_required'])
            && $existing['same_layer_required'][$layer] !== null
            && $requiredOverride !== null
            && $existing['same_layer_required'][$layer] !== $requiredOverride
        ) {
            $conflicts[] = [
                'code' => 'same_layer_required_conflict',
                'message' => "Widersprüchliche required_override auf Ebene {$layer} für „{$fieldKey}“.",
                'details' => ['field_key' => $fieldKey, 'layer' => $layer],
            ];
            $existing['blocked'] = true;

            return;
        }
        if (
            array_key_exists($layer, $existing['same_layer_visible'])
            && $existing['same_layer_visible'][$layer] !== null
            && $visibleOverride !== null
            && $existing['same_layer_visible'][$layer] !== $visibleOverride
        ) {
            $conflicts[] = [
                'code' => 'same_layer_visible_conflict',
                'message' => "Widersprüchliche visible_override auf Ebene {$layer} für „{$fieldKey}“.",
                'details' => ['field_key' => $fieldKey, 'layer' => $layer],
            ];
            $existing['blocked'] = true;

            return;
        }
        if ($requiredOverride !== null) {
            $existing['same_layer_required'][$layer] = $requiredOverride;
        } elseif (! array_key_exists($layer, $existing['same_layer_required'])) {
            $existing['same_layer_required'][$layer] = null;
        }
        if ($visibleOverride !== null) {
            $existing['same_layer_visible'][$layer] = $visibleOverride;
        } elseif (! array_key_exists($layer, $existing['same_layer_visible'])) {
            $existing['same_layer_visible'][$layer] = null;
        }

        // Spezifischere non-null Overrides gewinnen; null erbt.
        if ($requiredOverride !== null) {
            if (
                (bool) $existing['definition_is_system']
                && $isCore === false
                && $existing['required_override'] !== null
                && $existing['required_override'] !== $requiredOverride
                && $this->layerRank($layer) <= $this->layerRank((string) $existing['winning_layer'])
            ) {
                // erlaubt: spezifischere Ebene darf überschreiben; System-Invariante
                // schützt nur Core-Pflichtkern nicht vor erlaubten Overrides.
            }
            $existing['required_override'] = $requiredOverride;
        }
        if ($visibleOverride !== null) {
            $existing['visible_override'] = $visibleOverride;
        }

        // Gruppe/Sort der fachlich gewinnenden (spezifischeren) Membership
        $existing['group_key'] = $membership['group_key'] ?? null;
        $existing['sort'] = (int) $membership['sort'];
        $existing['winning_layer'] = $layer;
        $existing['winning_merge_order'] = $mergeOrder;
        $existing['winning_assignment_id'] = $assignmentId;
        $existing['winning_field_set_id'] = $fieldSetId;
        $existing['winning_field_set_key'] = $fieldSetKey;
        $existing['winning_field_set_version_id'] = $fieldSetVersionId;

        $existing['effective_required'] = SnapshotFieldDefinition::effectiveRequired(
            $existing['required_override'],
        );
        $existing['effective_visible'] = SnapshotFieldDefinition::effectiveVisible(
            $existing['visible_override'],
        );
    }

    /**
     * @param  array<string, mixed>  $rule
     * @param  array<string, array<string, mixed>>  $rulesByFingerprint
     * @param  list<array{code: string, message: string}>  $warnings
     */
    private function collectRule(
        array $rule,
        int $mergeOrder,
        string $layer,
        ?int $assignmentId,
        int $fieldSetId,
        string $fieldSetKey,
        int $fieldSetVersionId,
        array &$rulesByFingerprint,
        array &$warnings,
    ): void {
        $condition = is_array($rule['condition_json'] ?? null) ? $rule['condition_json'] : [];
        $action = is_array($rule['action_json'] ?? null) ? $rule['action_json'] : [];
        $fingerprint = hash('sha256', json_encode([
            'condition' => $condition,
            'action' => $action,
        ], JSON_THROW_ON_ERROR));

        if (isset($rulesByFingerprint[$fingerprint])) {
            $warnings[] = [
                'code' => 'duplicate_rule',
                'message' => 'Identische Regel aus mehreren Quellen – dedupliziert.',
            ];
            $rulesByFingerprint[$fingerprint]['sources'][] = [
                'merge_order' => $mergeOrder,
                'layer' => $layer,
                'assignment_id' => $assignmentId,
                'field_set_id' => $fieldSetId,
                'field_set_key' => $fieldSetKey,
                'field_set_version_id' => $fieldSetVersionId,
            ];

            return;
        }

        $rulesByFingerprint[$fingerprint] = [
            'field_rule_id' => (int) $rule['field_rule_id'],
            'sort' => (int) $rule['sort'],
            'condition_json' => $condition,
            'action_json' => $action,
            'merge_order' => $mergeOrder,
            'layer' => $layer,
            'assignment_id' => $assignmentId,
            'field_set_id' => $fieldSetId,
            'field_set_key' => $fieldSetKey,
            'field_set_version_id' => $fieldSetVersionId,
            'sources' => [
                [
                    'merge_order' => $mergeOrder,
                    'layer' => $layer,
                    'assignment_id' => $assignmentId,
                    'field_set_id' => $fieldSetId,
                    'field_set_key' => $fieldSetKey,
                    'field_set_version_id' => $fieldSetVersionId,
                ],
            ],
        ];
    }

    private function definitionAppliesToProcess(FieldAppliesTo $definition, FieldAppliesTo $process): bool
    {
        if ($definition === FieldAppliesTo::Both) {
            return true;
        }

        return $definition === $process;
    }

    private function layerRank(string $layer): int
    {
        return match ($layer) {
            self::LAYER_PRIMARY_CORE => 1,
            self::LAYER_GLOBAL => 2,
            self::LAYER_ADVERTISING_CATEGORY => 3,
            self::LAYER_ADVERTISING_MEDIUM => 4,
            default => 99,
        };
    }

    private function normalizeProcess(FieldAppliesTo|string $process): FieldAppliesTo
    {
        if ($process instanceof FieldAppliesTo) {
            if ($process === FieldAppliesTo::Both) {
                throw new \InvalidArgumentException('Resolver-Prozess muss calculation oder dispo_order sein.');
            }

            return $process;
        }

        $enum = FieldAppliesTo::tryFrom($process);
        if ($enum === null || $enum === FieldAppliesTo::Both) {
            throw new \InvalidArgumentException('Resolver-Prozess muss calculation oder dispo_order sein.');
        }

        return $enum;
    }

    private function normalizeScope(FieldScope|string $scope): FieldScope
    {
        if ($scope instanceof FieldScope) {
            return $scope;
        }

        return FieldScope::from($scope);
    }

    public static function layerFromTarget(FieldSetAssignmentTargetLayer $layer): string
    {
        return match ($layer) {
            FieldSetAssignmentTargetLayer::Global => self::LAYER_GLOBAL,
            FieldSetAssignmentTargetLayer::AdvertisingCategory => self::LAYER_ADVERTISING_CATEGORY,
            FieldSetAssignmentTargetLayer::AdvertisingMedium => self::LAYER_ADVERTISING_MEDIUM,
        };
    }
}
