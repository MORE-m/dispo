<?php

namespace App\Services\Crm;

use App\Models\Calculation;
use App\Models\CrmAccount;
use App\Models\DispoOrder;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use Illuminate\Support\Facades\DB;

/**
 * Gezielter Meridian-Nachtrag (E1): nur fehlende Nummern, keine Freigabeinvalidierung,
 * keine Mutation von Firmierung/Preisen/Status.
 */
final class CrmMeridianSupplementService
{
    public function __construct(
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @return array{calculations: int, dispo_orders: int}
     */
    public function supplementMissing(CrmAccount $account, string $meridianNumber, User $actor, ?int $importId = null): array
    {
        $meridianNumber = trim($meridianNumber);
        if ($meridianNumber === '') {
            return ['calculations' => 0, 'dispo_orders' => 0];
        }

        $accountIds = $this->identityAccountIds($account);
        $calcUpdated = 0;
        $dispoUpdated = 0;

        DB::transaction(function () use ($accountIds, $meridianNumber, $actor, $importId, &$calcUpdated, &$dispoUpdated): void {
            $calcRows = DB::table('calculations')
                ->where(function ($q) use ($accountIds): void {
                    $q->whereIn('customer_account_id', $accountIds)
                        ->orWhereIn('agency_account_id', $accountIds);
                })
                ->lockForUpdate()
                ->get(['id', 'customer_account_id', 'agency_account_id', 'customer_meridian_number', 'agency_meridian_number']);

            foreach ($calcRows as $row) {
                $updates = [];
                if (in_array((int) $row->customer_account_id, $accountIds, true)
                    && ($row->customer_meridian_number === null || $row->customer_meridian_number === '')) {
                    $updates['customer_meridian_number'] = $meridianNumber;
                }
                if (in_array((int) $row->agency_account_id, $accountIds, true)
                    && ($row->agency_meridian_number === null || $row->agency_meridian_number === '')) {
                    $updates['agency_meridian_number'] = $meridianNumber;
                }
                if ($updates !== []) {
                    DB::table('calculations')->where('id', $row->id)->update($updates);
                    $calcUpdated++;
                    $this->audit->record(
                        Calculation::query()->findOrFail($row->id),
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
                ->get(['id', 'customer_account_id', 'agency_account_id', 'customer_meridian_number', 'agency_meridian_number', 'status']);

            foreach ($dispoRows as $row) {
                $updates = [];
                if (in_array((int) $row->customer_account_id, $accountIds, true)
                    && ($row->customer_meridian_number === null || $row->customer_meridian_number === '')) {
                    $updates['customer_meridian_number'] = $meridianNumber;
                }
                if (in_array((int) $row->agency_account_id, $accountIds, true)
                    && ($row->agency_meridian_number === null || $row->agency_meridian_number === '')) {
                    $updates['agency_meridian_number'] = $meridianNumber;
                }
                if ($updates !== []) {
                    // Nur Meridian-Spalten – kein Status, keine Freigabelogik.
                    DB::table('dispo_orders')->where('id', $row->id)->update($updates);
                    $dispoUpdated++;
                    $this->audit->record(
                        DispoOrder::query()->findOrFail($row->id),
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

        return ['calculations' => $calcUpdated, 'dispo_orders' => $dispoUpdated];
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
