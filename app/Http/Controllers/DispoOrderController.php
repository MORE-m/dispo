<?php

namespace App\Http\Controllers;

use App\Http\Requests\DispoOrder\CreateDispoOrderFromCalculationRequest;
use App\Models\Calculation;
use App\Models\DispoOrder;
use App\Models\DispoOrderPosition;
use App\Models\User;
use App\Services\DispoOrder\DispoOrderPositionAdoptionService;
use App\Services\DispoOrder\DispoOrderWriter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class DispoOrderController extends Controller
{
    public function __construct(
        private readonly DispoOrderWriter $writer,
        private readonly DispoOrderPositionAdoptionService $adoptions,
    ) {}

    public function index(Request $request): Response
    {
        $this->authorize('viewAny', DispoOrder::class);

        $orders = DispoOrder::query()
            ->with(['creator', 'calculation'])
            ->withCount('positions')
            ->latest()
            ->limit(100)
            ->get()
            ->map(fn (DispoOrder $order): array => [
                'id' => $order->id,
                'number' => $order->number,
                'customer_name' => $order->customer_name,
                'campaign' => $order->campaign,
                'product_title' => $order->product_title,
                'source_calculation_number' => $order->source_calculation_number,
                'calculation_id' => $order->calculation_id,
                'positions_count' => $order->positions_count,
                'nn_invest' => (string) $order->nn_invest,
                'status' => $order->status->value,
                'status_label' => $order->status->label(),
                'creator_name' => $order->creator?->name,
                'created_at' => $order->created_at?->toIso8601String(),
            ]);

        return Inertia::render('dispo-orders/index', [
            'orders' => $orders,
            'canCreate' => false,
        ]);
    }

    public function show(Request $request, DispoOrder $dispoOrder): Response
    {
        $this->authorize('view', $dispoOrder);

        $dispoOrder->load(['positions', 'creator', 'calculation']);

        $canViewCalculation = $dispoOrder->calculation !== null
            && ($request->user()?->can('view', $dispoOrder->calculation) ?? false);

        return Inertia::render('dispo-orders/show', [
            'order' => $this->serializeOrder($dispoOrder),
            'canViewCalculation' => $canViewCalculation,
            'canCreate' => false,
        ]);
    }

    public function positions(Request $request, Calculation $calculation): JsonResponse
    {
        $this->authorize('create', [DispoOrder::class, $calculation]);

        $calculation->load(['positions.inventory', 'positions.advertisingMedium', 'positions.timeRanges']);

        return response()->json([
            'positions' => $this->adoptions->selectablePositions($calculation),
        ]);
    }

    public function store(CreateDispoOrderFromCalculationRequest $request, Calculation $calculation): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();

        $result = $this->writer->createFromCalculation(
            $calculation,
            $request->positionIds(),
            $user,
        );

        return redirect()
            ->route('dispo-orders.show', $result->order)
            ->with('success', 'Dispoauftrag angelegt.');
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeOrder(DispoOrder $order): array
    {
        return [
            'id' => $order->id,
            'number' => $order->number,
            'status' => $order->status->value,
            'status_label' => $order->status->label(),
            'source_calculation_number' => $order->source_calculation_number,
            'calculation_id' => $order->calculation_id,
            'customer_name' => $order->customer_name,
            'agency_name' => $order->agency_name,
            'campaign' => $order->campaign,
            'product_title' => $order->product_title,
            'briefing' => $order->briefing,
            'advisor_name' => $order->advisor_name,
            'order_discount_percent' => (string) $order->order_discount_percent,
            'ae_enabled' => (bool) $order->ae_enabled,
            'target_budget_nn' => $order->target_budget_nn === null ? null : (string) $order->target_budget_nn,
            'media_gross' => (string) $order->media_gross,
            'position_discount_total' => (string) $order->position_discount_total,
            'order_discount_total' => (string) $order->order_discount_total,
            'ae_total' => (string) $order->ae_total,
            'nn_invest' => (string) $order->nn_invest,
            'requires_special_approval' => (bool) $order->requires_special_approval,
            'order_discounts' => $order->order_discounts_snapshot ?? [],
            'source_calculation_totals' => $order->source_calculation_totals_snapshot ?? null,
            'creator_name' => $order->creator?->name,
            'created_at' => $order->created_at?->toIso8601String(),
            'positions' => $order->positions->map(fn (DispoOrderPosition $position): array => [
                'id' => $position->id,
                'inventory_name' => $position->inventory_name,
                'advertising_medium_name' => $position->advertising_medium_name,
                'spot_method' => $position->spot_method->value,
                'spot_method_label' => $position->spot_method->label(),
                'length_seconds' => $position->length_seconds,
                'total_spot_count' => $position->total_spot_count,
                'price_list_version' => $position->price_list_version,
                'media_gross' => (string) $position->media_gross,
                'position_discount_amount' => (string) $position->position_discount_amount,
                'order_discount_amount' => (string) $position->order_discount_amount,
                'ae_amount' => (string) $position->ae_amount,
                'nn_invest' => (string) $position->nn_invest,
                'time_ranges' => $position->time_ranges_snapshot ?? [],
                'position_discounts' => $position->position_discounts_snapshot ?? [],
            ])->all(),
        ];
    }
}
