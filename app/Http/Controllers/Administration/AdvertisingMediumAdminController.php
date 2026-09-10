<?php

namespace App\Http\Controllers\Administration;

use App\Enums\CalculationKind;
use App\Enums\FieldSetAssignmentTargetLayer;
use App\Http\Controllers\Controller;
use App\Models\AdvertisingCategory;
use App\Models\AdvertisingMedium;
use App\Models\CalculationPosition;
use App\Models\DispoOrderPosition;
use App\Models\FieldSetAssignment;
use App\Models\InventoryMediumRule;
use App\Services\Advertising\Admin\AdvertisingMediumAdminWriter;
use App\Services\Advertising\Admin\CatalogImpactPreviewService;
use App\Support\Advertising\AdvertisingKindCategoryCompatibility;
use App\Support\Advertising\CanonicalAdvertisingCategories;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * ADV-001b: Admin-UI Werbemittel.
 */
class AdvertisingMediumAdminController extends Controller
{
    public function index(Request $request): Response
    {
        $this->authorize('access-administration');

        $categoryFilter = $request->query('category_id');
        $activeFilter = $request->query('is_active');

        $query = AdvertisingMedium::query()->with('category');

        if ($categoryFilter !== null && $categoryFilter !== '') {
            $query->where('category_id', (int) $categoryFilter);
        }

        if ($activeFilter === '1' || $activeFilter === '0') {
            $query->where('is_active', $activeFilter === '1');
        }

        $media = $query
            ->orderBy('sort')
            ->orderBy('name')
            ->orderBy('id')
            ->get()
            ->map(fn (AdvertisingMedium $medium): array => $this->serializeListRow($medium));

        $categories = AdvertisingCategory::query()
            ->orderBy('sort')
            ->orderBy('name')
            ->orderBy('id')
            ->get(['id', 'key', 'name', 'is_active']);

        return Inertia::render('administration/katalog/media/index', [
            'media' => $media,
            'filters' => [
                'category_id' => $categoryFilter !== null && $categoryFilter !== '' ? (int) $categoryFilter : null,
                'is_active' => $activeFilter === '1' || $activeFilter === '0' ? $activeFilter : null,
            ],
            'filterOptions' => [
                'categories' => $categories->map(fn (AdvertisingCategory $c) => [
                    'id' => $c->id,
                    'key' => $c->key,
                    'name' => $c->name,
                    'is_active' => $c->is_active,
                    'label' => $c->is_active ? $c->name : $c->name.' – deaktiviert',
                ]),
            ],
        ]);
    }

    public function create(Request $request): Response
    {
        $this->authorize('access-administration');

        return Inertia::render('administration/katalog/media/create', [
            'formOptions' => $this->formOptions(),
            'kindCompatibilityNote' => 'Aktuell wird nur die Berechnungsart „spot_classic“ unterstützt. Sie darf ausschließlich der Oberkategorie „Spots“ (Key spots) zugeordnet werden (PO-ADV001b-8). Weitere Berechnungsarten folgen in eigenen Slices.',
        ]);
    }

    public function store(Request $request, AdvertisingMediumAdminWriter $writer): RedirectResponse
    {
        $this->authorize('access-administration');

        $validated = $request->validate([
            'code' => ['required', 'string', 'max:64'],
            'name' => ['required', 'string', 'max:255'],
            'kind' => ['required', 'string', Rule::enum(CalculationKind::class)],
            'category_id' => ['required', 'integer', 'min:1'],
            'default_length_seconds' => ['nullable', 'integer', 'min:1', 'max:3600'],
            'is_discountable' => ['sometimes', 'boolean'],
            'is_ae_eligible' => ['sometimes', 'boolean'],
            'sort' => ['nullable', 'integer', 'min:0'],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        $medium = $writer->create($validated, $request->user());

        return redirect()
            ->route('administration.catalog.media.show', $medium)
            ->with('success', 'Werbemittel angelegt.');
    }

    public function show(Request $request, AdvertisingMedium $medium, AdvertisingMediumAdminWriter $writer): Response
    {
        $this->authorize('access-administration');

        $medium->load('category');

        return Inertia::render('administration/katalog/media/show', [
            'medium' => $this->serializeDetail($medium, $writer),
            'formOptions' => $this->formOptions(),
            'kindCompatibilityNote' => 'Aktuell wird nur „spot_classic“ unterstützt und bleibt auf die Oberkategorie „Spots“ begrenzt. Der technische Code ist unveränderlich.',
            'routes' => [
                'index' => route('administration.catalog.media.index'),
                'update' => route('administration.catalog.media.update', $medium),
                'deactivatePreview' => route('administration.catalog.media.deactivate-preview', $medium),
                'deactivate' => route('administration.catalog.media.deactivate', $medium),
                'reactivate' => route('administration.catalog.media.reactivate', $medium),
                'categoryChangePreview' => route('administration.catalog.media.category-change-preview', $medium),
                'categoryChange' => route('administration.catalog.media.category-change', $medium),
            ],
        ]);
    }

    public function update(
        Request $request,
        AdvertisingMedium $medium,
        AdvertisingMediumAdminWriter $writer,
    ): RedirectResponse|JsonResponse {
        $this->authorize('access-administration');

        if ($request->exists('code') && (string) $request->input('code') !== $medium->code) {
            throw ValidationException::withMessages([
                'code' => 'Der technische Code ist nach dem Anlegen unveränderlich (PO-ADV001b-2).',
            ]);
        }

        $currentKind = (string) ($medium->getAttributes()['kind'] ?? '');
        if ($request->exists('kind') && (string) $request->input('kind') !== $currentKind) {
            throw ValidationException::withMessages([
                'kind' => 'Die Berechnungsart kann über diese Aktion nicht geändert werden.',
            ]);
        }

        if ($request->exists('category_id')
            && (int) $request->input('category_id') !== (int) $medium->category_id) {
            throw ValidationException::withMessages([
                'category_id' => 'Der Kategoriewechsel ist nur über die dedizierte Vorschau-Aktion erlaubt.',
            ]);
        }

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'default_length_seconds' => ['nullable', 'integer', 'min:1', 'max:3600'],
            'is_discountable' => ['sometimes', 'boolean'],
            'is_ae_eligible' => ['sometimes', 'boolean'],
            'sort' => ['nullable', 'integer', 'min:0'],
            'lock_version' => ['required', 'integer', 'min:1'],
        ]);

        $updated = $writer->update($medium, $validated, $request->user());

        if ($request->expectsJson()) {
            return response()->json([
                'message' => 'Werbemittel gespeichert.',
                'lock_version' => $updated->lock_version,
                'medium' => $this->serializeDetail($updated, $writer),
            ]);
        }

        return redirect()
            ->route('administration.catalog.media.show', $updated)
            ->with('success', 'Werbemittel gespeichert.');
    }

    public function deactivatePreview(
        Request $request,
        AdvertisingMedium $medium,
        CatalogImpactPreviewService $impact,
    ): JsonResponse {
        $this->authorize('access-administration');

        return response()->json($impact->previewMediumDeactivate($medium));
    }

    public function deactivate(
        Request $request,
        AdvertisingMedium $medium,
        AdvertisingMediumAdminWriter $writer,
    ): JsonResponse {
        $this->authorize('access-administration');

        $validated = $request->validate([
            'lock_version' => ['required', 'integer', 'min:1'],
            'fingerprint' => ['required', 'string', 'size:64'],
        ]);

        $updated = $writer->deactivate($medium, $validated, $request->user());

        return response()->json([
            'message' => 'Werbemittel deaktiviert.',
            'lock_version' => $updated->lock_version,
            'is_active' => $updated->is_active,
        ]);
    }

    public function reactivate(
        Request $request,
        AdvertisingMedium $medium,
        AdvertisingMediumAdminWriter $writer,
    ): JsonResponse {
        $this->authorize('access-administration');

        $validated = $request->validate([
            'lock_version' => ['required', 'integer', 'min:1'],
        ]);

        $updated = $writer->reactivate($medium, $validated, $request->user());

        return response()->json([
            'message' => 'Werbemittel reaktiviert.',
            'lock_version' => $updated->lock_version,
            'is_active' => $updated->is_active,
        ]);
    }

    public function categoryChangePreview(
        Request $request,
        AdvertisingMedium $medium,
        CatalogImpactPreviewService $impact,
    ): JsonResponse {
        $this->authorize('access-administration');

        $validated = $request->validate([
            'category_id' => ['required', 'integer', 'min:1'],
        ]);

        return response()->json($impact->previewMediumCategoryChange($medium, [
            'target_category_id' => (int) $validated['category_id'],
        ]));
    }

    public function changeCategory(
        Request $request,
        AdvertisingMedium $medium,
        AdvertisingMediumAdminWriter $writer,
    ): JsonResponse {
        $this->authorize('access-administration');

        $validated = $request->validate([
            'category_id' => ['required', 'integer', 'min:1'],
            'lock_version' => ['required', 'integer', 'min:1'],
            'fingerprint' => ['required', 'string', 'size:64'],
        ]);

        $updated = $writer->changeCategory($medium, $validated, $request->user());

        return response()->json([
            'message' => 'Oberkategorie gewechselt.',
            'lock_version' => $updated->lock_version,
            'medium' => $this->serializeDetail($updated, $writer),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function formOptions(): array
    {
        $kinds = collect(CalculationKind::cases())->map(fn (CalculationKind $kind) => [
            'value' => $kind->value,
            'label' => $kind->value,
            'allowed_category_keys' => AdvertisingKindCategoryCompatibility::allowedCategoryKeysFor($kind),
        ]);

        $categories = AdvertisingCategory::query()
            ->where('is_active', true)
            ->orderBy('sort')
            ->orderBy('name')
            ->orderBy('id')
            ->get()
            ->map(fn (AdvertisingCategory $c) => [
                'id' => $c->id,
                'key' => $c->key,
                'name' => $c->name,
                'compatible_with_spot_classic' => $c->key === CanonicalAdvertisingCategories::SPOTS,
            ]);

        // Für Kategoriewechsel-Vorschau auch inaktive Ziele der aktuellen Zuordnung lesbar halten:
        // formOptions.categories bleibt auf aktive beschränkt; Show lädt ggf. aktuelle separat.

        return [
            'kinds' => $kinds,
            'categories' => $categories,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeListRow(AdvertisingMedium $medium): array
    {
        return [
            'id' => $medium->id,
            'name' => $medium->name,
            'code' => $medium->code,
            'kind' => $medium->kind?->value,
            'category_id' => $medium->category_id,
            'category_name' => $medium->category?->name,
            'category_key' => $medium->category?->key,
            'is_active' => $medium->is_active,
            'status_label' => $medium->is_active ? 'Aktiv' : 'Inaktiv',
            'is_discountable' => $medium->is_discountable,
            'is_ae_eligible' => $medium->is_ae_eligible,
            'default_length_seconds' => $medium->default_length_seconds,
            'sort' => $medium->sort,
            'calculation_positions_count' => CalculationPosition::query()
                ->where('advertising_medium_id', $medium->id)
                ->count(),
            'assignments_active_count' => FieldSetAssignment::query()
                ->where('target_layer', FieldSetAssignmentTargetLayer::AdvertisingMedium->value)
                ->where('advertising_medium_id', $medium->id)
                ->where('is_active', true)
                ->count(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeDetail(AdvertisingMedium $medium, AdvertisingMediumAdminWriter $writer): array
    {
        $medium->loadMissing('category');

        return [
            'id' => $medium->id,
            'name' => $medium->name,
            'code' => $medium->code,
            'kind' => $medium->kind?->value,
            'category_id' => $medium->category_id,
            'category_name' => $medium->category?->name,
            'category_key' => $medium->category?->key,
            'category_is_active' => (bool) $medium->category?->is_active,
            'default_length_seconds' => $medium->default_length_seconds,
            'is_discountable' => $medium->is_discountable,
            'is_ae_eligible' => $medium->is_ae_eligible,
            'sort' => $medium->sort,
            'is_active' => $medium->is_active,
            'status_label' => $medium->is_active ? 'Aktiv' : 'Inaktiv',
            'lock_version' => $medium->lock_version,
            'has_position_reference' => $writer->hasPositionReference($medium),
            'calculation_positions_count' => CalculationPosition::query()
                ->where('advertising_medium_id', $medium->id)
                ->count(),
            'dispo_order_positions_count' => DispoOrderPosition::query()
                ->where('advertising_medium_id', $medium->id)
                ->count(),
            'inventory_medium_rules_count' => InventoryMediumRule::query()
                ->where('advertising_medium_id', $medium->id)
                ->count(),
            'assignments_active_count' => FieldSetAssignment::query()
                ->where('target_layer', FieldSetAssignmentTargetLayer::AdvertisingMedium->value)
                ->where('advertising_medium_id', $medium->id)
                ->where('is_active', true)
                ->count(),
            'assignments_inactive_count' => FieldSetAssignment::query()
                ->where('target_layer', FieldSetAssignmentTargetLayer::AdvertisingMedium->value)
                ->where('advertising_medium_id', $medium->id)
                ->where('is_active', false)
                ->count(),
        ];
    }
}
