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
     * @return array{calculations: int, dispo_orders: int, conflicts: int}
     */
    public function supplementMissing(
        CrmAccount $account,
        string $meridianNumber,
        User $actor,
        ?int $importId = null,
    ): array {
        $meridianNumber = trim($meridianNumber);
        if ($meridianNumber === '') {
            return ['calculations' => 0, 'dispo_orders' => 0, 'conflicts' => 0];
        }

        $accountIds = $this->identityAccountIds($account);
        $sfRaw = $account->salesforce_account_id_raw;
        $calcUpdated = 0;
        $dispoUpdated = 0;
        $conflicts = 0;

        DB::transaction(function () use (
            $account,
            $accountIds,
            $meridianNumber,
            $sfRaw,
            $actor,
            $importId,
            &$calcUpdated,
            &$dispoUpdated,
            &$conflicts,
        ): void {
            $calcRows = DB::table('calculations')
                ->where(function ($q) use ($accountIds): void {
                    $q->whereIn('customer_account_id', $accountIds)
                        ->orWhereIn('agency_account_id', $accountIds);
                })
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
                        $this->openOrderConflict($account, $importId, [
                            'entity' => 'calculation',
                            'entity_id' => (int) $row->id,
                            'field' => 'customer_meridian_number',
                            'existing' => $row->customer_meridian_number,
                            'incoming' => $meridianNumber,
                        ]);
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
                        $this->openOrderConflict($account, $importId, [
                            'entity' => 'calculation',
                            'entity_id' => (int) $row->id,
                            'field' => 'agency_meridian_number',
                            'existing' => $row->agency_meridian_number,
                            'incoming' => $meridianNumber,
                        ]);
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
                        $this->openOrderConflict($account, $importId, [
                            'entity' => 'dispo_order',
                            'entity_id' => (int) $row->id,
                            'field' => 'customer_meridian_number',
                            'existing' => $row->customer_meridian_number,
                            'incoming' => $meridianNumber,
                            'order_status' => $row->status,
                        ]);
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
                        $this->openOrderConflict($account, $importId, [
                            'entity' => 'dispo_order',
                            'entity_id' => (int) $row->id,
                            'field' => 'agency_meridian_number',
                            'existing' => $row->agency_meridian_number,
                            'incoming' => $meridianNumber,
                            'order_status' => $row->status,
                        ]);
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
            'conflicts' => $conflicts,
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

        $accountIds = $this->identityAccountIds($account);
        $calcUpdated = 0;
        $dispoUpdated = 0;

        DB::transaction(function () use ($accountIds, $sfRaw, $actor, $importId, &$calcUpdated, &$dispoUpdated): void {
            $calcRows = DB::table('calculations')
                ->where(function ($q) use ($accountIds): void {
                    $q->whereIn('customer_account_id', $accountIds)
                        ->orWhereIn('agency_account_id', $accountIds);
                })
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
     * @param  array<string, mixed>  $details
     */
    private function openOrderConflict(CrmAccount $account, ?int $importId, array $details): void
    {
        CrmConflict::query()->create([
            'crm_account_id' => $account->id,
            'crm_import_id' => $importId,
            'type' => CrmConflictType::OrderMeridianMismatch,
            'status' => 'open',
            'details' => $details,
        ]);
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
