<?php

namespace App\Services\Crm;

use App\Exceptions\Crm\CrmAccountLockSetExpandedException;
use App\Models\CrmAccount;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Gemeinsame Account-Identitäts-Sperren für Link, Supplement und Import-Nachtrag.
 *
 * Reihenfolge: alle benötigten Account-IDs aufsteigend → danach Aufträge/Konflikte
 * in den aufrufenden Services. Keine nachträgliche Sperre kleinerer IDs bei
 * gehaltenen größeren Locks; bei erweiterter Menge Transaktion kontrolliert neu.
 */
final class CrmAccountIdentityLocker
{
    /**
     * @param  array<int, int>  $extraIds
     * @return array{canonical: CrmAccount, account_ids: list<int>}
     */
    public function lockIdentity(CrmAccount $account, array $extraIds = []): array
    {
        $seed = array_values(array_unique(array_merge(
            $this->discoverIdentityIds($account),
            $extraIds,
        )));
        $lockedIds = $this->lockIdsAscending($seed);

        $start = $this->currentRead($account->id);
        $canonical = $this->resolveCanonicalFromLocked($start, $lockedIds);
        $accountIds = $this->probeIdentityIds($canonical);

        $missing = array_values(array_diff($accountIds, $lockedIds));
        if ($missing !== []) {
            throw new CrmAccountLockSetExpandedException(array_values(array_unique(array_merge($lockedIds, $missing))));
        }

        // Bereits gesperrte Alias-Zeilen als Current Read bestätigen.
        $this->lockIdsAscending($accountIds);

        return ['canonical' => $canonical, 'account_ids' => $accountIds];
    }

    /**
     * Sperrt Quelle, kanonisches Ziel und Aliasse/Merge-Ketten vor Link/Repoint.
     *
     * @param  array<int, int>  $extraIds
     * @return array{
     *     provisional: CrmAccount,
     *     target: CrmAccount,
     *     account_ids: list<int>
     * }
     */
    public function lockForLink(CrmAccount $provisional, CrmAccount $salesforceAccount, array $extraIds = []): array
    {
        $seed = array_values(array_unique(array_merge(
            [$provisional->id, $salesforceAccount->id],
            $this->discoverIdentityIds($provisional),
            $this->discoverIdentityIds($salesforceAccount),
            $extraIds,
        )));
        $lockedIds = $this->lockIdsAscending($seed);

        $provisional = $this->currentRead($provisional->id);
        $targetStart = $this->currentRead($salesforceAccount->id);
        $target = $this->resolveCanonicalFromLocked($targetStart, $lockedIds);

        $needed = array_values(array_unique(array_merge(
            [$provisional->id, $target->id],
            $this->probeIdentityIds($target),
            $this->probeMergeChainIds($provisional),
            $this->probeMergeChainIds($target),
        )));

        $missing = array_values(array_diff($needed, $lockedIds));
        if ($missing !== []) {
            throw new CrmAccountLockSetExpandedException(array_values(array_unique(array_merge($lockedIds, $missing))));
        }

        $this->lockIdsAscending($needed);

        return [
            'provisional' => $provisional,
            'target' => $this->currentRead($target->id),
            'account_ids' => $needed,
        ];
    }

    /**
     * @template T
     *
     * @param  callable(array<int, int>): T  $callback
     * @return T
     */
    public function withStableAccountLocks(callable $callback): mixed
    {
        /** @var array<int, int> $extraIds */
        $extraIds = [];
        for ($attempt = 0; $attempt < 2; $attempt++) {
            try {
                return DB::transaction(function () use ($callback, $extraIds) {
                    return $callback($extraIds);
                });
            } catch (CrmAccountLockSetExpandedException $exception) {
                $extraIds = $exception->accountIds();
            }
        }

        throw new RuntimeException('Account-Sperrmenge konnte nicht stabil ermittelt werden.');
    }

    /**
     * @param  list<int>  $ids
     * @return list<int>
     */
    public function lockIdsAscending(array $ids): array
    {
        $ids = array_values(array_unique(array_map('intval', $ids)));
        sort($ids);
        if ($ids === []) {
            return [];
        }

        CrmAccount::query()
            ->whereIn('id', $ids)
            ->orderBy('id')
            ->lockForUpdate()
            ->get(['id']);

        return $ids;
    }

    /**
     * @return list<int>
     */
    public function discoverIdentityIds(CrmAccount $account): array
    {
        $canonical = $this->resolveCanonicalRead($account);

        return array_values(array_unique(array_merge(
            [$account->id],
            $this->probeMergeChainIds($account),
            $this->probeIdentityIds($canonical),
        )));
    }

    private function currentRead(int $id): CrmAccount
    {
        return CrmAccount::query()->whereKey($id)->lockForUpdate()->firstOrFail();
    }

    /**
     * @param  list<int>  $lockedIds
     */
    private function resolveCanonicalFromLocked(CrmAccount $account, array $lockedIds): CrmAccount
    {
        $current = $account;
        $guard = 0;
        while ($current->merged_into_account_id !== null && $guard < 10) {
            $nextId = (int) $current->merged_into_account_id;
            if (! in_array($nextId, $lockedIds, true)) {
                throw new CrmAccountLockSetExpandedException(array_values(array_unique(array_merge(
                    $lockedIds,
                    [$nextId],
                    $this->probeMergeChainIds($current),
                ))));
            }
            $current = $this->currentRead($nextId);
            $guard++;
        }

        return $current;
    }

    private function resolveCanonicalRead(CrmAccount $account): CrmAccount
    {
        $current = $account;
        $guard = 0;
        while ($current->merged_into_account_id !== null && $guard < 10) {
            $next = CrmAccount::query()->find($current->merged_into_account_id);
            if ($next === null) {
                break;
            }
            $current = $next;
            $guard++;
        }

        return $current;
    }

    /**
     * ID-Liste ohne neue Sperren (Probe). Fehlende IDs → Expansion, nicht Nachsperren.
     *
     * @return list<int>
     */
    private function probeIdentityIds(CrmAccount $canonical): array
    {
        $merged = CrmAccount::query()
            ->where('merged_into_account_id', $canonical->id)
            ->orderBy('id')
            ->pluck('id')
            ->all();

        return array_values(array_unique(array_merge([$canonical->id], array_map('intval', $merged))));
    }

    /**
     * @return list<int>
     */
    private function probeMergeChainIds(CrmAccount $account): array
    {
        $ids = [$account->id];
        $current = $account;
        $guard = 0;
        while ($current->merged_into_account_id !== null && $guard < 10) {
            $ids[] = (int) $current->merged_into_account_id;
            $next = CrmAccount::query()->find($current->merged_into_account_id);
            if ($next === null) {
                break;
            }
            $current = $next;
            $guard++;
        }

        return $ids;
    }
}
