<?php

namespace App\Http\Controllers\Administration;

use App\Exceptions\FieldDefinitionConflictException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Administration\DynamicField\DeactivateFieldDefinitionRequest;
use App\Http\Requests\Administration\DynamicField\DeleteFieldDefinitionRequest;
use App\Http\Requests\Administration\DynamicField\PreviewFieldDefinitionOptionsRequest;
use App\Http\Requests\Administration\DynamicField\ReactivateFieldDefinitionRequest;
use App\Http\Requests\Administration\DynamicField\ReplaceFieldDefinitionOptionsRequest;
use App\Http\Requests\Administration\DynamicField\StoreCustomFieldDefinitionRequest;
use App\Http\Requests\Administration\DynamicField\StoreFieldDefinitionRevisionRequest;
use App\Http\Requests\Administration\DynamicField\UpdateCustomFieldDefinitionRequest;
use App\Models\FieldDefinition;
use App\Models\FieldSetVersionField;
use App\Services\DynamicField\Admin\FieldDefinitionAdminWriter;
use App\Services\DynamicField\Admin\FieldDefinitionCustomWriter;
use App\Services\DynamicField\Admin\FieldDefinitionOptionsWriter;
use App\Support\DynamicField\FieldDefinitionOptionContract;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * DF-3.1 / DF-3.2a / DF-3-REST-B / DYN-001 / ADM-001
 */
class FieldDefinitionAdminController extends Controller
{
    public function __construct(
        private readonly FieldDefinitionAdminWriter $writer,
        private readonly FieldDefinitionCustomWriter $customWriter,
        private readonly FieldDefinitionOptionsWriter $optionsWriter,
    ) {}

    public function index(Request $request): Response
    {
        $this->authorize('access-administration');

        $type = (string) $request->query('type', 'all');
        if (! in_array($type, ['all', 'system', 'custom'], true)) {
            $type = 'all';
        }

        $mapDefinition = fn (FieldDefinition $definition): array => [
            'id' => $definition->id,
            'key' => $definition->key,
            'field_type' => $definition->field_type->value,
            'scope' => $definition->scope->value,
            'applies_to' => $definition->applies_to->value,
            'is_system' => $definition->is_system,
            'is_key_protected' => $definition->is_key_protected,
            'is_active' => $definition->is_active,
            'lock_version' => $definition->lock_version,
            'label' => $definition->currentRevision?->label,
            'revision' => $definition->currentRevision?->revision,
            'reportable' => $definition->currentRevision?->reportable,
            'type' => $definition->is_system && $definition->is_key_protected ? 'system' : 'custom',
        ];

        $systemDefinitions = FieldDefinition::query()
            ->where('is_system', true)
            ->where('is_key_protected', true)
            ->with('currentRevision')
            ->orderBy('key')
            ->get()
            ->map($mapDefinition)
            ->values()
            ->all();

        $customDefinitions = FieldDefinition::query()
            ->where('is_system', false)
            ->with('currentRevision')
            ->orderBy('key')
            ->get()
            ->map($mapDefinition)
            ->values()
            ->all();

        $definitions = match ($type) {
            'system' => $systemDefinitions,
            'custom' => $customDefinitions,
            default => array_merge($systemDefinitions, $customDefinitions),
        };

        return Inertia::render('administration/dynamic-fields/definitions/index', [
            'definitions' => $definitions,
            'systemDefinitions' => $systemDefinitions,
            'customDefinitions' => $customDefinitions,
            'filter' => ['type' => $type],
        ]);
    }

    public function create(): Response
    {
        $this->authorize('access-administration');

        return Inertia::render('administration/dynamic-fields/definitions/create', [
            'defaults' => [
                'field_type' => 'short_text',
                'scope' => 'header',
                'applies_to' => 'both',
                'sort_default' => 100,
                'reportable' => false,
            ],
        ]);
    }

    public function store(StoreCustomFieldDefinitionRequest $request): RedirectResponse
    {
        $this->authorize('access-administration');

        $definition = $this->customWriter->create($request->payload(), $request->user());

        return redirect()
            ->route('administration.dynamic-fields.definitions.show', $definition)
            ->with('success', 'Custom-Feld wurde angelegt.');
    }

    public function show(FieldDefinition $definition): Response
    {
        $this->authorize('access-administration');
        $this->assertVisibleDefinition($definition);

        $definition->load([
            'currentRevision.options',
            'revisions' => fn ($q) => $q->orderByDesc('revision'),
        ]);

        $memberships = FieldSetVersionField::query()
            ->where('field_definition_id', $definition->id)
            ->with(['version.fieldSet'])
            ->get()
            ->map(fn (FieldSetVersionField $membership): array => [
                'id' => $membership->id,
                'field_set_id' => $membership->version?->field_set_id,
                'field_set_key' => $membership->version?->fieldSet?->key,
                'field_set_name' => $membership->version?->fieldSet?->name,
                'version_id' => $membership->field_set_version_id,
                'version' => $membership->version?->version,
                'status' => $membership->version?->status?->value,
                'sort' => $membership->sort,
                'required_override' => $membership->required_override,
                'visible_override' => $membership->visible_override,
                'field_definition_revision_id' => $membership->field_definition_revision_id,
            ])
            ->values()
            ->all();

        $validation = $definition->currentRevision?->validation_json;
        $maxLength = is_array($validation) && isset($validation['max_length'])
            ? (int) $validation['max_length']
            : null;

        $isCustomChoice = ! $definition->is_system && $definition->field_type->isChoice();
        $options = $isCustomChoice && $definition->currentRevision !== null
            ? FieldDefinitionOptionContract::fromRevisionOptions($definition->currentRevision->options)
            : [];
        $optionsFingerprint = $isCustomChoice
            ? FieldDefinitionOptionContract::fingerprint($options)
            : null;

        return Inertia::render('administration/dynamic-fields/definitions/show', [
            'definition' => [
                'id' => $definition->id,
                'key' => $definition->key,
                'field_type' => $definition->field_type->value,
                'scope' => $definition->scope->value,
                'applies_to' => $definition->applies_to->value,
                'is_system' => $definition->is_system,
                'is_key_protected' => $definition->is_key_protected,
                'is_active' => $definition->is_active,
                'lock_version' => $definition->lock_version,
                'is_used' => $this->customWriter->isUsed($definition),
                'max_length' => $maxLength,
                'current_revision' => $definition->currentRevision === null ? null : [
                    'id' => $definition->currentRevision->id,
                    'revision' => $definition->currentRevision->revision,
                    'label' => $definition->currentRevision->label,
                    'help_text' => $definition->currentRevision->help_text,
                    'group_key' => $definition->currentRevision->group_key,
                    'sort_default' => $definition->currentRevision->sort_default,
                    'reportable' => $definition->currentRevision->reportable,
                    'validation_json' => $definition->currentRevision->validation_json,
                ],
                'revisions' => $definition->revisions->map(fn ($revision): array => [
                    'id' => $revision->id,
                    'revision' => $revision->revision,
                    'label' => $revision->label,
                    'help_text' => $revision->help_text,
                    'group_key' => $revision->group_key,
                    'sort_default' => $revision->sort_default,
                    'reportable' => $revision->reportable,
                    'validation_json' => $revision->validation_json,
                    'created_at' => $revision->created_at?->timezone('Europe/Berlin')->toIso8601String(),
                ]),
                'memberships' => $memberships,
                'options' => $options,
                'options_fingerprint' => $optionsFingerprint,
                'can_manage_options' => $isCustomChoice,
            ],
            'routes' => $isCustomChoice ? [
                'optionsPreview' => route(
                    'administration.dynamic-fields.definitions.options.preview',
                    $definition,
                ),
                'optionsReplace' => route(
                    'administration.dynamic-fields.definitions.options.replace',
                    $definition,
                ),
            ] : null,
            'optionsBoundaryNote' => 'Bestehende Feldset-Versionen bleiben auf ihrer bisher gepinnten '
                .'Feldrevision. Die neuen Optionen wirken dort erst nach einer expliziten '
                .'Aktualisierung der Feldset-Version.',
        ]);
    }

    public function update(
        UpdateCustomFieldDefinitionRequest $request,
        FieldDefinition $definition,
    ): RedirectResponse|JsonResponse {
        $this->authorize('access-administration');
        abort_unless(! $definition->is_system, 404);

        $this->customWriter->updateBeforeUsed($definition, $request->payload(), $request->user());

        $redirect = route('administration.dynamic-fields.definitions.show', $definition);
        $message = 'Custom-Feld wurde aktualisiert.';

        if ($request->wantsJson()) {
            return response()->json(['redirect' => $redirect, 'message' => $message]);
        }

        return redirect()->to($redirect)->with('success', $message);
    }

    public function storeRevision(
        StoreFieldDefinitionRevisionRequest $request,
        FieldDefinition $definition,
    ): RedirectResponse|JsonResponse {
        $this->authorize('access-administration');
        $this->assertVisibleDefinition($definition);

        $payload = $request->payload();
        $revision = $this->writer->createRevision(
            $definition,
            $payload,
            $request->user(),
            $payload['lock_version'],
        );

        $redirect = route('administration.dynamic-fields.definitions.show', $definition);
        $message = "Revision {$revision->revision} wurde angelegt.";

        if ($request->wantsJson()) {
            return response()->json(['redirect' => $redirect, 'message' => $message]);
        }

        return redirect()->to($redirect)->with('success', $message);
    }

    public function deactivate(
        DeactivateFieldDefinitionRequest $request,
        FieldDefinition $definition,
    ): RedirectResponse|JsonResponse {
        $this->authorize('access-administration');
        abort_unless(! $definition->is_system, 404);

        $this->customWriter->deactivate($definition, $request->user(), $request->lockVersion());

        $redirect = route('administration.dynamic-fields.definitions.show', $definition);
        $message = 'Custom-Feld wurde deaktiviert.';

        if ($request->wantsJson()) {
            return response()->json(['redirect' => $redirect, 'message' => $message]);
        }

        return redirect()->to($redirect)->with('success', $message);
    }

    public function reactivate(
        ReactivateFieldDefinitionRequest $request,
        FieldDefinition $definition,
    ): RedirectResponse|JsonResponse {
        $this->authorize('access-administration');
        abort_unless(! $definition->is_system, 404);

        $this->customWriter->reactivate($definition, $request->user(), $request->lockVersion());

        $redirect = route('administration.dynamic-fields.definitions.show', $definition);
        $message = 'Custom-Feld wurde reaktiviert.';

        if ($request->wantsJson()) {
            return response()->json(['redirect' => $redirect, 'message' => $message]);
        }

        return redirect()->to($redirect)->with('success', $message);
    }

    public function destroy(
        DeleteFieldDefinitionRequest $request,
        FieldDefinition $definition,
    ): RedirectResponse|JsonResponse {
        $this->authorize('access-administration');
        abort_unless(! $definition->is_system, 404);

        $this->customWriter->destroy($definition, $request->user(), $request->lockVersion());

        $redirect = route('administration.dynamic-fields.definitions.index');
        $message = 'Custom-Feld wurde gelöscht.';

        if ($request->wantsJson()) {
            return response()->json(['redirect' => $redirect, 'message' => $message]);
        }

        return redirect()->to($redirect)->with('success', $message);
    }

    public function optionsPreview(
        PreviewFieldDefinitionOptionsRequest $request,
        FieldDefinition $definition,
    ): JsonResponse {
        $this->authorize('access-administration');
        $this->assertCustomChoiceDefinition($definition);

        $payload = $request->payload();
        if ((int) $definition->lock_version !== $payload['lock_version']) {
            throw new FieldDefinitionConflictException;
        }

        $preview = $this->optionsWriter->preview($definition, $payload['options']);

        return response()->json([
            'lock_version' => (int) $definition->lock_version,
            'fingerprint' => $preview['fingerprint'],
            'has_changes' => $preview['has_changes'],
            'options' => $preview['options'],
            'previous_options' => $preview['previous_options'],
            'summary' => $preview['summary'],
        ]);
    }

    public function optionsReplace(
        ReplaceFieldDefinitionOptionsRequest $request,
        FieldDefinition $definition,
    ): JsonResponse {
        $this->authorize('access-administration');
        $this->assertCustomChoiceDefinition($definition);

        $payload = $request->payload();
        $result = $this->optionsWriter->replace($definition, $payload, $request->user());
        $updated = $result['definition'];
        $updated->load(['currentRevision.options', 'revisions' => fn ($q) => $q->orderByDesc('revision')]);

        $options = FieldDefinitionOptionContract::fromRevisionOptions(
            $updated->currentRevision !== null
                ? $updated->currentRevision->options
                : [],
        );

        $message = $result['has_changes']
            ? 'Auswahloptionen übernommen.'
            : 'Keine Änderungen';

        return response()->json([
            'message' => $message,
            'has_changes' => $result['has_changes'],
            'lock_version' => (int) $updated->lock_version,
            'fingerprint' => $result['fingerprint'],
            'options' => $options,
            'current_revision' => $updated->currentRevision === null ? null : [
                'id' => $updated->currentRevision->id,
                'revision' => $updated->currentRevision->revision,
                'label' => $updated->currentRevision->label,
                'help_text' => $updated->currentRevision->help_text,
                'group_key' => $updated->currentRevision->group_key,
                'sort_default' => $updated->currentRevision->sort_default,
                'reportable' => $updated->currentRevision->reportable,
                'validation_json' => $updated->currentRevision->validation_json,
            ],
            'revisions' => $updated->revisions->map(fn ($revision): array => [
                'id' => $revision->id,
                'revision' => $revision->revision,
                'label' => $revision->label,
                'help_text' => $revision->help_text,
                'group_key' => $revision->group_key,
                'sort_default' => $revision->sort_default,
                'reportable' => $revision->reportable,
                'validation_json' => $revision->validation_json,
                'created_at' => $revision->created_at?->timezone('Europe/Berlin')->toIso8601String(),
            ])->values()->all(),
        ]);
    }

    private function assertVisibleDefinition(FieldDefinition $definition): void
    {
        $isSystemProtected = $definition->is_system && $definition->is_key_protected;
        $isCustom = ! $definition->is_system;
        abort_unless($isSystemProtected || $isCustom, 404);
    }

    private function assertCustomChoiceDefinition(FieldDefinition $definition): void
    {
        abort_unless(! $definition->is_system, 404);

        if (! $definition->field_type->isChoice()) {
            throw ValidationException::withMessages([
                'definition' => 'Optionen können nur für select- und multi_select-Felder gepflegt werden.',
            ]);
        }
    }
}
