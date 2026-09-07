<?php

namespace App\Http\Controllers\Administration;

use App\Http\Controllers\Controller;
use App\Http\Requests\Administration\DynamicField\ActivateFieldSetAssignmentRequest;
use App\Http\Requests\Administration\DynamicField\DeactivateFieldSetAssignmentRequest;
use App\Http\Requests\Administration\DynamicField\PreviewFieldSetAssignmentContextRequest;
use App\Http\Requests\Administration\DynamicField\StoreFieldSetAssignmentRequest;
use App\Http\Requests\Administration\DynamicField\UpdateFieldSetAssignmentRequest;
use App\Models\FieldSetAssignment;
use App\Services\DynamicField\Assignment\FieldSetAssignmentAdminWriter;
use App\Services\DynamicField\Assignment\FieldSetAssignmentPreviewService;
use Illuminate\Http\JsonResponse;

/**
 * DF-3.3a1 / DYN-002 / ADM-002: minimale JSON-API für Assignments + Kontext-Preview.
 * Keine Inertia-Admin-UI.
 */
class FieldSetAssignmentAdminController extends Controller
{
    public function store(
        StoreFieldSetAssignmentRequest $request,
        FieldSetAssignmentAdminWriter $writer,
    ): JsonResponse {
        $this->authorize('access-administration');

        $assignment = $writer->create($request->payload(), $request->user());

        return response()->json([
            'assignment' => $this->serialize($assignment),
        ], 201);
    }

    public function update(
        UpdateFieldSetAssignmentRequest $request,
        FieldSetAssignment $assignment,
        FieldSetAssignmentAdminWriter $writer,
    ): JsonResponse {
        $this->authorize('access-administration');

        $updated = $writer->update($assignment, $request->payload(), $request->user());

        return response()->json([
            'assignment' => $this->serialize($updated),
        ]);
    }

    public function previewContext(
        PreviewFieldSetAssignmentContextRequest $request,
        FieldSetAssignmentPreviewService $preview,
    ): JsonResponse {
        $this->authorize('access-administration');

        return response()->json([
            'preview' => $preview->preview($request->payload()),
        ]);
    }

    public function previewActivation(
        FieldSetAssignment $assignment,
        FieldSetAssignmentAdminWriter $writer,
    ): JsonResponse {
        $this->authorize('access-administration');

        $assignment->loadMissing(['fieldSet.activeVersion']);
        $previews = $writer->previewAffectedContexts($assignment, asCandidate: true);

        return response()->json([
            'fingerprint' => $writer->canonicalActivationFingerprint($previews),
            'previews' => $previews,
            'has_blocking_conflicts' => (bool) array_reduce(
                $previews,
                static fn (bool $carry, array $preview): bool => $carry || (bool) $preview['has_blocking_conflicts'],
                false,
            ),
        ]);
    }

    public function activate(
        ActivateFieldSetAssignmentRequest $request,
        FieldSetAssignment $assignment,
        FieldSetAssignmentAdminWriter $writer,
    ): JsonResponse {
        $this->authorize('access-administration');

        $activated = $writer->activate($assignment, $request->payload(), $request->user());

        return response()->json([
            'assignment' => $this->serialize($activated),
        ]);
    }

    public function deactivate(
        DeactivateFieldSetAssignmentRequest $request,
        FieldSetAssignment $assignment,
        FieldSetAssignmentAdminWriter $writer,
    ): JsonResponse {
        $this->authorize('access-administration');

        $deactivated = $writer->deactivate($assignment, $request->payload(), $request->user());

        return response()->json([
            'assignment' => $this->serialize($deactivated),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function serialize(FieldSetAssignment $assignment): array
    {
        $assignment->loadMissing('fieldSet');

        return [
            'id' => $assignment->id,
            'field_set_id' => $assignment->field_set_id,
            'field_set_key' => $assignment->fieldSet?->key,
            'target_layer' => $assignment->target_layer->value,
            'advertising_category_id' => $assignment->advertising_category_id,
            'advertising_medium_id' => $assignment->advertising_medium_id,
            'target_identity' => $assignment->target_identity,
            'applies_to_process' => $assignment->applies_to_process->value,
            'sort' => $assignment->sort,
            'is_active' => $assignment->is_active,
            'lock_version' => $assignment->lock_version,
        ];
    }
}
