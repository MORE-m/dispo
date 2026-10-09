<?php

namespace App\Http\Controllers\Crm;

use App\Enums\CrmAccountType;
use App\Http\Controllers\Controller;
use App\Models\CrmAccount;
use App\Models\CrmConflict;
use App\Services\Crm\CrmAccountService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * BL-P2-03a: Stammdaten, vorläufige Accounts, manuelle Zuordnung.
 */
class CrmAccountController extends Controller
{
    public function __construct(
        private readonly CrmAccountService $accounts,
    ) {}

    public function index(Request $request): Response
    {
        $this->authorize('view-crm-accounts');

        $type = $request->query('type');
        $query = CrmAccount::query()
            ->with('currentVersion')
            ->whereNull('merged_into_account_id')
            ->orderByDesc('id');

        if (in_array($type, ['customer', 'agency'], true)) {
            $query->where('type', $type);
        }
        if ($request->query('provisional') === '1') {
            $query->where('is_provisional', true);
        }

        $accounts = $query->limit(200)->get()->map(fn (CrmAccount $a): array => $this->serializeAccount($a));

        return Inertia::render('crm/accounts/index', [
            'accounts' => $accounts,
            'filters' => [
                'type' => $type,
                'provisional' => $request->query('provisional') === '1',
            ],
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
        ]);

        $query = CrmAccount::query()
            ->with('currentVersion')
            ->whereNull('merged_into_account_id')
            ->orderByDesc('id')
            ->limit(30);

        if (! empty($validated['type'])) {
            $query->where('type', $validated['type']);
        }
        if (! empty($validated['q'])) {
            $q = '%'.$validated['q'].'%';
            $query->where(function ($builder) use ($q): void {
                $builder->where('salesforce_account_id_raw', 'like', $q)
                    ->orWhere('matching_domain', 'like', $q)
                    ->orWhereHas('currentVersion', fn ($v) => $v->where('name', 'like', $q)
                        ->orWhere('meridian_number', 'like', $q));
            });
        }

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
        $linked = $this->accounts->linkProvisionalToSalesforce($crmAccount, $target, $request->user());

        return response()->json([
            'account' => $this->serializeAccount($linked->load('currentVersion')),
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
            ->get()
            ->map(fn (CrmConflict $c): array => [
                'id' => $c->id,
                'type' => $c->type->value,
                'details' => $c->details,
                'account' => $c->account ? $this->serializeAccount($c->account) : null,
            ]);

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
            'conflicts' => $openConflicts,
            'canManageMatches' => $request->user()?->can('manage-crm-matches') ?? false,
        ]);
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
            'meridian_pending' => $version === null || $version->meridian_number === null || $version->meridian_number === '',
            'version_number' => $version?->version_number,
        ];

        if ($withVersions) {
            $data['versions'] = $account->versions->map(fn ($v): array => [
                'id' => $v->id,
                'version_number' => $v->version_number,
                'name' => $v->name,
                'billing_email' => $v->billing_email,
                'matching_domain' => $v->matching_domain,
                'meridian_number' => $v->meridian_number,
                'source' => $v->source->value,
                'created_at' => $v->created_at?->toIso8601String(),
                'created_by' => $v->createdBy?->name,
            ])->all();
        }

        return $data;
    }
}
