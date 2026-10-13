<?php

namespace App\Http\Controllers\Crm;

use App\Enums\CrmAccountType;
use App\Http\Controllers\Controller;
use App\Models\CrmAccount;
use App\Models\CrmConflict;
use App\Services\Crm\CrmAccountService;
use App\Support\Crm\CrmAccountPresentation;
use App\Support\Crm\CrmConflictPresentation;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/**
 * BL-P2-03a: Stammdaten, vorläufige Accounts, manuelle Zuordnung.
 * Textsuche/Pagination Stammdatenliste + verständliche Labels (Audit P3).
 */
class CrmAccountController extends Controller
{
    private const INDEX_PER_PAGE = 50;

    public function __construct(
        private readonly CrmAccountService $accounts,
    ) {}

    public function index(Request $request): Response
    {
        $this->authorize('view-crm-accounts');

        $type = $request->query('type');
        $typeFilter = in_array($type, ['customer', 'agency'], true) ? $type : null;
        $provisionalOnly = $request->query('provisional') === '1';
        $q = $this->optionalSearchQuery($request);
        $page = max(1, (int) $request->query('page', 1));

        $query = $this->baseAccountQuery();
        $this->applyTypeFilter($query, $typeFilter);
        if ($provisionalOnly) {
            $query->where('is_provisional', true);
        }
        $this->applyTextSearch($query, $q);

        /** @var LengthAwarePaginator<int, CrmAccount> $paginator */
        $paginator = $query
            ->orderByDesc('id')
            ->paginate(self::INDEX_PER_PAGE, ['*'], 'page', $page)
            ->withQueryString();

        $accounts = collect($paginator->items())
            ->map(fn (CrmAccount $a): array => $this->serializeAccount($a))
            ->values()
            ->all();

        return Inertia::render('crm/accounts/index', [
            'accounts' => $accounts,
            'filters' => [
                'type' => $typeFilter,
                'provisional' => $provisionalOnly,
                'q' => $q,
            ],
            'pagination' => $this->paginationMeta($paginator),
            'canImport' => $request->user()?->can('import-crm-accounts') ?? false,
            'canCreateProvisional' => $request->user()?->can('create-provisional-crm-accounts') ?? false,
            'canManageMatches' => $request->user()?->can('manage-crm-matches') ?? false,
        ]);
    }

    public function show(Request $request, CrmAccount $crmAccount): Response
    {
        $this->authorize('view-crm-accounts');
        $crmAccount->load(['currentVersion', 'versions.createdBy']);

        $conflicts = CrmConflict::query()
            ->where('crm_account_id', $crmAccount->id)
            ->orderByDesc('id')
            ->limit(50)
            ->get()
            ->map(fn (CrmConflict $c): array => [
                'id' => $c->id,
                'type' => $c->type->value,
                'status' => $c->status,
                'details' => $c->details,
                'created_at' => $c->created_at?->toIso8601String(),
            ]);

        return Inertia::render('crm/accounts/show', [
            'account' => $this->serializeAccount($crmAccount, withVersions: true),
            'conflicts' => $conflicts,
            'canManageMatches' => $request->user()?->can('manage-crm-matches') ?? false,
        ]);
    }

    public function storeProvisional(Request $request): JsonResponse
    {
        $this->authorize('create-provisional-crm-accounts');
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'type' => ['required', 'in:customer,agency'],
            'billing_email' => ['nullable', 'string', 'max:255'],
            'matching_domain' => ['nullable', 'string', 'max:255'],
        ]);

        $account = $this->accounts->createProvisional([
            'name' => $validated['name'],
            'type' => CrmAccountType::from($validated['type']),
            'billing_email' => $validated['billing_email'] ?? null,
            'matching_domain' => $validated['matching_domain'] ?? null,
        ], $request->user());

        return response()->json([
            'account' => $this->serializeAccount($account->load('currentVersion')),
        ], 201);
    }

    public function search(Request $request): JsonResponse
    {
        $this->authorize('view-crm-accounts');
        $validated = $request->validate([
            'q' => ['nullable', 'string', 'max:120'],
            'type' => ['nullable', 'in:customer,agency'],
            'salesforce_only' => ['nullable', 'boolean'],
        ]);

        $query = $this->baseAccountQuery()->orderByDesc('id')->limit(30);
        $this->applyTypeFilter($query, $validated['type'] ?? null);
        if ($request->boolean('salesforce_only')) {
            $query->whereNotNull('salesforce_account_id_canonical')
                ->where('is_provisional', false);
        }
        $this->applyTextSearch($query, isset($validated['q']) ? trim((string) $validated['q']) : null);

        return response()->json([
            'accounts' => $query->get()->map(fn (CrmAccount $a): array => $this->serializeAccount($a)),
        ]);
    }

    public function link(Request $request, CrmAccount $crmAccount): JsonResponse
    {
        $this->authorize('manage-crm-matches');
        $validated = $request->validate([
            'salesforce_account_id' => ['required', 'integer', 'min:1'],
        ]);

        $target = CrmAccount::query()
            ->whereKey((int) $validated['salesforce_account_id'])
            ->firstOrFail();
        $linkResult = $this->accounts->linkProvisionalToSalesforce($crmAccount, $target, $request->user());
        $linked = $linkResult['account'];

        return response()->json([
            'account' => $this->serializeAccount($linked->load('currentVersion')),
        ]);
    }

    public function resolveConflict(Request $request, CrmConflict $crmConflict): JsonResponse
    {
        $this->authorize('manage-crm-matches');
        $validated = $request->validate([
            'note' => ['nullable', 'string', 'max:1000'],
        ]);

        $resolved = $this->accounts->resolveConflict(
            $crmConflict,
            $request->user(),
            $validated['note'] ?? null,
        );

        return response()->json([
            'conflict' => [
                'id' => $resolved->id,
                'type' => $resolved->type->value,
                'status' => $resolved->status,
                'details' => $resolved->details,
                'resolved_at' => $resolved->resolved_at?->toIso8601String(),
            ],
        ]);
    }

    public function matchQueue(Request $request): Response
    {
        $this->authorize('view-crm-accounts');

        $provisionals = CrmAccount::query()
            ->with('currentVersion')
            ->where('is_provisional', true)
            ->whereNull('salesforce_account_id_canonical')
            ->whereNull('merged_into_account_id')
            ->orderByDesc('id')
            ->limit(100)
            ->get();

        $openConflicts = CrmConflict::query()
            ->with('account.currentVersion')
            ->where('status', 'open')
            ->orderByDesc('id')
            ->limit(100)
            ->get();

        $relatedIds = $this->collectConflictAccountIds($openConflicts);
        $relatedModels = $relatedIds === []
            ? collect()
            : CrmAccount::query()
                ->with('currentVersion')
                ->whereIn('id', $relatedIds)
                ->get()
                ->keyBy('id');
        $relatedById = $relatedModels->map(
            fn (CrmAccount $account): array => $this->serializeAccount($account),
        );

        [$calcIds, $dispoIds] = $this->collectConflictEntityIds($openConflicts);
        $calculationsById = $calcIds === []
            ? collect()
            : DB::table('calculations')->whereIn('id', $calcIds)->get(['id', 'number'])->keyBy('id');
        $dispoOrdersById = $dispoIds === []
            ? collect()
            : DB::table('dispo_orders')->whereIn('id', $dispoIds)->get(['id', 'number', 'status'])->keyBy('id');

        $viewer = $request->user();
        $canViewCalculations = $viewer?->canAccessCalculations() ?? false;
        $canViewDispoOrders = $viewer?->canViewDispoOrders() ?? false;

        $conflicts = $openConflicts->map(function (CrmConflict $c) use (
            $relatedById,
            $calculationsById,
            $dispoOrdersById,
            $canViewCalculations,
            $canViewDispoOrders,
        ): array {
            $presentation = CrmConflictPresentation::summarize(
                $c,
                $relatedById,
                $calculationsById,
                $dispoOrdersById,
                $canViewCalculations,
                $canViewDispoOrders,
            );

            return [
                'id' => $c->id,
                'type' => $c->type->value,
                'type_label' => $this->conflictTypeLabel($c->type->value),
                'details' => $c->details,
                'summary_lines' => $presentation['summary_lines'],
                'account_expected' => $presentation['account_expected'],
                'entity_link' => $presentation['entity_link'],
                'technical_details' => $presentation['technical_details'],
                'account' => $c->account ? $this->serializeAccount($c->account) : null,
            ];
        });

        $rows = $provisionals->map(function (CrmAccount $account): array {
            $candidates = [];
            if ($account->matching_domain && ! $this->accounts->isSharedDomain($account->matching_domain)) {
                $candidates = array_map(
                    fn (CrmAccount $c): array => $this->serializeAccount($c),
                    $this->accounts->findSalesforceCandidatesByDomain($account->type, $account->matching_domain),
                );
            }

            return [
                'account' => $this->serializeAccount($account),
                'candidates' => $candidates,
                'shared_domain' => $account->matching_domain
                    ? $this->accounts->isSharedDomain($account->matching_domain)
                    : false,
            ];
        });

        return Inertia::render('crm/accounts/match-queue', [
            'rows' => $rows,
            'conflicts' => $conflicts,
            'canManageMatches' => $request->user()?->can('manage-crm-matches') ?? false,
        ]);
    }

    /**
     * @return Builder<CrmAccount>
     */
    private function baseAccountQuery(): Builder
    {
        return CrmAccount::query()
            ->with('currentVersion')
            ->whereNull('merged_into_account_id');
    }

    /**
     * @param  Builder<CrmAccount>  $query
     */
    private function applyTypeFilter(Builder $query, ?string $type): void
    {
        if ($type === 'customer' || $type === 'agency') {
            $query->where('type', $type);
        }
    }

    /**
     * @param  Builder<CrmAccount>  $query
     */
    private function applyTextSearch(Builder $query, ?string $q): void
    {
        if ($q === null || $q === '') {
            return;
        }

        $like = '%'.$q.'%';
        $query->where(function (Builder $builder) use ($like): void {
            $builder->where('salesforce_account_id_raw', 'like', $like)
                ->orWhere('salesforce_account_id_canonical', 'like', $like)
                ->orWhere('matching_domain', 'like', $like)
                ->orWhereHas('currentVersion', function (Builder $version) use ($like): void {
                    $version->where('name', 'like', $like)
                        ->orWhere('meridian_number', 'like', $like);
                });
        });
    }

    private function optionalSearchQuery(Request $request): ?string
    {
        $raw = $request->query('q');
        if (! is_string($raw)) {
            return null;
        }

        $trimmed = trim($raw);
        if ($trimmed === '') {
            return null;
        }

        return mb_substr($trimmed, 0, 120);
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

    private function conflictTypeLabel(string $type): string
    {
        return match ($type) {
            'meridian_mismatch' => 'Abweichende Meridian-Nummer (Stammdaten)',
            'order_meridian_mismatch' => 'Abweichende Meridian-Nummer (Auftrag)',
            'type_change' => 'Typwechsel Kunde/Agentur',
            'ambiguous_domain' => 'Mehrdeutige Domain',
            'divergent_domains' => 'Unterschiedliche Domains in einer Zeile',
            'link_conflict' => 'Widersprüchliche Zuordnung',
            'invalid_salesforce_id' => 'Ungültige Salesforce-ID',
            'duplicate_contradictory_row' => 'Widersprüchliche Dublette',
            default => $type,
        };
    }

    /**
     * @param  Collection<int, CrmConflict>  $conflicts
     * @return list<int>
     */
    private function collectConflictAccountIds($conflicts): array
    {
        $ids = [];
        foreach ($conflicts as $conflict) {
            $details = $conflict->details ?? [];
            foreach (['existing_target_id', 'requested_target_id', 'crm_account_id'] as $key) {
                if (isset($details[$key]) && is_numeric($details[$key])) {
                    $ids[] = (int) $details[$key];
                }
            }
            $candidates = $details['candidate_ids'] ?? null;
            if (is_array($candidates)) {
                foreach ($candidates as $candidateId) {
                    if (is_numeric($candidateId)) {
                        $ids[] = (int) $candidateId;
                    }
                }
            }
        }

        return array_values(array_unique(array_filter($ids, fn (int $id): bool => $id > 0)));
    }

    /**
     * @param  Collection<int, CrmConflict>  $conflicts
     * @return array{0: list<int>, 1: list<int>}
     */
    private function collectConflictEntityIds($conflicts): array
    {
        $calcIds = [];
        $dispoIds = [];
        foreach ($conflicts as $conflict) {
            $details = $conflict->details ?? [];
            $entity = $details['entity'] ?? null;
            $entityId = $details['entity_id'] ?? null;
            if (! is_numeric($entityId) || (int) $entityId <= 0) {
                continue;
            }
            $id = (int) $entityId;
            if ($entity === 'calculation') {
                $calcIds[] = $id;
            } elseif ($entity === 'dispo_order') {
                $dispoIds[] = $id;
            }
        }

        return [
            array_values(array_unique($calcIds)),
            array_values(array_unique($dispoIds)),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeAccount(CrmAccount $account, bool $withVersions = false): array
    {
        $version = $account->currentVersion;
        $data = [
            'id' => $account->id,
            'type' => $account->type->value,
            'type_label' => $account->type->label(),
            'is_provisional' => $account->is_provisional,
            'matching_domain' => $account->matching_domain,
            'salesforce_account_id_raw' => $account->salesforce_account_id_raw,
            'salesforce_account_id_canonical' => $account->salesforce_account_id_canonical,
            'merged_into_account_id' => $account->merged_into_account_id,
            'name' => $version?->name,
            'billing_email' => $version?->billing_email,
            'meridian_number' => $version?->meridian_number,
            'salesforce_record_type' => $version?->salesforce_record_type,
            'meridian_pending' => $version === null || $version->meridian_number === null || $version->meridian_number === '',
            'version_number' => $version?->version_number,
        ];

        $data['label'] = CrmAccountPresentation::fromSerialized($data);

        if ($withVersions) {
            $data['versions'] = $account->versions->map(fn ($v): array => [
                'id' => $v->id,
                'version_number' => $v->version_number,
                'name' => $v->name,
                'billing_email' => $v->billing_email,
                'matching_domain' => $v->matching_domain,
                'meridian_number' => $v->meridian_number,
                'salesforce_record_type' => $v->salesforce_record_type,
                'source' => $v->source->value,
                'created_at' => $v->created_at?->toIso8601String(),
                'created_by' => $v->createdBy?->name,
            ])->all();
        }

        return $data;
    }
}
