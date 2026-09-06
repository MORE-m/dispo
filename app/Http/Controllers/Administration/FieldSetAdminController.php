<?php

namespace App\Http\Controllers\Administration;

use App\Enums\FieldAppliesTo;
use App\Enums\FieldScope;
use App\Enums\FieldSetVersionStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Administration\DynamicField\ActivateFieldSetVersionRequest;
use App\Http\Requests\Administration\DynamicField\AddFieldSetMembershipRequest;
use App\Http\Requests\Administration\DynamicField\CreateFieldSetDraftRequest;
use App\Http\Requests\Administration\DynamicField\PinCurrentRevisionsRequest;
use App\Http\Requests\Administration\DynamicField\RemoveFieldSetMembershipRequest;
use App\Http\Requests\Administration\DynamicField\UpdateFieldSetDraftRequest;
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
 * DF-3.1 / DYN-003 / VER-005 / VER-006 / ADM-002
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
                    'description' => 'Systemfelder revisionieren und eigene Header-Textfelder verwalten.',
                    'href' => '/administration/dynamische-felder/definitionen',
                ],
                [
                    'title' => 'Feldsets',
                    'description' => 'Kern-Feldsets versionieren und aktivieren.',
                    'href' => '/administration/dynamische-felder/feldsets',
                ],
            ],
        ]);
    }

    public function index(): Response
    {
        $this->authorize('access-administration');

        $sets = FieldSet::query()
            ->whereIn('key', AdminFieldSetCatalog::allowedKeys())
            ->with(['activeVersion'])
            ->orderBy('key')
            ->get()
            ->map(fn (FieldSet $set): array => [
                'id' => $set->id,
                'key' => $set->key,
                'name' => $set->name,
                'lock_version' => $set->lock_version,
                'active_version' => $set->activeVersion === null ? null : [
                    'id' => $set->activeVersion->id,
                    'version' => $set->activeVersion->version,
                    'status' => $set->activeVersion->status->value,
                ],
            ]);

        return Inertia::render('administration/dynamic-fields/field-sets/index', [
            'fieldSets' => $sets,
        ]);
    }

    public function show(FieldSet $fieldSet): Response
    {
        $this->authorize('access-administration');
        $this->writer->assertAdminFieldSet($fieldSet);

        $fieldSet->load(['versions' => fn ($q) => $q->orderByDesc('version'), 'activeVersion']);

        return Inertia::render('administration/dynamic-fields/field-sets/show', [
            'fieldSet' => [
                'id' => $fieldSet->id,
                'key' => $fieldSet->key,
                'name' => $fieldSet->name,
                'lock_version' => $fieldSet->lock_version,
                'active_version_id' => $fieldSet->active_version_id,
                'versions' => $fieldSet->versions->map(fn (FieldSetVersion $version): array => [
                    'id' => $version->id,
                    'version' => $version->version,
                    'status' => $version->status->value,
                    'created_at' => $version->created_at?->timezone('Europe/Berlin')->toIso8601String(),
                ]),
            ],
        ]);
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
        $allowedApplies = match ($fieldSet->key) {
            AdminFieldSetCatalog::CALCULATION_CORE => [
                FieldAppliesTo::Calculation->value,
                FieldAppliesTo::Both->value,
            ],
            AdminFieldSetCatalog::DISPO_ORDER_CORE => [
                FieldAppliesTo::DispoOrder->value,
                FieldAppliesTo::Both->value,
            ],
            default => [],
        };

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
            ->where('scope', FieldScope::Header)
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
                'lock_version' => $fieldSet->lock_version,
            ],
            'preview' => $this->preview->preview($version),
            'canActivate' => $version->status === FieldSetVersionStatus::Draft,
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
}
