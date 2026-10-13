<?php

namespace App\Services\Crm;

use App\Enums\CrmConflictType;
use App\Models\Calculation;
use App\Models\CrmAccount;
use App\Models\CrmConflict;
use App\Models\DispoOrder;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use Illuminate\Support\Facades\DB;

/**
 * Gezielter technischer Nachtrag (E1): fehlende Meridian-/Salesforce-IDs,
 * keine Freigabeinvalidierung, keine Mutation von Firmierung/Preisen/Status/Versionen.
 */
final class CrmMeridianSupplementService
{
    public function __construct(
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @return array{
     *     calculations: int,
     *     dispo_orders: int,
     *     conflicts: int,
     *     conflicts_created: int
     * }
     */
    public function supplementMissing(
        CrmAccount $account,
        string $meridianNumber,
        User $actor,
        ?int $importId = null,
    ): array {
        $meridianNumber = trim($meridianNumber);
        if ($meridianNumber === '') {
            return [
                'calculations' => 0,
                'dispo_orders' => 0,
                'conflicts' => 0,
                'conflicts_created' => 0,
            ];
        }

        $calcUpdated = 0;
        $dispoUpdated = 0;
        $conflicts = 0;
        $conflictsCreated = 0;

        DB::transaction(function () use (
            $account,
            $meridianNumber,
            $actor,
            $importId,
            &$calcUpdated,
            &$dispoUpdated,
            &$conflicts,
            &$conflictsCreated,
        ): void {
            // Sperrreihenfolge (wie Import/Link): Accounts (id ASC) → Aufträge → Konflikte.
            [$lockedAccount, $accountIds] = $this->lockIdentityAccounts($account);
            $sfRaw = $lockedAccount->salesforce_account_id_raw;

            $calcRows = DB::table('calculations')
                ->where(function ($q) use ($accountIds): void {
                    $q->whereIn('customer_account_id', $accountIds)
                        ->orWhereIn('agency_account_id', $accountIds);
                })
                ->orderBy('id')
                ->lockForUpdate()
                ->get([
                    'id',
                    'customer_account_id',
                    'agency_account_id',
                    'customer_meridian_number',
                    'agency_meridian_number',
                    'customer_salesforce_account_id',
                    'agency_salesforce_account_id',
                ]);

            foreach ($calcRows as $row) {
                $updates = [];
                $sideConflicts = 0;

                if (in_array((int) $row->customer_account_id, $accountIds, true)) {
                    [$meridianUpdate, $conflicted] = $this->planMeridianField(
                        $row->customer_meridian_number,
                        $meridianNumber,
                    );
                    if ($conflicted) {
                        $sideConflicts++;
                        if ($this->openOrderConflict($lockedAccount, $importId, [
                            'entity' => 'calculation',
                            'entity_id' => (int) $row->id,
                            'field' => 'customer_meridian_number',
                            'existing' => $row->customer_meridian_number,
                            'incoming' => $meridianNumber,
                        ])) {
                            $conflictsCreated++;
                        }
                    } elseif ($meridianUpdate !== null) {
                        $updates['customer_meridian_number'] = $meridianUpdate;
                    }
                    if ($sfRaw !== null && $sfRaw !== ''
                        && ($row->customer_salesforce_account_id === null || $row->customer_salesforce_account_id === '')) {
                        $updates['customer_salesforce_account_id'] = $sfRaw;
                    }
                }

                if (in_array((int) $row->agency_account_id, $accountIds, true)) {
                    [$meridianUpdate, $conflicted] = $this->planMeridianField(
                        $row->agency_meridian_number,
                        $meridianNumber,
                    );
                    if ($conflicted) {
                        $sideConflicts++;
                        if ($this->openOrderConflict($lockedAccount, $importId, [
                            'entity' => 'calculation',
                            'entity_id' => (int) $row->id,
                            'field' => 'agency_meridian_number',
                            'existing' => $row->agency_meridian_number,
                            'incoming' => $meridianNumber,
                        ])) {
                            $conflictsCreated++;
                        }
                    } elseif ($meridianUpdate !== null) {
                        $updates['agency_meridian_number'] = $meridianUpdate;
                    }
                    if ($sfRaw !== null && $sfRaw !== ''
                        && ($row->agency_salesforce_account_id === null || $row->agency_salesforce_account_id === '')) {
                        $updates['agency_salesforce_account_id'] = $sfRaw;
                    }
                }

                $conflicts += $sideConflicts;
                if ($updates !== []) {
                    DB::table('calculations')->where('id', $row->id)->update($updates);
                    $calcUpdated++;
                    $this->audit->record(
                        Calculation::query()->whereKey((int) $row->id)->firstOrFail(),
                        'crm.meridian_supplemented',
                        $actor,
                        null,
                        [
                            'fields' => array_keys($updates),
                            'meridian_number' => $meridianNumber,
                            'import_id' => $importId,
                            'approval_invalidated' => false,
                        ],
                    );
                }
            }

            $dispoRows = DB::table('dispo_orders')
                ->where(function ($q) use ($accountIds): void {
                    $q->whereIn('customer_account_id', $accountIds)
                        ->orWhereIn('agency_account_id', $accountIds);
                })
                ->orderBy('id')
                ->lockForUpdate()
                ->get([
                    'id',
                    'customer_account_id',
                    'agency_account_id',
                    'customer_meridian_number',
                    'agency_meridian_number',
                    'customer_salesforce_account_id',
                    'agency_salesforce_account_id',
                    'status',
                ]);

            foreach ($dispoRows as $row) {
                $updates = [];
                $sideConflicts = 0;

                if (in_array((int) $row->customer_account_id, $accountIds, true)) {
                    [$meridianUpdate, $conflicted] = $this->planMeridianField(
                        $row->customer_meridian_number,
                        $meridianNumber,
                    );
                    if ($conflicted) {
                        $sideConflicts++;
                        if ($this->openOrderConflict($lockedAccount, $importId, [
                            'entity' => 'dispo_order',
                            'entity_id' => (int) $row->id,
                            'field' => 'customer_meridian_number',
                            'existing' => $row->customer_meridian_number,
                            'incoming' => $meridianNumber,
                            'order_status' => $row->status,
                        ])) {
                            $conflictsCreated++;
                        }
                    } elseif ($meridianUpdate !== null) {
                        $updates['customer_meridian_number'] = $meridianUpdate;
                    }
                    if ($sfRaw !== null && $sfRaw !== ''
                        && ($row->customer_salesforce_account_id === null || $row->customer_salesforce_account_id === '')) {
                        $updates['customer_salesforce_account_id'] = $sfRaw;
                    }
                }

                if (in_array((int) $row->agency_account_id, $accountIds, true)) {
                    [$meridianUpdate, $conflicted] = $this->planMeridianField(
                        $row->agency_meridian_number,
                        $meridianNumber,
                    );
                    if ($conflicted) {
                        $sideConflicts++;
                        if ($this->openOrderConflict($lockedAccount, $importId, [
                            'entity' => 'dispo_order',
                            'entity_id' => (int) $row->id,
                            'field' => 'agency_meridian_number',
                            'existing' => $row->agency_meridian_number,
                            'incoming' => $meridianNumber,
                            'order_status' => $row->status,
                        ])) {
                            $conflictsCreated++;
                        }
                    } elseif ($meridianUpdate !== null) {
                        $updates['agency_meridian_number'] = $meridianUpdate;
                    }
                    if ($sfRaw !== null && $sfRaw !== ''
                        && ($row->agency_salesforce_account_id === null || $row->agency_salesforce_account_id === '')) {
                        $updates['agency_salesforce_account_id'] = $sfRaw;
                    }
                }

                $conflicts += $sideConflicts;
                if ($updates !== []) {
                    DB::table('dispo_orders')->where('id', $row->id)->update($updates);
                    $dispoUpdated++;
                    $this->audit->record(
                        DispoOrder::query()->whereKey((int) $row->id)->firstOrFail(),
                        'crm.meridian_supplemented',
                        $actor,
                        null,
                        [
                            'fields' => array_keys($updates),
                            'meridian_number' => $meridianNumber,
                            'import_id' => $importId,
                            'order_status' => $row->status,
                            'approval_invalidated' => false,
                        ],
                    );
                }
            }
        });

        return [
            'calculations' => $calcUpdated,
            'dispo_orders' => $dispoUpdated,
            // Erkannte Widersprüche (fachlich), unabhängig von Doppelaufrufen.
            'conflicts' => $conflicts,
            // Neu angelegte offene Konfliktdatensätze (idempotent).
            'conflicts_created' => $conflictsCreated,
        ];
    }

    /**
     * Nur fehlende Salesforce-IDs auf Aufträgen (ohne Meridian-Änderung).
     *
     * @return array{calculations: int, dispo_orders: int}
     */
    public function supplementSalesforceIds(CrmAccount $account, User $actor, ?int $importId = null): array
    {
        $sfRaw = $account->salesforce_account_id_raw;
        if ($sfRaw === null || $sfRaw === '') {
            return ['calculations' => 0, 'dispo_orders' => 0];
        }

        $calcUpdated = 0;
        $dispoUpdated = 0;

        DB::transaction(function () use ($account, $sfRaw, $actor, $importId, &$calcUpdated, &$dispoUpdated): void {
            [, $accountIds] = $this->lockIdentityAccounts($account);

            $calcRows = DB::table('calculations')
                ->where(function ($q) use ($accountIds): void {
                    $q->whereIn('customer_account_id', $accountIds)
                        ->orWhereIn('agency_account_id', $accountIds);
                })
                ->orderBy('id')
                ->lockForUpdate()
                ->get([
                    'id',
                    'customer_account_id',
                    'agency_account_id',
                    'customer_salesforce_account_id',
                    'agency_salesforce_account_id',
                ]);

            foreach ($calcRows as $row) {
                $updates = [];
                if (in_array((int) $row->customer_account_id, $accountIds, true)
                    && ($row->customer_salesforce_account_id === null || $row->customer_salesforce_account_id === '')) {
                    $updates['customer_salesforce_account_id'] = $sfRaw;
                }
                if (in_array((int) $row->agency_account_id, $accountIds, true)
                    && ($row->agency_salesforce_account_id === null || $row->agency_salesforce_account_id === '')) {
                    $updates['agency_salesforce_account_id'] = $sfRaw;
                }
                if ($updates !== []) {
                    DB::table('calculations')->where('id', $row->id)->update($updates);
                    $calcUpdated++;
                    $this->audit->record(
                        Calculation::query()->whereKey((int) $row->id)->firstOrFail(),
                        'crm.salesforce_supplemented',
                        $actor,
                        null,
                        [
                            'fields' => array_keys($updates),
                            'salesforce_account_id' => $sfRaw,
                            'import_id' => $importId,
                            'approval_invalidated' => false,
                        ],
                    );
                }
            }

            $dispoRows = DB::table('dispo_orders')
                ->where(function ($q) use ($accountIds): void {
                    $q->whereIn('customer_account_id', $accountIds)
                        ->orWhereIn('agency_account_id', $accountIds);
                })
                ->orderBy('id')
                ->lockForUpdate()
                ->get([
                    'id',
                    'customer_account_id',
                    'agency_account_id',
                    'customer_salesforce_account_id',
                    'agency_salesforce_account_id',
                    'status',
                ]);

            foreach ($dispoRows as $row) {
                $updates = [];
                if (in_array((int) $row->customer_account_id, $accountIds, true)
                    && ($row->customer_salesforce_account_id === null || $row->customer_salesforce_account_id === '')) {
                    $updates['customer_salesforce_account_id'] = $sfRaw;
                }
                if (in_array((int) $row->agency_account_id, $accountIds, true)
                    && ($row->agency_salesforce_account_id === null || $row->agency_salesforce_account_id === '')) {
                    $updates['agency_salesforce_account_id'] = $sfRaw;
                }
                if ($updates !== []) {
                    DB::table('dispo_orders')->where('id', $row->id)->update($updates);
                    $dispoUpdated++;
                    $this->audit->record(
                        DispoOrder::query()->whereKey((int) $row->id)->firstOrFail(),
                        'crm.salesforce_supplemented',
                        $actor,
                        null,
                        [
                            'fields' => array_keys($updates),
                            'salesforce_account_id' => $sfRaw,
                            'import_id' => $importId,
                            'order_status' => $row->status,
                            'approval_invalidated' => false,
                        ],
                    );
                }
            }
        });

        return ['calculations' => $calcUpdated, 'dispo_orders' => $dispoUpdated];
    }

    /**
     * @return array{0: ?string, 1: bool} [updateValue|null, isConflict]
     */
    private function planMeridianField(?string $existing, string $incoming): array
    {
        if ($existing === null || $existing === '') {
            return [$incoming, false];
        }
        if ($existing === $incoming) {
            return [null, false];
        }

        return [null, true];
    }

    /**
     * Öffnet einen Auftrags-Meridian-Konflikt idempotent.
     *
     * Fachliche Identität (offen): Account, Vorgangstyp, Vorgang, Seite (Feld),
     * bestehende und eingehende Meridian. Import-ID und Auftragsstatus allein
     * vervielfachen denselben offenen Widerspruch nicht. Abgeschlossene Konflikte
     * bleiben unberührt; ein erneuter Widerspruch nach Abschluss erzeugt einen
     * neuen offenen Datensatz (R5: Abweichung = Konflikt, keine Unterdrückung).
     *
     * @param  array<string, mixed>  $details
     * @return bool true, wenn ein neuer offener Konflikt angelegt wurde
     */
    private function openOrderConflict(CrmAccount $account, ?int $importId, array $details): bool
    {
        // Account ist in supplementMissing bereits gesperrt; erneutes lockForUpdate
        // ist in derselben Transaktion idempotent und absichert Direktaufrufe.
        CrmAccount::query()->whereKey($account->id)->lockForUpdate()->firstOrFail();

        $open = CrmConflict::query()
            ->where('crm_account_id', $account->id)
            ->where('type', CrmConflictType::OrderMeridianMismatch)
            ->where('status', 'open')
            ->orderBy('id')
            ->lockForUpdate()
            ->get();

        foreach ($open as $conflict) {
            if ($this->sameOpenOrderMeridianIdentity($conflict->details ?? [], $details)) {
                return false;
            }
        }

        CrmConflict::query()->create([
            'crm_account_id' => $account->id,
            'crm_import_id' => $importId,
            'type' => CrmConflictType::OrderMeridianMismatch,
            'status' => 'open',
            'details' => $details,
        ]);

        return true;
    }

    /**
     * @param  array<string, mixed>  $existing
     * @param  array<string, mixed>  $incoming
     */
    private function sameOpenOrderMeridianIdentity(array $existing, array $incoming): bool
    {
        return (string) ($existing['entity'] ?? '') === (string) ($incoming['entity'] ?? '')
            && (int) ($existing['entity_id'] ?? 0) === (int) ($incoming['entity_id'] ?? 0)
            && (string) ($existing['field'] ?? '') === (string) ($incoming['field'] ?? '')
            && (string) ($existing['existing'] ?? '') === (string) ($incoming['existing'] ?? '')
            && (string) ($existing['incoming'] ?? '') === (string) ($incoming['incoming'] ?? '');
    }

    /**
     * Sperrt die Account-Identität (Kanon + Aliasse) in aufsteigender id-Reihenfolge
     * und liefert die unter Sperre erneut ermittelte Alias-Liste.
     *
     * @return array{0: CrmAccount, 1: list<int>}
     */
    private function lockIdentityAccounts(CrmAccount $account): array
    {
        $canonicalGuess = $this->resolveCanonicalRead($account);
        $seedIds = array_values(array_unique(array_merge(
            [$account->id],
            $this->identityAccountIds($canonicalGuess),
            $this->mergeChainIds($account),
        )));
        sort($seedIds);

        CrmAccount::query()
            ->whereIn('id', $seedIds)
            ->orderBy('id')
            ->lockForUpdate()
            ->get(['id']);

        // Erneute Auflösung ohne zusätzliche Sperren (Zeilen sind bereits gelockt).
        $lockedStart = CrmAccount::query()->whereKey($account->id)->firstOrFail();
        $canonical = $this->resolveCanonicalRead($lockedStart);
        $accountIds = $this->identityAccountIds($canonical);

        $missing = array_values(array_diff($accountIds, $seedIds));
        if ($missing !== []) {
            sort($missing);
            CrmAccount::query()
                ->whereIn('id', $missing)
                ->orderBy('id')
                ->lockForUpdate()
                ->get(['id']);
            $canonical = $this->resolveCanonicalRead($lockedStart->fresh() ?? $lockedStart);
            $accountIds = $this->identityAccountIds($canonical);
        }

        return [$canonical, $accountIds];
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
     * @return list<int>
     */
    private function mergeChainIds(CrmAccount $account): array
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

    /**
     * @return list<int>
     */
    private function identityAccountIds(CrmAccount $account): array
    {
        $ids = [$account->id];
        $merged = CrmAccount::query()
            ->where('merged_into_account_id', $account->id)
            ->pluck('id')
            ->all();

        return array_values(array_unique(array_merge($ids, array_map('intval', $merged))));
    }
}
