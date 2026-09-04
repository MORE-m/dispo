<?php

namespace App\Http\Controllers\Administration;

use App\Http\Controllers\Controller;
use App\Http\Requests\Administration\DynamicField\StoreFieldDefinitionRevisionRequest;
use App\Models\FieldDefinition;
use App\Services\DynamicField\Admin\FieldDefinitionAdminWriter;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

/**
 * DF-3.1 / DYN-001 / ADM-001
 */
class FieldDefinitionAdminController extends Controller
{
    public function __construct(
        private readonly FieldDefinitionAdminWriter $writer,
    ) {}

    public function index(): Response
    {
        $this->authorize('access-administration');

        $definitions = FieldDefinition::query()
            ->where('is_system', true)
            ->with('currentRevision')
            ->orderBy('key')
            ->get()
            ->map(fn (FieldDefinition $definition): array => [
                'id' => $definition->id,
                'key' => $definition->key,
                'field_type' => $definition->field_type->value,
                'scope' => $definition->scope->value,
                'applies_to' => $definition->applies_to->value,
                'is_system' => $definition->is_system,
                'label' => $definition->currentRevision?->label,
                'revision' => $definition->currentRevision?->revision,
                'reportable' => $definition->currentRevision?->reportable,
            ]);

        return Inertia::render('administration/dynamic-fields/definitions/index', [
            'definitions' => $definitions,
        ]);
    }

    public function show(FieldDefinition $definition): Response
    {
        $this->authorize('access-administration');
        abort_unless($definition->is_system && $definition->is_key_protected, 404);

        $definition->load(['currentRevision', 'revisions' => fn ($q) => $q->orderByDesc('revision')]);

        return Inertia::render('administration/dynamic-fields/definitions/show', [
            'definition' => [
                'id' => $definition->id,
                'key' => $definition->key,
                'field_type' => $definition->field_type->value,
                'scope' => $definition->scope->value,
                'applies_to' => $definition->applies_to->value,
                'is_system' => $definition->is_system,
                'is_key_protected' => $definition->is_key_protected,
                'current_revision' => $definition->currentRevision === null ? null : [
                    'id' => $definition->currentRevision->id,
                    'revision' => $definition->currentRevision->revision,
                    'label' => $definition->currentRevision->label,
                    'help_text' => $definition->currentRevision->help_text,
                    'group_key' => $definition->currentRevision->group_key,
                    'sort_default' => $definition->currentRevision->sort_default,
                    'reportable' => $definition->currentRevision->reportable,
                ],
                'revisions' => $definition->revisions->map(fn ($revision): array => [
                    'id' => $revision->id,
                    'revision' => $revision->revision,
                    'label' => $revision->label,
                    'help_text' => $revision->help_text,
                    'group_key' => $revision->group_key,
                    'sort_default' => $revision->sort_default,
                    'reportable' => $revision->reportable,
                    'created_at' => $revision->created_at?->timezone('Europe/Berlin')->toIso8601String(),
                ]),
            ],
        ]);
    }

    public function storeRevision(
        StoreFieldDefinitionRevisionRequest $request,
        FieldDefinition $definition,
    ): RedirectResponse {
        $this->authorize('access-administration');
        abort_unless($definition->is_system && $definition->is_key_protected, 404);

        $revision = $this->writer->createRevision(
            $definition,
            $request->payload(),
            $request->user(),
        );

        return redirect()
            ->route('administration.dynamic-fields.definitions.show', $definition)
            ->with('success', "Revision {$revision->revision} wurde angelegt.");
    }
}
