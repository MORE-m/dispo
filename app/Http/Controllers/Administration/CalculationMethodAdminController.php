<?php

namespace App\Http\Controllers\Administration;

use App\Http\Controllers\Controller;
use App\Models\AdvertisingCategoryCalculationMethod;
use App\Models\AdvertisingMediumCalculationMethod;
use App\Models\CalculationMethod;
use App\Services\Advertising\Admin\CalculationMethodAdminWriter;
use App\Services\Advertising\Admin\CalculationMethodImpactPreviewService;
use App\Support\Calculation\EngineProfileRegistry;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * ADV-001c3b1: Admin-UI Berechnungsmethoden (systemdefinierte Stammdaten).
 */
class CalculationMethodAdminController extends Controller
{
    public function index(Request $request): Response
    {
        $this->authorize('access-administration');

        $methods = CalculationMethod::query()
            ->orderBy('sort')
            ->orderBy('name')
            ->orderBy('id')
            ->get()
            ->map(fn (CalculationMethod $method): array => $this->serializeListRow($method));

        return Inertia::render('administration/katalog/methods/index', [
            'methods' => $methods,
            'boundaryNote' => 'Berechnungsmethoden sind systemseitig definiert. '
                .'Katalogaktivität und technische Registry-Verfügbarkeit sind getrennt. '
                .'Aktiv bedeutet nicht automatisch buchbar.',
        ]);
    }

    public function show(Request $request, CalculationMethod $method): Response
    {
        $this->authorize('access-administration');

        return Inertia::render('administration/katalog/methods/show', [
            'method' => $this->serializeDetail($method),
            'boundaryNote' => 'Zuordnungen und Defaults werden hier nicht verändert. '
                .'Eine Deaktivierung ist nur ohne aktive Kategorie- oder Mediumzuordnungen möglich.',
            'routes' => [
                'index' => route('administration.catalog.methods.index'),
                'update' => route('administration.catalog.methods.update', $method),
                'deactivatePreview' => route('administration.catalog.methods.deactivate-preview', $method),
                'deactivate' => route('administration.catalog.methods.deactivate', $method),
                'reactivate' => route('administration.catalog.methods.reactivate', $method),
            ],
        ]);
    }

    public function update(
        Request $request,
        CalculationMethod $method,
        CalculationMethodAdminWriter $writer,
    ): RedirectResponse|JsonResponse {
        $this->authorize('access-administration');

        foreach ([
            'key',
            'is_active',
            'engine_profile_key',
            'algorithm_version',
            'registry_status',
            'current_released_version',
            'assignments',
            'default_calculation_method_id',
        ] as $prohibited) {
            if ($request->exists($prohibited)) {
                throw ValidationException::withMessages([
                    $prohibited => 'Das Feld „'.$prohibited.'“ darf über die Metadatenpflege nicht gesetzt werden.',
                ]);
            }
        }

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'help_text' => ['nullable', 'string', 'max:2000'],
            'sort' => ['nullable', 'integer', 'min:0'],
            'lock_version' => ['required', 'integer', 'min:1'],
        ]);

        $updated = $writer->update($method, $validated, $request->user());

        if ($request->expectsJson()) {
            return response()->json([
                'message' => 'Berechnungsmethode gespeichert.',
                'lock_version' => $updated->lock_version,
                'method' => $this->serializeDetail($updated),
            ]);
        }

        return redirect()
            ->route('administration.catalog.methods.show', $updated)
            ->with('success', 'Berechnungsmethode gespeichert.');
    }

    public function deactivatePreview(
        Request $request,
        CalculationMethod $method,
        CalculationMethodImpactPreviewService $impact,
    ): JsonResponse {
        $this->authorize('access-administration');

        return response()->json($impact->previewDeactivate($method));
    }

    public function deactivate(
        Request $request,
        CalculationMethod $method,
        CalculationMethodAdminWriter $writer,
    ): JsonResponse {
        $this->authorize('access-administration');

        $validated = $request->validate([
            'lock_version' => ['required', 'integer', 'min:1'],
            'fingerprint' => ['required', 'string', 'size:64'],
        ]);

        $updated = $writer->deactivate($method, $validated, $request->user());

        return response()->json([
            'message' => 'Berechnungsmethode deaktiviert.',
            'lock_version' => $updated->lock_version,
            'method' => $this->serializeDetail($updated),
        ]);
    }

    public function reactivate(
        Request $request,
        CalculationMethod $method,
        CalculationMethodAdminWriter $writer,
    ): JsonResponse {
        $this->authorize('access-administration');

        $validated = $request->validate([
            'lock_version' => ['required', 'integer', 'min:1'],
        ]);

        $updated = $writer->reactivate($method, $validated, $request->user());

        return response()->json([
            'message' => 'Berechnungsmethode reaktiviert.',
            'lock_version' => $updated->lock_version,
            'method' => $this->serializeDetail($updated),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeListRow(CalculationMethod $method): array
    {
        $pairs = EngineProfileRegistry::pairsForMethodKey($method->key);
        $activeCategoryCount = AdvertisingCategoryCalculationMethod::query()
            ->where('calculation_method_id', $method->id)
            ->where('is_active', true)
            ->count();
        $activeMediumCount = AdvertisingMediumCalculationMethod::query()
            ->where('calculation_method_id', $method->id)
            ->where('is_active', true)
            ->count();

        return [
            'id' => $method->id,
            'key' => $method->key,
            'name' => $method->name,
            'help_text' => $method->help_text,
            'sort' => $method->sort,
            'is_active' => $method->is_active,
            'status_label' => $method->is_active ? 'Aktiv' : 'Inaktiv',
            'registry_pairs' => $pairs,
            'registry_summary' => $this->registrySummary($pairs),
            'active_category_assignments_count' => $activeCategoryCount,
            'active_medium_assignments_count' => $activeMediumCount,
            'lock_version' => $method->lock_version,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeDetail(CalculationMethod $method): array
    {
        $pairs = EngineProfileRegistry::pairsForMethodKey($method->key);
        $impact = app(CalculationMethodImpactPreviewService::class);
        $categoryAssignments = $impact->activeCategoryAssignments((int) $method->id);
        $mediumAssignments = $impact->activeMediumAssignments((int) $method->id);

        return [
            'id' => $method->id,
            'key' => $method->key,
            'name' => $method->name,
            'help_text' => $method->help_text,
            'sort' => $method->sort,
            'is_active' => $method->is_active,
            'status_label' => $method->is_active ? 'Aktiv' : 'Inaktiv',
            'registry_pairs' => $pairs,
            'registry_summary' => $this->registrySummary($pairs),
            'active_category_assignments' => $categoryAssignments,
            'active_medium_assignments' => $mediumAssignments,
            'active_category_assignments_count' => count($categoryAssignments),
            'active_medium_assignments_count' => count($mediumAssignments),
            'lock_version' => $method->lock_version,
        ];
    }

    /**
     * @param  list<array{engine_profile_key: string, pair_status: string, current_released_version: string|null}>  $pairs
     */
    private function registrySummary(array $pairs): string
    {
        if ($pairs === []) {
            return 'Keinem technischen Profil zugeordnet';
        }

        $labels = array_map(
            static function (array $pair): string {
                $version = $pair['current_released_version'] !== null
                    ? ' / '.$pair['current_released_version']
                    : '';

                return $pair['engine_profile_key'].': '.$pair['pair_status'].$version;
            },
            $pairs,
        );

        return implode('; ', $labels);
    }
}
