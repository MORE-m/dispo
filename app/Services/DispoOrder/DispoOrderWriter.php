<?php

namespace App\Services\DispoOrder;

use App\Enums\DispoOrderStatus;
use App\Exceptions\DispoOrderConflictException;
use App\Models\Calculation;
use App\Models\CalculationPosition;
use App\Models\DispoOrder;
use App\Models\DispoOrderPosition;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Services\DynamicField\DispoOrderDynamicFieldWriter;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
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
        private readonly DispoOrderDynamicFieldWriter $dynamicFields,
    ) {}

    /**
     * @param  list<int>  $positionIds
     */
    public function createFromCalculation(
        Calculation $calculation,
        array $positionIds,
        User $user,
        ?DispoOrder $revises = null,
    ): DispoOrderWriterResult {
        if ($revises !== null) {
            return $this->createRevision($revises, $calculation, $positionIds, $user);
        }

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
    public function createRevision(
        DispoOrder $predecessor,
        Calculation $calculation,
        array $positionIds,
        User $user,
    ): DispoOrderWriterResult {
        $attempt = 0;

        while (true) {
            try {
                return DB::transaction(function () use ($predecessor, $calculation, $positionIds, $user): DispoOrderWriterResult {
                    $locked = DispoOrder::query()
                        ->whereKey($predecessor->id)
                        ->lockForUpdate()
                        ->firstOrFail();

                    $this->assertRevisionPreconditions($locked, $calculation, $user);

                    $result = $this->createWithinTransaction($calculation, $positionIds, $user, $locked);

                    $this->auditRevisionCreated($result, $locked, $user);

                    return $result;
                }, self::MAX_DEADLOCK_RETRIES);
            } catch (UniqueConstraintViolationException) {
                throw new DispoOrderConflictException(
                    'Für diesen abgelehnten Dispoauftrag wurde bereits eine Nachbesserung angelegt.',
                );
            } catch (QueryException $exception) {
                if ($this->isUniqueRevisesViolation($exception)) {
                    throw new DispoOrderConflictException(
                        'Für diesen abgelehnten Dispoauftrag wurde bereits eine Nachbesserung angelegt.',
                    );
                }

                $attempt++;

                if (! $this->numbers->isRetryable($exception) || $attempt >= self::MAX_DEADLOCK_RETRIES) {
                    throw $exception;
                }
            }
        }
    }

    private function assertRevisionPreconditions(
        DispoOrder $predecessor,
        Calculation $calculation,
        User $user,
    ): void {
        if (! DispoOrderRevisionRules::predecessorStatusAllowed($predecessor)) {
            throw ValidationException::withMessages([
                'revises_dispo_order_id' => 'Nur abgelehnte Dispoaufträge können nachgebessert werden.',
            ]);
        }

        if (! DispoOrderRevisionRules::sameCalculation($predecessor, $calculation)) {
            throw ValidationException::withMessages([
                'revises_dispo_order_id' => 'Vorgänger und Kalkulation müssen übereinstimmen.',
            ]);
        }

        if ($predecessor->hasRevision()) {
            throw new DispoOrderConflictException(
                'Für diesen abgelehnten Dispoauftrag wurde bereits eine Nachbesserung angelegt.',
            );
        }

        if ((int) $user->id !== (int) $predecessor->created_by_id
            || ! $user->can('update', $calculation)
        ) {
            throw ValidationException::withMessages([
                'revises_dispo_order_id' => 'Keine Berechtigung zur Nachbesserung dieses Dispoauftrags.',
            ]);
        }
    }

    /**
     * @param  list<int>  $positionIds
     */
    private function createWithinTransaction(
        Calculation $calculation,
        array $positionIds,
        User $user,
        ?DispoOrder $revises = null,
    ): DispoOrderWriterResult {
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

        $calculation->loadMissing([
            'configurationSnapshot.fieldDefinitions',
            'configurationSnapshot.rules',
        ]);
        if ($calculation->configurationSnapshot === null) {
            throw ValidationException::withMessages([
                'calculation' => 'Die Kalkulation besitzt keinen Konfigurationssnapshot.',
            ]);
        }

        $order = new DispoOrder;
        $order->calculation_id = $calculation->id;
        $order->number = $number;
        $order->number_year = $year;
        $order->number_org_seq = $orgSeq;
        $order->number_calc_seq = $calcSeq;
        $order->status = DispoOrderStatus::Draft;
        $order->created_by_id = $user->id;
        $order->lock_version = 1;
        if ($revises !== null) {
            $order->revises_dispo_order_id = $revises->id;
        }
        $order->fill($this->mapper->headerFromCalculation($calculation, $selected));
        if ($revises !== null) {
            // Nachbesserung erbt die eingefrorene Konfiguration des Vorgängers.
            $this->dynamicFields->assignClonedSnapshot($order, $revises);
        } else {
            $this->dynamicFields->assignComposedSnapshot($order, $calculation);
        }
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

        $this->dynamicFields->persistCopiedValues(
            $order,
            $calculation,
            $uniqueIds,
            $revises,
        );

        $fresh = $this->reloadOrder($order);
        $result = new DispoOrderWriterResult($fresh, $uniqueIds);

        if ($revises === null) {
            $this->audit->record(
                $fresh,
                'dispo_order.created',
                $user,
                null,
                array_merge(
                    $this->mapper->orderSnapshot($result),
                    $this->dynamicFields->dynamicSnapshotForAudit($fresh),
                ),
            );
        }

        return $result;
    }

    private function auditRevisionCreated(
        DispoOrderWriterResult $result,
        DispoOrder $predecessor,
        User $user,
    ): void {
        $snapshot = array_merge(
            $this->mapper->orderSnapshot($result),
            $this->dynamicFields->dynamicSnapshotForAudit($result->order),
        );
        $meta = [
            'predecessor_dispo_order_id' => $predecessor->id,
            'predecessor_number' => $predecessor->number,
            'successor_dispo_order_id' => $result->order->id,
            'successor_number' => $result->order->number,
            'calculation_id' => $result->order->calculation_id,
            'position_ids' => $result->positionIds,
            'approval_kind' => $result->order->approval_kind->value,
            'requires_special_approval' => (bool) $result->order->requires_special_approval,
        ];

        $this->audit->record(
            $result->order,
            'dispo_order.revision_created',
            $user,
            [
                'revises_dispo_order_id' => $predecessor->id,
            ],
            array_merge($snapshot, $meta),
        );

        $this->audit->record(
            $predecessor,
            'dispo_order.revision_created',
            $user,
            [
                'status' => $predecessor->status->value,
                'has_revision' => false,
            ],
            [
                'revision_dispo_order_id' => $result->order->id,
                'revision_number' => $result->order->number,
                'calculation_id' => $result->order->calculation_id,
                'position_ids' => $result->positionIds,
                'has_revision' => true,
            ],
        );
    }

    private function isUniqueRevisesViolation(QueryException $exception): bool
    {
        $message = $exception->getMessage();

        return str_contains($message, 'revises_dispo_order_id')
            && (
                str_contains($message, 'UNIQUE')
                || str_contains($message, 'unique')
                || (string) $exception->getCode() === '23000'
            );
    }

    private function reloadOrder(DispoOrder $order): DispoOrder
    {
        $order->refresh();
        $order->load([
            'positions.fieldValues',
            'fieldValues',
            'configurationSnapshot.fieldDefinitions',
            'creator',
            'advisor',
            'calculation',
            'revises',
            'revision',
        ]);

        return $order;
    }
}
