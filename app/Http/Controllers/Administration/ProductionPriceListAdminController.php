<?php

namespace App\Http\Controllers\Administration;

use App\Enums\PriceListStatus;
use App\Enums\ProductionType;
use App\Exceptions\PriceListAdminConflictException;
use App\Http\Controllers\Controller;
use App\Models\Inventory;
use App\Models\ProductionPriceList;
use App\Models\User;
use App\Services\ProductionPrice\Admin\ProductionPriceListAdminWriter;
use App\Support\Inventory\InventoryIdentity;
use App\Support\PriceList\PriceListCalendar;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * BL-P5-02a: Admin für inventarspezifische Produktionspreise (Spotproduktion). Kein Hard Delete.
 */
class ProductionPriceListAdminController extends Controller
{
    public function index(Request $request): Response
    {
        $this->authorize('access-administration');

        $status = (string) $request->query('status', 'all');
        $year = $request->query('year');
        $inventoryId = $request->query('inventory_id');

        if (! in_array($status, ['all', ...array_column(PriceListStatus::cases(), 'value')], true)) {
            $status = 'all';
        }

        $query = ProductionPriceList::query()
            ->with('inventory')
            ->orderByDesc('year')
            ->orderBy('inventory_id')
            ->orderByDesc('revision_number')
            ->orderBy('id');

        if ($status !== 'all') {
            $query->where('status', $status);
        }
        if (is_numeric($year)) {
            $query->where('year', (int) $year);
        }
        if (is_numeric($inventoryId)) {
            $query->where('inventory_id', (int) $inventoryId);
        }

        return Inertia::render('administration/production-prices/index', [
            'filters' => [
                'status' => $status,
                'year' => is_numeric($year) ? (int) $year : null,
                'inventory_id' => is_numeric($inventoryId) ? (int) $inventoryId : null,
            ],
            'inventories' => $this->inventoryOptions(),
            'productionPriceLists' => $query->get()
                ->map(fn (ProductionPriceList $list): array => $this->serialize($list))
                ->values()
                ->all(),
            'currentYear' => PriceListCalendar::currentYear(),
        ]);
    }

    public function create(Request $request): Response
    {
        $this->authorize('access-administration');

        return Inertia::render('administration/production-prices/create', [
            'inventories' => $this->inventoryOptions(),
            'currentYear' => PriceListCalendar::currentYear(),
            'productionTypes' => $this->productionTypeOptions(),
        ]);
    }

    public function store(Request $request, ProductionPriceListAdminWriter $writer): RedirectResponse
    {
        $this->authorize('access-administration');
        $this->rejectServerOwnedFields($request, forCreate: true);

        $validated = $request->validate([
            'inventory_id' => ['required', 'integer', 'exists:inventories,id'],
            'production_type' => ['sometimes', 'string', Rule::in(array_column(ProductionType::cases(), 'value'))],
            'year' => ['required', 'integer', 'min:2000', 'max:2100'],
            'name' => ['required', 'string', 'max:255'],
            'unit_price' => ['required', 'regex:/^\d{1,12}([.,]\d{1,2})?$/'],
            'is_discountable' => ['sometimes', 'boolean'],
            'is_ae_eligible' => ['sometimes', 'boolean'],
        ], [
            'unit_price.regex' => 'Der Produktionspreis muss ein nicht-negativer Betrag mit höchstens zwei Nachkommastellen sein.',
        ]);

        $list = $writer->createDraft($validated, $this->actor($request));

        return redirect()
            ->route('administration.production-prices.show', $list)
            ->with('success', 'Entwurf angelegt.');
    }

    public function show(Request $request, ProductionPriceList $productionPriceList): Response
    {
        $this->authorize('access-administration');
        $productionPriceList->load('inventory');

        return Inertia::render('administration/production-prices/show', [
            'productionPriceList' => $this->serialize($productionPriceList),
            'routes' => [
                'index' => route('administration.production-prices.index'),
                'update' => route('administration.production-prices.update', $productionPriceList),
                'activate' => route('administration.production-prices.activate', $productionPriceList),
                'archive' => route('administration.production-prices.archive', $productionPriceList),
                'copy' => route('administration.production-prices.copy', $productionPriceList),
            ],
        ]);
    }

    public function update(
        Request $request,
        ProductionPriceList $productionPriceList,
        ProductionPriceListAdminWriter $writer,
    ): RedirectResponse|JsonResponse {
        $this->authorize('access-administration');
        $this->rejectServerOwnedFields($request, forCreate: false);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'unit_price' => ['required', 'regex:/^\d{1,12}([.,]\d{1,2})?$/'],
            'is_discountable' => ['sometimes', 'boolean'],
            'is_ae_eligible' => ['sometimes', 'boolean'],
            'lock_version' => ['required', 'integer', 'min:1'],
        ], [
            'unit_price.regex' => 'Der Produktionspreis muss ein nicht-negativer Betrag mit höchstens zwei Nachkommastellen sein.',
        ]);

        return $this->respond(
            $request,
            fn (): ProductionPriceList => $writer->updateDraft($productionPriceList, $validated, $this->actor($request)),
            'Entwurf gespeichert.',
        );
    }

    public function activate(
        Request $request,
        ProductionPriceList $productionPriceList,
        ProductionPriceListAdminWriter $writer,
    ): RedirectResponse|JsonResponse {
        $this->authorize('access-administration');

        $validated = $request->validate([
            'lock_version' => ['required', 'integer', 'min:1'],
        ]);

        return $this->respond(
            $request,
            fn (): ProductionPriceList => $writer->activate($productionPriceList, $validated, $this->actor($request)),
            'Produktionspreis aktiviert.',
        );
    }

    public function archive(
        Request $request,
        ProductionPriceList $productionPriceList,
        ProductionPriceListAdminWriter $writer,
    ): RedirectResponse|JsonResponse {
        $this->authorize('access-administration');

        $validated = $request->validate([
            'lock_version' => ['required', 'integer', 'min:1'],
        ]);

        return $this->respond(
            $request,
            fn (): ProductionPriceList => $writer->archive($productionPriceList, $validated, $this->actor($request)),
            'Produktionspreis archiviert.',
        );
    }

    public function copy(
        Request $request,
        ProductionPriceList $productionPriceList,
        ProductionPriceListAdminWriter $writer,
    ): RedirectResponse|JsonResponse {
        $this->authorize('access-administration');

        $validated = $request->validate([
            'year' => ['sometimes', 'nullable', 'integer', 'min:2000', 'max:2100'],
            'name' => ['sometimes', 'nullable', 'string', 'max:255'],
        ]);
        $validated = array_filter($validated, static fn (mixed $value): bool => $value !== null && $value !== '');

        $copy = $writer->copyAsDraft($productionPriceList, $validated, $this->actor($request));

        if ($request->expectsJson()) {
            return response()->json([
                'message' => 'Kopie als Entwurf angelegt.',
                'productionPriceList' => $this->serialize($copy),
            ], 201);
        }

        return redirect()
            ->route('administration.production-prices.show', $copy)
            ->with('success', 'Kopie als Entwurf angelegt.');
    }

    /**
     * Lock-Konflikte bleiben für JSON-Clients HTTP 409; Inertia-Formulare erhalten einen Formularfehler.
     *
     * @param  callable(): ProductionPriceList  $action
     */
    private function respond(Request $request, callable $action, string $message): RedirectResponse|JsonResponse
    {
        try {
            $list = $action();
        } catch (PriceListAdminConflictException $exception) {
            if ($request->header('X-Inertia') !== null) {
                throw ValidationException::withMessages(['lock_version' => $exception->getMessage()]);
            }

            throw $exception;
        }

        if ($request->expectsJson()) {
            $list->loadMissing('inventory');

            return response()->json([
                'message' => $message,
                'lock_version' => (int) $list->lock_version,
                'status' => $list->status->value,
                'status_label' => $list->status->label(),
                'productionPriceList' => $this->serialize($list),
            ]);
        }

        return redirect()
            ->route('administration.production-prices.show', $list)
            ->with('success', $message);
    }

    private function actor(Request $request): User
    {
        return $request->user() ?? abort(403);
    }

    /**
     * @return list<array{id: int, name: string, code: string, type: string, type_label: string}>
     */
    private function inventoryOptions(): array
    {
        $options = [];
        foreach (Inventory::query()->orderBy('sort')->orderBy('name')->get() as $inventory) {
            $options[] = [
                'id' => $inventory->id,
                'name' => $inventory->name,
                'code' => $inventory->code,
                'type' => $inventory->type->value,
                'type_label' => InventoryIdentity::typeLabel($inventory->type),
            ];
        }

        return $options;
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    private function productionTypeOptions(): array
    {
        $options = [];
        foreach (ProductionType::cases() as $type) {
            if ($type->isSupportedInSlice()) {
                $options[] = ['value' => $type->value, 'label' => $type->label()];
            }
        }

        return $options;
    }

    /**
     * @return array<string, mixed>
     */
    private function serialize(ProductionPriceList $list): array
    {
        $inventory = $list->inventory;

        return [
            'id' => $list->id,
            'name' => $list->name,
            'production_type' => $list->production_type->value,
            'production_type_label' => $list->production_type->label(),
            'year' => (int) $list->year,
            'version' => $list->version,
            'revision_number' => (int) $list->revision_number,
            'status' => $list->status->value,
            'status_label' => $list->status->label(),
            'editable' => $list->status->isEditable(),
            'lock_version' => (int) $list->lock_version,
            'unit_price' => (string) $list->unit_price,
            'is_discountable' => (bool) $list->is_discountable,
            'is_ae_eligible' => (bool) $list->is_ae_eligible,
            'published_at' => $list->published_at?->toIso8601String(),
            'archived_at' => $list->archived_at?->toIso8601String(),
            'inventory_id' => (int) $list->inventory_id,
            'inventory_name' => $inventory?->name,
            'inventory_code' => $inventory?->code,
            'inventory_type_label' => $inventory !== null ? InventoryIdentity::typeLabel($inventory->type) : null,
        ];
    }

    private function rejectServerOwnedFields(Request $request, bool $forCreate): void
    {
        foreach (['revision_number', 'version', 'status', 'published_at', 'archived_at'] as $field) {
            if ($request->exists($field)) {
                throw ValidationException::withMessages([
                    $field => 'Dieses Feld wird serverseitig gesetzt.',
                ]);
            }
        }

        if ($forCreate) {
            return;
        }

        foreach (['inventory_id', 'year', 'production_type'] as $field) {
            if ($request->exists($field)) {
                throw ValidationException::withMessages([
                    $field => 'Dieses Feld einer angelegten Version ist unveränderlich.',
                ]);
            }
        }
    }
}
