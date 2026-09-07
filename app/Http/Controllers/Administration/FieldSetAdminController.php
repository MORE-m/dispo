<?php

namespace App\Http\Controllers\Administration;

use App\Enums\FieldAppliesTo;
use App\Enums\FieldScope;
use App\Enums\FieldSetVersionStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Administration\DynamicField\ActivateFieldSetVersionRequest;
use App\Http\Requests\Administration\DynamicField\AddFieldSetMembershipRequest;
use App\Http\Requests\Administration\DynamicField\CreateFieldSetDraftRequest;
use App\Http\Requests\Administration\DynamicField\DeactivateFieldSetRequest;
use App\Http\Requests\Administration\DynamicField\PinCurrentRevisionsRequest;
use App\Http\Requests\Administration\DynamicField\ReactivateFieldSetRequest;
use App\Http\Requests\Administration\DynamicField\RemoveFieldSetMembershipRequest;
use App\Http\Requests\Administration\DynamicField\StoreFreeFieldSetRequest;
use App\Http\Requests\Administration\DynamicField\UpdateFieldSetDraftRequest;
use App\Http\Requests\Administration\DynamicField\UpdateFieldSetMetadataRequest;
use App\Models\FieldDefinition;
use App\Models\FieldDefinitionRevision;
use App\Models\FieldSet;
use App\Models\FieldSetVersion;
use App\Models\FieldSetVersionField;
use App\Services\DynamicField\Admin\AdminFieldSetCatalog;
use App\Services\DynamicField\Admin\FieldSetVersionAdminWriter;
use App\Services\DynamicField\Admin\FieldSetVersionPreviewService;
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
                'can_deactivate' => ! $fieldSet->is_system && $fieldSet->is_assignable,
                'can_reactivate' => ! $fieldSet->is_system
                    && ! $fieldSet->is_assignable
                    && $fieldSet->active_version_id !== null,
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
                : 'Freie Feldsets wirken in diesem Stand noch nicht auf Kalkulation oder Dispoauftrag. Assignments folgen in späteren Slices.',
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
        $version->load(['fields.revision.definition', 'fields.definition', 'rules']);

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
                        'sort' => $membership->sort,
                        'required_override' => $membership->required_override,
                        'visible_override' => $membership->visible_override,
                        'available_revisions' => $revisionsByDefinition[$membership->field_definition_id] ?? [],
                    ];
                }),
                'rules' => $version->rules->map(fn ($rule): array => [
                    'id' => $rule->id,
                    'sort' => $rule->sort,
                    'condition' => $rule->condition_json,
                    'action' => $rule->action_json,
                ]),
            ],
            'availableCustomDefinitions' => $availableCustomDefinitions,
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
}
