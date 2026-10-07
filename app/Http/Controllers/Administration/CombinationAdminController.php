<?php

namespace App\Http\Controllers\Administration;

use App\Enums\ComponentCalculationStrategy;
use App\Exceptions\CatalogAdminConflictException;
use App\Http\Controllers\Controller;
use App\Models\AdvertisingCategory;
use App\Models\AdvertisingMedium;
use App\Models\Inventory;
use App\Models\InventoryMediumRule;
use App\Services\InventoryMediumRule\Admin\InventoryMediumRuleAdminWriter;
use App\Support\InventoryMediumRule\InventoryMediumRuleOperativeContract;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * BL-P2-02a / MAT-CORE-1: Kombinationstabellen-Admin (UX-GATE-D Teilfreigabe PO-BLP202A-1).
 */
class CombinationAdminController extends Controller
{
    public function index(Request $request): Response
    {
        $this->authorize('access-administration');

        $status = (string) $request->query('status', 'all');
        if (! in_array($status, ['all', 'active', 'inactive'], true)) {
            $status = 'all';
        }

        $inventoryId = $request->query('inventory_id');
        $inventoryId = is_numeric($inventoryId) ? (int) $inventoryId : null;

        $mediumId = $request->query('advertising_medium_id');
        $mediumId = is_numeric($mediumId) ? (int) $mediumId : null;

        $categoryId = $request->query('category_id');
        $categoryId = is_numeric($categoryId) ? (int) $categoryId : null;

        $planning = InventoryMediumRuleOperativeContract::normalizePlanningKey(
            $request->query('planning_responsibility_key'),
        );
        $booking = InventoryMediumRuleOperativeContract::normalizeBookingCode(
            $request->query('booking_code'),
        );

        $query = InventoryMediumRule::query()
            ->with(['inventory', 'advertisingMedium.category'])
            ->orderBy('sort')
            ->orderBy('id');

        if ($status === 'active') {
            $query->where('is_active', true);
        } elseif ($status === 'inactive') {
            $query->where('is_active', false);
        }
        if ($inventoryId !== null) {
            $query->where('inventory_id', $inventoryId);
        }
        if ($mediumId !== null) {
            $query->where('advertising_medium_id', $mediumId);
        }
        if ($categoryId !== null) {
            $query->whereHas('advertisingMedium', static function ($q) use ($categoryId): void {
                $q->where('category_id', $categoryId);
            });
        }
        if ($planning !== null) {
            $query->where('planning_responsibility_key', $planning);
        }
        if ($booking !== null) {
            $query->where('booking_code', $booking);
        }

        $rules = $query->get();

        return Inertia::render('administration/combinations/index', [
            'filters' => [
                'status' => $status,
                'inventory_id' => $inventoryId,
                'advertising_medium_id' => $mediumId,
                'category_id' => $categoryId,
                'planning_responsibility_key' => $planning,
                'booking_code' => $booking,
            ],
            'filterOptions' => $this->filterOptions(),
            'planningOptions' => InventoryMediumRuleOperativeContract::planningOptions(),
            'rules' => $rules->map(fn (InventoryMediumRule $rule): array => $this->listRow($rule))->all(),
        ]);
    }

    public function create(): Response
    {
        $this->authorize('access-administration');

        return Inertia::render('administration/combinations/create', [
            'filterOptions' => $this->filterOptions(),
            'strategyOptions' => $this->strategyOptions(),
            'planningOptions' => InventoryMediumRuleOperativeContract::planningOptions(),
        ]);
    }

    public function store(Request $request, InventoryMediumRuleAdminWriter $writer): RedirectResponse
    {
        $this->authorize('access-administration');

        $validated = $request->validate([
            'inventory_id' => ['required', 'integer', 'exists:inventories,id'],
            'advertising_medium_id' => ['required', 'integer', 'exists:advertising_media,id'],
            'is_active' => ['sometimes', 'boolean'],
            'booking_code' => ['nullable', 'string', 'max:32'],
            'planning_responsibility_key' => ['nullable', 'string', 'max:64'],
            'hint_text' => ['nullable', 'string', 'max:5000'],
            'sort' => ['sometimes', 'integer', 'min:0', 'max:999999'],
            'default_length_seconds' => ['nullable', 'integer', 'min:1', 'max:3600'],
            'surcharge_percent' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:999.9999'],
            'is_discountable' => ['sometimes', 'boolean'],
            'is_ae_eligible' => ['sometimes', 'boolean'],
            'component_calculation_strategy' => ['sometimes', 'string', Rule::enum(ComponentCalculationStrategy::class)],
        ]);

        $rule = $writer->create($validated, $request->user());

        return redirect()
            ->route('administration.combinations.show', $rule)
            ->with('success', 'Kombination angelegt.');
    }

    public function show(InventoryMediumRule $rule): Response
    {
        $this->authorize('access-administration');
        $rule->loadMissing(['inventory', 'advertisingMedium.category']);

        return Inertia::render('administration/combinations/show', [
            'rule' => $this->detailPayload($rule),
            'filterOptions' => $this->filterOptions(),
            'strategyOptions' => $this->strategyOptions(),
            'planningOptions' => InventoryMediumRuleOperativeContract::planningOptions(),
            'urls' => [
                'index' => route('administration.combinations.index'),
                'update' => route('administration.combinations.update', $rule),
                'deactivate' => route('administration.combinations.deactivate', $rule),
                'reactivate' => route('administration.combinations.reactivate', $rule),
            ],
        ]);
    }

    public function update(
        Request $request,
        InventoryMediumRule $rule,
        InventoryMediumRuleAdminWriter $writer,
    ): RedirectResponse {
        $this->authorize('access-administration');

        $validated = $request->validate([
            'lock_version' => ['required', 'integer', 'min:1'],
            'booking_code' => ['nullable', 'string', 'max:32'],
            'planning_responsibility_key' => ['nullable', 'string', 'max:64'],
            'hint_text' => ['nullable', 'string', 'max:5000'],
            'sort' => ['sometimes', 'integer', 'min:0', 'max:999999'],
            'default_length_seconds' => ['nullable', 'integer', 'min:1', 'max:3600'],
            'surcharge_percent' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:999.9999'],
            'is_discountable' => ['sometimes', 'boolean'],
            'is_ae_eligible' => ['sometimes', 'boolean'],
            'component_calculation_strategy' => ['sometimes', 'string', Rule::enum(ComponentCalculationStrategy::class)],
        ]);

        try {
            $updated = $writer->update($rule, $validated, $request->user());
        } catch (CatalogAdminConflictException $exception) {
            return redirect()
                ->route('administration.combinations.show', $rule)
                ->with('error', $exception->getMessage());
        }

        return redirect()
            ->route('administration.combinations.show', $updated)
            ->with('success', 'Kombination gespeichert.');
    }

    public function deactivate(
        Request $request,
        InventoryMediumRule $rule,
        InventoryMediumRuleAdminWriter $writer,
    ): RedirectResponse {
        $this->authorize('access-administration');
        $validated = $request->validate([
            'lock_version' => ['required', 'integer', 'min:1'],
        ]);

        try {
            $updated = $writer->deactivate($rule, $validated, $request->user());
        } catch (CatalogAdminConflictException $exception) {
            return redirect()
                ->route('administration.combinations.show', $rule)
                ->with('error', $exception->getMessage());
        }

        return redirect()
            ->route('administration.combinations.show', $updated)
            ->with('success', 'Kombination deaktiviert.');
    }

    public function reactivate(
        Request $request,
        InventoryMediumRule $rule,
        InventoryMediumRuleAdminWriter $writer,
    ): RedirectResponse {
        $this->authorize('access-administration');
        $validated = $request->validate([
            'lock_version' => ['required', 'integer', 'min:1'],
        ]);

        try {
            $updated = $writer->reactivate($rule, $validated, $request->user());
        } catch (CatalogAdminConflictException $exception) {
            return redirect()
                ->route('administration.combinations.show', $rule)
                ->with('error', $exception->getMessage());
        }

        return redirect()
            ->route('administration.combinations.show', $updated)
            ->with('success', 'Kombination reaktiviert.');
    }

    /**
     * @return array<string, mixed>
     */
    private function listRow(InventoryMediumRule $rule): array
    {
        $planningKey = $rule->planning_responsibility_key;
        $inventory = $rule->inventory;
        $medium = $rule->advertisingMedium;

        return [
            'id' => $rule->id,
            'inventory_id' => $rule->inventory_id,
            'inventory_name' => $inventory !== null ? $inventory->name : '—',
            'inventory_code' => $inventory?->code,
            'advertising_medium_id' => $rule->advertising_medium_id,
            'advertising_medium_name' => $medium !== null ? $medium->name : '—',
            'advertising_medium_code' => $medium?->code,
            'category_id' => $medium?->category_id,
            'category_name' => $medium?->category?->name,
            'is_active' => (bool) $rule->is_active,
            'status_label' => $rule->is_active ? 'Aktiv' : 'Inaktiv',
            'booking_code' => $rule->booking_code,
            'planning_responsibility_key' => $planningKey,
            'planning_responsibility_label' => $planningKey !== null
                ? InventoryMediumRuleOperativeContract::planningLabel($planningKey)
                : null,
            'hint_text' => $rule->hint_text,
            'sort' => (int) $rule->sort,
            'is_operative_complete' => InventoryMediumRuleOperativeContract::normalizeBookingCode($rule->booking_code) !== null
                && InventoryMediumRuleOperativeContract::normalizePlanningKey($rule->planning_responsibility_key) !== null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function detailPayload(InventoryMediumRule $rule): array
    {
        $row = $this->listRow($rule);

        return array_merge($row, [
            'default_length_seconds' => $rule->default_length_seconds,
            'surcharge_percent' => $rule->surcharge_percent === null ? null : (string) $rule->surcharge_percent,
            'is_discountable' => (bool) $rule->is_discountable,
            'is_ae_eligible' => (bool) $rule->is_ae_eligible,
            'component_calculation_strategy' => $rule->component_calculation_strategy->value,
            'component_calculation_strategy_label' => $rule->component_calculation_strategy->label(),
            'lock_version' => (int) $rule->lock_version,
            'must_not_plan' => InventoryMediumRuleOperativeContract::isMustNotPlan(
                $rule->planning_responsibility_key,
            ),
        ]);
    }

    /**
     * @return array{
     *     inventories: list<array{id: int, name: string, code: string}>,
     *     media: list<array{id: int, name: string, code: string, category_id: int}>,
     *     categories: list<array{id: int, name: string, key: string}>,
     *     booking_codes: list<string>
     * }
     */
    private function filterOptions(): array
    {
        $bookingCodes = [];
        foreach (
            InventoryMediumRule::query()
                ->whereNotNull('booking_code')
                ->where('booking_code', '!=', '')
                ->distinct()
                ->orderBy('booking_code')
                ->pluck('booking_code') as $code
        ) {
            $bookingCodes[] = (string) $code;
        }

        $inventories = [];
        foreach (
            Inventory::query()
                ->orderBy('sort')
                ->orderBy('name')
                ->get(['id', 'name', 'code']) as $inventory
        ) {
            $inventories[] = [
                'id' => $inventory->id,
                'name' => $inventory->name,
                'code' => $inventory->code,
            ];
        }

        $media = [];
        foreach (
            AdvertisingMedium::query()
                ->orderBy('name')
                ->get(['id', 'name', 'code', 'category_id']) as $medium
        ) {
            $media[] = [
                'id' => $medium->id,
                'name' => $medium->name,
                'code' => $medium->code,
                'category_id' => (int) $medium->category_id,
            ];
        }

        $categories = [];
        foreach (
            AdvertisingCategory::query()
                ->orderBy('sort')
                ->orderBy('name')
                ->get(['id', 'name', 'key']) as $category
        ) {
            $categories[] = [
                'id' => $category->id,
                'name' => $category->name,
                'key' => $category->key,
            ];
        }

        return [
            'inventories' => $inventories,
            'media' => $media,
            'categories' => $categories,
            'booking_codes' => $bookingCodes,
        ];
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    private function strategyOptions(): array
    {
        $options = [];
        foreach (ComponentCalculationStrategy::cases() as $strategy) {
            $options[] = [
                'value' => $strategy->value,
                'label' => $strategy->label(),
            ];
        }

        return $options;
    }
}
