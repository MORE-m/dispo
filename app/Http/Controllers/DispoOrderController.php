<?php

namespace App\Http\Controllers;

use App\Enums\DispoOrderStatus;
use App\Http\Requests\DispoOrder\ApproveDispoOrderRequest;
use App\Http\Requests\DispoOrder\CreateDispoOrderFromCalculationRequest;
use App\Http\Requests\DispoOrder\RejectDispoOrderRequest;
use App\Http\Requests\DispoOrder\SubmitDispoOrderRequest;
use App\Models\Calculation;
use App\Models\DispoOrder;
use App\Models\DispoOrderApprovalRequest;
use App\Models\DispoOrderPosition;
use App\Models\User;
use App\Services\DispoOrder\DispoOrderApprovalService;
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
        private readonly DispoOrderApprovalService $approvals,
    ) {}

    public function index(Request $request): Response
    {
        $this->authorize('viewAny', DispoOrder::class);

        $orders = DispoOrder::query()
            ->with(['creator', 'calculation', 'latestApprovalRequest'])
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
                'approval_kind' => $order->approval_kind->value,
                'approval_kind_label' => $order->approval_kind->label(),
                'requires_special_approval' => $order->requiresSpecialApproval(),
                'creator_name' => $order->creator?->name,
                'created_at' => $order->created_at?->toIso8601String(),
                'submitted_at' => $order->latestApprovalRequest?->submitted_at?->toIso8601String(),
            ]);

        return Inertia::render('dispo-orders/index', [
            'orders' => $orders,
            'canCreate' => false,
        ]);
    }

    public function show(Request $request, DispoOrder $dispoOrder): Response
    {
        $this->authorize('view', $dispoOrder);

        $dispoOrder->load([
            'positions',
            'creator',
            'calculation',
            'approvalRequests',
            'pendingApprovalRequest',
        ]);

        /** @var User|null $user */
        $user = $request->user();

        $canViewCalculation = $dispoOrder->calculation !== null
            && ($user?->can('view', $dispoOrder->calculation) ?? false);

        $isCreator = $user !== null && (int) $user->id === (int) $dispoOrder->created_by_id;
        $canSubmit = ($user?->can('submit', $dispoOrder) ?? false)
            && $dispoOrder->status === DispoOrderStatus::Draft;
        $awaiting = $dispoOrder->status === DispoOrderStatus::AwaitingSalesApproval
            && $dispoOrder->pendingApprovalRequest !== null;
        $canApprove = ($user?->can('approve', $dispoOrder) ?? false) && $awaiting;
        $canReject = ($user?->can('reject', $dispoOrder) ?? false) && $awaiting;

        return Inertia::render('dispo-orders/show', [
            'order' => $this->serializeOrder($dispoOrder),
            'canViewCalculation' => $canViewCalculation,
            'canCreate' => false,
            'canSubmit' => $canSubmit,
            'canApprove' => $canApprove,
            'canReject' => $canReject,
            'isCreator' => $isCreator,
        ]);
    }

    public function submit(SubmitDispoOrderRequest $request, DispoOrder $dispoOrder): JsonResponse|RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();

        $order = $this->approvals->submit(
            $dispoOrder,
            $user,
            $request->expectedLockVersion(),
        );

        return $this->respondSuccess($request, $order, 'Dispoauftrag zur Freigabe eingereicht.');
    }

    public function approve(ApproveDispoOrderRequest $request, DispoOrder $dispoOrder): JsonResponse|RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();

        $order = $this->approvals->approve(
            $dispoOrder,
            $user,
            $request->expectedLockVersion(),
            $request->note(),
        );

        return $this->respondSuccess($request, $order, 'Dispoauftrag genehmigt. Status: Liegt bei Disposition.');
    }

    public function reject(RejectDispoOrderRequest $request, DispoOrder $dispoOrder): JsonResponse|RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();

        $order = $this->approvals->reject(
            $dispoOrder,
            $user,
            $request->expectedLockVersion(),
            $request->reason(),
        );

        return $this->respondSuccess($request, $order, 'Dispoauftrag abgelehnt.');
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

    private function respondSuccess(Request $request, DispoOrder $order, string $message): JsonResponse|RedirectResponse
    {
        $url = route('dispo-orders.show', $order);

        if ($request->expectsJson()) {
            return response()->json([
                'message' => $message,
                'redirect' => $url,
            ]);
        }

        return redirect()->to($url)->with('success', $message);
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
            'lock_version' => $order->lock_version,
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
            'requires_special_approval' => $order->requiresSpecialApproval(),
            'approval_kind' => $order->approval_kind->value,
            'approval_kind_label' => $order->approval_kind->label(),
            'special_approval_reasons' => $order->special_approval_reasons ?? [],
            'order_discounts' => $order->order_discounts_snapshot ?? [],
            'source_calculation_totals' => $order->source_calculation_totals_snapshot ?? null,
            'creator_name' => $order->creator?->name,
            'created_at' => $order->created_at?->toIso8601String(),
            'approval_history' => $order->approvalRequests->map(
                fn (DispoOrderApprovalRequest $request): array => $this->serializeApprovalRequest($request),
            )->all(),
            'current_approval' => $order->pendingApprovalRequest instanceof DispoOrderApprovalRequest
                ? $this->serializeApprovalRequest($order->pendingApprovalRequest)
                : ($order->approvalRequests->last() instanceof DispoOrderApprovalRequest
                    ? $this->serializeApprovalRequest($order->approvalRequests->last())
                    : null),
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

    /**
     * @return array<string, mixed>
     */
    private function serializeApprovalRequest(DispoOrderApprovalRequest $request): array
    {
        return [
            'id' => $request->id,
            'cycle_number' => $request->cycle_number,
            'status' => $request->status->value,
            'status_label' => $request->status->label(),
            'kind' => $request->kind->value,
            'kind_label' => $request->kind->label(),
            'special_approval_reasons' => $request->special_approval_reasons ?? [],
            'submitted_by_name' => $request->submitted_by_name,
            'submitted_at' => $request->submitted_at->toIso8601String(),
            'decided_by_name' => $request->decided_by_name,
            'decided_at' => $request->decided_at?->toIso8601String(),
            'rejection_reason' => $request->rejection_reason,
            'decision_note' => $request->decision_note,
        ];
    }
}
