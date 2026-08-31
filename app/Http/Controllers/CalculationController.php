<?php

namespace App\Http\Controllers;

use App\Enums\BudgetStrategy;
use App\Enums\DayGroup;
use App\Enums\DiscountType;
use App\Http\Requests\Calculation\CalculationPayloadRequest;
use App\Models\AdvertisingMedium;
use App\Models\BudgetProposal;
use App\Models\Calculation;
use App\Models\CalculationOrderDiscount;
use App\Models\CalculationPosition;
use App\Models\CalculationPositionDiscount;
use App\Models\CalculationPositionTimeRange;
use App\Models\Inventory;
use App\Models\InventoryMediumRule;
use App\Models\SpotClassicPlanRow;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Services\Calculation\BudgetProposalService;
use App\Services\Calculation\CalculationWriter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class CalculationController extends Controller
{
    public function __construct(
        private readonly CalculationWriter $writer,
        private readonly BudgetProposalService $proposals,
        private readonly AuditLogger $audit,
    ) {}

    public function index(Request $request): Response
    {
        $this->authorize('viewAny', Calculation::class);

        $calculations = Calculation::query()
            ->with('advisor')
            ->latest()
            ->limit(100)
            ->get();

        return Inertia::render('calculations/index', [
            'calculations' => $calculations,
            'canCreate' => $request->user()?->can('create', Calculation::class) ?? false,
        ]);
    }

    public function create(Request $request): Response
    {
        $this->authorize('create', Calculation::class);

        return Inertia::render('calculations/wizard', $this->wizardProps($request, null));
    }

    public function store(CalculationPayloadRequest $request): RedirectResponse
    {
        $this->authorize('create', Calculation::class);

        /** @var User $user */
        $user = $request->user();
        $calculation = $this->writer->create($request->payload(), $user);

        return redirect()
            ->route('calculations.edit', $calculation)
            ->with('success', 'Kalkulation gespeichert.');
    }

    public function edit(Request $request, Calculation $calculation): Response
    {
        $this->authorize('view', $calculation);

        $calculation->load(['positions.planRows', 'positions.timeRanges', 'positions.discounts', 'positions.inventory', 'positions.advertisingMedium', 'positions.priceList', 'orderDiscounts']);

        return Inertia::render('calculations/wizard', $this->wizardProps($request, $calculation));
    }

    public function update(CalculationPayloadRequest $request, Calculation $calculation): RedirectResponse
    {
        $this->authorize('update', $calculation);

        /** @var User $user */
        $user = $request->user();
        $this->writer->update($calculation, $request->payload(), $user);

        return redirect()
            ->route('calculations.edit', $calculation)
            ->with('success', 'Kalkulation gespeichert.');
    }

    public function preview(CalculationPayloadRequest $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $existing = null;

        if ($request->integer('calculation_id') > 0) {
            $existing = Calculation::query()
                ->with(['positions.planRows', 'positions.timeRanges', 'positions.discounts', 'orderDiscounts'])
                ->findOrFail($request->integer('calculation_id'));
            $this->authorize('view', $existing);
        } else {
            $this->authorize('create', Calculation::class);
        }

        return response()->json([
            'totals' => $this->writer->preview($request->payload(), $user, $existing)->toArray(),
        ]);
    }

    public function proposeBudget(CalculationPayloadRequest $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $payload = $request->payload();
        $existing = null;

        if ($request->integer('calculation_id') > 0) {
            $existing = Calculation::query()
                ->with(['positions.planRows', 'positions.timeRanges', 'positions.discounts', 'orderDiscounts'])
                ->findOrFail($request->integer('calculation_id'));
            $this->authorize('update', $existing);
            $payload = $this->enrichPayloadWithExistingPositions($payload, $existing);
        } else {
            $this->authorize('create', Calculation::class);
        }

        if (! isset($payload['target_budget_nn']) || $payload['target_budget_nn'] === '') {
            return response()->json([
                'message' => 'Zielbudget N/N fehlt.',
            ], 422);
        }

        $proposal = $this->proposals->propose(
            $this->writer->positionInputsFromPayload($payload, $existing),
            (string) $payload['target_budget_nn'],
            (string) ($payload['order_discount_percent'] ?? '0'),
            BudgetStrategy::from((string) ($payload['budget_strategy'] ?? 'equal_budget')),
        );

        if ($existing !== null) {
            $stored = BudgetProposal::query()->create([
                'calculation_id' => $existing->id,
                'strategy' => $proposal['strategy'],
                'target_budget_nn' => $proposal['target_budget_nn'],
                'lock_version' => $existing->lock_version,
                'payload' => $proposal,
                'created_by' => $user->id,
            ]);
            $proposal['id'] = $stored->id;
            $proposal['lock_version'] = $existing->lock_version;
            $this->audit->record($existing, 'budget.proposed', $user, null, [
                'proposal_id' => $stored->id,
                'strategy' => $proposal['strategy'],
                'lock_version' => $existing->lock_version,
            ]);
        }

        return response()->json(['proposal' => $proposal]);
    }

    public function applyBudget(Request $request, Calculation $calculation, BudgetProposal $proposal): RedirectResponse
    {
        $this->authorize('update', $calculation);

        /** @var User $user */
        $user = $request->user();

        $this->writer->applyBudgetProposal($calculation, $proposal, $user);

        return redirect()
            ->route('calculations.edit', $calculation)
            ->with('success', 'Vorschlag übernommen. Die Mengen bleiben editierbar.');
    }

    /**
     * @return array<string, mixed>
     */
    private function wizardProps(Request $request, ?Calculation $calculation): array
    {
        $calculation?->loadMissing(['positions.planRows', 'positions.timeRanges', 'positions.discounts', 'positions.inventory', 'orderDiscounts']);

        $activeInventories = Inventory::query()
            ->where('is_active', true)
            ->orderBy('sort')
            ->orderBy('name')
            ->get(['id', 'name', 'code', 'type', 'logo_path', 'is_active']);

        $historicalInventoryIds = $calculation !== null
            ? $calculation->positions->pluck('inventory_id')->unique()->values()
            : collect();

        $historicalInventories = Inventory::query()
            ->whereIn('id', $historicalInventoryIds)
            ->where('is_active', false)
            ->orderBy('sort')
            ->orderBy('name')
            ->get(['id', 'name', 'code', 'type', 'logo_path', 'is_active']);

        $inventories = $activeInventories->concat($historicalInventories)->values();

        $activeMedia = AdvertisingMedium::query()
            ->where('is_active', true)
            ->get(['id', 'name', 'code', 'kind', 'default_length_seconds', 'is_discountable', 'is_ae_eligible', 'is_active']);

        $historicalMediumIds = $calculation !== null
            ? $calculation->positions->pluck('advertising_medium_id')->unique()->values()
            : collect();

        $historicalMedia = AdvertisingMedium::query()
            ->whereIn('id', $historicalMediumIds)
            ->where('is_active', false)
            ->get(['id', 'name', 'code', 'kind', 'default_length_seconds', 'is_discountable', 'is_ae_eligible', 'is_active']);

        $media = $activeMedia->concat($historicalMedia)->values();

        $activeRules = InventoryMediumRule::query()
            ->where('is_active', true)
            ->get([
                'id',
                'inventory_id',
                'advertising_medium_id',
                'default_length_seconds',
                'surcharge_percent',
                'is_discountable',
                'is_ae_eligible',
                'is_active',
            ]);

        $historicalRuleIds = $calculation !== null
            ? $calculation->positions->pluck('inventory_medium_rule_id')->filter()->unique()->values()
            : collect();

        $historicalCombos = $calculation !== null
            ? $calculation->positions->map(fn ($position): array => [
                'inventory_id' => $position->inventory_id,
                'advertising_medium_id' => $position->advertising_medium_id,
            ])->unique()->values()
            : collect();

        $historicalRules = collect();

        if ($calculation !== null && ($historicalRuleIds->isNotEmpty() || $historicalCombos->isNotEmpty())) {
            $historicalRules = InventoryMediumRule::query()
                ->where('is_active', false)
                ->where(function ($query) use ($historicalRuleIds, $historicalCombos): void {
                    if ($historicalRuleIds->isNotEmpty()) {
                        $query->whereIn('id', $historicalRuleIds);
                    }

                    foreach ($historicalCombos as $combo) {
                        $query->orWhere(function ($nested) use ($combo): void {
                            $nested->where('inventory_id', $combo['inventory_id'])
                                ->where('advertising_medium_id', $combo['advertising_medium_id']);
                        });
                    }
                })
                ->get([
                    'id',
                    'inventory_id',
                    'advertising_medium_id',
                    'default_length_seconds',
                    'surcharge_percent',
                    'is_discountable',
                    'is_ae_eligible',
                    'is_active',
                ]);
        }

        $rules = $activeRules->concat($historicalRules)->unique('id')->values();

        $canEdit = $calculation === null
            ? ($request->user()?->can('create', Calculation::class) ?? false)
            : ($request->user()?->can('update', $calculation) ?? false);

        $savedSummary = null;
        $savedDisplayTotals = null;
        if ($calculation !== null) {
            $savedSummary = [
                'media_gross' => (string) $calculation->media_gross,
                'position_discount_total' => (string) $calculation->position_discount_total,
                'order_discount_total' => (string) $calculation->order_discount_total,
                'ae_total' => (string) $calculation->ae_total,
                'nn_invest' => (string) $calculation->nn_invest,
                'target_budget_nn' => $calculation->target_budget_nn === null ? null : (string) $calculation->target_budget_nn,
                'requires_special_approval' => $calculation->requires_special_approval,
                'positions' => $calculation->positions->map(fn ($position): array => [
                    'inventory_name' => $position->inventory->name ?? '',
                    'spot_method' => $position->spot_method->value,
                    'price_list_version' => $position->price_list_version,
                    'length_seconds' => $position->length_seconds,
                    'total_spot_count' => $position->total_spot_count,
                    'media_gross' => (string) $position->media_gross,
                    'nn_invest' => (string) $position->nn_invest,
                    'position_discount_percent' => (string) $position->position_discount_percent,
                    'ae_percent' => (string) $position->ae_percent,
                    'plan_rows' => $position->planRows->map(fn ($row): array => [
                        'hour' => $row->hour,
                        'day_group' => $row->day_group->value,
                        'second_price' => (string) $row->second_price,
                    ])->all(),
                    'time_ranges' => $position->timeRanges->map(fn (CalculationPositionTimeRange $range): array => [
                        'start_hour' => $range->start_hour,
                        'end_hour_exclusive' => $range->end_hour_exclusive,
                        'day_group' => $range->day_group->value,
                        'spot_count' => $range->spot_count,
                        'average_second_price' => $range->average_second_price === null ? null : (string) $range->average_second_price,
                        'range_gross' => $range->range_gross === null ? null : (string) $range->range_gross,
                    ])->all(),
                    'position_discounts' => $position->discounts->map(fn (CalculationPositionDiscount $discount): array => [
                        'type' => $discount->type->value,
                        'custom_label' => $discount->custom_label,
                        'percent' => (string) $discount->percent,
                    ])->all(),
                ])->all(),
                'order_discounts' => $calculation->orderDiscounts->map(fn (CalculationOrderDiscount $discount): array => [
                    'type' => $discount->type->value,
                    'custom_label' => $discount->custom_label,
                    'percent' => (string) $discount->percent,
                ])->all(),
                'ae_enabled' => (bool) $calculation->ae_enabled,
            ];

            if (! $canEdit) {
                /** @var User $user */
                $user = $request->user();
                $savedDisplayTotals = $this->writer->preview(
                    $this->writer->payloadFromCalculation($calculation),
                    $user,
                    $calculation,
                )->toArray();
            }
        }

        return [
            'catalog' => [
                'inventories' => $inventories,
                'media' => $media,
                'rules' => $rules,
            ],
            'dayGroups' => DayGroup::options(),
            'discountTypes' => DiscountType::options(),
            'calculation' => $calculation === null ? null : [
                'id' => $calculation->id,
                'lock_version' => $calculation->lock_version,
                'planning_mode' => $calculation->planning_mode->value,
                'customer_name' => $calculation->customer_name,
                'agency_name' => $calculation->agency_name,
                'campaign' => $calculation->campaign,
                'product_title' => $calculation->product_title,
                'briefing' => $calculation->briefing,
                'order_discount_percent' => (string) $calculation->order_discount_percent,
                'ae_enabled' => (bool) $calculation->ae_enabled,
                'target_budget_nn' => $calculation->target_budget_nn === null ? null : (string) $calculation->target_budget_nn,
                'budget_strategy' => $calculation->budget_strategy?->value,
                'order_discounts' => $calculation->orderDiscounts->map(fn (CalculationOrderDiscount $discount): array => [
                    'type' => $discount->type->value,
                    'custom_label' => $discount->custom_label,
                    'percent' => (string) $discount->percent,
                ])->all(),
                'positions' => $calculation->positions->map(fn (CalculationPosition $position): array => [
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
                    'plan_rows' => $position->planRows->map(fn (SpotClassicPlanRow $row): array => [
                        'hour' => $row->hour,
                        'day_group' => $row->day_group->value,
                        'second_price' => (string) $row->second_price,
                    ])->all(),
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
                ])->all(),
            ],
            'savedSummary' => $savedSummary,
            'savedDisplayTotals' => $savedDisplayTotals,
            'canEdit' => $canEdit,
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function enrichPayloadWithExistingPositions(array $payload, Calculation $calculation): array
    {
        if (! isset($payload['positions']) || ! is_array($payload['positions'])) {
            return $payload;
        }

        $byClient = $calculation->positions->keyBy('client_key');
        $byInventory = $calculation->positions->keyBy('inventory_id');

        foreach ($payload['positions'] as $index => $position) {
            $existing = null;

            if (isset($position['id'])) {
                $existing = $calculation->positions->firstWhere('id', (int) $position['id']);
            } elseif (isset($position['client_key'])) {
                $existing = $byClient->get((string) $position['client_key']);
            } else {
                $existing = $byInventory->get((int) ($position['inventory_id'] ?? 0));
            }

            if ($existing !== null) {
                $payload['positions'][$index]['id'] = $existing->id;
                $payload['positions'][$index]['client_key'] = $existing->client_key;
            }
        }

        return $payload;
    }
}
