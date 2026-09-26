<?php

namespace App\Http\Controllers;

use App\Enums\DispoOrderStatus;
use App\Http\Requests\DispoOrder\AnswerSalesInquiryRequest;
use App\Http\Requests\DispoOrder\ApproveDispoOrderRequest;
use App\Http\Requests\DispoOrder\ArchiveDispoOrderUploadRequest;
use App\Http\Requests\DispoOrder\AskSalesInquiryRequest;
use App\Http\Requests\DispoOrder\CancelDispoOrderRequest;
use App\Http\Requests\DispoOrder\CompleteDispoOrderRequest;
use App\Http\Requests\DispoOrder\CreateDispoOrderFromCalculationRequest;
use App\Http\Requests\DispoOrder\RejectDispoOrderRequest;
use App\Http\Requests\DispoOrder\ReopenCompletedDispoOrderRequest;
use App\Http\Requests\DispoOrder\SubmitDispoOrderRequest;
use App\Http\Requests\DispoOrder\SyncDispoOrderCalculationDynamicFieldsRequest;
use App\Http\Requests\DispoOrder\TransitionOperationalStatusRequest;
use App\Http\Requests\DispoOrder\UpdateCustomerConfirmationRequest;
use App\Http\Requests\DispoOrder\UpdateDispoOrderDraftRequest;
use App\Http\Requests\DispoOrder\UpdateDispoOrderPositionCustomsRequest;
use App\Http\Requests\DispoOrder\UpdateInvoiceEndMonthsRequest;
use App\Http\Requests\DispoOrder\UploadCustomerConfirmationRequest;
use App\Http\Requests\DispoOrder\UploadDispoOrderDynamicFieldRequest;
use App\Http\Requests\DispoOrder\UploadDispoOrderMaterialRequest;
use App\Models\Calculation;
use App\Models\DispoOrder;
use App\Models\DispoOrderApprovalRequest;
use App\Models\DispoOrderComment;
use App\Models\DispoOrderPosition;
use App\Models\DispoOrderStatusEvent;
use App\Models\DispoOrderUpload;
use App\Models\User;
use App\Services\DispoOrder\DispoOrderApprovalService;
use App\Services\DispoOrder\DispoOrderCancellationService;
use App\Services\DispoOrder\DispoOrderCompletedReopenService;
use App\Services\DispoOrder\DispoOrderCompletionReadiness;
use App\Services\DispoOrder\DispoOrderCompletionService;
use App\Services\DispoOrder\DispoOrderCustomerConfirmationService;
use App\Services\DispoOrder\DispoOrderInvoiceEndService;
use App\Services\DispoOrder\DispoOrderOperationalStatusService;
use App\Services\DispoOrder\DispoOrderPositionAdoptionService;
use App\Services\DispoOrder\DispoOrderRevisionContext;
use App\Services\DispoOrder\DispoOrderSalesInquiryService;
use App\Services\DispoOrder\DispoOrderStatusTransition;
use App\Services\DispoOrder\DispoOrderUploadService;
use App\Services\DispoOrder\DispoOrderWriter;
use App\Services\DispoOrder\SpotDistributionExport\SpotDistributionExportService;
use App\Services\DynamicField\DispoOrderDynamicFieldWriter;
use App\Support\Advertising\SpotComponentProfileContract;
use App\Support\DispoOrder\DerivedCampaignPeriodPresenter;
use App\Support\DispoOrder\InvoiceEndMonthsContract;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

class DispoOrderController extends Controller
{
    public function __construct(
        private readonly DispoOrderWriter $writer,
        private readonly DispoOrderPositionAdoptionService $adoptions,
        private readonly DispoOrderApprovalService $approvals,
        private readonly DispoOrderOperationalStatusService $operationalStatus,
        private readonly DispoOrderSalesInquiryService $salesInquiry,
        private readonly DispoOrderCustomerConfirmationService $customerConfirmation,
        private readonly DispoOrderUploadService $uploads,
        private readonly DispoOrderInvoiceEndService $invoiceEnd,
        private readonly DispoOrderCompletionService $completion,
        private readonly DispoOrderCompletedReopenService $completedReopen,
        private readonly DispoOrderCancellationService $cancellation,
        private readonly DispoOrderCompletionReadiness $completionReadiness,
        private readonly DispoOrderRevisionContext $revisionContext,
        private readonly DispoOrderDynamicFieldWriter $dynamicFields,
        private readonly SpotDistributionExportService $spotDistributionExport,
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
            'positions.fieldValues.snapshotFieldDefinition',
            'fieldValues.snapshotFieldDefinition',
            'configurationSnapshot.fieldDefinitions',
            'configurationSnapshot.rules',
            'creator',
            'calculation',
            'approvalRequests',
            'pendingApprovalRequest',
            'latestApprovalRequest',
            'statusEvents',
            'comments',
            'revises',
            'revision',
        ]);

        /** @var User|null $user */
        $user = $request->user();

        $canViewCalculation = $dispoOrder->calculation !== null
            && ($user?->can('view', $dispoOrder->calculation) ?? false);

        $isCreator = $user !== null && (int) $user->id === (int) $dispoOrder->created_by_id;
        $canSubmit = ($user?->can('submit', $dispoOrder) ?? false)
            && $dispoOrder->status === DispoOrderStatus::Draft;
        $canUpdate = ($user?->can('update', $dispoOrder) ?? false)
            && $dispoOrder->status === DispoOrderStatus::Draft;
        $canUpdateCustomerConfirmation = ($user?->can('updateCustomerConfirmation', $dispoOrder) ?? false)
            && $dispoOrder->status === DispoOrderStatus::Draft;
        $canUploadCustomerConfirmation = ($user?->can('uploadCustomerConfirmation', $dispoOrder) ?? false)
            && $dispoOrder->status === DispoOrderStatus::Draft;
        $canUploadMaterial = $this->uploads->canUploadMaterialFor($dispoOrder, $user);
        $canArchiveUpload = $user?->can('archiveUpload', $dispoOrder) ?? false;
        $awaiting = $dispoOrder->status === DispoOrderStatus::AwaitingSalesApproval
            && $dispoOrder->pendingApprovalRequest !== null;
        $canApprove = ($user?->can('approve', $dispoOrder) ?? false) && $awaiting;
        $canReject = ($user?->can('reject', $dispoOrder) ?? false) && $awaiting;
        $canRevise = $user?->can('revise', $dispoOrder) ?? false;
        $canTransitionOperationalStatus = $user?->can('transitionOperationalStatus', $dispoOrder) ?? false;
        $operationalTargets = $canTransitionOperationalStatus
            ? $this->operationalStatus->allowedTargetsProp($dispoOrder)
            : [];
        $canAskSalesInquiry = ($user?->can('askSalesInquiry', $dispoOrder) ?? false)
            && in_array(
                $dispoOrder->status,
                DispoOrderStatusTransition::salesInquiryAskSources(),
                true,
            );
        $openSalesInquiry = $this->salesInquiry->findOpenSalesInquiry($dispoOrder);
        $canAnswerSalesInquiry = ($user?->can('answerSalesInquiry', $dispoOrder) ?? false)
            && $dispoOrder->status === DispoOrderStatus::SalesInquiry
            && $openSalesInquiry !== null;
        $canUpdateInvoiceEndMonths = ($user?->can('updateInvoiceEndMonths', $dispoOrder) ?? false)
            && DispoOrderStatusTransition::isInvoiceEndEditable($dispoOrder->status);
        $canComplete = ($user?->can('complete', $dispoOrder) ?? false)
            && $dispoOrder->status === DispoOrderStatus::Disposed;
        $canForceComplete = ($user?->can('forceComplete', $dispoOrder) ?? false)
            && $dispoOrder->status === DispoOrderStatus::Disposed;
        $canReopenCompleted = ($user?->can('reopenCompleted', $dispoOrder) ?? false)
            && $dispoOrder->status === DispoOrderStatus::Completed;
        $canCancel = ($user?->can('cancel', $dispoOrder) ?? false)
            && DispoOrderStatusTransition::isCancellationSource($dispoOrder->status);
        $completionReadiness = in_array(
            $dispoOrder->status,
            [DispoOrderStatus::Disposed, DispoOrderStatus::Completed],
            true,
        )
            ? $this->completion->readinessProp($dispoOrder)
            : null;
        $completionSummary = $this->completion->completionSummaryProp($dispoOrder);
        $cancellationSummary = $this->cancellation->cancellationSummaryProp($dispoOrder);
        $dynamicValues = $this->dynamicFields->valuesProp($dispoOrder);
        $canSyncCalculationDynamicFields = $canUpdate
            && $dynamicValues['missing_calc_origin_keys'] !== [];

        $spotDistributionCapability = $this->spotDistributionExport->capability($dispoOrder);

        return Inertia::render('dispo-orders/show', [
            'order' => $this->serializeOrder($dispoOrder, $dynamicValues),
            'fieldSchema' => $this->dynamicFields->fieldSchemaProp($dispoOrder),
            'canViewCalculation' => $canViewCalculation,
            'canCreate' => false,
            'canSubmit' => $canSubmit,
            'canUpdate' => $canUpdate,
            'canUpdateCustomerConfirmation' => $canUpdateCustomerConfirmation,
            'canUploadCustomerConfirmation' => $canUploadCustomerConfirmation,
            'canUploadMaterial' => $canUploadMaterial,
            'materialUploadCategories' => $canUploadMaterial
                ? $this->uploads->materialCategoryOptionsProp()
                : [],
            'canArchiveUpload' => $canArchiveUpload,
            'uploads' => $this->uploads->listProp($dispoOrder),
            'activeCustomerConfirmationUpload' => ($active = $this->uploads->activeCustomerConfirmation($dispoOrder))
                ? $this->uploads->serializeUpload($active, $dispoOrder)
                : null,
            'canSyncCalculationDynamicFields' => $canSyncCalculationDynamicFields,
            'canApprove' => $canApprove,
            'canReject' => $canReject,
            'canRevise' => $canRevise,
            'canTransitionOperationalStatus' => $canTransitionOperationalStatus,
            'operationalStatusTargets' => $operationalTargets,
            'canAskSalesInquiry' => $canAskSalesInquiry,
            'canAnswerSalesInquiry' => $canAnswerSalesInquiry,
            'openSalesInquiry' => $openSalesInquiry !== null
                ? $this->serializeComment($openSalesInquiry)
                : null,
            'canUpdateInvoiceEndMonths' => $canUpdateInvoiceEndMonths,
            'canComplete' => $canComplete,
            'canForceComplete' => $canForceComplete,
            'canReopenCompleted' => $canReopenCompleted,
            'canCancel' => $canCancel,
            'completionReadiness' => $completionReadiness,
            'completionSummary' => $completionSummary,
            'cancellationSummary' => $cancellationSummary,
            'isCreator' => $isCreator,
            'spotDistributionExport' => [
                'can_export' => true,
                'enabled' => $spotDistributionCapability['enabled'],
                'has_calendar_positions' => $spotDistributionCapability['has_calendar_positions'],
                'has_average_positions' => $spotDistributionCapability['has_average_positions'],
                'mixed_order' => $spotDistributionCapability['mixed_order'],
                'hint_kind' => $spotDistributionCapability['hint_kind'],
                'disabled_reason' => $spotDistributionCapability['disabled_reason'],
                'url' => route('dispo-orders.export-spot-distribution', $dispoOrder),
            ],
        ]);
    }

    public function exportSpotDistribution(Request $request, DispoOrder $dispoOrder): StreamedResponse
    {
        $this->authorize('view', $dispoOrder);

        /** @var User $user */
        $user = $request->user();

        return $this->spotDistributionExport->download($dispoOrder, $user);
    }

    public function update(UpdateDispoOrderDraftRequest $request, DispoOrder $dispoOrder): JsonResponse|RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();

        $order = $this->dynamicFields->updateDraftTexts(
            $dispoOrder,
            $user,
            $request->expectedLockVersion(),
            $request->dynamicFieldValues(),
        );

        return $this->respondSuccess($request, $order, 'Dispoauftrag gespeichert.');
    }

    public function updateCustomerConfirmation(
        UpdateCustomerConfirmationRequest $request,
        DispoOrder $dispoOrder,
    ): JsonResponse|RedirectResponse {
        /** @var User $user */
        $user = $request->user();

        $order = $this->customerConfirmation->update(
            $dispoOrder,
            $user,
            $request->expectedLockVersion(),
            $request->confirmationWithoutUpload(),
            $request->exceptionReason(),
        );

        return $this->respondSuccess(
            $request,
            $order,
            $request->confirmationWithoutUpload()
                ? 'Kundenbestätigungs-Ausnahme gespeichert.'
                : 'Kundenbestätigungs-Ausnahme entfernt.',
        );
    }

    public function uploadCustomerConfirmation(
        UploadCustomerConfirmationRequest $request,
        DispoOrder $dispoOrder,
    ): JsonResponse|RedirectResponse {
        /** @var User $user */
        $user = $request->user();

        $this->uploads->uploadCustomerConfirmation(
            $dispoOrder,
            $user,
            $request->expectedLockVersion(),
            $request->file('file'),
        );

        $order = $dispoOrder->fresh([
            'positions',
            'creator',
            'approvalRequests',
            'pendingApprovalRequest',
            'latestApprovalRequest',
        ]) ?? $dispoOrder;

        return $this->respondSuccess($request, $order, 'Kundenbestätigung hochgeladen.');
    }

    public function uploadMaterial(
        UploadDispoOrderMaterialRequest $request,
        DispoOrder $dispoOrder,
    ): JsonResponse|RedirectResponse {
        /** @var User $user */
        $user = $request->user();

        $this->uploads->uploadMaterial(
            $dispoOrder,
            $user,
            $request->expectedLockVersion(),
            $request->category(),
            $request->file('file'),
        );

        $order = $dispoOrder->fresh([
            'positions',
            'creator',
            'approvalRequests',
            'pendingApprovalRequest',
            'latestApprovalRequest',
        ]) ?? $dispoOrder;

        return $this->respondSuccess($request, $order, 'Material hochgeladen.');
    }

    public function uploadDynamicField(
        UploadDispoOrderDynamicFieldRequest $request,
        DispoOrder $dispoOrder,
    ): JsonResponse|RedirectResponse {
        /** @var User $user */
        $user = $request->user();

        $this->uploads->uploadDynamicFieldFile(
            $dispoOrder,
            $user,
            $request->expectedLockVersion(),
            $request->file('file'),
            $request->fieldKey(),
            $request->positionId(),
        );

        $order = $dispoOrder->fresh([
            'positions',
            'creator',
            'approvalRequests',
            'pendingApprovalRequest',
            'latestApprovalRequest',
        ]) ?? $dispoOrder;

        return $this->respondSuccess($request, $order, 'Datei hochgeladen.');
    }

    public function archiveUpload(
        ArchiveDispoOrderUploadRequest $request,
        DispoOrder $dispoOrder,
        DispoOrderUpload $upload,
    ): JsonResponse|RedirectResponse {
        /** @var User $user */
        $user = $request->user();

        $this->uploads->archive(
            $dispoOrder,
            $upload,
            $user,
            $request->expectedLockVersion(),
        );

        $order = $dispoOrder->fresh([
            'positions',
            'creator',
            'approvalRequests',
            'pendingApprovalRequest',
            'latestApprovalRequest',
        ]) ?? $dispoOrder;

        return $this->respondSuccess($request, $order, 'Datei archiviert.');
    }

    public function downloadUpload(
        Request $request,
        DispoOrder $dispoOrder,
        DispoOrderUpload $upload,
    ): StreamedResponse {
        /** @var User $user */
        $user = $request->user();

        return $this->uploads->download($dispoOrder, $upload, $user);
    }

    public function streamUpload(
        Request $request,
        DispoOrder $dispoOrder,
        DispoOrderUpload $upload,
    ): BinaryFileResponse {
        /** @var User $user */
        $user = $request->user();

        return $this->uploads->stream($dispoOrder, $upload, $user);
    }

    public function updatePositionCustoms(
        UpdateDispoOrderPositionCustomsRequest $request,
        DispoOrder $dispoOrder,
    ): JsonResponse|RedirectResponse {
        /** @var User $user */
        $user = $request->user();

        $order = $this->dynamicFields->updateDraftPositionCustoms(
            $dispoOrder,
            $user,
            $request->expectedLockVersion(),
            $request->positionDynamicFieldValues(),
        );

        return $this->respondSuccess($request, $order, 'Positionsangaben gespeichert.');
    }

    public function syncCalculationDynamicFields(
        SyncDispoOrderCalculationDynamicFieldsRequest $request,
        DispoOrder $dispoOrder,
    ): JsonResponse|RedirectResponse {
        /** @var User $user */
        $user = $request->user();

        $order = $this->dynamicFields->syncMissingCalculationFields(
            $dispoOrder,
            $user,
            $request->expectedLockVersion(),
        );

        return $this->respondSuccess(
            $request,
            $order,
            'Dynamische Zeitraumfelder aus der Kalkulation übernommen.',
        );
    }

    public function startRevision(Request $request, DispoOrder $dispoOrder): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();

        $this->authorize('revise', $dispoOrder);

        $dispoOrder->loadMissing('calculation');

        abort_if($dispoOrder->calculation === null, 404);

        $this->revisionContext->start($user, $dispoOrder);

        return redirect()
            ->route('calculations.edit', $dispoOrder->calculation)
            ->with('success', 'Nachbesserung gestartet. Passe die Kalkulation an und erstelle danach den korrigierten Dispoauftrag.');
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
            $request->customerConfirmationExceptionAcknowledged(),
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

    public function transitionOperationalStatus(
        TransitionOperationalStatusRequest $request,
        DispoOrder $dispoOrder,
    ): JsonResponse|RedirectResponse {
        /** @var User $user */
        $user = $request->user();

        $order = $this->operationalStatus->transition(
            $dispoOrder,
            $user,
            $request->expectedLockVersion(),
            $request->targetStatus(),
            $request->reason(),
        );

        return $this->respondSuccess(
            $request,
            $order,
            sprintf('Status aktualisiert: %s.', $order->status->label()),
        );
    }

    public function updateInvoiceEndMonths(
        UpdateInvoiceEndMonthsRequest $request,
        DispoOrder $dispoOrder,
        DispoOrderPosition $position,
    ): JsonResponse|RedirectResponse {
        /** @var User $user */
        $user = $request->user();

        $order = $this->invoiceEnd->update(
            $dispoOrder,
            $position,
            $user,
            $request->expectedLockVersion(),
            $request->months(),
        );

        return $this->respondSuccess($request, $order, 'Rechnung per Ende gespeichert.');
    }

    public function complete(
        CompleteDispoOrderRequest $request,
        DispoOrder $dispoOrder,
    ): JsonResponse|RedirectResponse {
        /** @var User $user */
        $user = $request->user();

        $order = $this->completion->complete(
            $dispoOrder,
            $user,
            $request->expectedLockVersion(),
            $request->overrideReason(),
        );

        return $this->respondSuccess(
            $request,
            $order,
            sprintf('Status aktualisiert: %s.', $order->status->label()),
        );
    }

    public function reopenCompleted(
        ReopenCompletedDispoOrderRequest $request,
        DispoOrder $dispoOrder,
    ): JsonResponse|RedirectResponse {
        /** @var User $user */
        $user = $request->user();

        $order = $this->completedReopen->reopen(
            $dispoOrder,
            $user,
            $request->expectedLockVersion(),
            $request->reason(),
        );

        return $this->respondSuccess(
            $request,
            $order,
            sprintf('Status aktualisiert: %s.', $order->status->label()),
        );
    }

    public function cancel(
        CancelDispoOrderRequest $request,
        DispoOrder $dispoOrder,
    ): JsonResponse|RedirectResponse {
        /** @var User $user */
        $user = $request->user();

        $order = $this->cancellation->cancel(
            $dispoOrder,
            $user,
            $request->expectedLockVersion(),
            $request->reason(),
        );

        return $this->respondSuccess(
            $request,
            $order,
            sprintf('Status aktualisiert: %s.', $order->status->label()),
        );
    }

    public function askSalesInquiry(
        AskSalesInquiryRequest $request,
        DispoOrder $dispoOrder,
    ): JsonResponse|RedirectResponse {
        /** @var User $user */
        $user = $request->user();

        $order = $this->salesInquiry->ask(
            $dispoOrder,
            $user,
            $request->expectedLockVersion(),
            $request->question(),
        );

        return $this->respondSuccess(
            $request,
            $order,
            'Rückfrage an den Vertrieb gestellt.',
        );
    }

    public function answerSalesInquiry(
        AnswerSalesInquiryRequest $request,
        DispoOrder $dispoOrder,
        DispoOrderComment $comment,
    ): JsonResponse|RedirectResponse {
        /** @var User $user */
        $user = $request->user();

        $order = $this->salesInquiry->answer(
            $dispoOrder,
            $comment,
            $user,
            $request->expectedLockVersion(),
            $request->answer(),
        );

        return $this->respondSuccess(
            $request,
            $order,
            'Rückfrage beantwortet. Status: Liegt bei Disposition.',
        );
    }

    public function positions(Request $request, Calculation $calculation): JsonResponse
    {
        $this->authorize('create', [DispoOrder::class, $calculation]);

        $calculation->load(['positions.inventory', 'positions.advertisingMedium', 'positions.timeRanges']);

        $payload = [
            'positions' => $this->adoptions->selectablePositions($calculation),
        ];

        $revision = $this->revisionContext->currentForCalculation($request, $calculation->id);

        if ($revision !== null) {
            $predecessor = DispoOrder::query()
                ->with('positions')
                ->find($revision['predecessor_id']);

            $existingIds = $calculation->positions->pluck('id')->all();
            $preferred = [];

            if ($predecessor !== null) {
                foreach ($predecessor->positions as $position) {
                    $calcPositionId = $position->calculation_position_id;
                    if ($calcPositionId !== null && in_array((int) $calcPositionId, $existingIds, true)) {
                        $preferred[] = (int) $calcPositionId;
                    }
                }
            }

            $payload['preferred_position_ids'] = array_values(array_unique($preferred));
            $payload['revision'] = [
                'predecessor_id' => $revision['predecessor_id'],
                'predecessor_number' => $revision['predecessor_number'],
                'rejection_reason' => $revision['rejection_reason'],
            ];
        }

        return response()->json($payload);
    }

    public function store(CreateDispoOrderFromCalculationRequest $request, Calculation $calculation): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();

        $predecessor = $request->predecessor();

        if ($predecessor !== null) {
            $result = $this->writer->createRevision(
                $predecessor,
                $calculation,
                $request->positionIds(),
                $user,
            );
            $this->revisionContext->clear();

            return redirect()
                ->route('dispo-orders.show', $result->order)
                ->with('success', 'Korrigierter Dispoauftrag als Entwurf angelegt. Bitte erneut zur Freigabe einreichen.');
        }

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
     * @param  array{
     *     header: array<string, mixed>,
     *     positions: array<int, array<string, mixed>>,
     *     header_captured: array<string, bool>,
     *     positions_captured: array<int, array<string, bool>>,
     *     missing_calc_origin_keys: list<string>,
     *     historically_uncaptured: bool
     * }|null  $dynamicValues
     * @return array<string, mixed>
     */
    private function serializeOrder(DispoOrder $order, ?array $dynamicValues = null): array
    {
        $dynamicValues ??= $this->dynamicFields->valuesProp($order);

        return [
            'id' => $order->id,
            'number' => $order->number,
            'status' => $order->status->value,
            'status_label' => $order->status->label(),
            'lock_version' => $order->lock_version,
            'customer_confirmation_without_upload' => (bool) $order->customer_confirmation_without_upload,
            'customer_confirmation_exception_reason' => $order->customer_confirmation_exception_reason,
            'customer_confirmation_exception_set_by_name' => $order->customer_confirmation_exception_set_by_name,
            'customer_confirmation_exception_set_at' => $order->customer_confirmation_exception_set_at?->toIso8601String(),
            'configuration_snapshot_id' => $order->configuration_snapshot_id,
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
            'rejection_reason' => $order->latestApprovalRequest?->rejection_reason,
            'revises_dispo_order_id' => $order->revises_dispo_order_id,
            'revises' => $this->serializeRevisionLink($order->revises),
            'revision' => $this->serializeRevisionLink($order->revision),
            'dynamic_field_values' => $dynamicValues['header'],
            'dynamic_field_captured' => $dynamicValues['header_captured'],
            'missing_calc_origin_keys' => $dynamicValues['missing_calc_origin_keys'],
            'historically_uncaptured' => $dynamicValues['historically_uncaptured'],
            'derived_campaign_period' => DerivedCampaignPeriodPresenter::forOrder(
                $order,
                is_array($dynamicValues['header']['campaign_period'] ?? null)
                    ? $dynamicValues['header']['campaign_period']
                    : null,
            ),
            'approval_history' => $order->approvalRequests->map(
                fn (DispoOrderApprovalRequest $request): array => $this->serializeApprovalRequest($request),
            )->all(),
            'status_history' => ($order->relationLoaded('statusEvents')
                ? $order->statusEvents
                : $order->statusEvents()->get()
            )->map(
                fn (DispoOrderStatusEvent $event): array => $this->serializeStatusEvent($event),
            )->all(),
            'communication' => $this->salesInquiry->communicationProp($order),
            'current_approval' => $order->pendingApprovalRequest instanceof DispoOrderApprovalRequest
                ? $this->serializeApprovalRequest($order->pendingApprovalRequest)
                : ($order->approvalRequests->last() instanceof DispoOrderApprovalRequest
                    ? $this->serializeApprovalRequest($order->approvalRequests->last())
                    : null),
            'positions' => $order->positions->map(function (DispoOrderPosition $position) use ($order, $dynamicValues): array {
                $months = InvoiceEndMonthsContract::canonicalize(
                    is_array($position->invoice_end_months) ? $position->invoice_end_months : [],
                );
                $periodState = $this->completionReadiness->periodStateForPosition($order, $position);

                return [
                    'id' => $position->id,
                    'inventory_name' => $position->inventory_name,
                    'advertising_medium_name' => $position->advertising_medium_name,
                    'spot_method' => $position->spot_method->value,
                    'spot_method_label' => $position->spot_method->label(),
                    'length_seconds' => $position->length_seconds,
                    'component_calculation_strategy' => $position->component_calculation_strategy,
                    'component_profile' => $position->component_profile?->value,
                    'derived_component_airings' => $position->derived_component_airings
                        ?? ($position->component_profile !== null
                            ? SpotComponentProfileContract::derivedAirings(
                                $position->component_profile,
                                (int) $position->total_spot_count,
                            )
                            : null),
                    'components' => $position->components_snapshot ?? [],
                    'total_spot_count' => $position->total_spot_count,
                    'price_list_version' => $position->price_list_version,
                    'media_gross' => (string) $position->media_gross,
                    'position_discount_amount' => (string) $position->position_discount_amount,
                    'order_discount_amount' => (string) $position->order_discount_amount,
                    'ae_amount' => (string) $position->ae_amount,
                    'nn_invest' => (string) $position->nn_invest,
                    'pricing_settlement_mode' => $position->pricing_settlement_mode->value,
                    'fixed_price_nn' => $position->fixed_price_nn === null ? null : (string) $position->fixed_price_nn,
                    'calculation_method_name' => $position->calculation_method_name,
                    'effective_pay_factor_percent' => $position->effective_pay_factor_percent === null
                        ? null
                        : (string) $position->effective_pay_factor_percent,
                    'effective_total_discount_percent' => $position->effective_total_discount_percent === null
                        ? null
                        : (string) $position->effective_total_discount_percent,
                    'time_ranges' => $position->time_ranges_snapshot ?? [],
                    'planner_entries' => $position->planner_entries_snapshot ?? [],
                    'position_discounts' => $position->position_discounts_snapshot ?? [],
                    'invoice_end_months' => $months,
                    'invoice_end_month_labels' => InvoiceEndMonthsContract::labelsFor($months),
                    'invoice_end_period_state' => $periodState,
                    'dynamic_field_values' => $dynamicValues['positions'][(int) $position->id] ?? [],
                    'dynamic_field_captured' => $dynamicValues['positions_captured'][(int) $position->id] ?? [],
                ];
            })->all(),
        ];
    }

    /**
     * @return array{id: int, number: string, status: string, status_label: string}|null
     */
    private function serializeRevisionLink(?DispoOrder $order): ?array
    {
        if ($order === null) {
            return null;
        }

        return [
            'id' => $order->id,
            'number' => $order->number,
            'status' => $order->status->value,
            'status_label' => $order->status->label(),
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
            'customer_confirmation_without_upload' => (bool) $request->customer_confirmation_without_upload,
            'customer_confirmation_exception_reason' => $request->customer_confirmation_exception_reason,
            'customer_confirmation_exception_set_by_name' => $request->customer_confirmation_exception_set_by_name,
            'customer_confirmation_exception_set_at' => $request->customer_confirmation_exception_set_at?->toIso8601String(),
            'customer_confirmation_exception_acknowledged' => (bool) $request->customer_confirmation_exception_acknowledged,
            'customer_confirmation_exception_acknowledged_by_name' => $request->customer_confirmation_exception_acknowledged_by_name,
            'customer_confirmation_exception_acknowledged_at' => $request->customer_confirmation_exception_acknowledged_at?->toIso8601String(),
            'customer_confirmation_mode' => $request->customer_confirmation_mode,
            'customer_confirmation_upload' => $request->hasCustomerConfirmationUploadSnapshot()
                ? [
                    'upload_id' => $request->customer_confirmation_upload_id,
                    'category' => $request->customer_confirmation_upload_category,
                    'original_filename' => $request->customer_confirmation_upload_original_filename,
                    'mime_type' => $request->customer_confirmation_upload_mime_type,
                    'size_bytes' => $request->customer_confirmation_upload_size_bytes,
                    'sha256' => $request->customer_confirmation_upload_sha256,
                    'uploaded_at' => $request->customer_confirmation_upload_uploaded_at?->toIso8601String(),
                    'uploaded_by_name' => $request->customer_confirmation_upload_uploaded_by_name,
                    'download_url' => $request->customer_confirmation_upload_id
                        ? route('dispo-orders.uploads.download', [
                            'dispoOrder' => $request->dispo_order_id,
                            'upload' => $request->customer_confirmation_upload_id,
                        ])
                        : null,
                ]
                : null,
            'requires_customer_confirmation_exception_ack' => $request->requiresCustomerConfirmationExceptionAcknowledgement(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeStatusEvent(DispoOrderStatusEvent $event): array
    {
        return [
            'id' => $event->id,
            'from_status' => $event->from_status->value,
            'from_status_label' => $event->from_status->label(),
            'to_status' => $event->to_status->value,
            'to_status_label' => $event->to_status->label(),
            'changed_by_name' => $event->changed_by_name,
            'changed_at' => $event->changed_at->toIso8601String(),
            'reason' => $event->reason,
            'is_reopen' => $event->is_reopen,
            'is_completion_override' => (bool) $event->is_completion_override,
            'completion_override_violations' => $event->completion_override_violations ?? [],
            'lock_version_after' => $event->lock_version_after,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeComment(DispoOrderComment $comment): array
    {
        return [
            'id' => $comment->id,
            'type' => $comment->type->value,
            'type_label' => $comment->type->label(),
            'body' => $comment->body,
            'created_by_name' => $comment->created_by_name,
            'created_at' => $comment->created_at?->toIso8601String(),
            'parent_id' => $comment->parent_id,
        ];
    }
}
