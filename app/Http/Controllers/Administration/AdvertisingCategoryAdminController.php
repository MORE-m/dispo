<?php

namespace App\Http\Controllers\Administration;

use App\Enums\EngineCapabilityStatus;
use App\Enums\FieldSetAssignmentTargetLayer;
use App\Http\Controllers\Controller;
use App\Models\AdvertisingCategory;
use App\Models\AdvertisingCategoryCalculationMethod;
use App\Models\CalculationMethod;
use App\Models\FieldSetAssignment;
use App\Services\Advertising\Admin\AdvertisingCategoryAdminWriter;
use App\Services\Advertising\Admin\AdvertisingCategoryCalculationMethodAdminWriter;
use App\Services\Advertising\Admin\AdvertisingCategoryCalculationMethodImpactPreviewService;
use App\Services\Advertising\Admin\CatalogImpactPreviewService;
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
 * ADV-001b / ADV-001c3b2: Admin-UI Oberkategorien inkl. Methoden-Desired-State.
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

        $category->load([
            'advertisingMedia' => fn ($q) => $q->orderBy('sort')->orderBy('name')->orderBy('id'),
            'calculationMethodAssignments',
            'defaultCalculationMethod',
        ]);

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
                'default_calculation_method_id' => $category->default_calculation_method_id,
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
            'calculationMethods' => $this->serializeCategoryCalculationMethods($category),
            'boundaryNote' => 'Berechnungsmethoden werden als vollständiger Desired State gespeichert. '
                .'Aktiv zugeordnet bedeutet nicht automatisch buchbar. '
                .'Technische Profile werden hier nicht gesetzt.',
            'routes' => [
                'index' => route('administration.catalog.categories.index'),
                'update' => route('administration.catalog.categories.update', $category),
                'deactivatePreview' => route('administration.catalog.categories.deactivate-preview', $category),
                'deactivate' => route('administration.catalog.categories.deactivate', $category),
                'reactivate' => route('administration.catalog.categories.reactivate', $category),
                'calculationMethodsPreview' => route(
                    'administration.catalog.categories.calculation-methods-preview',
                    $category,
                ),
                'calculationMethodsReplace' => route(
                    'administration.catalog.categories.calculation-methods',
                    $category,
                ),
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

    public function calculationMethodsPreview(
        Request $request,
        AdvertisingCategory $category,
        AdvertisingCategoryCalculationMethodImpactPreviewService $impact,
    ): JsonResponse {
        $this->authorize('access-administration');

        if ($request->exists('fingerprint')) {
            throw ValidationException::withMessages([
                'fingerprint' => 'Die Vorschau erwartet keinen Fingerprint.',
            ]);
        }

        $payload = $this->validatedCalculationMethodsPayload($request, requireFingerprint: false);

        return response()->json($impact->preview($category, $payload));
    }

    public function calculationMethodsReplace(
        Request $request,
        AdvertisingCategory $category,
        AdvertisingCategoryCalculationMethodAdminWriter $writer,
    ): JsonResponse {
        $this->authorize('access-administration');

        $payload = $this->validatedCalculationMethodsPayload($request, requireFingerprint: true);
        $result = $writer->replace($category, $payload, $request->user());
        $updated = $result['category'];

        return response()->json([
            'message' => $result['message'],
            'has_changes' => $result['has_changes'],
            'lock_version' => $updated->lock_version,
            'default_calculation_method_id' => $updated->default_calculation_method_id,
            'calculationMethods' => $this->serializeCategoryCalculationMethods($updated->fresh([
                'calculationMethodAssignments',
                'defaultCalculationMethod',
            ]) ?? $updated),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function validatedCalculationMethodsPayload(Request $request, bool $requireFingerprint): array
    {
        foreach (AdvertisingCategoryCalculationMethodImpactPreviewService::PROHIBITED_TOP_LEVEL as $prohibited) {
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
                    if (! in_array((string) $key, AdvertisingCategoryCalculationMethodImpactPreviewService::ALLOWED_ASSIGNMENT_KEYS, true)) {
                        throw ValidationException::withMessages([
                            "assignments.{$index}.{$key}" => 'Das Feld „'.$key.'“ darf in Methodenzuordnungen nicht gesetzt werden.',
                        ]);
                    }
                }
            }
        }

        $rules = [
            'lock_version' => ['required', 'integer', 'min:1'],
            'default_calculation_method_id' => ['nullable', 'integer', 'exists:calculation_methods,id'],
            'assignments' => ['required', 'array'],
            'assignments.*.calculation_method_id' => ['required', 'integer', 'exists:calculation_methods,id'],
            'assignments.*.is_active' => ['required', 'boolean'],
            'assignments.*.sort' => ['required', 'integer', 'min:0'],
        ];
        if ($requireFingerprint) {
            $rules['fingerprint'] = ['required', 'string', 'size:64', 'regex:/^[a-f0-9]{64}$/'];
        }

        $validated = $request->validate($rules);

        // Boolean-Cast für JSON/Form-Inputs vereinheitlichen.
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
    private function serializeCategoryCalculationMethods(AdvertisingCategory $category): array
    {
        $category->loadMissing(['calculationMethodAssignments', 'defaultCalculationMethod']);

        /** @var Collection<int, AdvertisingCategoryCalculationMethod> $byMethod */
        $byMethod = $category->calculationMethodAssignments
            ->keyBy(static fn (AdvertisingCategoryCalculationMethod $row): int => (int) $row->calculation_method_id);

        $methods = CalculationMethod::query()
            ->orderBy('sort')
            ->orderBy('id')
            ->get();

        $rows = $methods->map(function (CalculationMethod $method) use ($byMethod, $category): array {
            /** @var AdvertisingCategoryCalculationMethod|null $assignment */
            $assignment = $byMethod->get((int) $method->id);
            $pairs = EngineProfileRegistry::pairsForMethodKey((string) $method->key);
            $profile = $assignment?->engine_profile_key;
            $technicalStatus = $this->technicalStatus($method, $assignment, $pairs);
            $defaultEligible = $this->isDefaultEligible($method, $assignment);

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
                'is_category_default' => $category->default_calculation_method_id !== null
                    && (int) $category->default_calculation_method_id === (int) $method->id,
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
     * @param  list<array{engine_profile_key: string, pair_status: string, current_released_version: string|null}>  $pairs
     */
    private function technicalStatus(
        CalculationMethod $method,
        ?AdvertisingCategoryCalculationMethod $assignment,
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

    private function isDefaultEligible(
        CalculationMethod $method,
        ?AdvertisingCategoryCalculationMethod $assignment,
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
