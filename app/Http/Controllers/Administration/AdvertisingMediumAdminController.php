<?php

namespace App\Http\Controllers\Administration;

use App\Enums\EngineCapabilityStatus;
use App\Enums\FieldSetAssignmentTargetLayer;
use App\Http\Controllers\Controller;
use App\Models\AdvertisingCategory;
use App\Models\AdvertisingCategoryCalculationMethod;
use App\Models\AdvertisingMedium;
use App\Models\AdvertisingMediumCalculationMethod;
use App\Models\CalculationMethod;
use App\Models\CalculationPosition;
use App\Models\DispoOrderPosition;
use App\Models\FieldSetAssignment;
use App\Models\InventoryMediumRule;
use App\Services\Advertising\Admin\AdvertisingMediumAdminWriter;
use App\Services\Advertising\Admin\AdvertisingMediumCalculationMethodAdminWriter;
use App\Services\Advertising\Admin\AdvertisingMediumCalculationMethodImpactPreviewService;
use App\Services\Advertising\Admin\CatalogImpactPreviewService;
use App\Support\Advertising\AdvertisingMediumLiveBookability;
use App\Support\Calculation\EngineProfileRegistry;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use InvalidArgumentException;

/**
 * ADV-001b / ADV-001c3a: Admin-UI Werbemittel (engine-unabhängig).
 */
class AdvertisingMediumAdminController extends Controller
{
    public function __construct(
        private readonly AdvertisingMediumLiveBookability $liveBookability = new AdvertisingMediumLiveBookability,
    ) {}

    public function index(Request $request): Response
    {
        $this->authorize('access-administration');

        $categoryFilter = $request->query('category_id');
        $activeFilter = $request->query('is_active');

        $query = AdvertisingMedium::query()->with([
            'category.defaultCalculationMethod',
            'category.calculationMethodAssignments.calculationMethod',
            'defaultCalculationMethod',
            'calculationMethodAssignments.calculationMethod',
        ]);

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
            'catalogNote' => 'Das Werbemittel kann im Katalog gepflegt werden. Die Buchbarkeit hängt von freigegebenen Berechnungsmethoden, Inventarregeln und Preislisten ab.',
        ]);
    }

    public function store(Request $request, AdvertisingMediumAdminWriter $writer): RedirectResponse
    {
        $this->authorize('access-administration');

        if ($request->exists('kind')) {
            throw ValidationException::withMessages([
                'kind' => 'Das Feld „kind“ darf über die Admin-Pflege nicht gesetzt oder geändert werden.',
            ]);
        }

        $validated = $request->validate([
            'code' => ['required', 'string', 'max:64'],
            'name' => ['required', 'string', 'max:255'],
            'kind' => ['prohibited'],
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

        $medium->load([
            'category.defaultCalculationMethod',
            'category.calculationMethodAssignments.calculationMethod',
            'defaultCalculationMethod',
            'calculationMethodAssignments.calculationMethod',
        ]);

        return Inertia::render('administration/katalog/media/show', [
            'medium' => $this->serializeDetail($medium, $writer),
            'calculationMethods' => $this->serializeMediumCalculationMethods($medium),
            'categoryCalculationMethods' => $this->serializeCategoryReferenceMethods($medium),
            'methodsBoundaryNote' => 'Desired State für Vererbungsmodus, gespeicherte Medium-Overrides und Medium-Default. '
                .'Wirksame Konfiguration und gespeicherte Override-Daten sind getrennt. '
                .'engine_profile_key wird nicht aus dem Admin gesetzt. Preview vor Apply; kein Force.',
            'formOptions' => $this->formOptions(),
            'catalogNote' => 'Katalogaktivität und technische Buchbarkeit für neue Kalkulationen sind getrennt. Legacy-kind ist kein Adminfeld.',
            'routes' => [
                'index' => route('administration.catalog.media.index'),
                'update' => route('administration.catalog.media.update', $medium),
                'deactivatePreview' => route('administration.catalog.media.deactivate-preview', $medium),
                'deactivate' => route('administration.catalog.media.deactivate', $medium),
                'reactivate' => route('administration.catalog.media.reactivate', $medium),
                'categoryChangePreview' => route('administration.catalog.media.category-change-preview', $medium),
                'categoryChange' => route('administration.catalog.media.category-change', $medium),
                'calculationMethodsPreview' => route('administration.catalog.media.calculation-methods-preview', $medium),
                'calculationMethodsReplace' => route('administration.catalog.media.calculation-methods', $medium),
                'categoryShow' => $medium->category !== null
                    ? route('administration.catalog.categories.show', $medium->category)
                    : null,
            ],
        ]);
    }

    public function calculationMethodsPreview(
        Request $request,
        AdvertisingMedium $medium,
        AdvertisingMediumCalculationMethodImpactPreviewService $impact,
    ): JsonResponse {
        $this->authorize('access-administration');

        if ($request->exists('fingerprint')) {
            throw ValidationException::withMessages([
                'fingerprint' => 'Die Vorschau erwartet keinen Fingerprint.',
            ]);
        }

        $payload = $this->validatedCalculationMethodsPayload($request, requireFingerprint: false);

        return response()->json($impact->preview($medium, $payload));
    }

    public function calculationMethodsReplace(
        Request $request,
        AdvertisingMedium $medium,
        AdvertisingMediumCalculationMethodAdminWriter $writer,
        AdvertisingMediumAdminWriter $mediumWriter,
    ): JsonResponse {
        $this->authorize('access-administration');

        $payload = $this->validatedCalculationMethodsPayload($request, requireFingerprint: true);
        $result = $writer->replace($medium, $payload, $request->user());
        $updated = $result['medium']->load([
            'category.defaultCalculationMethod',
            'category.calculationMethodAssignments.calculationMethod',
            'defaultCalculationMethod',
            'calculationMethodAssignments.calculationMethod',
        ]);

        return response()->json([
            'message' => $result['message'],
            'has_changes' => $result['has_changes'],
            'lock_version' => $updated->lock_version,
            'calculation_method_mode' => $updated->calculation_method_mode->value,
            'default_calculation_method_id' => $updated->default_calculation_method_id,
            'medium' => $this->serializeDetail($updated, $mediumWriter),
            'calculationMethods' => $this->serializeMediumCalculationMethods($updated),
            'categoryCalculationMethods' => $this->serializeCategoryReferenceMethods($updated),
        ]);
    }

    public function update(
        Request $request,
        AdvertisingMedium $medium,
        AdvertisingMediumAdminWriter $writer,
    ): RedirectResponse|JsonResponse {
        $this->authorize('access-administration');

        if ($request->exists('kind')) {
            throw ValidationException::withMessages([
                'kind' => 'Das Feld „kind“ darf über die Admin-Pflege nicht gesetzt oder geändert werden.',
            ]);
        }

        if ($request->exists('code') && (string) $request->input('code') !== $medium->code) {
            throw ValidationException::withMessages([
                'code' => 'Der technische Code ist nach dem Anlegen unveränderlich (PO-ADV001b-2).',
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
            'kind' => ['prohibited'],
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
                'medium' => $this->serializeDetail($updated->load([
                    'category.defaultCalculationMethod',
                    'category.calculationMethodAssignments.calculationMethod',
                    'defaultCalculationMethod',
                    'calculationMethodAssignments.calculationMethod',
                ]), $writer),
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

        if ($request->exists('kind')) {
            throw ValidationException::withMessages([
                'kind' => 'Das Feld „kind“ darf über die Admin-Pflege nicht gesetzt oder geändert werden.',
            ]);
        }

        $validated = $request->validate([
            'kind' => ['prohibited'],
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

        if ($request->exists('kind')) {
            throw ValidationException::withMessages([
                'kind' => 'Das Feld „kind“ darf über die Admin-Pflege nicht gesetzt oder geändert werden.',
            ]);
        }

        $validated = $request->validate([
            'kind' => ['prohibited'],
            'category_id' => ['required', 'integer', 'min:1'],
            'lock_version' => ['required', 'integer', 'min:1'],
            'fingerprint' => ['required', 'string', 'size:64'],
        ]);

        $updated = $writer->changeCategory($medium, $validated, $request->user());

        return response()->json([
            'message' => 'Oberkategorie gewechselt.',
            'lock_version' => $updated->lock_version,
            'medium' => $this->serializeDetail($updated->load([
                'category.defaultCalculationMethod',
                'category.calculationMethodAssignments.calculationMethod',
                'defaultCalculationMethod',
                'calculationMethodAssignments.calculationMethod',
            ]), $writer),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function formOptions(): array
    {
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
            ]);

        return [
            'categories' => $categories,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeListRow(AdvertisingMedium $medium): array
    {
        $bookability = $this->liveBookability->payloadForMedium($medium);

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
            'is_bookable_for_new_positions' => $bookability['is_bookable_for_new_positions'],
            'unbookable_reason' => $bookability['unbookable_reason'],
            'bookability_label' => $bookability['is_bookable_for_new_positions']
                ? 'Technisch verfügbar'
                : 'Noch nicht technisch verfügbar',
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
        $medium->loadMissing([
            'category.defaultCalculationMethod',
            'category.calculationMethodAssignments.calculationMethod',
            'defaultCalculationMethod',
            'calculationMethodAssignments.calculationMethod',
        ]);
        $bookability = $this->liveBookability->payloadForMedium($medium);

        return [
            'id' => $medium->id,
            'name' => $medium->name,
            'code' => $medium->code,
            'kind' => $medium->kind?->value,
            'category_id' => $medium->category_id,
            'category_name' => $medium->category?->name,
            'category_key' => $medium->category?->key,
            'category_is_active' => (bool) $medium->category?->is_active,
            'calculation_method_mode' => $medium->calculation_method_mode->value,
            'default_calculation_method_id' => $medium->default_calculation_method_id !== null
                ? (int) $medium->default_calculation_method_id
                : null,
            'category_default_calculation_method_id' => $medium->category?->default_calculation_method_id !== null
                ? (int) $medium->category->default_calculation_method_id
                : null,
            'effective_source' => $this->liveBookability->configurationSource($medium),
            'default_length_seconds' => $medium->default_length_seconds,
            'is_discountable' => $medium->is_discountable,
            'is_ae_eligible' => $medium->is_ae_eligible,
            'sort' => $medium->sort,
            'is_active' => $medium->is_active,
            'status_label' => $medium->is_active ? 'Aktiv' : 'Inaktiv',
            'is_bookable_for_new_positions' => $bookability['is_bookable_for_new_positions'],
            'unbookable_reason' => $bookability['unbookable_reason'],
            'bookability_label' => $bookability['is_bookable_for_new_positions']
                ? 'Technisch verfügbar'
                : 'Noch nicht technisch verfügbar',
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

    /**
     * @return array<string, mixed>
     */
    private function validatedCalculationMethodsPayload(Request $request, bool $requireFingerprint): array
    {
        foreach (AdvertisingMediumCalculationMethodImpactPreviewService::PROHIBITED_TOP_LEVEL as $prohibited) {
            if ($request->exists($prohibited)) {
                throw ValidationException::withMessages([
                    $prohibited => 'Das Feld „'.$prohibited.'“ darf über die Methodenkonfiguration nicht gesetzt werden.',
                ]);
            }
        }

        $assignmentsInput = $request->input('assignments');
        if (is_array($assignmentsInput)) {
            foreach ($assignmentsInput as $index => $row) {
                if (! is_array($row)) {
                    continue;
                }
                foreach (array_keys($row) as $key) {
                    if (! in_array((string) $key, AdvertisingMediumCalculationMethodImpactPreviewService::ALLOWED_ASSIGNMENT_KEYS, true)) {
                        throw ValidationException::withMessages([
                            "assignments.{$index}.{$key}" => 'Das Feld „'.$key.'“ darf in Methodenzuordnungen nicht gesetzt werden.',
                        ]);
                    }
                }
            }
        }

        $rules = [
            'lock_version' => ['required', 'integer', 'min:1'],
            'calculation_method_mode' => ['required', 'string', 'in:inherit,override'],
            'default_calculation_method_id' => ['nullable', 'integer', 'exists:calculation_methods,id'],
            'assignments' => ['present', 'array'],
            'assignments.*.calculation_method_id' => ['required', 'integer', 'exists:calculation_methods,id'],
            'assignments.*.is_active' => ['required', 'boolean'],
            'assignments.*.sort' => ['required', 'integer', 'min:0'],
        ];
        if ($requireFingerprint) {
            $rules['fingerprint'] = ['required', 'string', 'size:64', 'regex:/^[a-f0-9]{64}$/'];
        }

        $validated = $request->validate($rules);

        $assignments = [];
        foreach (array_values($validated['assignments']) as $row) {
            $assignments[] = [
                'calculation_method_id' => (int) $row['calculation_method_id'],
                'is_active' => filter_var($row['is_active'], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? (bool) $row['is_active'],
                'sort' => (int) $row['sort'],
            ];
        }

        $payload = [
            'lock_version' => (int) $validated['lock_version'],
            'calculation_method_mode' => (string) $validated['calculation_method_mode'],
            'default_calculation_method_id' => $validated['default_calculation_method_id'] ?? null,
            'assignments' => $assignments,
        ];
        if ($requireFingerprint) {
            $payload['fingerprint'] = (string) $validated['fingerprint'];
        }

        return $payload;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function serializeMediumCalculationMethods(AdvertisingMedium $medium): array
    {
        $medium->loadMissing(['calculationMethodAssignments', 'defaultCalculationMethod']);

        /** @var Collection<int, AdvertisingMediumCalculationMethod> $byMethod */
        $byMethod = $medium->calculationMethodAssignments
            ->keyBy(static fn (AdvertisingMediumCalculationMethod $row): int => (int) $row->calculation_method_id);

        $methods = CalculationMethod::query()
            ->orderBy('sort')
            ->orderBy('id')
            ->get();

        $rows = $methods->map(function (CalculationMethod $method) use ($byMethod, $medium): array {
            /** @var AdvertisingMediumCalculationMethod|null $assignment */
            $assignment = $byMethod->get((int) $method->id);
            $pairs = EngineProfileRegistry::pairsForMethodKey((string) $method->key);
            $profile = $assignment?->engine_profile_key;
            $technicalStatus = $this->technicalStatus($method, $assignment, $pairs);
            $defaultEligible = $this->isOverrideDefaultEligible($method, $assignment);
            $operative = $medium->calculation_method_mode->value === 'override';

            return [
                'calculation_method_id' => (int) $method->id,
                'key' => (string) $method->key,
                'name' => (string) $method->name,
                'method_is_active' => (bool) $method->is_active,
                'method_status_label' => $method->is_active ? 'Aktiv' : 'Inaktiv',
                'assigned' => $assignment !== null,
                'is_active' => $assignment !== null ? (bool) $assignment->is_active : false,
                'sort' => $assignment !== null ? (int) $assignment->sort : (int) $method->sort,
                'engine_profile_key' => $profile,
                'assignment_lock_version' => $assignment !== null ? (int) $assignment->lock_version : null,
                'registry_pairs' => $pairs,
                'technical_status' => $technicalStatus,
                'technical_status_label' => $this->technicalStatusLabel($technicalStatus),
                'default_eligible' => $defaultEligible,
                'is_medium_default' => $medium->default_calculation_method_id !== null
                    && (int) $medium->default_calculation_method_id === (int) $method->id,
                'is_operative' => $operative && $assignment !== null && (bool) $assignment->is_active,
                'stored_inactive_note' => (! $operative && $assignment !== null)
                    ? 'gespeichert, aktuell nicht wirksam'
                    : null,
                'method_show_url' => route('administration.catalog.methods.show', $method),
            ];
        })->all();

        usort($rows, static function (array $a, array $b): int {
            $bySort = $a['sort'] <=> $b['sort'];
            if ($bySort !== 0) {
                return $bySort;
            }

            return $a['calculation_method_id'] <=> $b['calculation_method_id'];
        });

        return $rows;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function serializeCategoryReferenceMethods(AdvertisingMedium $medium): array
    {
        $category = $medium->category;
        if ($category === null) {
            return [];
        }

        $category->loadMissing(['calculationMethodAssignments', 'defaultCalculationMethod']);

        /** @var Collection<int, AdvertisingCategoryCalculationMethod> $byMethod */
        $byMethod = $category->calculationMethodAssignments
            ->keyBy(static fn (AdvertisingCategoryCalculationMethod $row): int => (int) $row->calculation_method_id);

        $methods = CalculationMethod::query()
            ->orderBy('sort')
            ->orderBy('id')
            ->get();

        $rows = $methods->map(function (CalculationMethod $method) use ($byMethod, $category, $medium): array {
            /** @var AdvertisingCategoryCalculationMethod|null $assignment */
            $assignment = $byMethod->get((int) $method->id);
            $pairs = EngineProfileRegistry::pairsForMethodKey((string) $method->key);
            $profile = $assignment?->engine_profile_key;
            $technicalStatus = $this->technicalStatus($method, $assignment, $pairs);
            $operative = $medium->calculation_method_mode->value === 'inherit';

            return [
                'calculation_method_id' => (int) $method->id,
                'key' => (string) $method->key,
                'name' => (string) $method->name,
                'method_is_active' => (bool) $method->is_active,
                'assigned' => $assignment !== null,
                'is_active' => $assignment !== null ? (bool) $assignment->is_active : false,
                'sort' => $assignment !== null ? (int) $assignment->sort : (int) $method->sort,
                'engine_profile_key' => $profile,
                'registry_pairs' => $pairs,
                'technical_status' => $technicalStatus,
                'technical_status_label' => $this->technicalStatusLabel($technicalStatus),
                'is_category_default' => $category->default_calculation_method_id !== null
                    && (int) $category->default_calculation_method_id === (int) $method->id,
                'is_operative' => $operative && $assignment !== null && (bool) $assignment->is_active,
                'method_show_url' => route('administration.catalog.methods.show', $method),
            ];
        })->filter(static fn (array $row): bool => $row['assigned'])->values()->all();

        usort($rows, static function (array $a, array $b): int {
            $bySort = $a['sort'] <=> $b['sort'];
            if ($bySort !== 0) {
                return $bySort;
            }

            return $a['calculation_method_id'] <=> $b['calculation_method_id'];
        });

        return $rows;
    }

    /**
     * @param  list<array{engine_profile_key: string, pair_status: string, current_released_version: string|null}>  $pairs
     */
    private function technicalStatus(
        CalculationMethod $method,
        AdvertisingCategoryCalculationMethod|AdvertisingMediumCalculationMethod|null $assignment,
        array $pairs,
    ): string {
        if (! $method->is_active) {
            return 'method_inactive';
        }

        $profile = $assignment?->engine_profile_key;
        if ($profile === null || trim((string) $profile) === '') {
            if ($pairs === []) {
                return 'no_profile';
            }

            $hasReleased = false;
            $hasPlanned = false;
            foreach ($pairs as $pair) {
                if ($pair['pair_status'] === EngineCapabilityStatus::Released->value) {
                    $hasReleased = true;
                }
                if ($pair['pair_status'] === EngineCapabilityStatus::Planned->value) {
                    $hasPlanned = true;
                }
            }

            return $hasReleased ? 'released_executable' : ($hasPlanned ? 'planned' : 'no_profile');
        }

        try {
            EngineProfileRegistry::assertKnownProfile((string) $profile);
            EngineProfileRegistry::assertKnownMethodForProfile((string) $profile, (string) $method->key);
        } catch (InvalidArgumentException) {
            return 'invalid_profile';
        }

        $status = EngineProfileRegistry::pairStatus((string) $profile, (string) $method->key);
        $version = EngineProfileRegistry::currentReleasedVersion((string) $profile, (string) $method->key);
        if ($status === EngineCapabilityStatus::Released && $version !== null && trim($version) !== '') {
            return 'released_executable';
        }
        if ($status === EngineCapabilityStatus::Planned) {
            return 'planned';
        }

        return 'invalid_profile';
    }

    private function technicalStatusLabel(string $status): string
    {
        return match ($status) {
            'released_executable' => 'Freigegeben und ausführbar',
            'planned' => 'Geplant',
            'no_profile' => 'Keinem technischen Profil zugeordnet',
            'invalid_profile' => 'Technisch ungültiges Profil',
            'method_inactive' => 'Methode global inaktiv',
            default => $status,
        };
    }

    private function isOverrideDefaultEligible(
        CalculationMethod $method,
        ?AdvertisingMediumCalculationMethod $assignment,
    ): bool {
        if (! $method->is_active || $assignment === null || ! $assignment->is_active) {
            return false;
        }
        $profile = $assignment->engine_profile_key;
        if ($profile === null || trim((string) $profile) === '') {
            return false;
        }
        try {
            EngineProfileRegistry::assertKnownProfile((string) $profile);
            EngineProfileRegistry::assertKnownMethodForProfile((string) $profile, (string) $method->key);
        } catch (InvalidArgumentException) {
            return false;
        }
        $status = EngineProfileRegistry::pairStatus((string) $profile, (string) $method->key);
        $version = EngineProfileRegistry::currentReleasedVersion((string) $profile, (string) $method->key);

        return $status === EngineCapabilityStatus::Released && $version !== null && trim($version) !== '';
    }
}
