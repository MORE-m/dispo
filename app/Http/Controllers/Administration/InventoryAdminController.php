<?php

namespace App\Http\Controllers\Administration;

use App\Enums\InventoryType;
use App\Http\Controllers\Controller;
use App\Models\CalculationPosition;
use App\Models\DispoOrderPosition;
use App\Models\Inventory;
use App\Models\InventoryMediumRule;
use App\Models\PriceList;
use App\Services\Inventory\Admin\InventoryAdminWriter;
use App\Services\Inventory\Admin\InventoryImpactPreviewService;
use App\Support\Inventory\InventoryIdentity;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * BL-P2-01a: Inventar-Admin-Lifecycle. Keine Memberships, kein Hard Delete.
 */
class InventoryAdminController extends Controller
{
    public function index(Request $request): Response
    {
        $this->authorize('access-administration');

        $status = (string) $request->query('status', 'all');
        $type = (string) $request->query('type', 'all');
        if (! in_array($status, ['all', 'active', 'inactive'], true)) {
            $status = 'all';
        }
        if (! in_array($type, ['all', InventoryType::Sender->value, InventoryType::Kombi->value], true)) {
            $type = 'all';
        }

        $query = Inventory::query()
            ->orderBy('sort')
            ->orderBy('name')
            ->orderBy('id');

        if ($status === 'active') {
            $query->where('is_active', true);
        } elseif ($status === 'inactive') {
            $query->where('is_active', false);
        }

        if ($type !== 'all') {
            $query->where('type', $type);
        }

        $inventories = $query->get();
        $ids = $inventories->pluck('id')->all();

        $calculationCounts = $ids === []
            ? collect()
            : CalculationPosition::query()
                ->selectRaw('inventory_id, COUNT(*) as aggregate')
                ->whereIn('inventory_id', $ids)
                ->groupBy('inventory_id')
                ->pluck('aggregate', 'inventory_id');
        $dispoCounts = $ids === []
            ? collect()
            : DispoOrderPosition::query()
                ->selectRaw('inventory_id, COUNT(*) as aggregate')
                ->whereIn('inventory_id', $ids)
                ->groupBy('inventory_id')
                ->pluck('aggregate', 'inventory_id');
        $priceListCounts = $ids === []
            ? collect()
            : PriceList::query()
                ->selectRaw('inventory_id, COUNT(*) as aggregate')
                ->whereIn('inventory_id', $ids)
                ->groupBy('inventory_id')
                ->pluck('aggregate', 'inventory_id');
        $ruleCounts = $ids === []
            ? collect()
            : InventoryMediumRule::query()
                ->selectRaw('inventory_id, COUNT(*) as aggregate')
                ->whereIn('inventory_id', $ids)
                ->groupBy('inventory_id')
                ->pluck('aggregate', 'inventory_id');

        return Inertia::render('administration/inventories/index', [
            'filters' => [
                'status' => $status,
                'type' => $type,
            ],
            'inventories' => $inventories->map(function (Inventory $inventory) use (
                $calculationCounts,
                $dispoCounts,
                $priceListCounts,
                $ruleCounts,
            ): array {
                return $this->serializeListRow(
                    $inventory,
                    (int) ($calculationCounts[$inventory->id] ?? 0),
                    (int) ($dispoCounts[$inventory->id] ?? 0),
                    (int) ($priceListCounts[$inventory->id] ?? 0),
                    (int) ($ruleCounts[$inventory->id] ?? 0),
                );
            })->values()->all(),
        ]);
    }

    public function create(Request $request): Response
    {
        $this->authorize('access-administration');

        return Inertia::render('administration/inventories/create', [
            'typeOptions' => $this->typeOptions(),
        ]);
    }

    public function store(Request $request, InventoryAdminWriter $writer): RedirectResponse
    {
        $this->authorize('access-administration');
        $this->rejectServerOwnedFields($request, forCreate: true);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'code' => ['required', 'string', 'max:64'],
            'type' => ['required', 'string', Rule::in([InventoryType::Sender->value, InventoryType::Kombi->value])],
            'sort' => ['nullable', 'integer', 'min:0'],
            'logo_path' => ['nullable', 'string', 'max:255'],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        $inventory = $writer->create($validated, $request->user());

        return redirect()
            ->route('administration.inventories.show', $inventory)
            ->with('success', 'Inventar angelegt.');
    }

    public function show(Request $request, Inventory $inventory): Response
    {
        $this->authorize('access-administration');

        $impact = app(InventoryImpactPreviewService::class)->previewDeactivate($inventory);

        return Inertia::render('administration/inventories/show', [
            'inventory' => $this->serializeDetail($inventory, $impact),
            'typeOptions' => $this->typeOptions(),
            'routes' => [
                'index' => route('administration.inventories.index'),
                'update' => route('administration.inventories.update', $inventory),
                'deactivatePreview' => route('administration.inventories.deactivate-preview', $inventory),
                'deactivate' => route('administration.inventories.deactivate', $inventory),
                'reactivatePreview' => route('administration.inventories.reactivate-preview', $inventory),
                'reactivate' => route('administration.inventories.reactivate', $inventory),
            ],
        ]);
    }

    public function update(
        Request $request,
        Inventory $inventory,
        InventoryAdminWriter $writer,
    ): RedirectResponse|JsonResponse {
        $this->authorize('access-administration');
        $this->rejectServerOwnedFields($request, forCreate: false);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'sort' => ['nullable', 'integer', 'min:0'],
            'logo_path' => ['nullable', 'string', 'max:255'],
            'lock_version' => ['required', 'integer', 'min:1'],
        ]);

        $updated = $writer->update($inventory, $validated, $request->user());

        if ($request->expectsJson()) {
            return response()->json([
                'message' => 'Inventar gespeichert.',
                'lock_version' => $updated->lock_version,
                'inventory' => $this->serializeDetail(
                    $updated,
                    app(InventoryImpactPreviewService::class)->previewDeactivate($updated),
                ),
            ]);
        }

        return redirect()
            ->route('administration.inventories.show', $updated)
            ->with('success', 'Inventar gespeichert.');
    }

    public function deactivatePreview(
        Request $request,
        Inventory $inventory,
        InventoryImpactPreviewService $impact,
    ): JsonResponse {
        $this->authorize('access-administration');

        return response()->json($impact->previewDeactivate($inventory));
    }

    public function deactivate(
        Request $request,
        Inventory $inventory,
        InventoryAdminWriter $writer,
    ): JsonResponse {
        $this->authorize('access-administration');

        $validated = $request->validate([
            'lock_version' => ['required', 'integer', 'min:1'],
            'fingerprint' => ['required', 'string', 'size:64'],
        ]);

        $updated = $writer->deactivate($inventory, $validated, $request->user());

        return response()->json([
            'message' => 'Inventar deaktiviert.',
            'lock_version' => $updated->lock_version,
            'is_active' => $updated->is_active,
        ]);
    }

    public function reactivatePreview(
        Request $request,
        Inventory $inventory,
        InventoryImpactPreviewService $impact,
    ): JsonResponse {
        $this->authorize('access-administration');

        return response()->json($impact->previewReactivate($inventory));
    }

    public function reactivate(
        Request $request,
        Inventory $inventory,
        InventoryAdminWriter $writer,
    ): JsonResponse {
        $this->authorize('access-administration');

        $validated = $request->validate([
            'lock_version' => ['required', 'integer', 'min:1'],
        ]);

        $updated = $writer->reactivate($inventory, $validated, $request->user());

        return response()->json([
            'message' => 'Inventar reaktiviert.',
            'lock_version' => $updated->lock_version,
            'is_active' => $updated->is_active,
        ]);
    }

    /**
     * @param  array<string, mixed>  $impact
     * @return array<string, mixed>
     */
    private function serializeDetail(Inventory $inventory, array $impact): array
    {
        $type = $inventory->type;

        return [
            'id' => $inventory->id,
            'name' => $inventory->name,
            'code' => $inventory->code,
            'type' => $type->value,
            'type_label' => InventoryIdentity::typeLabel($type),
            'sort' => $inventory->sort,
            'is_active' => $inventory->is_active,
            'status_label' => $inventory->is_active ? 'Aktiv' : 'Inaktiv',
            'logo_path' => $inventory->logo_path,
            'has_logo' => $inventory->logo_path !== null && $inventory->logo_path !== '',
            'lock_version' => $inventory->lock_version,
            'membership_note' => $type === InventoryType::Kombi
                ? 'Die Pflege der enthaltenen Sender folgt in einem späteren Slice. Diese Ansicht enthält keine Mitgliedschaftsliste.'
                : null,
            'impact' => [
                'calculation_positions_count' => $impact['calculation_positions_count'] ?? 0,
                'dispo_order_positions_count' => $impact['dispo_order_positions_count'] ?? 0,
                'price_lists_count' => $impact['price_lists_count'] ?? 0,
                'inventory_medium_rules_count' => $impact['inventory_medium_rules_count'] ?? 0,
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeListRow(
        Inventory $inventory,
        int $calculationCount,
        int $dispoCount,
        int $priceListCount,
        int $ruleCount,
    ): array {
        return [
            'id' => $inventory->id,
            'name' => $inventory->name,
            'code' => $inventory->code,
            'type' => $inventory->type->value,
            'type_label' => InventoryIdentity::typeLabel($inventory->type),
            'sort' => $inventory->sort,
            'is_active' => $inventory->is_active,
            'status_label' => $inventory->is_active ? 'Aktiv' : 'Inaktiv',
            'has_logo' => $inventory->logo_path !== null && $inventory->logo_path !== '',
            'logo_path' => $inventory->logo_path,
            'calculation_positions_count' => $calculationCount,
            'dispo_order_positions_count' => $dispoCount,
            'price_lists_count' => $priceListCount,
            'inventory_medium_rules_count' => $ruleCount,
        ];
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    private function typeOptions(): array
    {
        return [
            ['value' => InventoryType::Sender->value, 'label' => InventoryIdentity::typeLabel(InventoryType::Sender)],
            ['value' => InventoryType::Kombi->value, 'label' => InventoryIdentity::typeLabel(InventoryType::Kombi)],
        ];
    }

    private function rejectServerOwnedFields(Request $request, bool $forCreate): void
    {
        if ($request->exists('organization_id')) {
            throw ValidationException::withMessages([
                'organization_id' => 'Die Organisation wird serverseitig gesetzt.',
            ]);
        }

        if ($forCreate) {
            return;
        }

        if ($request->exists('code')) {
            throw ValidationException::withMessages([
                'code' => 'Der Kurzcode ist nach dem Anlegen unveränderlich.',
            ]);
        }

        if ($request->exists('type')) {
            throw ValidationException::withMessages([
                'type' => 'Der Inventartyp ist nach dem Anlegen unveränderlich.',
            ]);
        }

        if ($request->exists('is_active')) {
            throw ValidationException::withMessages([
                'is_active' => 'Der Aktivstatus wird nur über Aktivieren oder Deaktivieren geändert.',
            ]);
        }
    }
}
