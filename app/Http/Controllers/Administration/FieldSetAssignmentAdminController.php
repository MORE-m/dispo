<?php

namespace App\Http\Controllers\Administration;

use App\Enums\FieldAppliesTo;
use App\Enums\FieldSetAssignmentTargetLayer;
use App\Http\Controllers\Controller;
use App\Http\Requests\Administration\DynamicField\ActivateFieldSetAssignmentRequest;
use App\Http\Requests\Administration\DynamicField\DeactivateFieldSetAssignmentRequest;
use App\Http\Requests\Administration\DynamicField\PreviewFieldSetAssignmentContextRequest;
use App\Http\Requests\Administration\DynamicField\StoreFieldSetAssignmentRequest;
use App\Http\Requests\Administration\DynamicField\UpdateFieldSetAssignmentRequest;
use App\Models\AdvertisingCategory;
use App\Models\AdvertisingMedium;
use App\Models\FieldSet;
use App\Models\FieldSetAssignment;
use App\Services\DynamicField\Admin\AdminFieldSetCatalog;
use App\Services\DynamicField\Assignment\FieldSetAssignmentAdminWriter;
use App\Services\DynamicField\Assignment\FieldSetAssignmentPreviewService;
use Illuminate\Http\JsonResponse;
use Inertia\Inertia;
use Inertia\Response;

/**
 * DF-3.3a1 / DF-3.3b / DYN-002 / ADM-002:
 * JSON-Lifecycle-API (a1) plus Inertia-Admin-UI mit Herkunft/Konflikten (b).
 */
class FieldSetAssignmentAdminController extends Controller
{
    public function index(): Response
    {
        $this->authorize('access-administration');

        $assignments = FieldSetAssignment::query()
            ->with(['fieldSet', 'advertisingCategory', 'advertisingMedium.category'])
            ->orderBy('sort')
            ->orderBy('id')
            ->get()
            ->map(fn (FieldSetAssignment $assignment): array => $this->serializeForPage($assignment))
            ->all();

        return Inertia::render('administration/dynamic-fields/assignments/index', [
            'assignments' => $assignments,
        ]);
    }

    public function create(): Response
    {
        $this->authorize('access-administration');

        return Inertia::render('administration/dynamic-fields/assignments/create', [
            'formOptions' => $this->formOptions(),
            'catalogNote' => $this->catalogNote(),
        ]);
    }

    public function show(FieldSetAssignment $assignment): Response
    {
        $this->authorize('access-administration');

        $assignment->loadMissing(['fieldSet', 'advertisingCategory', 'advertisingMedium.category']);

        return Inertia::render('administration/dynamic-fields/assignments/show', [
            'assignment' => $this->serializeForPage($assignment),
            'formOptions' => $this->formOptions($assignment),
            'catalogNote' => $this->catalogNote(),
            'routes' => [
                'update' => route('administration.dynamic-fields.assignments.update', $assignment),
                'contextPreview' => route('administration.dynamic-fields.assignments.context-preview'),
                'activationPreview' => route('administration.dynamic-fields.assignments.activation-preview', $assignment),
                'activate' => route('administration.dynamic-fields.assignments.activate', $assignment),
                'deactivate' => route('administration.dynamic-fields.assignments.deactivate', $assignment),
                'index' => route('administration.dynamic-fields.assignments.index'),
            ],
        ]);
    }

    public function store(
        StoreFieldSetAssignmentRequest $request,
        FieldSetAssignmentAdminWriter $writer,
    ): JsonResponse {
        $this->authorize('access-administration');

        $assignment = $writer->create($request->payload(), $request->user());

        return response()->json([
            'assignment' => $this->serialize($assignment),
            'redirect' => route('administration.dynamic-fields.assignments.show', $assignment),
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
            'redirect' => route('administration.dynamic-fields.assignments.show', $updated),
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
            'redirect' => route('administration.dynamic-fields.assignments.show', $activated),
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
            'redirect' => route('administration.dynamic-fields.assignments.show', $deactivated),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function serialize(FieldSetAssignment $assignment): array
    {
        $assignment->loadMissing(['fieldSet', 'advertisingCategory', 'advertisingMedium.category']);

        return [
            'id' => $assignment->id,
            'field_set_id' => $assignment->field_set_id,
            'field_set_key' => $assignment->fieldSet?->key,
            'field_set_name' => $assignment->fieldSet?->name,
            'target_layer' => $assignment->target_layer->value,
            'advertising_category_id' => $assignment->advertising_category_id,
            'advertising_medium_id' => $assignment->advertising_medium_id,
            'target_identity' => $assignment->target_identity,
            'applies_to_process' => $assignment->applies_to_process->value,
            'sort' => $assignment->sort,
            'is_active' => $assignment->is_active,
            'lock_version' => $assignment->lock_version,
            'target_label' => $this->targetLabel($assignment),
            'updated_at' => $assignment->updated_at?->toIso8601String(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeForPage(FieldSetAssignment $assignment): array
    {
        $serialized = $this->serialize($assignment);
        $serialized['target_layer_label'] = $this->targetLayerLabel($assignment->target_layer);
        $serialized['applies_to_process_label'] = $this->processLabel($assignment->applies_to_process);
        $serialized['status_label'] = $assignment->is_active ? 'Aktiv' : 'Inaktiv';
        $serialized['target_is_selectable'] = $this->targetIsCurrentlySelectable($assignment);
        $serialized['created_at'] = $assignment->created_at?->toIso8601String();

        return $serialized;
    }

    /**
     * @return array{
     *     fieldSets: array<int, array<string, mixed>>,
     *     categories: array<int, array<string, mixed>>,
     *     media: array<int, array<string, mixed>>,
     *     targetLayerOptions: array<int, array{value: string, label: string}>,
     *     processOptions: array<int, array{value: string, label: string}>
     * }
     */
    private function formOptions(?FieldSetAssignment $current = null): array
    {
        $currentCategoryId = $current?->advertising_category_id;
        $currentMediumId = $current?->advertising_medium_id;

        $fieldSets = FieldSet::query()
            ->with('activeVersion')
            ->where('is_system', false)
            ->where('is_assignable', true)
            ->whereNotNull('active_version_id')
            ->orderBy('key')
            ->get()
            ->filter(fn (FieldSet $set): bool => AdminFieldSetCatalog::isAdministrable($set))
            ->map(fn (FieldSet $set): array => [
                'id' => $set->id,
                'key' => $set->key,
                'name' => $set->name,
                'applies_to' => $set->applies_to->value,
                'applies_to_label' => $this->processLabel($set->applies_to),
            ])
            ->values()
            ->all();

        $categories = AdvertisingCategory::query()
            ->orderBy('sort')
            ->orderBy('name')
            ->get()
            ->filter(function (AdvertisingCategory $category) use ($currentCategoryId): bool {
                if ($category->is_active) {
                    return true;
                }

                return $currentCategoryId !== null && (int) $category->id === (int) $currentCategoryId;
            })
            ->map(fn (AdvertisingCategory $category): array => [
                'id' => $category->id,
                'key' => $category->key,
                'name' => $category->name,
                'is_active' => $category->is_active,
                'label' => $category->is_active
                    ? "{$category->name} ({$category->key})"
                    : "{$category->name} ({$category->key}) – deaktiviert",
            ])
            ->values()
            ->all();

        $media = AdvertisingMedium::query()
            ->with('category')
            ->orderBy('name')
            ->orderBy('code')
            ->get()
            ->filter(function (AdvertisingMedium $medium) use ($currentMediumId): bool {
                if ($medium->is_active) {
                    return true;
                }

                return $currentMediumId !== null && (int) $medium->id === (int) $currentMediumId;
            })
            ->map(function (AdvertisingMedium $medium): array {
                $category = $medium->category;
                $categoryName = $category !== null ? $category->name : 'ohne Oberkategorie';
                $suffix = $medium->is_active ? '' : ' – deaktiviert';

                return [
                    'id' => $medium->id,
                    'code' => $medium->code,
                    'name' => $medium->name,
                    'category_id' => $medium->category_id,
                    'category_name' => $category?->name,
                    'is_active' => $medium->is_active,
                    'label' => "{$medium->name} ({$medium->code}) · {$categoryName}{$suffix}",
                ];
            })
            ->values()
            ->all();

        return [
            'fieldSets' => $fieldSets,
            'categories' => $categories,
            'media' => $media,
            'targetLayerOptions' => [
                ['value' => FieldSetAssignmentTargetLayer::Global->value, 'label' => 'Global'],
                ['value' => FieldSetAssignmentTargetLayer::AdvertisingCategory->value, 'label' => 'Oberkategorie'],
                ['value' => FieldSetAssignmentTargetLayer::AdvertisingMedium->value, 'label' => 'Werbemittel'],
            ],
            'processOptions' => [
                ['value' => FieldAppliesTo::Calculation->value, 'label' => 'Kalkulation'],
                ['value' => FieldAppliesTo::DispoOrder->value, 'label' => 'Dispoauftrag'],
                ['value' => FieldAppliesTo::Both->value, 'label' => 'Beide'],
            ],
        ];
    }

    private function catalogNote(): string
    {
        return 'Oberkategorien und Werbemittel werden hier nur als bestehende Auswahlwerte angeboten '
            .'(PO-33b-1). Anlegen, Bearbeiten, Deaktivieren und fachliches Zuordnen bleiben dem '
            .'verbindlich offenen Katalog-Admin vorbehalten.';
    }

    private function targetLabel(FieldSetAssignment $assignment): string
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

    private function targetLayerLabel(FieldSetAssignmentTargetLayer $layer): string
    {
        return match ($layer) {
            FieldSetAssignmentTargetLayer::Global => 'Global',
            FieldSetAssignmentTargetLayer::AdvertisingCategory => 'Oberkategorie',
            FieldSetAssignmentTargetLayer::AdvertisingMedium => 'Werbemittel',
        };
    }

    private function processLabel(FieldAppliesTo $process): string
    {
        return match ($process) {
            FieldAppliesTo::Calculation => 'Kalkulation',
            FieldAppliesTo::DispoOrder => 'Dispoauftrag',
            FieldAppliesTo::Both => 'Beide',
        };
    }

    private function targetIsCurrentlySelectable(FieldSetAssignment $assignment): bool
    {
        return match ($assignment->target_layer) {
            FieldSetAssignmentTargetLayer::Global => true,
            FieldSetAssignmentTargetLayer::AdvertisingCategory => (bool) $assignment->advertisingCategory?->is_active,
            FieldSetAssignmentTargetLayer::AdvertisingMedium => (bool) $assignment->advertisingMedium?->is_active,
        };
    }
}
