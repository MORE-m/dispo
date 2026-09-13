<?php

namespace App\Http\Controllers;

use App\Enums\BudgetProposalStatus;
use App\Enums\BudgetStrategy;
use App\Enums\DayGroup;
use App\Enums\DiscountType;
use App\Enums\FieldType;
use App\Enums\PlanningMode;
use App\Http\Requests\Calculation\BudgetProposalPayloadRequest;
use App\Http\Requests\Calculation\CalculationPayloadRequest;
use App\Models\AdvertisingMedium;
use App\Models\BudgetProposal;
use App\Models\Calculation;
use App\Models\CalculationOrderDiscount;
use App\Models\CalculationPosition;
use App\Models\CalculationPositionDiscount;
use App\Models\CalculationPositionTimeRange;
use App\Models\ConfigurationSnapshot;
use App\Models\DispoOrder;
use App\Models\FieldDefinition;
use App\Models\Inventory;
use App\Models\InventoryMediumRule;
use App\Models\SpotClassicPlanRow;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Services\Calculation\BudgetProposalFingerprint;
use App\Services\Calculation\BudgetSpotProposalService;
use App\Services\Calculation\CalculationWriter;
use App\Services\DispoOrder\DispoOrderRevisionContext;
use App\Services\DynamicField\CalculationDynamicFieldWriter;
use App\Services\DynamicField\ConfigurationSnapshotFreezeService;
use App\Support\Advertising\AdvertisingMediumCalculationMethodOptionsResolver;
use App\Support\Advertising\AdvertisingMediumLiveBookability;
use App\Support\DynamicField\ChoiceFieldValueContract;
use App\Support\Inventory\InventoryIdentity;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

class CalculationController extends Controller
{
    public function __construct(
        private readonly CalculationWriter $writer,
        private readonly BudgetSpotProposalService $spotProposals,
        private readonly BudgetProposalFingerprint $budgetFingerprints,
        private readonly AuditLogger $audit,
        private readonly DispoOrderRevisionContext $dispoOrderRevisionContext,
        private readonly CalculationDynamicFieldWriter $dynamicFields,
        private readonly ConfigurationSnapshotFreezeService $freeze,
        private readonly AdvertisingMediumLiveBookability $liveBookability = new AdvertisingMediumLiveBookability,
        private readonly AdvertisingMediumCalculationMethodOptionsResolver $methodOptionsResolver = new AdvertisingMediumCalculationMethodOptionsResolver,
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

        $calculation->load([
            'positions.planRows',
            'positions.timeRanges',
            'positions.discounts',
            'positions.inventory',
            'positions.advertisingMedium',
            'positions.priceList',
            'positions.fieldValues.snapshotFieldDefinition',
            'positions.effectiveConfigurationSnapshot.fieldDefinitions',
            'positions.effectiveConfigurationSnapshot.rules',
            'orderDiscounts',
            'configurationSnapshot.fieldDefinitions',
            'configurationSnapshot.rules',
            'fieldValues.snapshotFieldDefinition',
        ]);

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

    /**
     * DF-3.3a2β: Feldschema für den Wizard.
     * Bestehende Kalkulationen: eingefrorenes Schema (Gen 1–3).
     * Neue Kalkulationen: Live Gen-3-Basis; mit `advertising_medium_id` das
     * positionsbezogene Live-Schema im Werbemittelkontext.
     */
    public function fieldSchema(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'calculation_id' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'advertising_medium_id' => ['sometimes', 'nullable', 'integer', 'min:1', 'exists:advertising_media,id'],
        ]);

        $mediumId = isset($validated['advertising_medium_id'])
            ? (int) $validated['advertising_medium_id']
            : null;

        if (array_key_exists('calculation_id', $validated) && $validated['calculation_id'] !== null) {
            /** @var Calculation $calculation */
            $calculation = Calculation::query()
                ->with(['configurationSnapshot.fieldDefinitions', 'configurationSnapshot.rules'])
                ->findOrFail((int) $validated['calculation_id']);
            $this->authorize('view', $calculation);

            $fieldSchema = $this->fieldSchemaProp($calculation, $mediumId);

            return response()->json([
                'fieldSchema' => $fieldSchema,
                'format_version' => $fieldSchema['format_version'],
                'target_format_version' => $fieldSchema['format_version'],
            ]);
        }

        $this->authorize('create', Calculation::class);

        $resolved = $mediumId !== null && $mediumId > 0
            ? $this->freeze->resolveLivePositionSchema($mediumId)
            : $this->freeze->resolveLiveSchemaForCalculationV3();

        if ($resolved['has_blocking_conflicts']) {
            throw ValidationException::withMessages([
                'configuration' => 'Die aktive Feldkonfiguration ist widersprüchlich und kann nicht verwendet werden.',
                'conflicts' => array_map(
                    static fn (array $conflict): string => (string) $conflict['message'],
                    $resolved['conflicts'],
                ),
            ]);
        }

        $fieldSchema = $this->liveFieldSchemaProp($resolved);

        return response()->json([
            'fieldSchema' => $fieldSchema,
            'format_version' => $fieldSchema['format_version'],
            'target_format_version' => ConfigurationSnapshot::FORMAT_VERSION_CONTEXTUAL_FREEZE,
        ]);
    }

    public function proposeBudget(BudgetProposalPayloadRequest $request): JsonResponse
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
        } else {
            $this->authorize('create', Calculation::class);
        }

        $proposal = $this->spotProposals->propose($payload, $existing);

        if ($existing !== null) {
            BudgetProposal::query()
                ->where('calculation_id', $existing->id)
                ->whereNull('applied_at')
                ->update(['status' => BudgetProposalStatus::Stale]);

            $stored = BudgetProposal::query()->create([
                'calculation_id' => $existing->id,
                'strategy' => BudgetStrategy::from($proposal['strategy']),
                'status' => BudgetProposalStatus::from($proposal['status'] ?? BudgetProposalStatus::Current->value),
                'algorithm_version' => $proposal['algorithm_version'] ?? null,
                'input_fingerprint' => $proposal['input_fingerprint'] ?? null,
                'target_budget_nn' => $proposal['target_budget_nn'],
                'lock_version' => $existing->lock_version,
                'payload' => $proposal,
                'calculated_at' => now(),
                'created_by' => $user->id,
            ]);
            $proposal['id'] = $stored->id;
            $proposal['lock_version'] = $existing->lock_version;

            $existing->budget_proposal_status = BudgetProposalStatus::Current;
            $existing->budget_strategy = BudgetStrategy::from($proposal['strategy']);
            $existing->save();

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

        $proposal->refresh();

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
        $calculation?->loadMissing(['positions.planRows', 'positions.timeRanges', 'positions.discounts', 'positions.inventory', 'orderDiscounts', 'budgetProposals']);

        $latestBudgetProposal = null;
        $appliedBudgetProposal = null;
        if ($calculation !== null && $calculation->planning_mode === PlanningMode::Budget) {
            $applied = $calculation->budgetProposals
                ->whereNotNull('applied_at')
                ->sortByDesc('id')
                ->first();

            if ($applied !== null) {
                $appliedBudgetProposal = [
                    'id' => $applied->id,
                    'input_fingerprint' => $applied->input_fingerprint,
                    'status' => $applied->status->value,
                    'payload' => $applied->payloadArray(),
                    'applied_at' => $applied->applied_at?->toIso8601String(),
                ];
            }

            $latest = $calculation->budgetProposals
                ->whereNull('applied_at')
                ->sortByDesc('id')
                ->first();

            if ($latest !== null) {
                $latestPayload = $latest->payloadArray();
                $latestStatus = $this->budgetFingerprints->statusFromProposal(
                    [
                        'status' => $latest->status->value,
                        'input_fingerprint' => $latest->input_fingerprint,
                    ],
                    [
                        'target_budget_nn' => (string) $latest->target_budget_nn,
                        'budget_elements' => is_array($latestPayload['budget_elements'] ?? null)
                            ? $latestPayload['budget_elements']
                            : [],
                        'order_discounts' => is_array($latestPayload['order_discounts'] ?? null)
                            ? $latestPayload['order_discounts']
                            : [],
                        'ae_enabled' => (bool) ($latestPayload['ae_enabled'] ?? false),
                    ],
                    $calculation,
                );
                $latestBudgetProposal = [
                    'id' => $latest->id,
                    'input_fingerprint' => $latest->input_fingerprint,
                    'status' => $latestStatus->value,
                    'payload' => $latestPayload,
                ];
            }
        }

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
            ->with([
                'category.defaultCalculationMethod',
                'category.calculationMethodAssignments.calculationMethod',
                'defaultCalculationMethod',
                'calculationMethodAssignments.calculationMethod',
            ])
            ->where('is_active', true)
            ->get(['id', 'name', 'code', 'kind', 'category_id', 'default_length_seconds', 'is_discountable', 'is_ae_eligible', 'is_active', 'calculation_method_mode', 'default_calculation_method_id']);

        $historicalMediumIds = $calculation !== null
            ? $calculation->positions->pluck('advertising_medium_id')->unique()->values()
            : collect();

        $historicalMedia = AdvertisingMedium::query()
            ->with([
                'category.defaultCalculationMethod',
                'category.calculationMethodAssignments.calculationMethod',
                'defaultCalculationMethod',
                'calculationMethodAssignments.calculationMethod',
            ])
            ->whereIn('id', $historicalMediumIds)
            ->where('is_active', false)
            ->get(['id', 'name', 'code', 'kind', 'category_id', 'default_length_seconds', 'is_discountable', 'is_ae_eligible', 'is_active', 'calculation_method_mode', 'default_calculation_method_id']);

        $media = $activeMedia->concat($historicalMedia)->values()->map(
            function (AdvertisingMedium $medium): array {
                $bookability = $this->liveBookability->payloadForMedium($medium);
                $methodOptions = $this->methodOptionsResolver->resolve($medium)->toPayload();

                return [
                    'id' => $medium->id,
                    'name' => $medium->name,
                    'code' => $medium->code,
                    'kind' => $medium->kind?->value,
                    'category_id' => $medium->category_id,
                    'default_length_seconds' => $medium->default_length_seconds,
                    'is_discountable' => $medium->is_discountable,
                    'is_ae_eligible' => $medium->is_ae_eligible,
                    'is_active' => $medium->is_active,
                    'is_bookable_for_new_positions' => $bookability['is_bookable_for_new_positions'],
                    'unbookable_reason' => $bookability['unbookable_reason'],
                    'calculation_method_options' => $methodOptions,
                ];
            },
        )->values();

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
                'positions' => $calculation->positions->map(fn (CalculationPosition $position): array => [
                    'inventory_name' => InventoryIdentity::displayName($position),
                    'inventory_code' => InventoryIdentity::displayCode($position),
                    'spot_method' => $position->spot_method->value,
                    'price_list_version' => $position->price_list_version,
                    'length_seconds' => $position->length_seconds,
                    'total_spot_count' => $position->total_spot_count,
                    'media_gross' => (string) $position->media_gross,
                    'nn_invest' => (string) $position->nn_invest,
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
            'fieldSchema' => $this->fieldSchemaProp($calculation),
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
                'budget_proposal_status' => $calculation->budget_proposal_status?->value,
                'dynamic_field_values' => $this->dynamicFields->headerValuesForPayload($calculation),
                'order_discounts' => $calculation->orderDiscounts->map(fn (CalculationOrderDiscount $discount): array => [
                    'type' => $discount->type->value,
                    'custom_label' => $discount->custom_label,
                    'percent' => (string) $discount->percent,
                ])->all(),
                'positions' => $calculation->positions->map(function (CalculationPosition $position) use ($calculation): array {
                    $snapshot = $calculation->configurationSnapshot;
                    $positionFieldSchema = $this->positionFieldSchemaProp($calculation, $position);

                    return [
                        'id' => $position->id,
                        'client_key' => $position->client_key,
                        'inventory_id' => $position->inventory_id,
                        'inventory_name' => InventoryIdentity::displayName($position),
                        'inventory_code' => InventoryIdentity::displayCode($position),
                        'advertising_medium_id' => $position->advertising_medium_id,
                        'schema_fingerprint' => $positionFieldSchema['schema_fingerprint'] ?? null,
                        'field_schema' => $positionFieldSchema,
                        'spot_method' => $position->spot_method->value,
                        'calculation_method_key' => $position->calculation_method_key,
                        'calculation_method_name' => $position->calculation_method_name,
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
                        'dynamic_field_values' => $snapshot === null
                            ? ['period_open' => true]
                            : $this->dynamicFields->positionValuesForPayload($position, $snapshot),
                    ];
                })->all(),
            ],
            'savedSummary' => $savedSummary,
            'savedDisplayTotals' => $savedDisplayTotals,
            'latestBudgetProposal' => $latestBudgetProposal,
            'appliedBudgetProposal' => $appliedBudgetProposal,
            'canEdit' => $canEdit,
            'canCreateDispoOrder' => $calculation !== null
                && ($request->user()?->can('create', [DispoOrder::class, $calculation]) ?? false),
            'dispoOrderRevision' => $calculation === null
                ? null
                : $this->dispoOrderRevisionProp($request, $calculation),
        ];
    }

    /**
     * @return array{
     *     fields: array<int, array<string, mixed>>,
     *     rules: array<int, array<string, mixed>>,
     *     format_version: int,
     *     schema_fingerprint: string|null
     * }
     */
    private function fieldSchemaProp(?Calculation $calculation, ?int $mediumId = null): array
    {
        $snapshot = $calculation?->configurationSnapshot;

        if ($snapshot === null) {
            $resolved = $mediumId !== null && $mediumId > 0
                ? $this->freeze->resolveLivePositionSchema($mediumId)
                : $this->freeze->resolveLiveSchemaForCalculationV3();

            return $this->liveFieldSchemaProp($resolved);
        }

        $rulesIntegrityError = null;
        try {
            $snapshot->assertReadable();
        } catch (Throwable $exception) {
            Log::warning('Calculation field schema snapshot integrity failed', [
                'calculation_id' => $calculation->id,
                'snapshot_id' => $snapshot->id,
                'exception' => $exception->getMessage(),
            ]);
            $rulesIntegrityError = 'Die Feldregeln dieses Vorgangs sind ungültig. Speichern ist nicht möglich.';
        }

        // Gen 3: Positions-Schema aus eingefrorener Basis für gewähltes Medium.
        if ($rulesIntegrityError === null
            && (int) $snapshot->format_version === ConfigurationSnapshot::FORMAT_VERSION_CONTEXTUAL_FREEZE
            && $mediumId !== null
            && $mediumId > 0
        ) {
            $resolved = $this->freeze->resolvePositionSchemaFromBase($snapshot, $mediumId);
            if ($resolved['has_blocking_conflicts']) {
                throw ValidationException::withMessages([
                    'configuration' => 'Die eingefrorene Positionskonfiguration ist widersprüchlich.',
                ]);
            }

            $prop = $this->liveFieldSchemaProp($resolved);
            $prop['format_version'] = ConfigurationSnapshot::FORMAT_VERSION_CONTEXTUAL_FREEZE;

            return $prop;
        }

        $snapshot->loadMissing(['fieldDefinitions', 'rules']);

        $systemByDefinitionId = FieldDefinition::query()
            ->whereIn('id', $snapshot->fieldDefinitions->pluck('field_definition_id')->unique()->all())
            ->pluck('is_system', 'id');

        return [
            'fields' => $snapshot->fieldDefinitions->map(fn ($def): array => [
                'key' => (string) $def->key,
                'field_type' => (string) $def->field_type->value,
                'label' => (string) $def->label,
                'help_text' => $def->help_text,
                'scope' => (string) $def->scope->value,
                'applies_to' => (string) $def->applies_to->value,
                'sort' => (int) $def->sort,
                'is_system' => (bool) ($systemByDefinitionId[$def->field_definition_id] ?? false),
                'required' => (bool) $def->required,
                'visible' => (bool) $def->visible,
                'editable' => true,
                'calc_origin' => false,
                'action_target_readonly' => false,
                'max_length' => $this->maxLengthFromValidation($def->validation_json, (string) $def->field_type->value),
                'validation_json' => $def->validation_json,
                'options_json' => $this->optionsJsonForSchemaProp($def->field_type, $def->options_json),
            ])->values()->all(),
            'rules' => $snapshot->rules->map(fn ($rule): array => [
                'condition' => $rule->condition_json,
                'action' => $rule->action_json,
            ])->values()->all(),
            'format_version' => (int) $snapshot->format_version,
            'schema_fingerprint' => $snapshot->schema_fingerprint,
            'rules_integrity_error' => $rulesIntegrityError,
        ];
    }

    /**
     * Positionsbezogenes Schema für den Wizard (Gen 3: Effektiv-Snapshot).
     *
     * @return array{
     *     fields: array<int, array<string, mixed>>,
     *     rules: array<int, array<string, mixed>>,
     *     format_version: int,
     *     schema_fingerprint: string|null
     * }|null
     */
    private function positionFieldSchemaProp(Calculation $calculation, CalculationPosition $position): ?array
    {
        $base = $calculation->configurationSnapshot;
        if ($base === null) {
            return null;
        }

        if ((int) $base->format_version !== ConfigurationSnapshot::FORMAT_VERSION_CONTEXTUAL_FREEZE) {
            return null;
        }

        $position->loadMissing([
            'effectiveConfigurationSnapshot.fieldDefinitions',
            'effectiveConfigurationSnapshot.rules',
        ]);
        $effective = $position->effectiveConfigurationSnapshot;
        if ($effective === null) {
            return null;
        }

        try {
            $effective->assertReadable();
        } catch (Throwable $exception) {
            Log::warning('Calculation position field schema integrity failed', [
                'calculation_id' => $calculation->id,
                'position_id' => $position->id,
                'snapshot_id' => $effective->id,
                'exception' => $exception->getMessage(),
            ]);

            return [
                'fields' => [],
                'rules' => [],
                'format_version' => ConfigurationSnapshot::FORMAT_VERSION_CONTEXTUAL_FREEZE,
                'schema_fingerprint' => $effective->schema_fingerprint,
                'rules_integrity_error' => 'Die Feldregeln dieses Vorgangs sind ungültig. Speichern ist nicht möglich.',
            ];
        }

        $resolved = $this->freeze->resolvePositionSchemaFromBase(
            $base,
            (int) $position->advertising_medium_id,
        );

        $systemByDefinitionId = FieldDefinition::query()
            ->whereIn('id', $effective->fieldDefinitions->pluck('field_definition_id')->unique()->all())
            ->pluck('is_system', 'id');

        return [
            'fields' => $effective->fieldDefinitions->map(fn ($def): array => [
                'key' => (string) $def->key,
                'field_type' => (string) $def->field_type->value,
                'label' => (string) $def->label,
                'help_text' => $def->help_text,
                'scope' => (string) $def->scope->value,
                'applies_to' => (string) $def->applies_to->value,
                'sort' => (int) $def->sort,
                'is_system' => (bool) ($systemByDefinitionId[$def->field_definition_id] ?? false),
                'required' => (bool) $def->required,
                'visible' => (bool) $def->visible,
                'editable' => true,
                'calc_origin' => false,
                'action_target_readonly' => false,
                'max_length' => $this->maxLengthFromValidation($def->validation_json, (string) $def->field_type->value),
                'validation_json' => $def->validation_json,
                'options_json' => $this->optionsJsonForSchemaProp($def->field_type, $def->options_json),
            ])->values()->all(),
            'rules' => $effective->rules->map(fn ($rule): array => [
                'condition' => $rule->condition_json,
                'action' => $rule->action_json,
            ])->values()->all(),
            'format_version' => ConfigurationSnapshot::FORMAT_VERSION_CONTEXTUAL_FREEZE,
            // Client-Vertrag: Fingerprint der kanonischen Basisauflösung, nicht
            // der materialisierte Effektiv-Fingerprint (kann abweichen).
            'schema_fingerprint' => (string) $resolved['schema_fingerprint'],
        ];
    }

    /**
     * Live aufgelöstes Freeze-Schema (Core + aktive globale Assignments) für
     * neue Kalkulationen – identische Feldform wie das Snapshot-Schema.
     *
     * @param  array<string, mixed>  $resolved
     * @return array{
     *     fields: array<int, array<string, mixed>>,
     *     rules: array<int, array<string, mixed>>,
     *     format_version: int,
     *     schema_fingerprint: string|null
     * }
     */
    private function liveFieldSchemaProp(array $resolved): array
    {
        return [
            'fields' => array_map(function (array $field): array {
                $fieldType = (string) $field['field_type'];

                return [
                    'key' => (string) $field['field_key'],
                    'field_type' => $fieldType,
                    'label' => (string) $field['label'],
                    'help_text' => $field['help_text'] ?? null,
                    'scope' => (string) $field['field_scope'],
                    'applies_to' => (string) $field['applies_to'],
                    'sort' => (int) $field['sort'],
                    'is_system' => (bool) $field['definition_is_system'],
                    'required' => (bool) $field['effective_required'],
                    'visible' => (bool) $field['effective_visible'],
                    'editable' => true,
                    'calc_origin' => false,
                    'action_target_readonly' => false,
                    'max_length' => $this->maxLengthFromValidation(
                        $field['validation_json'] ?? null,
                        $fieldType,
                    ),
                    'validation_json' => $field['validation_json'] ?? null,
                    'options_json' => $this->optionsJsonForSchemaProp(
                        FieldType::tryFrom($fieldType),
                        $field['options_json'] ?? null,
                    ),
                ];
            }, $resolved['fields']),
            'rules' => array_map(fn (array $rule): array => [
                'condition' => $rule['condition_json'],
                'action' => $rule['action_json'],
            ], $resolved['rules']),
            'format_version' => ConfigurationSnapshot::FORMAT_VERSION_CONTEXTUAL_FREEZE,
            'schema_fingerprint' => (string) $resolved['schema_fingerprint'],
        ];
    }

    /**
     * @param  array<string, mixed>|null  $validation
     */
    private function maxLengthFromValidation(?array $validation, string $fieldType): int
    {
        $fromJson = is_array($validation) ? ($validation['max_length'] ?? null) : null;
        if (is_numeric($fromJson)) {
            return (int) $fromJson;
        }

        return $fieldType === 'short_text' ? 255 : 20000;
    }

    /**
     * @return list<array{key: string, label: string, sort: int, is_active: bool}>|null
     */
    private function optionsJsonForSchemaProp(?FieldType $fieldType, mixed $optionsJson): ?array
    {
        if ($fieldType === null || ! $fieldType->isChoice()) {
            return null;
        }

        if (! is_array($optionsJson)) {
            return null;
        }

        return ChoiceFieldValueContract::optionsForSchemaProp($optionsJson, $fieldType);
    }

    /**
     * @return array{
     *     predecessor_id: int,
     *     predecessor_number: string,
     *     rejection_reason: ?string,
     *     return_url: string
     * }|null
     */
    private function dispoOrderRevisionProp(Request $request, Calculation $calculation): ?array
    {
        $context = $this->dispoOrderRevisionContext->currentForCalculation($request, $calculation->id);

        if ($context === null) {
            return null;
        }

        return [
            'predecessor_id' => $context['predecessor_id'],
            'predecessor_number' => $context['predecessor_number'],
            'rejection_reason' => $context['rejection_reason'],
            'return_url' => $context['return_url'],
        ];
    }
}
