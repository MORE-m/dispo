<?php

namespace App\Http\Controllers;

use App\Enums\BudgetStrategy;
use App\Enums\SpotCalculationMethod;
use App\Http\Requests\Calculation\CalculationPayloadRequest;
use App\Models\AdvertisingMedium;
use App\Models\BudgetProposal;
use App\Models\Calculation;
use App\Models\Inventory;
use App\Models\InventoryMediumRule;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Services\Calculation\BudgetProposalService;
use App\Services\Calculation\CalculationWriter;
use App\Services\Calculation\PositionInput;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
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

        $calculation->load(['positions.planRows', 'positions.inventory', 'positions.advertisingMedium', 'positions.priceList']);

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
            $existing = Calculation::query()->findOrFail($request->integer('calculation_id'));
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
                ->with('positions')
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
            $this->positionInputs($payload, $existing),
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

        abort_unless($proposal->calculation_id === $calculation->id, 404);
        abort_if($proposal->applied_at !== null, 422, 'Der Vorschlag wurde bereits übernommen.');

        if ($proposal->lock_version !== null && $proposal->lock_version !== $calculation->lock_version) {
            throw ValidationException::withMessages([
                'lock_version' => 'Der Vorschlag basiert auf einer älteren Version der Kalkulation. Bitte neuen Vorschlag erzeugen.',
            ]);
        }

        /** @var User $user */
        $user = $request->user();

        $payload = $this->writer->payloadFromCalculation($calculation);
        $payload['planning_mode'] = $calculation->planning_mode->value;
        $payload['order_discount_percent'] = (string) $calculation->order_discount_percent;
        $payload['target_budget_nn'] = (string) $proposal->target_budget_nn;
        $payload['budget_strategy'] = $proposal->strategy->value;
        $payload['lock_version'] = $calculation->lock_version;
        $payload['positions'] = $this->mergeProposalRows($payload['positions'], $proposal->payload['positions'] ?? []);

        $this->writer->update($calculation, $payload, $user);
        $proposal->applied_at = now();
        $proposal->applied_by = $user->id;
        $proposal->save();

        $this->audit->record($calculation, 'budget.applied', $user, null, [
            'proposal_id' => $proposal->id,
            'applied_by' => $user->id,
        ]);

        return redirect()
            ->route('calculations.edit', $calculation)
            ->with('success', 'Vorschlag übernommen. Die Mengen bleiben editierbar.');
    }

    /**
     * @return array<string, mixed>
     */
    private function wizardProps(Request $request, ?Calculation $calculation): array
    {
        $inventories = Inventory::query()
            ->where('is_active', true)
            ->orderBy('sort')
            ->orderBy('name')
            ->get(['id', 'name', 'code', 'type', 'logo_path']);

        $media = AdvertisingMedium::query()
            ->where('is_active', true)
            ->get(['id', 'name', 'code', 'kind', 'default_length_seconds', 'is_discountable', 'is_ae_eligible']);

        $rules = InventoryMediumRule::query()
            ->where('is_active', true)
            ->get([
                'inventory_id',
                'advertising_medium_id',
                'default_length_seconds',
                'surcharge_percent',
                'is_discountable',
                'is_ae_eligible',
            ]);

        $canEdit = $calculation === null
            ? ($request->user()?->can('create', Calculation::class) ?? false)
            : ($request->user()?->can('update', $calculation) ?? false);

        $savedSummary = null;
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
                ])->all(),
            ];
        }

        return [
            'catalog' => [
                'inventories' => $inventories,
                'media' => $media,
                'rules' => $rules,
            ],
            'calculation' => $calculation,
            'savedSummary' => $savedSummary,
            'canEdit' => $canEdit,
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return list<PositionInput>
     */
    private function positionInputs(array $payload, ?Calculation $existing = null): array
    {
        $resolved = $this->writer->resolvedPositions($payload, $existing);
        $inputs = [];

        foreach ($resolved as $index => $item) {
            $payloadPosition = $payload['positions'][$index] ?? [];
            $positionKey = isset($payloadPosition['id'])
                ? 'id:'.((int) $payloadPosition['id'])
                : ((string) ($payloadPosition['client_key'] ?? 'new:'.$index));

            $inputs[] = new PositionInput(
                inventoryId: $item['inventory']->id,
                inventoryName: $item['inventory']->name,
                positionKey: $positionKey,
                lengthSeconds: (int) $item['length_seconds'],
                surchargePercent: (string) $item['rule']->surcharge_percent,
                positionDiscountPercent: $item['is_discountable'] ? (string) $item['position_discount_percent'] : '0',
                aePercent: $item['is_ae_eligible'] ? (string) $item['ae_percent'] : '0',
                isDiscountable: $item['is_discountable'],
                isAeEligible: $item['is_ae_eligible'],
                totalSpotCount: 0,
                spotMethod: SpotCalculationMethod::Average,
                rows: $item['rows'],
            );
        }

        return $inputs;
    }

    /**
     * @param  list<array<string, mixed>>  $positions
     * @param  list<array<string, mixed>>  $proposed
     * @return list<array<string, mixed>>
     */
    private function mergeProposalRows(array $positions, array $proposed): array
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
                $position['plan_rows'] = array_map(
                    fn (array $row): array => [...$row, 'spot_count' => 0],
                    $position['plan_rows'] ?? [],
                );
            } else {
                $total = array_sum(array_column($item['rows'], 'spot_count'));
                $position['total_spot_count'] = $total;
                $position['length_seconds'] = $item['length_seconds'] ?? $position['length_seconds'];
                $position['plan_rows'] = array_map(
                    fn (array $row): array => [
                        'hour' => $row['hour'],
                        'day_group' => $row['day_group'],
                        'second_price' => $row['second_price'] ?? null,
                    ],
                    $item['rows'],
                );
            }

            $merged[] = $position;
        }

        return $merged;
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
