<?php

namespace App\Http\Controllers\Administration;

use App\Http\Controllers\Controller;
use App\Services\Notification\NotificationOutboxAdminQuery;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * PO-NOT002-ADMIN-1 / BL-P9-02e – rein lesende Admin-Outbox- und Suppress-Sicht.
 */
class NotificationOutboxAdminController extends Controller
{
    public function __construct(
        private readonly NotificationOutboxAdminQuery $query,
    ) {}

    public function index(Request $request): Response
    {
        $this->authorize('view-notification-outbox');

        $filters = $this->query->normalizeOutboxFilters(
            $this->optionalStringQuery($request, 'status'),
            $this->optionalStringQuery($request, 'event_type'),
        );

        $page = $this->pageQuery($request);
        $paginator = $this->query->paginateOutbox($filters, $page);

        return Inertia::render('administration/notification-outbox/index', [
            'activeTab' => 'outbox',
            'filters' => $filters,
            'statusOptions' => $this->query->statusFilterOptions(),
            'eventOptions' => $this->query->eventFilterOptions(),
            'rows' => $paginator->items(),
            'pagination' => $this->paginationMeta($paginator),
        ]);
    }

    public function show(Request $request, int $outbox): Response
    {
        $this->authorize('view-notification-outbox');

        $detail = $this->query->detail($outbox);
        abort_if($detail === null, 404);

        return Inertia::render('administration/notification-outbox/show', [
            'row' => $detail,
        ]);
    }

    public function suppressed(Request $request): Response
    {
        $this->authorize('view-notification-outbox');

        $page = $this->pageQuery($request);
        $paginator = $this->query->paginateSuppressions($page);

        return Inertia::render('administration/notification-outbox/suppressed', [
            'activeTab' => 'suppressed',
            'rows' => $paginator->items(),
            'pagination' => $this->paginationMeta($paginator),
            'notice' => 'Unterdrückte Benachrichtigungen: Es wurde keine E-Mail versendet. Dies ist kein SMTP-Fehler und wird nicht erneut versucht.',
        ]);
    }

    /**
     * @param  LengthAwarePaginator<int, mixed>  $paginator
     * @return array{current_page: int, last_page: int, per_page: int, total: int, from: int|null, to: int|null}
     */
    private function paginationMeta(LengthAwarePaginator $paginator): array
    {
        return [
            'current_page' => $paginator->currentPage(),
            'last_page' => $paginator->lastPage(),
            'per_page' => $paginator->perPage(),
            'total' => $paginator->total(),
            'from' => $paginator->firstItem(),
            'to' => $paginator->lastItem(),
        ];
    }

    private function optionalStringQuery(Request $request, string $key): ?string
    {
        $all = $request->query->all();
        if (! array_key_exists($key, $all)) {
            return null;
        }

        $value = $all[$key];
        if ($value === null || $value === '') {
            return null;
        }

        if (! is_string($value)) {
            throw ValidationException::withMessages([
                $key => 'Ungültiger Filterwert.',
            ]);
        }

        return $value;
    }

    private function pageQuery(Request $request): int
    {
        $all = $request->query->all();
        if (! array_key_exists('page', $all)) {
            return 1;
        }

        $value = $all['page'];
        if (! is_string($value) && ! is_int($value)) {
            throw ValidationException::withMessages([
                'page' => 'Ungültige Seite.',
            ]);
        }

        $normalized = trim((string) $value);
        if ($normalized === '' || ! ctype_digit($normalized)) {
            throw ValidationException::withMessages([
                'page' => 'Ungültige Seite.',
            ]);
        }

        return max(1, (int) $normalized);
    }
}
