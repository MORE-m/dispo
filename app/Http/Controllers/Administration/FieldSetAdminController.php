<?php

namespace App\Http\Controllers\Administration;

use App\Enums\FieldAppliesTo;
use App\Enums\FieldScope;
use App\Enums\FieldSetAssignmentTargetLayer;
use App\Enums\FieldSetVersionStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Administration\DynamicField\ActivateFieldSetVersionRequest;
use App\Http\Requests\Administration\DynamicField\AddFieldSetMembershipRequest;
use App\Http\Requests\Administration\DynamicField\CreateFieldSetDraftRequest;
use App\Http\Requests\Administration\DynamicField\DeactivateFieldSetRequest;
use App\Http\Requests\Administration\DynamicField\PinCurrentRevisionsRequest;
use App\Http\Requests\Administration\DynamicField\PreviewFieldSetVersionRulesRequest;
use App\Http\Requests\Administration\DynamicField\ReactivateFieldSetRequest;
use App\Http\Requests\Administration\DynamicField\RemoveFieldSetMembershipRequest;
use App\Http\Requests\Administration\DynamicField\ReplaceFieldSetVersionRulesRequest;
use App\Http\Requests\Administration\DynamicField\StoreFreeFieldSetRequest;
use App\Http\Requests\Administration\DynamicField\UpdateFieldSetDraftRequest;
use App\Http\Requests\Administration\DynamicField\UpdateFieldSetMetadataRequest;
use App\Models\FieldDefinition;
use App\Models\FieldDefinitionRevision;
use App\Models\FieldSet;
use App\Models\FieldSetAssignment;
use App\Models\FieldSetVersion;
use App\Models\FieldSetVersionField;
use App\Services\DynamicField\Admin\AdminFieldSetCatalog;
use App\Services\DynamicField\Admin\FieldSetVersionAdminWriter;
use App\Services\DynamicField\Admin\FieldSetVersionPreviewService;
use App\Services\DynamicField\Admin\FieldSetVersionRulesWriter;
use App\Support\DynamicField\FieldDefinitionOptionContract;
use App\Support\DynamicField\FieldRuleContract;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

/**
 * DF-3.1 / DF-3.3-fs / DYN-002 / DYN-003 / VER-005 / VER-006 / ADM-002
 */
class FieldSetAdminController extends Controller
{
    public function __construct(
        private readonly FieldSetVersionAdminWriter $writer,
        private readonly FieldSetVersionPreviewService $preview,
        private readonly FieldSetVersionRulesWriter $rulesWriter,
    ) {}

    public function home(): Response
    {
        $this->authorize('access-administration');

        return Inertia::render('administration/dynamic-fields/index', [
            'links' => [
                [
                    'title' => 'Felddefinitionen',
                    'description' => 'Systemfelder revisionieren und eigene Header-/Position-Textfelder verwalten.',
                    'href' => '/administration/dynamische-felder/definitionen',
                ],
                [
                    'title' => 'Feldsets',
                    'description' => 'Kern-Feldsets und freie Feldsets versionieren und aktivieren.',
                    'href' => '/administration/dynamische-felder/feldsets',
                ],
                [
                    'title' => 'Assignments',
                    'description' => 'Freie Feldsets global, an Oberkategorien oder Werbemitteln zuordnen; Herkunft und Konflikte prüfen.',
                    'href' => '/administration/dynamische-felder/assignments',
                ],
            ],
        ]);
    }

    public function index(): Response
    {
        $this->authorize('access-administration');

        $sets = FieldSet::query()
            ->with(['activeVersion', 'versions'])
            ->orderByDesc('is_system')
            ->orderBy('key')
            ->get()
            ->filter(fn (FieldSet $set): bool => AdminFieldSetCatalog::isAdministrable($set))
            ->map(fn (FieldSet $set): array => $this->serializeFieldSetSummary($set))
            ->values()
            ->all();

        return Inertia::render('administration/dynamic-fields/field-sets/index', [
            'coreFieldSets' => array_values(array_filter(
                $sets,
                fn (array $row): bool => $row['is_system'] === true,
            )),
            'freeFieldSets' => array_values(array_filter(
                $sets,
                fn (array $row): bool => $row['is_system'] === false,
            )),
        ]);
    }

    public function create(): Response
    {
        $this->authorize('access-administration');

        return Inertia::render('administration/dynamic-fields/field-sets/create', [
            'appliesToOptions' => [
                ['value' => FieldAppliesTo::Calculation->value, 'label' => 'Kalkulation'],
                ['value' => FieldAppliesTo::DispoOrder->value, 'label' => 'Dispoauftrag'],
                ['value' => FieldAppliesTo::Both->value, 'label' => 'Beide'],
            ],
        ]);
    }

    public function store(StoreFreeFieldSetRequest $request): RedirectResponse
    {
        $this->authorize('access-administration');

        $fieldSet = $this->writer->createFreeFieldSet($request->payload(), $request->user());

        return redirect()
            ->route('administration.dynamic-fields.field-sets.show', $fieldSet)
            ->with('success', 'Freies Feldset wurde angelegt (Entwurf / noch nicht nutzbar).');
    }

    public function show(FieldSet $fieldSet): Response
    {
        $this->authorize('access-administration');
        $this->writer->assertAdminFieldSet($fieldSet);

        $fieldSet->load(['versions' => fn ($q) => $q->orderByDesc('version'), 'activeVersion']);

        $hasEverBeenActivated = $fieldSet->versions->contains(
            fn (FieldSetVersion $version): bool => in_array(
                $version->status,
                [FieldSetVersionStatus::Active, FieldSetVersionStatus::Archived],
                true,
            ),
        );

        $activeAssignments = [];
        $hasInconsistentActiveAssignments = false;

        if (! $fieldSet->is_system) {
            $activeAssignments = $this->serializeActiveAssignments($fieldSet);
            $hasInconsistentActiveAssignments = ! $fieldSet->is_assignable
                && $activeAssignments !== [];
        }

        $activeAssignmentCount = count($activeAssignments);

        return Inertia::render('administration/dynamic-fields/field-sets/show', [
            'fieldSet' => [
                'id' => $fieldSet->id,
                'key' => $fieldSet->key,
                'name' => $fieldSet->name,
                'is_system' => $fieldSet->is_system,
                'applies_to' => $fieldSet->applies_to->value,
                'is_assignable' => $fieldSet->is_assignable,
                'lock_version' => $fieldSet->lock_version,
                'active_version_id' => $fieldSet->active_version_id,
                'has_ever_been_activated' => $hasEverBeenActivated,
                'can_edit_applies_to' => ! $fieldSet->is_system && ! $hasEverBeenActivated,
                'can_deactivate' => ! $fieldSet->is_system
                    && $fieldSet->is_assignable
                    && $activeAssignmentCount === 0,
                'can_reactivate' => ! $fieldSet->is_system
                    && ! $fieldSet->is_assignable
                    && $fieldSet->active_version_id !== null,
                'active_assignment_count' => $activeAssignmentCount,
                'active_assignments' => $activeAssignments,
                'has_inconsistent_active_assignments' => $hasInconsistentActiveAssignments,
                'versions' => $fieldSet->versions->map(fn (FieldSetVersion $version): array => [
                    'id' => $version->id,
                    'version' => $version->version,
                    'status' => $version->status->value,
                    'created_at' => $version->created_at?->timezone('Europe/Berlin')->toIso8601String(),
                ]),
            ],
            'appliesToOptions' => [
                ['value' => FieldAppliesTo::Calculation->value, 'label' => 'Kalkulation'],
                ['value' => FieldAppliesTo::DispoOrder->value, 'label' => 'Dispoauftrag'],
                ['value' => FieldAppliesTo::Both->value, 'label' => 'Beide'],
            ],
            'runtimeNote' => $fieldSet->is_system
                ? null
                : 'Freie Feldsets wirken über Assignments auf neue Kalkulationen und Dispoaufträge. Bestehende Snapshots bleiben unverändert.',
        ]);
    }

    public function updateMetadata(
        UpdateFieldSetMetadataRequest $request,
        FieldSet $fieldSet,
    ): RedirectResponse|JsonResponse {
        $this->authorize('access-administration');

        $updated = $this->writer->updateContainerMetadata(
            $fieldSet,
            $request->payload(),
            $request->user(),
        );

        $redirect = route('administration.dynamic-fields.field-sets.show', $updated);
        $message = 'Feldset-Metadaten wurden gespeichert.';

        if ($request->wantsJson()) {
            return response()->json([
                'redirect' => $redirect,
                'message' => $message,
                'lock_version' => $updated->lock_version,
            ]);
        }

        return redirect()->to($redirect)->with('success', $message);
    }

    public function deactivate(
        DeactivateFieldSetRequest $request,
        FieldSet $fieldSet,
    ): RedirectResponse|JsonResponse {
        $this->authorize('access-administration');

        $updated = $this->writer->deactivate(
            $fieldSet,
            $request->user(),
            $request->lockVersion(),
        );

        $redirect = route('administration.dynamic-fields.field-sets.show', $updated);
        $message = 'Feldset wurde deaktiviert. Bestehende Vorgänge bleiben unverändert.';

        if ($request->wantsJson()) {
            return response()->json([
                'redirect' => $redirect,
                'message' => $message,
            ]);
        }

        return redirect()->to($redirect)->with('success', $message);
    }

    public function reactivate(
        ReactivateFieldSetRequest $request,
        FieldSet $fieldSet,
    ): RedirectResponse|JsonResponse {
        $this->authorize('access-administration');

        $updated = $this->writer->reactivate(
            $fieldSet,
            $request->user(),
            $request->lockVersion(),
        );

        $redirect = route('administration.dynamic-fields.field-sets.show', $updated);
        $message = 'Feldset wurde reaktiviert (für spätere Assignments vorbereitet).';

        if ($request->wantsJson()) {
            return response()->json([
                'redirect' => $redirect,
                'message' => $message,
            ]);
        }

        return redirect()->to($redirect)->with('success', $message);
    }

    public function createDraft(CreateFieldSetDraftRequest $request, FieldSet $fieldSet): RedirectResponse|JsonResponse
    {
        $this->authorize('access-administration');
        $this->writer->assertAdminFieldSet($fieldSet);

        $source = FieldSetVersion::query()
            ->whereKey($request->sourceVersionId())
            ->where('field_set_id', $fieldSet->id)
            ->firstOrFail();

        $draft = $this->writer->createDraftFromVersion(
            $fieldSet,
            $source,
            $request->user(),
            $request->lockVersion(),
        );

        $redirect = route('administration.dynamic-fields.field-sets.versions.edit', [$fieldSet, $draft]);

        if ($request->wantsJson()) {
            return response()->json([
                'redirect' => $redirect,
                'message' => "Entwurf Version {$draft->version} wurde angelegt.",
            ]);
        }

        return redirect()
            ->to($redirect)
            ->with('success', "Entwurf Version {$draft->version} wurde angelegt.");
    }

    public function editVersion(FieldSet $fieldSet, FieldSetVersion $version): Response
    {
        $this->authorize('access-administration');
        $this->writer->assertAdminFieldSet($fieldSet);
        abort_unless((int) $version->field_set_id === (int) $fieldSet->id, 404);

        $fieldSet->refresh();
        $version->load(['fields.revision.definition', 'fields.revision.options', 'fields.definition', 'rules']);

        $definitionIds = $version->fields->pluck('field_definition_id')->unique()->all();
        /** @var array<int, list<array{id: int, revision: int, label: string}>> $revisionsByDefinition */
        $revisionsByDefinition = [];
        FieldDefinition::query()
            ->whereIn('id', $definitionIds)
            ->with(['revisions' => fn ($q) => $q->orderByDesc('revision')])
            ->get()
            ->each(function (FieldDefinition $definition) use (&$revisionsByDefinition): void {
                $revisionsByDefinition[$definition->id] = $definition->revisions
                    ->map(fn (FieldDefinitionRevision $revision): array => [
                        'id' => $revision->id,
                        'revision' => $revision->revision,
                        'label' => $revision->label,
                    ])
                    ->values()
                    ->all();
            });

        $memberDefinitionIds = $version->fields->pluck('field_definition_id')->unique()->all();
        $allowedApplies = FieldSetVersionAdminWriter::allowedAppliesToValuesForFieldSet($fieldSet);

        /** @var list<array{
         *     id: int,
         *     key: string,
         *     label: string|null,
         *     applies_to: string,
         *     field_type: string,
         *     current_revision_id: int|null,
         *     available_revisions: list<array{id: int, revision: int, label: string}>
         * }> $availableCustomDefinitions
         */
        $availableCustomDefinitions = FieldDefinition::query()
            ->where('is_system', false)
            ->where('is_active', true)
            ->whereIn('scope', [FieldScope::Header, FieldScope::Position])
            ->whereIn('applies_to', $allowedApplies)
            ->whereNotIn('id', $memberDefinitionIds)
            ->with(['currentRevision', 'revisions' => fn ($q) => $q->orderByDesc('revision')])
            ->orderBy('key')
            ->get()
            ->map(function (FieldDefinition $definition): array {
                /** @var list<array{id: int, revision: int, label: string}> $availableRevisions */
                $availableRevisions = $definition->revisions
                    ->map(fn (FieldDefinitionRevision $revision): array => [
                        'id' => $revision->id,
                        'revision' => $revision->revision,
                        'label' => $revision->label,
                    ])
                    ->values()
                    ->all();

                return [
                    'id' => $definition->id,
                    'key' => $definition->key,
                    'label' => $definition->currentRevision?->label,
                    'scope' => $definition->scope->value,
                    'applies_to' => $definition->applies_to->value,
                    'field_type' => $definition->field_type->value,
                    'current_revision_id' => $definition->current_revision_id,
                    'available_revisions' => $availableRevisions,
                ];
            })
            ->values()
            ->all();

        return Inertia::render('administration/dynamic-fields/field-sets/version-edit', [
            'fieldSet' => [
                'id' => $fieldSet->id,
                'key' => $fieldSet->key,
                'name' => $fieldSet->name,
                'is_system' => $fieldSet->is_system,
                'applies_to' => $fieldSet->applies_to->value,
                'lock_version' => $fieldSet->lock_version,
            ],
            'version' => [
                'id' => $version->id,
                'version' => $version->version,
                'status' => $version->status->value,
                'editable' => $version->status === FieldSetVersionStatus::Draft,
                'fields' => $version->fields->map(function (FieldSetVersionField $membership) use ($revisionsByDefinition): array {
                    $definition = $membership->definition ?? $membership->revision->definition;
                    $key = $definition->key;

                    return [
                        'id' => $membership->id,
                        'field_definition_id' => $membership->field_definition_id,
                        'field_definition_revision_id' => $membership->field_definition_revision_id,
                        'key' => $key,
                        'label' => $membership->revision->label,
                        'is_system' => (bool) $definition->is_system,
                        'scope' => $definition->scope->value,
                        'applies_to' => $definition->applies_to->value,
                        'field_type' => $definition->field_type->value,
                        'sort' => $membership->sort,
                        'required_override' => $membership->required_override,
                        'visible_override' => $membership->visible_override,
                        'available_revisions' => $revisionsByDefinition[$membership->field_definition_id] ?? [],
                    ];
                }),
                'rules' => $version->rules->map(function ($rule) use ($fieldSet): array {
                    /** @var array<string, mixed> $condition */
                    $condition = $rule->condition_json;
                    /** @var array<string, mixed> $action */
                    $action = $rule->action_json;
                    $dedupe = FieldRuleContract::dedupePayload($condition, $action);

                    return [
                        'id' => $rule->id,
                        'sort' => $rule->sort,
                        'condition' => $condition,
                        'action' => $action,
                        'dedupe_key' => $dedupe,
                        'is_system_seed' => $fieldSet->key === AdminFieldSetCatalog::CALCULATION_CORE
                            && $dedupe === FieldRuleContract::SEED_RULE_DEDUPE_SHA256,
                    ];
                }),
                'field_catalog' => $version->fields->map(function (FieldSetVersionField $membership) use ($fieldSet): array {
                    $definition = $membership->definition ?? $membership->revision->definition;
                    $revision = $membership->revision;
                    $options = [];
                    if ($definition->field_type->isChoice()) {
                        foreach (FieldDefinitionOptionContract::fromRevisionOptions($revision->options) as $option) {
                            $options[] = $option;
                        }
                    }

                    return [
                        'key' => $definition->key,
                        'label' => $revision->label,
                        'field_type' => $definition->field_type->value,
                        'scope' => $definition->scope->value,
                        'is_system' => (bool) $definition->is_system,
                        'action_target_readonly' => AdminFieldSetCatalog::isCalcOriginActionTarget(
                            $fieldSet->key,
                            $definition->key,
                        ),
                        'options' => $options,
                    ];
                })->values()->all(),
            ],
            'availableCustomDefinitions' => $availableCustomDefinitions,
            'rulesRoutes' => [
                'preview' => route(
                    'administration.dynamic-fields.field-sets.versions.rules.preview',
                    [$fieldSet, $version],
                ),
                'replace' => route(
                    'administration.dynamic-fields.field-sets.versions.rules.replace',
                    [$fieldSet, $version],
                ),
            ],
        ]);
    }

    public function rulesPreview(
        PreviewFieldSetVersionRulesRequest $request,
        FieldSet $fieldSet,
        FieldSetVersion $version,
    ): JsonResponse {
        $this->authorize('access-administration');
        $this->writer->assertAdminFieldSet($fieldSet);
        abort_unless((int) $version->field_set_id === (int) $fieldSet->id, 404);

        $payload = $request->payload();
        $preview = $this->rulesWriter->preview(
            $fieldSet,
            $version,
            $payload['rules'],
            $payload['lock_version'],
            $payload['example_header'],
            $payload['example_position'],
        );

        return response()->json($preview);
    }

    public function rulesReplace(
        ReplaceFieldSetVersionRulesRequest $request,
        FieldSet $fieldSet,
        FieldSetVersion $version,
    ): JsonResponse {
        $this->authorize('access-administration');
        $this->writer->assertAdminFieldSet($fieldSet);
        abort_unless((int) $version->field_set_id === (int) $fieldSet->id, 404);

        $result = $this->rulesWriter->replace(
            $fieldSet,
            $version,
            $request->payload(),
            $request->user(),
        );

        $message = $result['has_changes']
            ? 'Regeln übernommen.'
            : 'Keine Änderungen';

        return response()->json([
            'message' => $message,
            'has_changes' => $result['has_changes'],
            'lock_version' => $result['lock_version'],
            'fingerprint' => $result['fingerprint'],
            'rules' => $result['rules'],
        ]);
    }

    public function updateDraft(
        UpdateFieldSetDraftRequest $request,
        FieldSet $fieldSet,
        FieldSetVersion $version,
    ): RedirectResponse|JsonResponse {
        $this->authorize('access-administration');

        $this->writer->updateDraftMemberships(
            $fieldSet,
            $version,
            $request->memberships(),
            $request->user(),
            $request->lockVersion(),
        );

        $redirect = route('administration.dynamic-fields.field-sets.versions.edit', [$fieldSet, $version]);
        $message = 'Entwurf wurde gespeichert.';

        if ($request->wantsJson()) {
            return response()->json([
                'redirect' => $redirect,
                'message' => $message,
            ]);
        }

        return redirect()
            ->to($redirect)
            ->with('success', $message);
    }

    public function addMembership(
        AddFieldSetMembershipRequest $request,
        FieldSet $fieldSet,
        FieldSetVersion $version,
    ): RedirectResponse|JsonResponse {
        $this->authorize('access-administration');

        $this->writer->addCustomMembership(
            $fieldSet,
            $version,
            $request->payload(),
            $request->user(),
        );

        $redirect = route('administration.dynamic-fields.field-sets.versions.edit', [$fieldSet, $version]);
        $message = 'Feld wurde dem Entwurf hinzugefügt.';

        if ($request->wantsJson()) {
            return response()->json([
                'redirect' => $redirect,
                'message' => $message,
            ]);
        }

        return redirect()
            ->to($redirect)
            ->with('success', $message);
    }

    public function removeMembership(
        RemoveFieldSetMembershipRequest $request,
        FieldSet $fieldSet,
        FieldSetVersion $version,
        FieldSetVersionField $membership,
    ): RedirectResponse|JsonResponse {
        $this->authorize('access-administration');

        $this->writer->removeCustomMembership(
            $fieldSet,
            $version,
            $membership,
            $request->user(),
            $request->lockVersion(),
        );

        $redirect = route('administration.dynamic-fields.field-sets.versions.edit', [$fieldSet, $version]);
        $message = 'Feld wurde aus dem Entwurf entfernt.';

        if ($request->wantsJson()) {
            return response()->json([
                'redirect' => $redirect,
                'message' => $message,
            ]);
        }

        return redirect()
            ->to($redirect)
            ->with('success', $message);
    }

    public function pinCurrentRevisions(
        PinCurrentRevisionsRequest $request,
        FieldSet $fieldSet,
        FieldSetVersion $version,
    ): RedirectResponse|JsonResponse {
        $this->authorize('access-administration');

        $this->writer->pinCurrentRevisionsOnDraft(
            $fieldSet,
            $version,
            $request->user(),
            $request->lockVersion(),
        );

        $redirect = route('administration.dynamic-fields.field-sets.versions.edit', [$fieldSet, $version]);
        $message = 'Aktuelle Definition-Revisionen wurden übernommen.';

        if ($request->wantsJson()) {
            return response()->json([
                'redirect' => $redirect,
                'message' => $message,
            ]);
        }

        return redirect()
            ->to($redirect)
            ->with('success', $message);
    }

    public function preview(FieldSet $fieldSet, FieldSetVersion $version): Response
    {
        $this->authorize('access-administration');
        $this->writer->assertAdminFieldSet($fieldSet);
        abort_unless((int) $version->field_set_id === (int) $fieldSet->id, 404);

        $fieldSet->refresh();

        return Inertia::render('administration/dynamic-fields/field-sets/preview', [
            'fieldSet' => [
                'id' => $fieldSet->id,
                'key' => $fieldSet->key,
                'name' => $fieldSet->name,
                'is_system' => $fieldSet->is_system,
                'applies_to' => $fieldSet->applies_to->value,
                'lock_version' => $fieldSet->lock_version,
            ],
            'preview' => $this->preview->preview($version),
            'canActivate' => $version->status === FieldSetVersionStatus::Draft,
            'runtimeNote' => $fieldSet->is_system
                ? null
                : 'Diese Vorschau betrifft nur dieses Feldset. Freie Feldsets erscheinen noch nicht automatisch in Kalkulation oder Dispo.',
        ]);
    }

    public function activate(
        ActivateFieldSetVersionRequest $request,
        FieldSet $fieldSet,
        FieldSetVersion $version,
    ): RedirectResponse|JsonResponse {
        $this->authorize('access-administration');

        $activated = $this->writer->activateDraft(
            $fieldSet,
            $version,
            $request->user(),
            $request->lockVersion(),
        );

        $redirect = route('administration.dynamic-fields.field-sets.show', $fieldSet);
        $message = "Version {$activated->version} wurde aktiviert. Bestehende Vorgänge bleiben unverändert.";

        if ($request->wantsJson()) {
            return response()->json([
                'redirect' => $redirect,
                'message' => $message,
            ]);
        }

        return redirect()
            ->to($redirect)
            ->with('success', $message);
    }

    /**
     * @return array{
     *     id: int,
     *     key: string,
     *     name: string,
     *     is_system: bool,
     *     applies_to: string,
     *     is_assignable: bool,
     *     lock_version: int,
     *     has_draft: bool,
     *     usability_label: string,
     *     active_version: array{id: int, version: int, status: string}|null
     * }
     */
    private function serializeFieldSetSummary(FieldSet $set): array
    {
        $hasDraft = $set->versions->contains(
            fn (FieldSetVersion $version): bool => $version->status === FieldSetVersionStatus::Draft,
        );

        $usability = match (true) {
            $set->is_system => 'Kern-Feldset',
            $set->is_assignable => 'assignierbar (vorbereitet)',
            $set->active_version_id !== null => 'deaktiviert',
            default => 'Entwurf / noch nicht nutzbar',
        };

        return [
            'id' => $set->id,
            'key' => $set->key,
            'name' => $set->name,
            'is_system' => $set->is_system,
            'applies_to' => $set->applies_to->value,
            'is_assignable' => $set->is_assignable,
            'lock_version' => $set->lock_version,
            'has_draft' => $hasDraft,
            'usability_label' => $usability,
            'active_version' => $set->activeVersion === null ? null : [
                'id' => $set->activeVersion->id,
                'version' => $set->activeVersion->version,
                'status' => $set->activeVersion->status->value,
            ],
        ];
    }

    /**
     * DF-3.3-fs-HF1: aktive Assignments für Deaktivierungs-Guard und Admin-Hinweis.
     *
     * @return list<array{
     *     id: int,
     *     applies_to_process: string,
     *     applies_to_process_label: string,
     *     target_layer: string,
     *     target_layer_label: string,
     *     target_label: string,
     *     href: string
     * }>
     */
    private function serializeActiveAssignments(FieldSet $fieldSet): array
    {
        /** @var list<array{
         *     id: int,
         *     applies_to_process: string,
         *     applies_to_process_label: string,
         *     target_layer: string,
         *     target_layer_label: string,
         *     target_label: string,
         *     href: string
         * }> $rows
         */
        $rows = [];

        $assignments = FieldSetAssignment::query()
            ->where('field_set_id', $fieldSet->id)
            ->where('is_active', true)
            ->with(['advertisingCategory', 'advertisingMedium.category'])
            ->orderBy('id')
            ->get();

        foreach ($assignments as $assignment) {
            $rows[] = [
                'id' => (int) $assignment->id,
                'applies_to_process' => $assignment->applies_to_process->value,
                'applies_to_process_label' => $this->processLabel($assignment->applies_to_process),
                'target_layer' => $assignment->target_layer->value,
                'target_layer_label' => $this->targetLayerLabel($assignment->target_layer),
                'target_label' => $this->assignmentTargetLabel($assignment),
                'href' => route('administration.dynamic-fields.assignments.show', $assignment),
            ];
        }

        return $rows;
    }

    private function processLabel(FieldAppliesTo $process): string
    {
        return match ($process) {
            FieldAppliesTo::Calculation => 'Kalkulation',
            FieldAppliesTo::DispoOrder => 'Dispoauftrag',
            FieldAppliesTo::Both => 'Beide',
        };
    }

    private function targetLayerLabel(FieldSetAssignmentTargetLayer $layer): string
    {
        return match ($layer) {
            FieldSetAssignmentTargetLayer::Global => 'Global',
            FieldSetAssignmentTargetLayer::AdvertisingCategory => 'Oberkategorie',
            FieldSetAssignmentTargetLayer::AdvertisingMedium => 'Werbemittel',
        };
    }

    private function assignmentTargetLabel(FieldSetAssignment $assignment): string
    {
        return match ($assignment->target_layer) {
            FieldSetAssignmentTargetLayer::Global => 'Global',
            FieldSetAssignmentTargetLayer::AdvertisingCategory => $this->categoryTargetLabel($assignment),
            FieldSetAssignmentTargetLayer::AdvertisingMedium => $this->mediumTargetLabel($assignment),
        };
    }

    private function categoryTargetLabel(FieldSetAssignment $assignment): string
    {
        $category = $assignment->advertisingCategory;
        if ($category === null) {
            return 'Oberkategorie #'.(string) $assignment->advertising_category_id.' (nicht gefunden)';
        }

        $label = "{$category->name} ({$category->key})";

        return $category->is_active ? $label : "{$label} – deaktiviert";
    }

    private function mediumTargetLabel(FieldSetAssignment $assignment): string
    {
        $medium = $assignment->advertisingMedium;
        if ($medium === null) {
            return 'Werbemittel #'.(string) $assignment->advertising_medium_id.' (nicht gefunden)';
        }

        $category = $medium->category;
        $categoryName = $category !== null ? $category->name : 'ohne Oberkategorie';
        $label = "{$medium->name} ({$medium->code}) · {$categoryName}";

        return $medium->is_active ? $label : "{$label} – deaktiviert";
    }
}
