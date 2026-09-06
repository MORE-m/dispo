<?php

namespace App\Services\Calculation;

use App\Enums\BudgetProposalStatus;
use App\Enums\BudgetStrategy;
use App\Enums\CalculationKind;
use App\Enums\CalculationStatus;
use App\Enums\DiscountType;
use App\Enums\PlanningMode;
use App\Models\BudgetProposal;
use App\Models\Calculation;
use App\Models\CalculationOrderDiscount;
use App\Models\CalculationPosition;
use App\Models\CalculationPositionDiscount;
use App\Models\CalculationPositionTimeRange;
use App\Models\SpotClassicPlanRow;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Services\DynamicField\CalculationDynamicFieldWriter;
use App\Services\DynamicField\ConfigurationSnapshotMaterializer;
use Illuminate\Support\Collection;
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
        private readonly ConfigurationSnapshotMaterializer $snapshots,
        private readonly CalculationDynamicFieldWriter $dynamicFields,
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
            $calculation->configuration_snapshot_id = $this->snapshots->materializeFromActiveSet()->id;

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

            $locked->load(['positions.planRows', 'positions.timeRanges', 'positions.discounts', 'orderDiscounts']);
            $before = $this->calculationSnapshot($locked);

            if ($this->isHeaderOnlyChange($payload, $locked) && ! $this->hasPositionsWithMissingClientKey($locked)) {
                $this->applyHeaderFields($locked, $payload);
                $totals = $this->totalsFromExisting($locked, $payload, $user);
                $this->applyTotals($locked, $totals, $user);
                $locked->lock_version = $locked->lock_version + 1;
                $locked->save();
                $dynamicPayload = $this->payloadFromCalculation($locked);
                $dynamicPayload['dynamic_field_values'] = $payload['dynamic_field_values']
                    ?? $dynamicPayload['dynamic_field_values'];
                if (isset($payload['positions']) && is_array($payload['positions'])) {
                    $dynamicPayload['positions'] = $this->mergePositionDynamicValuesByIdentity(
                        $dynamicPayload['positions'],
                        $payload['positions'],
                    );
                }
                $this->dynamicFields->syncFromPayload($locked, $dynamicPayload);
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

            if ($lockedProposal->status === BudgetProposalStatus::Stale) {
                throw ValidationException::withMessages([
                    'proposal' => 'Der Vorschlag ist veraltet. Bitte neu berechnen, bevor Sie übernehmen.',
                ]);
            }

            $before = $this->calculationSnapshot($this->reloadCalculation($lockedCalculation));

            $payload = $this->payloadFromCalculation($lockedCalculation);
            $payload['planning_mode'] = $lockedCalculation->planning_mode->value;
            $payload['order_discount_percent'] = (string) $lockedCalculation->order_discount_percent;
            $payload['target_budget_nn'] = (string) $lockedProposal->target_budget_nn;
            $payload['budget_strategy'] = $lockedProposal->strategy->value;
            $payload['lock_version'] = $lockedCalculation->lock_version;

            if ($lockedProposal->strategy === BudgetStrategy::EqualSpotCount) {
                $payload['positions'] = $this->mergeProposalHourlyDistribution(
                    $payload['positions'],
                    $lockedProposal->payloadArray(),
                );
            } else {
                $proposalPayload = $lockedProposal->payloadArray();
                $payload['positions'] = $this->mergeProposalTotals(
                    $payload['positions'],
                    $proposalPayload['positions'] ?? [],
                );
            }

            $this->fillAndPersist($lockedCalculation, $payload, $user, isCreate: false);
            $lockedCalculation->lock_version = $lockedCalculation->lock_version + 1;
            $lockedCalculation->budget_proposal_status = BudgetProposalStatus::Applied;
            $lockedCalculation->save();

            $lockedProposal->applied_at = now();
            $lockedProposal->applied_by = $user->id;
            $lockedProposal->status = BudgetProposalStatus::Applied;
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
        $calculation->loadMissing(['positions.planRows', 'positions.timeRanges', 'positions.discounts', 'orderDiscounts']);
        $existingById = $calculation->positions->keyBy('id');
        $existingByClient = $calculation->positions->keyBy('client_key');

        $totals = $this->totalsFromPayload($payload, $user, $isCreate ? null : $calculation);
        $resolved = $this->resolvedPositions($payload, $isCreate ? null : $calculation);

        foreach ($resolved as $index => $item) {
            if ($item['needs_spot_redistribution'] && $item['time_ranges'] !== []) {
                throw ValidationException::withMessages([
                    "positions.{$index}.time_ranges" => 'Bitte verteile die bisherige Gesamtspotzahl auf die Preiszeiträume.',
                ]);
            }
        }

        $this->applyHeaderFields($calculation, $payload);
        $this->applyTotals($calculation, $totals, $user);
        $calculation->save();

        $this->assertPositionIdentitiesBelongToCalculation($payload, $existingById, $existingByClient);

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
                'needs_spot_redistribution' => $item['needs_spot_redistribution'] && $item['time_ranges'] === [],
                'average_second_price' => $result->averageSecondPrice,
                'length_index' => $result->lengthIndex,
                'surcharge_percent' => $item['surcharge_percent'],
                'position_discount_percent' => $item['is_discountable']
                    ? $this->effectivePercentFromDiscounts($item['position_discounts'])
                    : 0,
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

            // Identität direkt am Persistenz-Mapping stempeln (kein Index-Matching danach).
            if (isset($payload['positions'][$index]) && is_array($payload['positions'][$index])) {
                $payload['positions'][$index]['id'] = $position->id;
                $payload['positions'][$index]['client_key'] = $position->client_key;
            }

            $this->syncPlanRows($position, $result);
            $this->syncTimeRanges($position, $result);
            $this->syncPositionDiscounts($position, $item['position_discounts']);
        }

        $this->syncOrderDiscounts($calculation, $this->orderDiscountInputs($payload));

        if (! $isCreate) {
            CalculationPosition::query()
                ->where('calculation_id', $calculation->id)
                ->whereNotIn('id', $seenIds)
                ->each(function (CalculationPosition $orphan): void {
                    $orphan->planRows()->delete();
                    $orphan->timeRanges()->delete();
                    $orphan->discounts()->delete();
                    $orphan->fieldValues()->delete();
                    $orphan->delete();
                });
        }

        $calculation->load('positions');
        $this->dynamicFields->syncFromPayload($calculation, $payload);
    }

    /**
     * Übernimmt Positions-Dyn-Werte per id/client_key (nie per Array-Index).
     *
     * @param  list<array<string, mixed>>  $basePositions
     * @param  list<array<string, mixed>>  $incomingPositions
     * @return list<array<string, mixed>>
     */
    private function mergePositionDynamicValuesByIdentity(array $basePositions, array $incomingPositions): array
    {
        $byId = [];
        $byClient = [];
        foreach ($incomingPositions as $incoming) {
            if (! is_array($incoming) || ! isset($incoming['dynamic_field_values'])) {
                continue;
            }
            if (isset($incoming['id'])) {
                $byId[(int) $incoming['id']] = $incoming['dynamic_field_values'];
            }
            $clientKey = isset($incoming['client_key']) ? (string) $incoming['client_key'] : '';
            if ($clientKey !== '') {
                $byClient[$clientKey] = $incoming['dynamic_field_values'];
            }
        }

        foreach ($basePositions as $index => $base) {
            if (! is_array($base)) {
                continue;
            }
            $values = null;
            if (isset($base['id']) && array_key_exists((int) $base['id'], $byId)) {
                $values = $byId[(int) $base['id']];
            } elseif (isset($base['client_key']) && array_key_exists((string) $base['client_key'], $byClient)) {
                $values = $byClient[(string) $base['client_key']];
            }
            if ($values !== null) {
                $basePositions[$index]['dynamic_field_values'] = $values;
            }
        }

        return $basePositions;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  Collection<int, CalculationPosition>  $existingById
     * @param  Collection<string, CalculationPosition>  $existingByClient
     */
    private function assertPositionIdentitiesBelongToCalculation(
        array $payload,
        $existingById,
        $existingByClient,
    ): void {
        if (! isset($payload['positions']) || ! is_array($payload['positions'])) {
            return;
        }

        $seenIds = [];
        $seenClientKeys = [];

        foreach ($payload['positions'] as $index => $positionPayload) {
            if (! is_array($positionPayload)) {
                continue;
            }

            if (isset($positionPayload['id'])) {
                $id = (int) $positionPayload['id'];
                if ($existingById->get($id) === null) {
                    throw ValidationException::withMessages([
                        "positions.{$index}.id" => 'Unbekannte Position.',
                    ]);
                }
                if (isset($seenIds[$id])) {
                    throw ValidationException::withMessages([
                        "positions.{$index}.id" => 'Doppelte Positions-ID im Payload.',
                    ]);
                }
                $seenIds[$id] = true;
            }

            $clientKey = isset($positionPayload['client_key']) ? (string) $positionPayload['client_key'] : '';
            if ($clientKey === '') {
                continue;
            }

            if (isset($seenClientKeys[$clientKey])) {
                throw ValidationException::withMessages([
                    "positions.{$index}.client_key" => 'Doppelter client_key im Payload.',
                ]);
            }
            $seenClientKeys[$clientKey] = true;

            if ($existingByClient->get($clientKey) !== null) {
                continue;
            }

            $belongsElsewhere = CalculationPosition::query()
                ->where('client_key', $clientKey)
                ->exists();
            if ($belongsElsewhere) {
                throw ValidationException::withMessages([
                    "positions.{$index}.client_key" => 'Unbekannte Position.',
                ]);
            }
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
                positionDiscountPercent: $item['is_discountable']
                    ? $this->effectivePercentFromDiscounts($item['position_discounts'])
                    : '0',
                aePercent: $item['is_ae_eligible'] ? (string) $item['ae_percent'] : '0',
                isDiscountable: $item['is_discountable'],
                isAeEligible: $item['is_ae_eligible'],
                totalSpotCount: (int) $item['total_spot_count'],
                spotMethod: $item['spot_method'],
                rows: $item['rows'],
                lengthIndex: $lengthIndex,
                timeRanges: $item['time_ranges'],
                positionDiscounts: $item['is_discountable'] ? $item['position_discounts'] : [],
                needsSpotRedistribution: $item['needs_spot_redistribution'],
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

        $orderDiscounts = $this->orderDiscountInputs($payload);

        return $this->engine->calculate(
            $inputs,
            $this->effectivePercentFromDiscounts($orderDiscounts),
            $target === null || $target === '' ? null : (string) $target,
            $user->discount_limit_percent === null ? null : (string) $user->discount_limit_percent,
            $orderDiscounts,
            (bool) ($payload['ae_enabled'] ?? false),
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
                'position_discounts' => $this->positionDiscountInputs($position),
                'ae_percent' => $this->resolveAePercent($payload, $position, $catalog['is_ae_eligible'], $existingPosition),
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
        $orderDiscounts = $this->orderDiscountInputs($payload);
        $calculation->order_discount_percent = $this->effectivePercentFromDiscounts($orderDiscounts);
        if (array_key_exists('ae_enabled', $payload)) {
            $calculation->ae_enabled = (bool) $payload['ae_enabled'];
        }
        $calculation->target_budget_nn = $payload['target_budget_nn'] ?? null;
        $calculation->budget_strategy = isset($payload['budget_strategy'])
            ? BudgetStrategy::from((string) $payload['budget_strategy'])
            : null;
        if (array_key_exists('budget_proposal_status', $payload) && $payload['budget_proposal_status'] !== null) {
            $calculation->budget_proposal_status = BudgetProposalStatus::from((string) $payload['budget_proposal_status']);
        } elseif (($payload['budget_proposal_manual'] ?? false) === true) {
            $calculation->budget_proposal_status = BudgetProposalStatus::Manual;
        }
    }

    private function applyTotals(Calculation $calculation, CalculationTotals $totals, User $user): void
    {
        $calculation->media_gross = $totals->mediaGross;
        $calculation->position_discount_total = $totals->positionDiscountTotal;
        $calculation->order_discount_total = $totals->orderDiscountTotal;
        $calculation->ae_total = $totals->aeTotal;
        $calculation->nn_invest = $totals->nnInvest;
        $calculation->requires_special_approval = $totals->requiresSpecialApproval;
        $calculation->special_approval_reasons = $totals->specialApprovalReasons;
        $calculation->personal_discount_limit_percent = $user->discount_limit_percent;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function totalsFromExisting(Calculation $calculation, array $payload, User $user): CalculationTotals
    {
        $payloadFromDb = $this->payloadFromCalculation($calculation);
        $payloadFromDb['order_discount_percent'] = $payload['order_discount_percent'] ?? $payloadFromDb['order_discount_percent'];
        $payloadFromDb['order_discounts'] = $payload['order_discounts'] ?? $payloadFromDb['order_discounts'];
        $payloadFromDb['ae_enabled'] = $payload['ae_enabled'] ?? $payloadFromDb['ae_enabled'];
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

            $ranges = [];
            foreach ($position['time_ranges'] ?? [] as $range) {
                $ranges[] = [
                    'start_hour' => (int) ($range['start_hour'] ?? 0),
                    'end_hour_exclusive' => (int) ($range['end_hour_exclusive'] ?? 0),
                    'day_group' => (string) ($range['day_group'] ?? ''),
                    'spot_count' => (int) ($range['spot_count'] ?? 0),
                ];
            }

            $discounts = [];
            foreach ($position['position_discounts'] ?? [] as $discount) {
                $discounts[] = [
                    'type' => (string) ($discount['type'] ?? ''),
                    'custom_label' => $discount['custom_label'] ?? null,
                    'percent' => (string) ($discount['percent'] ?? '0'),
                ];
            }

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
                'time_ranges' => $ranges,
                'position_discounts' => $discounts,
                'dynamic_field_values' => $position['dynamic_field_values'] ?? null,
            ];
        }

        // Reihenfolge bleibt erhalten: Umordnen ist keine Header-only-Änderung.
        return $normalized;
    }

    /**
     * @return array<string, mixed>
     */
    public function payloadFromCalculation(Calculation $calculation): array
    {
        $calculation->loadMissing([
            'positions.planRows',
            'positions.timeRanges',
            'positions.discounts',
            'positions.fieldValues.snapshotFieldDefinition',
            'orderDiscounts',
            'configurationSnapshot.fieldDefinitions',
            'fieldValues.snapshotFieldDefinition',
        ]);

        $snapshot = $calculation->configurationSnapshot;
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
                'needs_spot_redistribution' => (bool) $position->needs_spot_redistribution,
                'position_discount_percent' => (string) $position->position_discount_percent,
                'ae_percent' => (string) $position->ae_percent,
                'plan_rows' => $rows,
                'time_ranges' => $position->timeRanges->map(fn (CalculationPositionTimeRange $range): array => [
                    'start_hour' => $range->start_hour,
                    'end_hour_exclusive' => $range->end_hour_exclusive,
                    'day_group' => $range->day_group->value,
                    'spot_count' => $range->spot_count,
                ])->all(),
                'position_discounts' => $position->discounts->map(fn (CalculationPositionDiscount $discount): array => [
                    'type' => $discount->type->value,
                    'custom_label' => $discount->custom_label,
                    'percent' => (string) $discount->percent,
                ])->all(),
                'dynamic_field_values' => $snapshot === null
                    ? ['period_open' => true]
                    : $this->dynamicFields->positionValuesForPayload($position, $snapshot),
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
            'order_discounts' => $calculation->orderDiscounts->map(fn (CalculationOrderDiscount $discount): array => [
                'type' => $discount->type->value,
                'custom_label' => $discount->custom_label,
                'percent' => (string) $discount->percent,
            ])->all(),
            'ae_enabled' => (bool) $calculation->ae_enabled,
            'target_budget_nn' => $calculation->target_budget_nn === null ? null : (string) $calculation->target_budget_nn,
            'budget_strategy' => $calculation->budget_strategy?->value,
            'dynamic_field_values' => $this->dynamicFields->headerValuesForPayload($calculation),
            'positions' => $positions,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function calculationSnapshot(Calculation $calculation): array
    {
        $calculation->loadMissing([
            'positions.planRows',
            'positions.timeRanges',
            'positions.discounts',
            'positions.priceList',
            'positions.fieldValues.snapshotFieldDefinition',
            'orderDiscounts',
            'configurationSnapshot.fieldDefinitions',
            'fieldValues.snapshotFieldDefinition',
        ]);

        $snapshot = $calculation->configurationSnapshot;

        return [
            'number' => $calculation->number,
            'planning_mode' => $calculation->planning_mode->value,
            'customer_name' => $calculation->customer_name,
            'agency_name' => $calculation->agency_name,
            'campaign' => $calculation->campaign,
            'product_title' => $calculation->product_title,
            'briefing' => $calculation->briefing,
            'configuration_snapshot_id' => $calculation->configuration_snapshot_id,
            'dynamic_field_values' => $this->dynamicFields->headerValuesForPayload($calculation),
            'order_discount_percent' => (string) $calculation->order_discount_percent,
            'ae_enabled' => (bool) $calculation->ae_enabled,
            'target_budget_nn' => $calculation->target_budget_nn === null ? null : (string) $calculation->target_budget_nn,
            'budget_strategy' => $calculation->budget_strategy?->value,
            'media_gross' => (string) $calculation->media_gross,
            'nn_invest' => (string) $calculation->nn_invest,
            'order_discounts' => $calculation->orderDiscounts->map(
                fn (CalculationOrderDiscount $discount): array => [
                    'type' => $discount->type->value,
                    'custom_label' => $discount->custom_label,
                    'percent' => (string) $discount->percent,
                ]
            )->all(),
            'positions' => $calculation->positions->map(function (CalculationPosition $position) use ($snapshot): array {
                return [
                    'id' => $position->id,
                    'client_key' => $position->client_key,
                    'inventory_id' => $position->inventory_id,
                    'advertising_medium_id' => $position->advertising_medium_id,
                    'spot_method' => $position->spot_method->value,
                    'length_seconds' => $position->length_seconds,
                    'total_spot_count' => $position->total_spot_count,
                    'needs_spot_redistribution' => (bool) $position->needs_spot_redistribution,
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
                    'time_ranges' => $position->timeRanges->map(
                        fn (CalculationPositionTimeRange $range): array => [
                            'start_hour' => $range->start_hour,
                            'end_hour_exclusive' => $range->end_hour_exclusive,
                            'day_group' => $range->day_group->value,
                            'spot_count' => $range->spot_count,
                            'average_second_price' => $range->average_second_price === null ? null : (string) $range->average_second_price,
                            'range_gross' => $range->range_gross === null ? null : (string) $range->range_gross,
                        ]
                    )->all(),
                    'position_discounts' => $position->discounts->map(
                        fn (CalculationPositionDiscount $discount): array => [
                            'type' => $discount->type->value,
                            'custom_label' => $discount->custom_label,
                            'percent' => (string) $discount->percent,
                        ]
                    )->all(),
                    'dynamic_field_values' => $snapshot === null
                        ? ['period_open' => true]
                        : $this->dynamicFields->positionValuesForPayload($position, $snapshot),
                ];
            })->values()->all(),
        ];
    }

    private function reloadCalculation(Calculation $calculation): Calculation
    {
        $calculation->refresh();
        $calculation->load([
            'positions.planRows',
            'positions.timeRanges',
            'positions.discounts',
            'positions.inventory',
            'positions.priceList',
            'positions.fieldValues.snapshotFieldDefinition',
            'orderDiscounts',
            'configurationSnapshot.fieldDefinitions',
            'configurationSnapshot.rules',
            'fieldValues.snapshotFieldDefinition',
        ]);

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
                $position['time_ranges'] = $this->scaleTimeRangeSpots($position['time_ranges'] ?? [], 0);
            } else {
                $position['total_spot_count'] = (int) ($item['total_spot_count'] ?? 0);
                $position['length_seconds'] = $item['length_seconds'] ?? $position['length_seconds'];
                $position['time_ranges'] = $this->scaleTimeRangeSpots(
                    $position['time_ranges'] ?? [],
                    (int) $position['total_spot_count'],
                );
            }

            $merged[] = $position;
        }

        return $merged;
    }

    /**
     * @param  list<array<string, mixed>>  $positions
     * @param  array<string, mixed>  $proposalPayload
     * @return list<array<string, mixed>>
     */
    public function mergeProposalHourlyDistribution(array $positions, array $proposalPayload): array
    {
        $proposedPositions = $proposalPayload['positions'] ?? [];
        /** @var array<int, array<string, mixed>> $proposedByInventory */
        $proposedByInventory = [];
        foreach ($proposedPositions as $proposedPosition) {
            if (! is_array($proposedPosition)) {
                continue;
            }
            $proposedByInventory[(int) ($proposedPosition['inventory_id'] ?? 0)] = $proposedPosition;
        }
        $merged = [];
        $seenInventoryIds = [];

        foreach ($positions as $position) {
            $inventoryId = (int) ($position['inventory_id'] ?? 0);
            $proposed = $proposedByInventory[$inventoryId] ?? null;

            if ($proposed === null) {
                $position['total_spot_count'] = 0;
                $position['time_ranges'] = [];
                $position['needs_spot_redistribution'] = false;
                $merged[] = $position;

                continue;
            }

            $seenInventoryIds[] = $inventoryId;
            $merged[] = $this->positionFromHourlyProposal($position, $proposed);
        }

        foreach ($proposedPositions as $proposed) {
            $inventoryId = (int) ($proposed['inventory_id'] ?? 0);
            if ($inventoryId < 1 || in_array($inventoryId, $seenInventoryIds, true)) {
                continue;
            }

            $merged[] = $this->positionFromHourlyProposal([
                'client_key' => (string) Str::uuid(),
            ], $proposed);
        }

        return $merged;
    }

    /**
     * @param  array<string, mixed>  $position
     * @param  array<string, mixed>  $proposed
     * @return array<string, mixed>
     */
    private function positionFromHourlyProposal(array $position, array $proposed): array
    {
        $timeRanges = [];
        $planRows = [];

        foreach ($proposed['time_ranges'] ?? [] as $range) {
            if ((int) ($range['spot_count'] ?? 0) < 1) {
                continue;
            }

            $timeRanges[] = [
                'start_hour' => (int) $range['start_hour'],
                'end_hour_exclusive' => (int) $range['end_hour_exclusive'],
                'day_group' => (string) $range['day_group'],
                'spot_count' => (int) $range['spot_count'],
            ];

            $planRows[] = [
                'hour' => (int) $range['start_hour'],
                'day_group' => (string) $range['day_group'],
            ];
        }

        $position['inventory_id'] = (int) $proposed['inventory_id'];
        $position['advertising_medium_id'] = (int) ($proposed['advertising_medium_id'] ?? $position['advertising_medium_id'] ?? 0);
        $position['length_seconds'] = (int) $proposed['length_seconds'];
        $position['total_spot_count'] = (int) $proposed['total_spot_count'];
        $position['spot_method'] = 'average';
        $position['needs_spot_redistribution'] = false;
        $position['time_ranges'] = $timeRanges;
        $position['plan_rows'] = $planRows;

        if (isset($proposed['position_discounts']) && is_array($proposed['position_discounts'])) {
            $position['position_discounts'] = $proposed['position_discounts'];
        }

        return $position;
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

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>  $position
     */
    private function resolveAePercent(array $payload, array $position, bool $isAeEligible, ?CalculationPosition $existing): string
    {
        if (! $isAeEligible) {
            return '0';
        }

        if (array_key_exists('ae_enabled', $payload)) {
            if (! (bool) $payload['ae_enabled']) {
                return '0';
            }

            if ($existing !== null && Decimal::cmp((string) $existing->ae_percent, '0') > 0) {
                return (string) $existing->ae_percent;
            }

            return '15';
        }

        return (string) ($position['ae_percent'] ?? '15');
    }

    /**
     * @param  array<string, mixed>  $position
     * @return list<DiscountInput>
     */
    private function positionDiscountInputs(array $position): array
    {
        $raw = $position['position_discounts'] ?? [];
        if (! is_array($raw) || $raw === []) {
            $legacy = (string) ($position['position_discount_percent'] ?? '0');
            if (Decimal::cmp($legacy, '0') <= 0) {
                return [];
            }

            return [new DiscountInput(DiscountType::Quantity, $legacy)];
        }

        return $this->discountInputsFromRows($raw);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return list<DiscountInput>
     */
    private function orderDiscountInputs(array $payload): array
    {
        $raw = $payload['order_discounts'] ?? [];
        if (! is_array($raw) || $raw === []) {
            $legacy = (string) ($payload['order_discount_percent'] ?? '0');
            if (Decimal::cmp($legacy, '0') <= 0) {
                return [];
            }

            return [new DiscountInput(DiscountType::Quantity, $legacy)];
        }

        return $this->discountInputsFromRows($raw);
    }

    /**
     * @param  array<int|string, mixed>  $rows
     * @return list<DiscountInput>
     */
    private function discountInputsFromRows(array $rows): array
    {
        $validated = (new DiscountValidator)->validated($rows, 'discounts');
        $inputs = [];

        foreach ($validated as $row) {
            $inputs[] = new DiscountInput(
                type: DiscountType::from($row['type']),
                percent: $row['percent'],
                customLabel: $row['custom_label'],
                sort: $row['sort'],
            );
        }

        return $inputs;
    }

    /**
     * @param  list<DiscountInput>  $discounts
     */
    private function effectivePercentFromDiscounts(array $discounts): string
    {
        if ($discounts === []) {
            return '0';
        }

        $factor = '1';
        foreach ($discounts as $discount) {
            $factor = Decimal::mul($factor, Decimal::oneMinusPercent($discount->percent));
        }

        return Decimal::roundPrice(Decimal::mul(Decimal::sub('1', $factor), '100'));
    }

    private function syncTimeRanges(CalculationPosition $position, PositionResult $result): void
    {
        $existing = $position->timeRanges()->get()->values();
        $seen = [];

        foreach ($result->timeRanges as $index => $range) {
            $model = $existing->get($index) ?? new CalculationPositionTimeRange;
            $model->fill([
                'start_hour' => $range['start_hour'],
                'end_hour_exclusive' => $range['end_hour_exclusive'],
                'day_group' => $range['day_group'],
                'spot_count' => $range['spot_count'],
                'sort' => $index,
                'average_second_price' => $range['average_second_price'],
                'range_gross' => $range['range_gross'],
            ]);
            $model->position()->associate($position);
            $model->save();
            $seen[] = $model->id;
        }

        CalculationPositionTimeRange::query()
            ->where('calculation_position_id', $position->id)
            ->when($seen !== [], fn ($query) => $query->whereNotIn('id', $seen))
            ->delete();
    }

    /**
     * @param  list<DiscountInput>  $discounts
     */
    private function syncPositionDiscounts(CalculationPosition $position, array $discounts): void
    {
        $existing = $position->discounts()->get()->values();
        $seen = [];

        foreach ($discounts as $index => $discount) {
            $model = $existing->get($index) ?? new CalculationPositionDiscount;
            $model->fill([
                'type' => $discount->type,
                'custom_label' => $discount->customLabel,
                'percent' => $discount->percent,
                'sort' => $index,
            ]);
            $model->position()->associate($position);
            $model->save();
            $seen[] = $model->id;
        }

        CalculationPositionDiscount::query()
            ->where('calculation_position_id', $position->id)
            ->when($seen !== [], fn ($query) => $query->whereNotIn('id', $seen))
            ->delete();
    }

    /**
     * @param  list<DiscountInput>  $discounts
     */
    private function syncOrderDiscounts(Calculation $calculation, array $discounts): void
    {
        $existing = $calculation->orderDiscounts()->get()->values();
        $seen = [];

        foreach ($discounts as $index => $discount) {
            $model = $existing->get($index) ?? new CalculationOrderDiscount;
            $model->fill([
                'type' => $discount->type,
                'custom_label' => $discount->customLabel,
                'percent' => $discount->percent,
                'sort' => $index,
            ]);
            $model->calculation()->associate($calculation);
            $model->save();
            $seen[] = $model->id;
        }

        CalculationOrderDiscount::query()
            ->where('calculation_id', $calculation->id)
            ->when($seen !== [], fn ($query) => $query->whereNotIn('id', $seen))
            ->delete();
    }

    /**
     * @param  list<array<string, mixed>>  $ranges
     * @return list<array<string, mixed>>
     */
    private function scaleTimeRangeSpots(array $ranges, int $targetTotal): array
    {
        if ($ranges === []) {
            return $ranges;
        }

        $current = 0;
        foreach ($ranges as $range) {
            $current += max(0, (int) ($range['spot_count'] ?? 0));
        }

        if ($targetTotal < 1) {
            foreach ($ranges as $index => $range) {
                $ranges[$index]['spot_count'] = $index === 0 ? 0 : 0;
            }

            return $ranges;
        }

        if ($current < 1) {
            $ranges[0]['spot_count'] = $targetTotal;
            for ($index = 1, $count = count($ranges); $index < $count; $index++) {
                $ranges[$index]['spot_count'] = 0;
            }

            return $ranges;
        }

        $assigned = 0;
        $last = count($ranges) - 1;
        foreach ($ranges as $index => $range) {
            if ($index === $last) {
                $ranges[$index]['spot_count'] = max(0, $targetTotal - $assigned);
                break;
            }

            $share = (int) floor(((int) ($range['spot_count'] ?? 0) / $current) * $targetTotal);
            $ranges[$index]['spot_count'] = $share;
            $assigned += $share;
        }

        $kept = array_values(array_filter(
            $ranges,
            fn (array $range): bool => (int) $range['spot_count'] >= 1,
        ));

        if ($kept === []) {
            $ranges[0]['spot_count'] = $targetTotal;

            return [$ranges[0]];
        }

        return $kept;
    }
}
