<?php

namespace App\Http\Controllers\Administration;

use App\Enums\DayGroup;
use App\Enums\InventoryType;
use App\Enums\PriceListStatus;
use App\Http\Controllers\Controller;
use App\Models\Inventory;
use App\Models\PriceList;
use App\Services\PriceList\Admin\PriceListAdminWriter;
use App\Services\PriceList\Admin\PriceListImpactPreviewService;
use App\Support\Inventory\InventoryIdentity;
use App\Support\PriceList\PriceListCalendar;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * BL-P4-01a: Preislisten-Admin. Kein Excel-Import, kein Hard Delete.
 */
class PriceListAdminController extends Controller
{
    public function index(Request $request): Response
    {
        $this->authorize('access-administration');

        $status = (string) $request->query('status', 'all');
        $type = (string) $request->query('type', 'all');
        $year = $request->query('year');
        $inventoryId = $request->query('inventory_id');

        if (! in_array($status, ['all', PriceListStatus::Draft->value, PriceListStatus::Active->value, PriceListStatus::Archived->value], true)) {
            $status = 'all';
        }
        if (! in_array($type, ['all', InventoryType::Sender->value, InventoryType::Kombi->value], true)) {
            $type = 'all';
        }

        $query = PriceList::query()
            ->with('inventory')
            ->orderByDesc('year')
            ->orderBy('inventory_id')
            ->orderByDesc('revision_number')
            ->orderBy('id');

        if ($status !== 'all') {
            $query->where('status', $status);
        }
        if ($type !== 'all') {
            $query->whereHas('inventory', fn ($q) => $q->where('type', $type));
        }
        if (is_numeric($year)) {
            $query->where('year', (int) $year);
        }
        if (is_numeric($inventoryId)) {
            $query->where('inventory_id', (int) $inventoryId);
        }

        $lists = $query->get();

        return Inertia::render('administration/price-lists/index', [
            'filters' => [
                'status' => $status,
                'type' => $type,
                'year' => is_numeric($year) ? (int) $year : null,
                'inventory_id' => is_numeric($inventoryId) ? (int) $inventoryId : null,
            ],
            'inventories' => Inventory::query()
                ->orderBy('sort')
                ->orderBy('name')
                ->get(['id', 'name', 'code', 'type'])
                ->map(fn (Inventory $inventory): array => [
                    'id' => $inventory->id,
                    'name' => $inventory->name,
                    'code' => $inventory->code,
                    'type' => $inventory->type->value,
                    'type_label' => InventoryIdentity::typeLabel($inventory->type),
                ])
                ->values()
                ->all(),
            'priceLists' => $lists->map(fn (PriceList $list): array => $this->serializeListRow($list))->values()->all(),
            'currentYear' => PriceListCalendar::currentYear(),
        ]);
    }

    public function create(Request $request): Response
    {
        $this->authorize('access-administration');

        return Inertia::render('administration/price-lists/create', [
            'inventories' => $this->inventoryOptions(),
            'currentYear' => PriceListCalendar::currentYear(),
            'copySource' => $this->copySource((int) $request->query('copy_from', 0)),
        ]);
    }

    public function store(Request $request, PriceListAdminWriter $writer): RedirectResponse
    {
        $this->authorize('access-administration');
        $this->rejectServerOwnedFields($request, forCreate: true);

        $validated = $request->validate([
            'inventory_id' => ['required', 'integer', 'exists:inventories,id'],
            'year' => ['required', 'integer', 'min:1990', 'max:2100'],
            'name' => ['required', 'string', 'max:255'],
            'copy_from_id' => ['nullable', 'integer', 'exists:price_lists,id'],
            'items' => ['sometimes', 'array'],
        ]);

        $actor = $request->user();
        if ($actor === null) {
            abort(403);
        }

        if (! empty($validated['copy_from_id'])) {
            $source = PriceList::query()->findOrFail((int) $validated['copy_from_id']);
            $list = $writer->copyAsDraft($source, $validated, $actor);
        } else {
            $list = $writer->createDraft($validated, $actor);
        }

        return redirect()
            ->route('administration.price-lists.show', $list)
            ->with('success', 'Entwurf angelegt.');
    }

    public function show(Request $request, PriceList $priceList, PriceListImpactPreviewService $impact): Response
    {
        $this->authorize('access-administration');
        $priceList->load(['inventory', 'items']);

        $baseItems = $this->baseItemPayload($priceList);

        return Inertia::render('administration/price-lists/show', [
            'priceList' => $this->serializeDetail($priceList, $impact->derivedPrices(
                $this->writerBaseItems($priceList),
            )),
            'baseItems' => $baseItems,
            'routes' => [
                'index' => route('administration.price-lists.index'),
                'update' => route('administration.price-lists.update', $priceList),
                'inspect' => route('administration.price-lists.inspect', $priceList),
                'activatePreview' => route('administration.price-lists.activate-preview', $priceList),
                'activate' => route('administration.price-lists.activate', $priceList),
                'archivePreview' => route('administration.price-lists.archive-preview', $priceList),
                'archive' => route('administration.price-lists.archive', $priceList),
                'copy' => route('administration.price-lists.create', ['copy_from' => $priceList->id]),
            ],
        ]);
    }

    public function update(
        Request $request,
        PriceList $priceList,
        PriceListAdminWriter $writer,
    ): RedirectResponse|JsonResponse {
        $this->authorize('access-administration');
        $this->rejectServerOwnedFields($request, forCreate: false);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'items' => ['present', 'array'],
            'lock_version' => ['required', 'integer', 'min:1'],
        ]);

        $actor = $request->user();
        if ($actor === null) {
            abort(403);
        }

        $updated = $writer->updateDraft($priceList, $validated, $actor);

        if ($request->expectsJson()) {
            return $this->jsonWriterPayload($updated, 'Entwurf gespeichert.');
        }

        return redirect()
            ->route('administration.price-lists.show', $updated)
            ->with('success', 'Entwurf gespeichert.');
    }

    public function inspect(
        Request $request,
        PriceList $priceList,
        PriceListAdminWriter $writer,
        PriceListImpactPreviewService $impact,
    ): JsonResponse {
        $this->authorize('access-administration');

        $validated = $request->validate([
            'items' => ['present', 'array'],
        ]);

        $items = $writer->inspectDraftItems($validated);

        return response()->json([
            'ok' => true,
            'derived' => $impact->derivedPrices($items),
            'item_count' => count($items),
        ]);
    }

    public function activatePreview(
        Request $request,
        PriceList $priceList,
        PriceListImpactPreviewService $impact,
    ): JsonResponse {
        $this->authorize('access-administration');

        return response()->json($impact->previewActivate($priceList));
    }

    public function activate(
        Request $request,
        PriceList $priceList,
        PriceListAdminWriter $writer,
    ): JsonResponse {
        $this->authorize('access-administration');

        $validated = $request->validate([
            'lock_version' => ['required', 'integer', 'min:1'],
            'fingerprint' => ['required', 'string', 'size:64'],
        ]);

        $actor = $request->user();
        if ($actor === null) {
            abort(403);
        }

        $updated = $writer->activate($priceList, $validated, $actor);

        return $this->jsonWriterPayload($updated, 'Preisliste veröffentlicht.');
    }

    public function archivePreview(
        Request $request,
        PriceList $priceList,
        PriceListImpactPreviewService $impact,
    ): JsonResponse {
        $this->authorize('access-administration');

        return response()->json($impact->previewArchive($priceList));
    }

    public function archive(
        Request $request,
        PriceList $priceList,
        PriceListAdminWriter $writer,
    ): JsonResponse {
        $this->authorize('access-administration');

        $validated = $request->validate([
            'lock_version' => ['required', 'integer', 'min:1'],
            'fingerprint' => ['required', 'string', 'size:64'],
        ]);

        $actor = $request->user();
        if ($actor === null) {
            abort(403);
        }

        $updated = $writer->archive($priceList, $validated, $actor);

        return $this->jsonWriterPayload($updated, 'Preisliste archiviert.');
    }

    public function destroy(): never
    {
        $this->authorize('access-administration');

        throw new HttpException(405, 'Preislisten mit historischen Bezügen werden nicht gelöscht.');
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
     * @return array<string, mixed>|null
     */
    private function copySource(int $id): ?array
    {
        if ($id < 1) {
            return null;
        }

        $source = PriceList::query()->with('inventory')->find($id);
        if ($source === null) {
            return null;
        }

        return [
            'id' => $source->id,
            'name' => $source->name,
            'year' => (int) $source->year,
            'version' => $source->version,
            'inventory_id' => (int) $source->inventory_id,
            'inventory_name' => $source->inventory?->name,
        ];
    }

    /**
     * @param  list<array{hour: int, day_group: string, day_group_label: string, second_price: string}>  $derived
     * @return array<string, mixed>
     */
    private function serializeDetail(PriceList $list, array $derived): array
    {
        $inventory = $list->inventory;
        $type = $inventory?->type;

        return [
            'id' => $list->id,
            'name' => $list->name,
            'year' => (int) $list->year,
            'version' => $list->version,
            'revision_number' => (int) $list->revision_number,
            'status' => $list->status->value,
            'status_label' => $list->status->label(),
            'editable' => $list->status->isEditable(),
            'lock_version' => (int) $list->lock_version,
            'valid_from' => $list->valid_from?->toDateString(),
            'inventory' => [
                'id' => $inventory?->id,
                'name' => $inventory?->name,
                'code' => $inventory?->code,
                'type' => $type?->value,
                'type_label' => $type !== null ? InventoryIdentity::typeLabel($type) : null,
            ],
            'derived' => $derived,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeListRow(PriceList $list): array
    {
        $inventory = $list->inventory;
        $type = $inventory?->type;

        return [
            'id' => $list->id,
            'name' => $list->name,
            'year' => (int) $list->year,
            'version' => $list->version,
            'status' => $list->status->value,
            'status_label' => $list->status->label(),
            'inventory_id' => (int) $list->inventory_id,
            'inventory_name' => $inventory?->name,
            'inventory_code' => $inventory?->code,
            'inventory_type' => $type?->value,
            'inventory_type_label' => $type !== null ? InventoryIdentity::typeLabel($type) : null,
        ];
    }

    /**
     * @return list<array{hour: int, day_group: string, second_price: string}>
     */
    private function baseItemPayload(PriceList $list): array
    {
        $items = [];
        foreach ($list->items as $item) {
            if ($item->day_group->isDerived()) {
                continue;
            }
            $items[] = [
                'hour' => (int) $item->hour,
                'day_group' => $item->day_group->value,
                'second_price' => (string) $item->second_price,
            ];
        }
        usort(
            $items,
            fn (array $left, array $right): int => [$left['hour'], $left['day_group']] <=> [$right['hour'], $right['day_group']],
        );

        return $items;
    }

    /**
     * @return list<array{hour: int, day_group: DayGroup, second_price: string}>
     */
    private function writerBaseItems(PriceList $list): array
    {
        $list->loadMissing('items');
        $items = [];
        foreach ($list->items as $item) {
            if ($item->day_group->isDerived()) {
                continue;
            }
            $items[] = [
                'hour' => (int) $item->hour,
                'day_group' => $item->day_group,
                'second_price' => (string) $item->second_price,
            ];
        }

        return $items;
    }

    private function jsonWriterPayload(PriceList $list, string $message): JsonResponse
    {
        $list->loadMissing(['items', 'inventory']);
        $impact = app(PriceListImpactPreviewService::class);

        return response()->json([
            'message' => $message,
            'lock_version' => $list->lock_version,
            'status' => $list->status->value,
            'status_label' => $list->status->label(),
            'priceList' => $this->serializeDetail(
                $list,
                $impact->derivedPrices($this->writerBaseItems($list)),
            ),
            'baseItems' => $this->baseItemPayload($list),
        ]);
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

        if ($request->exists('inventory_id')) {
            throw ValidationException::withMessages([
                'inventory_id' => 'Die Inventarzuordnung einer angelegten Version ist unveränderlich.',
            ]);
        }

        if ($request->exists('year')) {
            throw ValidationException::withMessages([
                'year' => 'Das Kalenderjahr einer angelegten Version ist unveränderlich.',
            ]);
        }
    }
}
