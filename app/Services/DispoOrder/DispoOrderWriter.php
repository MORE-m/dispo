<?php

namespace App\Services\DispoOrder;

use App\Enums\DispoOrderStatus;
use App\Models\Calculation;
use App\Models\CalculationPosition;
use App\Models\DispoOrder;
use App\Models\DispoOrderPosition;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use Illuminate\Database\QueryException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class DispoOrderWriter
{
    private const MAX_DEADLOCK_RETRIES = 5;

    public function __construct(
        private readonly DispoOrderNumberSequencer $numbers,
        private readonly DispoOrderSnapshotMapper $mapper,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @param  list<int>  $positionIds
     */
    public function createFromCalculation(Calculation $calculation, array $positionIds, User $user): DispoOrderWriterResult
    {
        $attempt = 0;

        while (true) {
            try {
                return DB::transaction(function () use ($calculation, $positionIds, $user): DispoOrderWriterResult {
                    return $this->createWithinTransaction($calculation, $positionIds, $user);
                }, self::MAX_DEADLOCK_RETRIES);
            } catch (QueryException $exception) {
                $attempt++;

                if (! $this->numbers->isRetryable($exception) || $attempt >= self::MAX_DEADLOCK_RETRIES) {
                    throw $exception;
                }
            }
        }
    }

    /**
     * @param  list<int>  $positionIds
     */
    private function createWithinTransaction(Calculation $calculation, array $positionIds, User $user): DispoOrderWriterResult
    {
        $calculation->loadMissing([
            'advisor',
            'orderDiscounts',
            'positions.inventory',
            'positions.advertisingMedium',
            'positions.priceList',
            'positions.planRows',
            'positions.timeRanges',
            'positions.discounts',
        ]);

        if ($calculation->positions->isEmpty()) {
            throw ValidationException::withMessages([
                'position_ids' => 'Die Kalkulation enthält keine Positionen.',
            ]);
        }

        $uniqueIds = array_values(array_unique($positionIds));

        if ($uniqueIds === []) {
            throw ValidationException::withMessages([
                'position_ids' => 'Mindestens eine Position muss ausgewählt werden.',
            ]);
        }

        /** @var Collection<int, CalculationPosition> $selected */
        $selected = $calculation->positions
            ->whereIn('id', $uniqueIds)
            ->values();

        if ($selected->count() !== count($uniqueIds)) {
            throw ValidationException::withMessages([
                'position_ids' => 'Eine oder mehrere Positionen gehören nicht zu dieser Kalkulation.',
            ]);
        }

        [$year, $orgSeq, $calcSeq, $number] = $this->numbers->next($calculation);

        $order = new DispoOrder;
        $order->calculation_id = $calculation->id;
        $order->number = $number;
        $order->number_year = $year;
        $order->number_org_seq = $orgSeq;
        $order->number_calc_seq = $calcSeq;
        $order->status = DispoOrderStatus::Draft;
        $order->created_by_id = $user->id;
        $order->lock_version = 1;
        $order->fill($this->mapper->headerFromCalculation($calculation));
        $order->save();

        $sort = 0;
        foreach ($selected as $position) {
            $snapshot = $this->mapper->positionFromCalculationPosition($position, $sort);
            $orderPosition = new DispoOrderPosition;
            $orderPosition->dispo_order_id = $order->id;
            $orderPosition->fill($snapshot);
            $orderPosition->save();
            $sort++;
        }

        $fresh = $this->reloadOrder($order);
        $result = new DispoOrderWriterResult($fresh, $uniqueIds);

        $this->audit->record(
            $fresh,
            'dispo_order.created',
            $user,
            null,
            $this->mapper->orderSnapshot($result),
        );

        return $result;
    }

    private function reloadOrder(DispoOrder $order): DispoOrder
    {
        $order->refresh();
        $order->load(['positions', 'creator', 'advisor', 'calculation']);

        return $order;
    }
}
