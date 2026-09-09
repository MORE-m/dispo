<?php

namespace App\Http\Controllers\Administration;

use App\Enums\FieldSetAssignmentTargetLayer;
use App\Http\Controllers\Controller;
use App\Models\AdvertisingCategory;
use App\Models\FieldSetAssignment;
use App\Services\Advertising\Admin\AdvertisingCategoryAdminWriter;
use App\Services\Advertising\Admin\CatalogImpactPreviewService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * ADV-001b: Admin-UI Oberkategorien.
 */
class AdvertisingCategoryAdminController extends Controller
{
    public function index(Request $request): Response
    {
        $this->authorize('access-administration');

        $categories = AdvertisingCategory::query()
            ->withCount([
                'advertisingMedia as media_total_count',
                'advertisingMedia as media_active_count' => fn ($q) => $q->where('is_active', true),
            ])
            ->orderBy('sort')
            ->orderBy('name')
            ->orderBy('id')
            ->get()
            ->map(function (AdvertisingCategory $category): array {
                $assignmentActive = FieldSetAssignment::query()
                    ->where('target_layer', FieldSetAssignmentTargetLayer::AdvertisingCategory->value)
                    ->where('advertising_category_id', $category->id)
                    ->where('is_active', true)
                    ->count();
                $assignmentInactive = FieldSetAssignment::query()
                    ->where('target_layer', FieldSetAssignmentTargetLayer::AdvertisingCategory->value)
                    ->where('advertising_category_id', $category->id)
                    ->where('is_active', false)
                    ->count();

                return [
                    'id' => $category->id,
                    'key' => $category->key,
                    'name' => $category->name,
                    'sort' => $category->sort,
                    'is_active' => $category->is_active,
                    'status_label' => $category->is_active ? 'Aktiv' : 'Inaktiv',
                    'media_total_count' => (int) $category->getAttribute('media_total_count'),
                    'media_active_count' => (int) $category->getAttribute('media_active_count'),
                    'assignments_active_count' => $assignmentActive,
                    'assignments_inactive_count' => $assignmentInactive,
                    'lock_version' => $category->lock_version,
                ];
            });

        return Inertia::render('administration/katalog/categories/index', [
            'categories' => $categories,
        ]);
    }

    public function create(Request $request): Response
    {
        $this->authorize('access-administration');

        return Inertia::render('administration/katalog/categories/create');
    }

    public function store(Request $request, AdvertisingCategoryAdminWriter $writer): RedirectResponse
    {
        $this->authorize('access-administration');

        $validated = $request->validate([
            'key' => ['required', 'string', 'max:64'],
            'name' => ['required', 'string', 'max:255'],
            'sort' => ['nullable', 'integer', 'min:0'],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        $category = $writer->create($validated, $request->user());

        return redirect()
            ->route('administration.catalog.categories.show', $category)
            ->with('success', 'Oberkategorie angelegt.');
    }

    public function show(Request $request, AdvertisingCategory $category): Response
    {
        $this->authorize('access-administration');

        $category->load(['advertisingMedia' => fn ($q) => $q->orderBy('sort')->orderBy('name')->orderBy('id')]);

        $assignmentActive = FieldSetAssignment::query()
            ->where('target_layer', FieldSetAssignmentTargetLayer::AdvertisingCategory->value)
            ->where('advertising_category_id', $category->id)
            ->where('is_active', true)
            ->count();
        $assignmentInactive = FieldSetAssignment::query()
            ->where('target_layer', FieldSetAssignmentTargetLayer::AdvertisingCategory->value)
            ->where('advertising_category_id', $category->id)
            ->where('is_active', false)
            ->count();

        return Inertia::render('administration/katalog/categories/show', [
            'category' => [
                'id' => $category->id,
                'key' => $category->key,
                'name' => $category->name,
                'sort' => $category->sort,
                'is_active' => $category->is_active,
                'status_label' => $category->is_active ? 'Aktiv' : 'Inaktiv',
                'lock_version' => $category->lock_version,
                'media_active_count' => $category->advertisingMedia->where('is_active', true)->count(),
                'media_inactive_count' => $category->advertisingMedia->where('is_active', false)->count(),
                'assignments_active_count' => $assignmentActive,
                'assignments_inactive_count' => $assignmentInactive,
                'media' => $category->advertisingMedia->map(fn ($m) => [
                    'id' => $m->id,
                    'name' => $m->name,
                    'code' => $m->code,
                    'is_active' => $m->is_active,
                    'status_label' => $m->is_active ? 'Aktiv' : 'Inaktiv',
                ])->values()->all(),
            ],
            'routes' => [
                'index' => route('administration.catalog.categories.index'),
                'update' => route('administration.catalog.categories.update', $category),
                'deactivatePreview' => route('administration.catalog.categories.deactivate-preview', $category),
                'deactivate' => route('administration.catalog.categories.deactivate', $category),
                'reactivate' => route('administration.catalog.categories.reactivate', $category),
            ],
        ]);
    }

    public function update(
        Request $request,
        AdvertisingCategory $category,
        AdvertisingCategoryAdminWriter $writer,
    ): RedirectResponse|JsonResponse {
        $this->authorize('access-administration');

        if ($request->exists('key') && (string) $request->input('key') !== $category->key) {
            throw ValidationException::withMessages([
                'key' => 'Der technische Key ist nach dem Anlegen unveränderlich (PO-ADV001b-2).',
            ]);
        }

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'sort' => ['nullable', 'integer', 'min:0'],
            'lock_version' => ['required', 'integer', 'min:1'],
        ]);

        $updated = $writer->update($category, $validated, $request->user());

        if ($request->expectsJson()) {
            return response()->json([
                'message' => 'Oberkategorie gespeichert.',
                'lock_version' => $updated->lock_version,
                'category' => [
                    'id' => $updated->id,
                    'name' => $updated->name,
                    'sort' => $updated->sort,
                    'is_active' => $updated->is_active,
                    'lock_version' => $updated->lock_version,
                ],
            ]);
        }

        return redirect()
            ->route('administration.catalog.categories.show', $updated)
            ->with('success', 'Oberkategorie gespeichert.');
    }

    public function deactivatePreview(
        Request $request,
        AdvertisingCategory $category,
        CatalogImpactPreviewService $impact,
    ): JsonResponse {
        $this->authorize('access-administration');

        return response()->json($impact->previewCategoryDeactivate($category));
    }

    public function deactivate(
        Request $request,
        AdvertisingCategory $category,
        AdvertisingCategoryAdminWriter $writer,
    ): JsonResponse {
        $this->authorize('access-administration');

        $validated = $request->validate([
            'lock_version' => ['required', 'integer', 'min:1'],
            'fingerprint' => ['required', 'string', 'size:64'],
        ]);

        $updated = $writer->deactivate($category, $validated, $request->user());

        return response()->json([
            'message' => 'Oberkategorie deaktiviert.',
            'lock_version' => $updated->lock_version,
            'is_active' => $updated->is_active,
        ]);
    }

    public function reactivate(
        Request $request,
        AdvertisingCategory $category,
        AdvertisingCategoryAdminWriter $writer,
    ): JsonResponse {
        $this->authorize('access-administration');

        $validated = $request->validate([
            'lock_version' => ['required', 'integer', 'min:1'],
        ]);

        $updated = $writer->reactivate($category, $validated, $request->user());

        return response()->json([
            'message' => 'Oberkategorie reaktiviert.',
            'lock_version' => $updated->lock_version,
            'is_active' => $updated->is_active,
        ]);
    }
}
