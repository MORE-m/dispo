<?php

namespace App\Http\Controllers\Administration\OnlineAudio;

use App\Enums\PriceListStatus;
use App\Http\Controllers\Controller;
use App\Models\AdvertisingMedium;
use App\Models\AuditEvent;
use App\Models\Inventory;
use App\Models\OaTkpPriceList;
use App\Models\OaTkpPriceListItem;
use App\Models\User;
use App\Services\OnlineAudio\Admin\OaTkpPriceListAdminWriter;
use App\Services\OnlineAudio\Import\OaTkpStammdatenImportException;
use App\Support\OnlineAudio\OaTkpActiveMediumInventoryPairs;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * PO-CALC-TKP-1D4c-4d: OA-TKP-Preislisten Admin (Inertia-UI + JSON-API aus 4c).
 */
class OaTkpPriceListAdminController extends Controller
{
    public function index(Request $request): JsonResponse|Response
    {
        $this->authorize('manage-oa-tkp-master-data');

        $status = (string) $request->query('status', 'all');
        $year = $request->query('year');

        if (! in_array($status, ['all', ...array_column(PriceListStatus::cases(), 'value')], true)) {
            $status = 'all';
        }

        $query = OaTkpPriceList::query()
            ->withCount('items')
            ->orderByDesc('year')
            ->orderByDesc('revision_number')
            ->orderBy('id');

        if ($status !== 'all') {
            $query->where('status', $status);
        }
        if (is_numeric($year)) {
            $query->where('year', (int) $year);
        }

        $lists = $query->get();
        $serialized = $lists->map(fn (OaTkpPriceList $list): array => $this->serializeList($list))->values()->all();
        $requiredPairs = OaTkpActiveMediumInventoryPairs::all();
        $filters = [
            'status' => $status,
            'year' => is_numeric($year) ? (int) $year : null,
        ];

        if ($request->expectsJson()) {
            return response()->json([
                'price_lists' => $serialized,
                'required_pairs' => $requiredPairs,
                'filters' => $filters,
            ]);
        }

        $years = OaTkpPriceList::query()
            ->select('year')
            ->distinct()
            ->orderByDesc('year')
            ->pluck('year')
            ->map(static fn ($y): int => (int) $y)
            ->values()
            ->all();

        return Inertia::render('administration/online-audio/price-lists/index', [
            'priceLists' => $serialized,
            'filters' => $filters,
            'years' => $years,
            'requiredPairsCount' => count($requiredPairs),
            'currentYear' => (int) date('Y'),
        ]);
    }

    public function create(Request $request): Response
    {
        $this->authorize('manage-oa-tkp-master-data');

        $currentYear = (int) date('Y');

        return Inertia::render('administration/online-audio/price-lists/create', [
            'requiredPairs' => $this->requiredPairsForUi(),
            'currentYear' => $currentYear,
            'suggestedYear' => max($currentYear + 1, 2027),
        ]);
    }

    public function show(Request $request, OaTkpPriceList $oaTkpPriceList): JsonResponse|Response
    {
        $this->authorize('manage-oa-tkp-master-data');
        $oaTkpPriceList->load(['items.advertisingMedium:id,code,name', 'items.inventory:id,code,name']);

        $serialized = $this->serializeList($oaTkpPriceList, withItems: true);
        $requiredPairs = OaTkpActiveMediumInventoryPairs::all();

        if ($request->expectsJson()) {
            return response()->json([
                'price_list' => $serialized,
                'required_pairs' => $requiredPairs,
            ]);
        }

        $requiredPairsUi = $this->requiredPairsForUi();

        $siblings = OaTkpPriceList::query()
            ->where('year', $oaTkpPriceList->year)
            ->orderByDesc('revision_number')
            ->orderBy('id')
            ->get()
            ->map(fn (OaTkpPriceList $list): array => [
                'id' => (int) $list->id,
                'revision_number' => (int) $list->revision_number,
                'status' => $list->status->value,
                'status_label' => $list->status->label(),
                'name' => (string) $list->name,
            ])
            ->values()
            ->all();

        return Inertia::render('administration/online-audio/price-lists/show', [
            'priceList' => $serialized,
            'requiredPairs' => $requiredPairsUi,
            'siblingRevisions' => $siblings,
            'routes' => [
                'index' => route('administration.oa-tkp.price-lists.index'),
                'update' => route('administration.oa-tkp.price-lists.update', $oaTkpPriceList),
                'validate' => route('administration.oa-tkp.price-lists.validate', $oaTkpPriceList),
                'activate' => route('administration.oa-tkp.price-lists.activate', $oaTkpPriceList),
                'archive' => route('administration.oa-tkp.price-lists.archive', $oaTkpPriceList),
                'history' => route('administration.oa-tkp.price-lists.history', $oaTkpPriceList),
            ],
        ]);
    }

    public function store(Request $request, OaTkpPriceListAdminWriter $writer): JsonResponse
    {
        $this->authorize('manage-oa-tkp-master-data');
        $this->rejectServerOwnedFields($request, forCreate: true);

        $validated = $request->validate([
            'year' => ['required', 'integer', 'min:2000', 'max:2100'],
            'name' => ['required', 'string', 'max:255'],
            'version' => ['sometimes', 'nullable', 'string', 'max:64'],
            'revision_number' => ['sometimes', 'integer', 'min:1'],
            'valid_from' => ['sometimes', 'nullable', 'date'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.medium_code' => ['required', 'string', 'max:64'],
            'items.*.inventory_code' => ['required', 'string', 'max:64'],
            'items.*.list_tkp' => ['required', 'string'],
            'items.*.minimum_tkp' => ['required', 'string'],
        ]);

        try {
            $list = $writer->createDraft($validated, $this->actor($request));
        } catch (OaTkpStammdatenImportException $e) {
            return $this->importException($e);
        }

        return response()->json([
            'message' => 'OA-TKP-Entwurf angelegt.',
            'price_list' => $this->serializeList($list->load(['items.advertisingMedium', 'items.inventory']), withItems: true),
        ], 201);
    }

    public function update(
        Request $request,
        OaTkpPriceList $oaTkpPriceList,
        OaTkpPriceListAdminWriter $writer,
    ): JsonResponse {
        $this->authorize('manage-oa-tkp-master-data');
        $this->rejectServerOwnedFields($request, forCreate: false);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'version' => ['sometimes', 'nullable', 'string', 'max:64'],
            'valid_from' => ['sometimes', 'nullable', 'date'],
            'lock_version' => ['required', 'integer', 'min:1'],
            'items' => ['sometimes', 'array', 'min:1'],
            'items.*.medium_code' => ['required_with:items', 'string', 'max:64'],
            'items.*.inventory_code' => ['required_with:items', 'string', 'max:64'],
            'items.*.list_tkp' => ['required_with:items', 'string'],
            'items.*.minimum_tkp' => ['required_with:items', 'string'],
        ]);

        try {
            $list = $writer->updateDraft($oaTkpPriceList, $validated, $this->actor($request));
        } catch (OaTkpStammdatenImportException $e) {
            return $this->importException($e);
        }

        return response()->json([
            'message' => 'OA-TKP-Entwurf gespeichert.',
            'price_list' => $this->serializeList($list, withItems: true),
            'lock_version' => (int) $list->lock_version,
        ]);
    }

    public function validateMatrix(
        Request $request,
        OaTkpPriceList $oaTkpPriceList,
        OaTkpPriceListAdminWriter $writer,
    ): JsonResponse {
        $this->authorize('manage-oa-tkp-master-data');
        $requireComplete = $request->boolean('require_complete', true);

        return response()->json($writer->validate($oaTkpPriceList, $requireComplete));
    }

    public function activate(
        Request $request,
        OaTkpPriceList $oaTkpPriceList,
        OaTkpPriceListAdminWriter $writer,
    ): JsonResponse {
        $this->authorize('manage-oa-tkp-master-data');

        $validated = $request->validate([
            'lock_version' => ['required', 'integer', 'min:1'],
            'dry_run' => ['sometimes', 'boolean'],
        ]);

        try {
            $result = $writer->activate(
                $oaTkpPriceList,
                $validated,
                $this->actor($request),
                dryRun: (bool) ($validated['dry_run'] ?? false),
            );
        } catch (OaTkpStammdatenImportException $e) {
            return $this->importException($e);
        }

        $list = $result['price_list'];
        unset($result['price_list']);

        return response()->json([
            'message' => ($validated['dry_run'] ?? false)
                ? 'Aktivierungs-Vorschau ok.'
                : 'OA-TKP-Revision aktiviert.',
            'result' => $result,
            'price_list' => $this->serializeList($list, withItems: true),
            'lock_version' => (int) $list->lock_version,
        ]);
    }

    public function archive(
        Request $request,
        OaTkpPriceList $oaTkpPriceList,
        OaTkpPriceListAdminWriter $writer,
    ): JsonResponse {
        $this->authorize('manage-oa-tkp-master-data');

        $validated = $request->validate([
            'lock_version' => ['required', 'integer', 'min:1'],
        ]);

        $list = $writer->archive($oaTkpPriceList, $validated, $this->actor($request));

        return response()->json([
            'message' => 'OA-TKP-Revision archiviert.',
            'price_list' => $this->serializeList($list, withItems: true),
            'lock_version' => (int) $list->lock_version,
        ]);
    }

    public function history(OaTkpPriceList $oaTkpPriceList): JsonResponse
    {
        $this->authorize('manage-oa-tkp-master-data');

        $events = AuditEvent::query()
            ->where('auditable_type', OaTkpPriceList::class)
            ->where('auditable_id', $oaTkpPriceList->id)
            ->orderByDesc('id')
            ->limit(100)
            ->get()
            ->map(static fn (AuditEvent $event): array => [
                'id' => (int) $event->id,
                'action' => (string) $event->action,
                'user_id' => $event->user_id !== null ? (int) $event->user_id : null,
                'old_values' => $event->old_values,
                'new_values' => $event->new_values,
                'correlation_id' => $event->correlation_id,
                'created_at' => $event->created_at->toIso8601String(),
            ])
            ->values()
            ->all();

        return response()->json([
            'price_list_id' => (int) $oaTkpPriceList->id,
            'events' => $events,
        ]);
    }

    public function destroy(): JsonResponse
    {
        $this->authorize('manage-oa-tkp-master-data');

        return response()->json([
            'message' => 'Hard-Delete ist für OA-TKP-Preislisten nicht erlaubt.',
        ], 405);
    }

    /**
     * @return list<array{
     *     medium_code: string,
     *     inventory_code: string,
     *     advertising_medium_id: int,
     *     inventory_id: int,
     *     medium_name: string,
     *     inventory_name: string
     * }>
     */
    private function requiredPairsForUi(): array
    {
        $pairs = OaTkpActiveMediumInventoryPairs::all();
        if ($pairs === []) {
            return [];
        }

        $mediumIds = array_values(array_unique(array_column($pairs, 'advertising_medium_id')));
        $inventoryIds = array_values(array_unique(array_column($pairs, 'inventory_id')));

        $mediumNames = AdvertisingMedium::query()
            ->whereIn('id', $mediumIds)
            ->pluck('name', 'id');
        $inventoryNames = Inventory::query()
            ->whereIn('id', $inventoryIds)
            ->pluck('name', 'id');

        return array_map(static function (array $pair) use ($mediumNames, $inventoryNames): array {
            return [
                ...$pair,
                'medium_name' => (string) ($mediumNames[$pair['advertising_medium_id']] ?? $pair['medium_code']),
                'inventory_name' => (string) ($inventoryNames[$pair['inventory_id']] ?? $pair['inventory_code']),
            ];
        }, $pairs);
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeList(OaTkpPriceList $list, bool $withItems = false): array
    {
        $payload = [
            'id' => (int) $list->id,
            'year' => (int) $list->year,
            'name' => (string) $list->name,
            'version' => (string) $list->version,
            'revision_number' => (int) $list->revision_number,
            'lock_version' => (int) $list->lock_version,
            'status' => $list->status->value,
            'status_label' => $list->status->label(),
            'editable' => $list->status === PriceListStatus::Draft,
            'valid_from' => $list->valid_from?->toDateString(),
            'published_at' => $list->published_at?->toIso8601String(),
            'archived_at' => $list->archived_at?->toIso8601String(),
            'items_count' => array_key_exists('items_count', $list->getAttributes())
                ? (int) $list->items_count
                : $list->items()->count(),
        ];

        if ($withItems) {
            $list->loadMissing(['items.advertisingMedium:id,code,name', 'items.inventory:id,code,name']);
            $payload['items'] = $list->items->map(static function (OaTkpPriceListItem $item): array {
                return [
                    'id' => (int) $item->id,
                    'medium_code' => (string) $item->advertisingMedium->code,
                    'medium_name' => (string) $item->advertisingMedium->name,
                    'inventory_code' => (string) $item->inventory->code,
                    'inventory_name' => (string) $item->inventory->name,
                    'list_tkp' => (string) $item->list_tkp,
                    'minimum_tkp' => (string) $item->minimum_tkp,
                ];
            })->values()->all();
        }

        return $payload;
    }

    private function actor(Request $request): User
    {
        /** @var User $user */
        $user = $request->user();

        return $user;
    }

    private function rejectServerOwnedFields(Request $request, bool $forCreate): void
    {
        $forbidden = $forCreate
            ? ['status', 'published_at', 'archived_at', 'lock_version']
            : ['status', 'published_at', 'archived_at', 'year', 'revision_number'];

        $present = [];
        foreach ($forbidden as $field) {
            if ($request->exists($field)) {
                $present[] = $field;
            }
        }

        if ($present !== []) {
            throw ValidationException::withMessages([
                $present[0] => 'Servergeführte Felder dürfen nicht gesetzt werden: '.implode(', ', $present),
            ]);
        }
    }

    private function importException(OaTkpStammdatenImportException $e): JsonResponse
    {
        $structured = [];
        foreach ($e->errors as $message) {
            $line = null;
            $field = null;
            if (preg_match('/^Item (\d+):/', $message, $m) === 1) {
                $line = (int) $m[1];
            }
            foreach (['list_tkp', 'minimum_tkp', 'medium_code', 'inventory_code', 'items'] as $candidate) {
                if (str_contains($message, $candidate)) {
                    $field = $candidate;
                    break;
                }
            }
            $structured[] = [
                'line' => $line,
                'field' => $field,
                'message' => $message,
            ];
        }

        return response()->json([
            'message' => $e->getMessage(),
            'errors' => $e->errors,
            'field_errors' => $structured,
        ], 422);
    }
}
