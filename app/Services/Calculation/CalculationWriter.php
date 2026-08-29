<?php

namespace App\Services\Calculation;

use App\Enums\BudgetStrategy;
use App\Enums\CalculationKind;
use App\Enums\CalculationStatus;
use App\Enums\PlanningMode;
use App\Models\BudgetProposal;
use App\Models\Calculation;
use App\Models\CalculationPosition;
use App\Models\SpotClassicPlanRow;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class CalculationWriter
{
    private const MAX_DEADLOCK_RETRIES = 5;

    public function __construct(
        private readonly CatalogResolver $catalog,
        private readonly CalculationEngine $engine,
        private readonly AuditLogger $audit,
        private readonly CalculationNumberSequencer $numbers,
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     */
    public function preview(array $payload, User $user, ?Calculation $existing = null): CalculationTotals
    {
        return $this->totalsFromPayload($payload, $user, $existing);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function create(array $payload, User $user): Calculation
    {
        return DB::transaction(function () use ($payload, $user): Calculation {
            [$year, $seq, $number] = $this->numbers->next();

            $calculation = new Calculation;
            $calculation->number = $number;
            $calculation->number_year = $year;
            $calculation->number_seq = $seq;
            $calculation->status = CalculationStatus::Draft;
            $calculation->advisor_id = $user->id;
            $calculation->lock_version = 1;

            $this->fillAndPersist($calculation, $payload, $user, isCreate: true);

            $fresh = $this->reloadCalculation($calculation);
            $this->audit->record($fresh, 'calculation.created', $user, null, $this->calculationSnapshot($fresh));

            return $fresh;
        }, self::MAX_DEADLOCK_RETRIES);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function update(Calculation $calculation, array $payload, User $user): Calculation
    {
        return DB::transaction(function () use ($calculation, $payload, $user): Calculation {
            $locked = Calculation::query()->whereKey($calculation->id)->lockForUpdate()->firstOrFail();

            if ((int) ($payload['lock_version'] ?? 0) !== $locked->lock_version) {
                throw ValidationException::withMessages([
                    'lock_version' => 'Die Kalkulation wurde parallel geändert. Bitte neu laden.',
                ]);
            }

            $locked->load(['positions.planRows']);
            $before = $this->calculationSnapshot($locked);

            if ($this->isHeaderOnlyChange($payload, $locked) && ! $this->hasPositionsWithMissingClientKey($locked)) {
                $this->applyHeaderFields($locked, $payload);
                $totals = $this->totalsFromExisting($locked, $payload, $user);
                $this->applyTotals($locked, $totals);
                $locked->lock_version = $locked->lock_version + 1;
                $locked->save();
            } else {
                $this->fillAndPersist($locked, $payload, $user, isCreate: false);
                $locked->lock_version = $locked->lock_version + 1;
                $locked->save();
            }

            $fresh = $this->reloadCalculation($locked);
            $this->audit->record($fresh, 'calculation.updated', $user, $before, $this->calculationSnapshot($fresh));

            return $fresh;
        });
    }

    public function applyBudgetProposal(Calculation $calculation, BudgetProposal $proposal, User $user): Calculation
    {
        return DB::transaction(function () use ($calculation, $proposal, $user): Calculation {
            $lockedCalculation = Calculation::query()->whereKey($calculation->id)->lockForUpdate()->firstOrFail();
            $lockedProposal = BudgetProposal::query()->whereKey($proposal->id)->lockForUpdate()->firstOrFail();

            abort_unless($lockedProposal->calculation_id === $lockedCalculation->id, 404);
            abort_if($lockedProposal->applied_at !== null, 422, 'Der Vorschlag wurde bereits übernommen.');

            if ($lockedProposal->lock_version !== null && $lockedProposal->lock_version !== $lockedCalculation->lock_version) {
                throw ValidationException::withMessages([
                    'lock_version' => 'Der Vorschlag basiert auf einer älteren Version der Kalkulation. Bitte neuen Vorschlag erzeugen.',
                ]);
            }

            $before = $this->calculationSnapshot($this->reloadCalculation($lockedCalculation));

            $payload = $this->payloadFromCalculation($lockedCalculation);
            $payload['planning_mode'] = $lockedCalculation->planning_mode->value;
            $payload['order_discount_percent'] = (string) $lockedCalculation->order_discount_percent;
            $payload['target_budget_nn'] = (string) $lockedProposal->target_budget_nn;
            $payload['budget_strategy'] = $lockedProposal->strategy->value;
            $payload['lock_version'] = $lockedCalculation->lock_version;
            $payload['positions'] = $this->mergeProposalTotals($payload['positions'], $lockedProposal->payload['positions'] ?? []);

            $this->fillAndPersist($lockedCalculation, $payload, $user, isCreate: false);
            $lockedCalculation->lock_version = $lockedCalculation->lock_version + 1;
            $lockedCalculation->save();

            $lockedProposal->applied_at = now();
            $lockedProposal->applied_by = $user->id;
            $lockedProposal->save();

            $fresh = $this->reloadCalculation($lockedCalculation);
            $this->audit->record($fresh, 'budget.applied', $user, $before, $this->calculationSnapshot($fresh));

            return $fresh;
        });
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function fillAndPersist(Calculation $calculation, array $payload, User $user, bool $isCreate): void
    {
        $calculation->loadMissing(['positions.planRows']);
        $existingById = $calculation->positions->keyBy('id');
        $existingByClient = $calculation->positions->keyBy('client_key');

        $totals = $this->totalsFromPayload($payload, $user, $isCreate ? null : $calculation);
        $resolved = $this->resolvedPositions($payload, $isCreate ? null : $calculation);

        $this->applyHeaderFields($calculation, $payload);
        $this->applyTotals($calculation, $totals);
        $calculation->save();

        $seenIds = [];

        foreach ($resolved as $index => $item) {
            $result = $totals->positions[$index];
            $payloadPosition = $payload['positions'][$index] ?? [];
            $existing = null;

            if (isset($payloadPosition['id'])) {
                $existing = $existingById->get((int) $payloadPosition['id']);
            } elseif (isset($payloadPosition['client_key'])) {
                $existing = $existingByClient->get((string) $payloadPosition['client_key']);
            }

            $clientKey = $this->resolveClientKey($existing);

            $position = $existing ?? new CalculationPosition;
            $position->fill([
                'client_key' => $clientKey,
                'inventory_id' => $item['inventory']->id,
                'advertising_medium_id' => $item['medium']->id,
                'inventory_medium_rule_id' => $item['inventory_medium_rule_id'],
                'price_list_id' => $item['priceList']->id,
                'kind' => CalculationKind::SpotClassic,
                'spot_method' => $item['spot_method'],
                'length_seconds' => (int) $item['length_seconds'],
                'total_spot_count' => (int) $item['total_spot_count'],
                'average_second_price' => $result->averageSecondPrice,
                'length_index' => $result->lengthIndex,
                'surcharge_percent' => $item['surcharge_percent'],
                'position_discount_percent' => $item['is_discountable'] ? $item['position_discount_percent'] : 0,
                'ae_percent' => $item['is_ae_eligible'] ? $item['ae_percent'] : 0,
                'is_discountable' => $item['is_discountable'],
                'is_ae_eligible' => $item['is_ae_eligible'],
                'price_list_version' => $item['priceList']->version,
                'media_gross' => $result->mediaGross,
                'position_discount_amount' => $result->positionDiscountAmount,
                'order_discount_amount' => $result->orderDiscountAmount,
                'ae_amount' => $result->aeAmount,
                'nn_invest' => $result->nnInvest,
                'sort' => $index,
            ]);
            $position->calculation()->associate($calculation);
            $position->save();
            $seenIds[] = $position->id;

            $this->syncPlanRows($position, $result);
        }

        if (! $isCreate) {
            CalculationPosition::query()
                ->where('calculation_id', $calculation->id)
                ->whereNotIn('id', $seenIds)
                ->each(function (CalculationPosition $orphan): void {
                    $orphan->planRows()->delete();
                    $orphan->delete();
                });
        }
    }

    private function syncPlanRows(CalculationPosition $position, PositionResult $result): void
    {
        $existing = $position->planRows()->get()->keyBy(fn ($row) => $row->hour.'|'.$row->day_group->value);
        $seen = [];

        foreach ($result->rows as $row) {
            $key = $row['hour'].'|'.$row['day_group'];
            $planRow = $existing->get($key) ?? new SpotClassicPlanRow;
            $planRow->fill([
                'hour' => $row['hour'],
                'day_group' => $row['day_group'],
                'spot_count' => 0,
                'second_price' => $row['second_price'],
                'line_gross' => '0.00',
            ]);
            $planRow->position()->associate($position);
            $planRow->save();
            $seen[] = $planRow->id;
        }

        SpotClassicPlanRow::query()
            ->where('calculation_position_id', $position->id)
            ->when($seen !== [], fn ($query) => $query->whereNotIn('id', $seen))
            ->delete();
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return list<PositionInput>
     */
    public function positionInputsFromPayload(array $payload, ?Calculation $existing = null): array
    {
        $resolved = $this->resolvedPositions($payload, $existing);
        $inputs = [];

        foreach ($resolved as $index => $item) {
            $payloadPosition = $payload['positions'][$index] ?? [];
            $positionKey = isset($payloadPosition['id'])
                ? 'id:'.((int) $payloadPosition['id'])
                : ($payloadPosition['client_key'] ?? 'new:'.$index);

            $existingPosition = null;
            if (isset($payloadPosition['id'])) {
                $existingPosition = $existing?->positions->firstWhere('id', (int) $payloadPosition['id']);
            } elseif (isset($payloadPosition['client_key'])) {
                $existingPosition = $existing?->positions->firstWhere('client_key', (string) $payloadPosition['client_key']);
            }

            $lengthSeconds = (int) $item['length_seconds'];
            $lengthIndex = $this->resolveLengthIndex($lengthSeconds, $existingPosition);

            $inputs[] = new PositionInput(
                inventoryId: $item['inventory']->id,
                inventoryName: $item['inventory']->name,
                positionKey: (string) $positionKey,
                lengthSeconds: $lengthSeconds,
                surchargePercent: (string) $item['surcharge_percent'],
                positionDiscountPercent: $item['is_discountable'] ? (string) $item['position_discount_percent'] : '0',
                aePercent: $item['is_ae_eligible'] ? (string) $item['ae_percent'] : '0',
                isDiscountable: $item['is_discountable'],
                isAeEligible: $item['is_ae_eligible'],
                totalSpotCount: (int) $item['total_spot_count'],
                spotMethod: $item['spot_method'],
                rows: $item['rows'],
                lengthIndex: $lengthIndex,
            );
        }

        return $inputs;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function totalsFromPayload(array $payload, User $user, ?Calculation $existing = null): CalculationTotals
    {
        $inputs = $this->positionInputsFromPayload($payload, $existing);
        $target = $payload['target_budget_nn'] ?? null;

        return $this->engine->calculate(
            $inputs,
            (string) ($payload['order_discount_percent'] ?? '0'),
            $target === null || $target === '' ? null : (string) $target,
            $user->discount_limit_percent === null ? null : (string) $user->discount_limit_percent,
        );
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return list<array<string, mixed>>
     */
    public function resolvedPositions(array $payload, ?Calculation $existing = null): array
    {
        $existingById = $existing?->positions->keyBy('id') ?? collect();
        $existingByClient = $existing?->positions->keyBy('client_key') ?? collect();
        $resolved = [];

        foreach ($payload['positions'] ?? [] as $position) {
            $existingPosition = null;

            if (isset($position['id'])) {
                $existingPosition = $existingById->get((int) $position['id']);
            } elseif (isset($position['client_key'])) {
                $existingPosition = $existingByClient->get((string) $position['client_key']);
            }

            $catalog = $this->catalog->resolvePosition($position, $existingPosition);
            $length = (int) ($position['length_seconds']
                ?? ($existingPosition !== null ? $existingPosition->length_seconds : null)
                ?? ($catalog['rule'] !== null ? $catalog['rule']->default_length_seconds : null)
                ?? $catalog['medium']->default_length_seconds
                ?? 30);

            $resolved[] = [
                ...$catalog,
                'length_seconds' => $length,
                'position_discount_percent' => $position['position_discount_percent'] ?? 0,
                'ae_percent' => $position['ae_percent'] ?? 15,
            ];
        }

        return $resolved;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function applyHeaderFields(Calculation $calculation, array $payload): void
    {
        $calculation->planning_mode = PlanningMode::from((string) ($payload['planning_mode'] ?? 'manual'));
        $calculation->customer_name = $payload['customer_name'] ?? null;
        $calculation->agency_name = $payload['agency_name'] ?? null;
        $calculation->campaign = $payload['campaign'] ?? null;
        $calculation->product_title = $payload['product_title'] ?? null;
        $calculation->briefing = $payload['briefing'] ?? null;
        $calculation->order_discount_percent = $payload['order_discount_percent'] ?? 0;
        $calculation->target_budget_nn = $payload['target_budget_nn'] ?? null;
        $calculation->budget_strategy = isset($payload['budget_strategy'])
            ? BudgetStrategy::from((string) $payload['budget_strategy'])
            : null;
    }

    private function applyTotals(Calculation $calculation, CalculationTotals $totals): void
    {
        $calculation->media_gross = $totals->mediaGross;
        $calculation->position_discount_total = $totals->positionDiscountTotal;
        $calculation->order_discount_total = $totals->orderDiscountTotal;
        $calculation->ae_total = $totals->aeTotal;
        $calculation->nn_invest = $totals->nnInvest;
        $calculation->requires_special_approval = $totals->requiresSpecialApproval;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function totalsFromExisting(Calculation $calculation, array $payload, User $user): CalculationTotals
    {
        $payloadFromDb = $this->payloadFromCalculation($calculation);
        $payloadFromDb['order_discount_percent'] = $payload['order_discount_percent'] ?? $payloadFromDb['order_discount_percent'];
        $payloadFromDb['target_budget_nn'] = $payload['target_budget_nn'] ?? $payloadFromDb['target_budget_nn'];
        $payloadFromDb['budget_strategy'] = $payload['budget_strategy'] ?? $payloadFromDb['budget_strategy'];

        return $this->totalsFromPayload($payloadFromDb, $user, $calculation);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function isHeaderOnlyChange(array $payload, Calculation $calculation): bool
    {
        if (! isset($payload['positions'])) {
            return true;
        }

        return $this->normalizePositionsForCompare($payload['positions'])
            === $this->normalizePositionsForCompare($this->payloadFromCalculation($calculation)['positions']);
    }

    /**
     * @param  list<array<string, mixed>>  $positions
     * @return list<array<string, mixed>>
     */
    private function normalizePositionsForCompare(array $positions): array
    {
        $normalized = [];

        foreach ($positions as $position) {
            $rows = [];
            foreach ($position['plan_rows'] ?? [] as $row) {
                $rows[] = [
                    'hour' => (int) $row['hour'],
                    'day_group' => (string) $row['day_group'],
                ];
            }
            usort($rows, fn (array $a, array $b): int => $a['hour'] <=> $b['hour'] ?: strcmp($a['day_group'], $b['day_group']));

            $normalized[] = [
                'id' => isset($position['id']) ? (int) $position['id'] : null,
                'client_key' => $position['client_key'] ?? null,
                'inventory_id' => (int) ($position['inventory_id'] ?? 0),
                'advertising_medium_id' => (int) ($position['advertising_medium_id'] ?? 0),
                'spot_method' => (string) ($position['spot_method'] ?? 'average'),
                'length_seconds' => (int) ($position['length_seconds'] ?? 0),
                'total_spot_count' => (int) ($position['total_spot_count'] ?? 0),
                'position_discount_percent' => (string) ($position['position_discount_percent'] ?? '0'),
                'ae_percent' => (string) ($position['ae_percent'] ?? '0'),
                'plan_rows' => $rows,
            ];
        }

        usort($normalized, fn (array $a, array $b): int => ($a['id'] ?? 0) <=> ($b['id'] ?? 0));

        return $normalized;
    }

    /**
     * @return array<string, mixed>
     */
    public function payloadFromCalculation(Calculation $calculation): array
    {
        $calculation->loadMissing(['positions.planRows']);

        $positions = [];

        foreach ($calculation->positions as $position) {
            $rows = [];
            foreach ($position->planRows as $row) {
                $rows[] = [
                    'hour' => $row->hour,
                    'day_group' => $row->day_group->value,
                ];
            }

            $positions[] = [
                'id' => $position->id,
                'client_key' => $position->client_key,
                'inventory_id' => $position->inventory_id,
                'advertising_medium_id' => $position->advertising_medium_id,
                'spot_method' => $position->spot_method->value,
                'length_seconds' => $position->length_seconds,
                'total_spot_count' => $position->total_spot_count,
                'position_discount_percent' => (string) $position->position_discount_percent,
                'ae_percent' => (string) $position->ae_percent,
                'plan_rows' => $rows,
            ];
        }

        return [
            'planning_mode' => $calculation->planning_mode->value,
            'customer_name' => $calculation->customer_name,
            'agency_name' => $calculation->agency_name,
            'campaign' => $calculation->campaign,
            'product_title' => $calculation->product_title,
            'briefing' => $calculation->briefing,
            'order_discount_percent' => (string) $calculation->order_discount_percent,
            'target_budget_nn' => $calculation->target_budget_nn === null ? null : (string) $calculation->target_budget_nn,
            'budget_strategy' => $calculation->budget_strategy?->value,
            'positions' => $positions,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function calculationSnapshot(Calculation $calculation): array
    {
        $calculation->loadMissing(['positions.planRows', 'positions.priceList']);

        return [
            'number' => $calculation->number,
            'planning_mode' => $calculation->planning_mode->value,
            'customer_name' => $calculation->customer_name,
            'agency_name' => $calculation->agency_name,
            'campaign' => $calculation->campaign,
            'product_title' => $calculation->product_title,
            'briefing' => $calculation->briefing,
            'order_discount_percent' => (string) $calculation->order_discount_percent,
            'target_budget_nn' => $calculation->target_budget_nn === null ? null : (string) $calculation->target_budget_nn,
            'budget_strategy' => $calculation->budget_strategy?->value,
            'media_gross' => (string) $calculation->media_gross,
            'nn_invest' => (string) $calculation->nn_invest,
            'positions' => $calculation->positions->map(function (CalculationPosition $position): array {
                return [
                    'id' => $position->id,
                    'client_key' => $position->client_key,
                    'inventory_id' => $position->inventory_id,
                    'advertising_medium_id' => $position->advertising_medium_id,
                    'spot_method' => $position->spot_method->value,
                    'length_seconds' => $position->length_seconds,
                    'total_spot_count' => $position->total_spot_count,
                    'average_second_price' => $position->average_second_price === null ? null : (string) $position->average_second_price,
                    'length_index' => $position->length_index,
                    'price_list_id' => $position->price_list_id,
                    'price_list_version' => $position->price_list_version,
                    'inventory_medium_rule_id' => $position->inventory_medium_rule_id,
                    'surcharge_percent' => (string) $position->surcharge_percent,
                    'position_discount_percent' => (string) $position->position_discount_percent,
                    'ae_percent' => (string) $position->ae_percent,
                    'is_discountable' => $position->is_discountable,
                    'is_ae_eligible' => $position->is_ae_eligible,
                    'plan_rows' => array_values($position->planRows->map(
                        fn (SpotClassicPlanRow $row): array => [
                            'hour' => $row->hour,
                            'day_group' => $row->day_group->value,
                            'second_price' => (string) $row->second_price,
                        ]
                    )->all()),
                ];
            })->values()->all(),
        ];
    }

    private function reloadCalculation(Calculation $calculation): Calculation
    {
        $calculation->refresh();
        $calculation->load(['positions.planRows', 'positions.inventory', 'positions.priceList']);

        return $calculation;
    }

    /**
     * @param  list<array<string, mixed>>  $positions
     * @param  list<array<string, mixed>>  $proposed
     * @return list<array<string, mixed>>
     */
    public function mergeProposalTotals(array $positions, array $proposed): array
    {
        $proposedByKey = collect($proposed)->keyBy(
            fn (array $item): string => (string) ($item['position_key'] ?? 'inventory:'.($item['inventory_id'] ?? 0)),
        );

        $merged = [];

        foreach ($positions as $index => $position) {
            $positionKey = isset($position['id'])
                ? 'id:'.$position['id']
                : ((string) ($position['client_key'] ?? 'new:'.$index));

            $item = $proposedByKey->get($positionKey)
                ?? $proposedByKey->get('inventory:'.($position['inventory_id'] ?? 0));

            if ($item === null) {
                $position['total_spot_count'] = 0;
            } else {
                $position['total_spot_count'] = (int) ($item['total_spot_count'] ?? 0);
                $position['length_seconds'] = $item['length_seconds'] ?? $position['length_seconds'];
            }

            $merged[] = $position;
        }

        return $merged;
    }

    private function hasPositionsWithMissingClientKey(Calculation $calculation): bool
    {
        return $calculation->positions->contains(
            fn (CalculationPosition $position): bool => $position->client_key === null || $position->client_key === '',
        );
    }

    private function resolveLengthIndex(int $lengthSeconds, ?CalculationPosition $existing): ?int
    {
        if ($existing === null) {
            return null;
        }

        if ($lengthSeconds === (int) $existing->length_seconds && $existing->length_index !== null) {
            return $existing->length_index;
        }

        return null;
    }

    private function resolveClientKey(?CalculationPosition $existing): string
    {
        if ($existing !== null) {
            $stored = $existing->client_key;

            if ($stored !== null && $stored !== '') {
                return $stored;
            }

            return (string) Str::uuid();
        }

        return (string) Str::uuid();
    }
}
