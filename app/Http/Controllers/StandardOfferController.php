<?php

namespace App\Http\Controllers;

use App\Enums\StandardOfferVersionStatus;
use App\Models\StandardOffer;
use App\Models\StandardOfferVersion;
use App\Models\User;
use App\Services\Calculation\CalculationWriter;
use App\Services\DynamicField\ConfigurationSnapshotFreezeService;
use App\Services\StandardOffer\StandardOfferAverageContract;
use App\Services\StandardOffer\StandardOfferWriter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * BL-P4-03a / STD-003–STD-009 / AUTH-006 / AUTH-007 / UX-GATE-D Teilfreigabe.
 *
 * Editor: Kalkulations-Wizard im Template-Modus (Spot Classic Average, Gruppe 1).
 */
class StandardOfferController extends Controller
{
    public function __construct(
        private readonly StandardOfferWriter $writer,
        private readonly ConfigurationSnapshotFreezeService $snapshots,
        private readonly CalculationController $calculations,
        private readonly CalculationWriter $calculationWriter,
        private readonly StandardOfferAverageContract $averageContract,
    ) {}

    public function index(Request $request): Response
    {
        $this->authorize('viewAny', StandardOffer::class);

        /** @var User $user */
        $user = $request->user();
        $canManage = $user->canManageStandardOffers();

        $offers = StandardOffer::query()
            ->with([
                'publishedVersion',
                'draftVersion',
                'versions' => fn ($query) => $query->orderByDesc('version_number'),
            ])
            ->orderByDesc('id')
            ->get()
            ->filter(function (StandardOffer $offer) use ($canManage): bool {
                if ($canManage) {
                    return true;
                }

                return $offer->publishedVersion !== null;
            })
            ->values()
            ->map(fn (StandardOffer $offer): array => $this->offerSummary($offer, $canManage));

        return Inertia::render('standard-offers/index', [
            'offers' => $offers,
            'canManage' => $canManage,
            'canAdopt' => $user->canAdoptStandardOffers(),
        ]);
    }

    public function create(Request $request): Response
    {
        $this->authorize('create', StandardOffer::class);

        return $this->renderTemplateWizard($request, null, null, editable: true);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorize('create', StandardOffer::class);

        /** @var User $user */
        $user = $request->user();
        $data = $this->validatedDraftRequest($request);
        $offer = $this->writer->create($data['title'], $data['payload'], $user);
        $version = $offer->draftVersion;

        return redirect()
            ->route('standard-offers.show', ['standardOffer' => $offer, 'version' => $version?->id])
            ->with('success', 'Standardangebot als Entwurf angelegt.');
    }

    public function preview(Request $request): JsonResponse
    {
        $this->authorize('create', StandardOffer::class);

        /** @var User $user */
        $user = $request->user();
        $data = $this->validatedDraftRequest($request, requireTitle: false);

        return response()->json([
            'totals' => $this->calculationWriter->preview($data['payload'], $user, null)->toArray(),
        ]);
    }

    public function fieldSchema(Request $request): JsonResponse
    {
        $this->authorize('create', StandardOffer::class);

        $validated = $request->validate([
            'advertising_medium_id' => ['sometimes', 'nullable', 'integer', 'min:1', 'exists:advertising_media,id'],
        ]);

        $mediumId = isset($validated['advertising_medium_id'])
            ? (int) $validated['advertising_medium_id']
            : null;

        return $this->calculations->liveFieldSchemaJson($mediumId);
    }

    public function show(Request $request, StandardOffer $standardOffer): Response
    {
        $this->authorize('view', $standardOffer);

        /** @var User $user */
        $user = $request->user();
        $canManage = $user->canManageStandardOffers();

        $standardOffer->load(['versions.author', 'publishedVersion', 'draftVersion']);

        $versionId = $request->integer('version') ?: null;
        $version = null;
        if ($versionId !== null) {
            $version = $standardOffer->versions->firstWhere('id', $versionId);
        }
        if ($version === null) {
            $version = $canManage
                ? ($standardOffer->draftVersion ?? $standardOffer->publishedVersion ?? $standardOffer->versions->first())
                : $standardOffer->publishedVersion;
        }

        abort_if($version === null, 404);
        if (! $canManage && $version->status !== StandardOfferVersionStatus::Published) {
            abort(403);
        }

        if ($canManage && $version->status->isEditable()) {
            return $this->renderTemplateWizard($request, $standardOffer, $version, editable: true);
        }

        $versionsForUi = $canManage
            ? $standardOffer->versions
            : $standardOffer->versions->filter(
                fn (StandardOfferVersion $row): bool => $row->status === StandardOfferVersionStatus::Published,
            )->values();

        return Inertia::render('standard-offers/show', [
            'offer' => $this->offerSummary($standardOffer, $canManage),
            'version' => $this->versionDetail($version, $canManage),
            'versions' => $versionsForUi->map(fn (StandardOfferVersion $row): array => [
                'id' => $row->id,
                'version_number' => $row->version_number,
                'status' => $row->status->value,
                'status_label' => $row->status->label(),
                'published_at' => $row->published_at?->timezone('Europe/Berlin')->toIso8601String(),
                'author_name' => $row->author?->name,
            ])->values(),
            'canManage' => $canManage,
            'canAdopt' => $user->canAdoptStandardOffers() && $version->status->isAdoptable(),
            'catalog' => null,
            'schemaFingerprint' => null,
            'scopeNote' => 'BL-P4-03a: Spot Classic Average. Calendar/Komponenten/Festpreis/Tandem/Abbinder: Folgeslices.',
        ]);
    }

    public function update(Request $request, StandardOffer $standardOffer, StandardOfferVersion $version): RedirectResponse
    {
        $this->authorize('update', $standardOffer);
        abort_unless($version->standard_offer_id === $standardOffer->id, 404);

        /** @var User $user */
        $user = $request->user();
        $data = $this->validatedDraftRequest($request);
        $this->writer->updateDraft(
            $version,
            $data['title'],
            $data['payload'],
            (int) $request->input('lock_version'),
            $user,
        );

        return redirect()
            ->route('standard-offers.show', ['standardOffer' => $standardOffer, 'version' => $version->id])
            ->with('success', 'Entwurf gespeichert.');
    }

    public function storeDraft(Request $request, StandardOffer $standardOffer): RedirectResponse
    {
        $this->authorize('update', $standardOffer);

        /** @var User $user */
        $user = $request->user();
        $version = $this->writer->createDraftFromPublished($standardOffer, $user);

        return redirect()
            ->route('standard-offers.show', ['standardOffer' => $standardOffer, 'version' => $version->id])
            ->with('success', 'Neuer Entwurf aus veröffentlichter Version angelegt.');
    }

    public function publish(Request $request, StandardOffer $standardOffer, StandardOfferVersion $version): RedirectResponse
    {
        $this->authorize('publish', $version);
        abort_unless($version->standard_offer_id === $standardOffer->id, 404);

        /** @var User $user */
        $user = $request->user();
        $published = $this->writer->publish($version, (int) $request->input('lock_version'), $user);

        return redirect()
            ->route('standard-offers.show', ['standardOffer' => $standardOffer, 'version' => $published->id])
            ->with('success', 'Version veröffentlicht.');
    }

    public function archive(Request $request, StandardOffer $standardOffer, StandardOfferVersion $version): RedirectResponse
    {
        $this->authorize('archive', $version);
        abort_unless($version->standard_offer_id === $standardOffer->id, 404);

        /** @var User $user */
        $user = $request->user();
        $archived = $this->writer->archive($version, (int) $request->input('lock_version'), $user);

        return redirect()
            ->route('standard-offers.show', ['standardOffer' => $standardOffer, 'version' => $archived->id])
            ->with('success', 'Version archiviert.');
    }

    public function adopt(Request $request, StandardOffer $standardOffer, StandardOfferVersion $version): RedirectResponse
    {
        $this->authorize('adopt', $version);
        abort_unless($version->standard_offer_id === $standardOffer->id, 404);

        $validated = $request->validate([
            'customer_name' => ['required', 'string', 'max:255'],
            'agency_name' => ['nullable', 'string', 'max:255'],
            'campaign' => ['nullable', 'string', 'max:255'],
        ]);

        /** @var User $user */
        $user = $request->user();
        $calculation = $this->writer->adopt(
            $version,
            $validated['customer_name'],
            $validated['agency_name'] ?? null,
            $validated['campaign'] ?? null,
            $user,
        );

        return redirect()
            ->route('calculations.edit', $calculation)
            ->with('success', 'Standardangebot als Kundenkalkulation übernommen.');
    }

    /**
     * @return array{title: string, payload: array<string, mixed>}
     */
    private function validatedDraftRequest(Request $request, bool $requireTitle = true): array
    {
        $validated = $request->validate([
            'title' => [$requireTitle ? 'required' : 'nullable', 'string', 'max:255'],
            'campaign' => ['nullable', 'string', 'max:255'],
            'product_title' => ['nullable', 'string', 'max:255'],
            'briefing' => ['nullable', 'string', 'max:20000'],
            'order_discount_percent' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'order_discounts' => ['sometimes', 'array'],
            'order_discounts.*.type' => ['nullable', 'string'],
            'order_discounts.*.custom_label' => ['nullable', 'string', 'max:120'],
            'order_discounts.*.percent' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'ae_enabled' => ['sometimes', 'boolean'],
            'schema_fingerprint' => ['required', 'string'],
            'dynamic_field_values' => ['sometimes', 'array'],
            'positions' => ['required', 'array', 'min:1'],
            'positions.*.client_key' => ['nullable', 'string', 'max:64'],
            'positions.*.inventory_id' => ['required', 'integer'],
            'positions.*.advertising_medium_id' => ['required', 'integer'],
            'positions.*.spot_method' => ['nullable', 'in:average'],
            'positions.*.length_seconds' => ['required', 'integer', 'min:1', 'max:3600'],
            'positions.*.total_spot_count' => ['nullable', 'integer', 'min:0'],
            'positions.*.price_year' => ['nullable', 'integer', 'min:2000', 'max:2100'],
            'positions.*.position_discount_percent' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'positions.*.ae_percent' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'positions.*.schema_fingerprint' => ['nullable', 'string'],
            'positions.*.time_ranges' => ['nullable', 'array'],
            'positions.*.time_ranges.*.start_hour' => ['nullable', 'integer', 'min:0', 'max:23'],
            'positions.*.time_ranges.*.end_hour_exclusive' => ['nullable', 'integer', 'min:1', 'max:24'],
            'positions.*.time_ranges.*.day_group' => ['nullable', 'string'],
            'positions.*.time_ranges.*.spot_count' => ['nullable', 'integer', 'min:0'],
            'positions.*.plan_rows' => ['nullable', 'array'],
            'positions.*.plan_rows.*.hour' => ['nullable', 'integer', 'min:0', 'max:23'],
            'positions.*.plan_rows.*.day_group' => ['nullable', 'string'],
            'positions.*.position_discounts' => ['sometimes', 'array'],
            'positions.*.position_discounts.*.type' => ['nullable', 'string'],
            'positions.*.position_discounts.*.custom_label' => ['nullable', 'string', 'max:120'],
            'positions.*.position_discounts.*.percent' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'positions.*.dynamic_field_values' => ['sometimes', 'array'],
            'positions.*.components' => ['prohibited'],
            'positions.*.planner_entries' => ['prohibited'],
            'positions.*.component_profile' => ['prohibited'],
            'positions.*.pricing_settlement_mode' => ['nullable', 'in:normal'],
            'positions.*.fixed_price_nn' => ['prohibited'],
            'customer_name' => ['prohibited'],
            'agency_name' => ['prohibited'],
            'planning_mode' => ['nullable', 'in:manual'],
            'target_budget_nn' => ['prohibited'],
            'budget_elements' => ['prohibited'],
        ]);

        $positions = [];
        foreach ($validated['positions'] as $index => $position) {
            $mediumId = (int) $position['advertising_medium_id'];
            $fingerprint = isset($position['schema_fingerprint']) && is_string($position['schema_fingerprint']) && $position['schema_fingerprint'] !== ''
                ? $position['schema_fingerprint']
                : $this->snapshots->resolveLivePositionSchema($mediumId)['schema_fingerprint'];
            if ($fingerprint === '') {
                throw ValidationException::withMessages([
                    "positions.{$index}.schema_fingerprint" => 'Positions-Fingerprint fehlt.',
                ]);
            }

            $row = [
                ...$position,
                'client_key' => $position['client_key'] ?? (string) Str::uuid(),
                'spot_method' => 'average',
                'schema_fingerprint' => $fingerprint,
                'pricing_settlement_mode' => 'normal',
            ];
            unset($row['components'], $row['planner_entries'], $row['component_profile'], $row['fixed_price_nn']);
            $positions[] = $row;
        }

        $payload = $this->averageContract->normalizeDraftPayload([
            'planning_mode' => 'manual',
            'campaign' => $validated['campaign'] ?? null,
            'product_title' => $validated['product_title'] ?? null,
            'briefing' => $validated['briefing'] ?? null,
            'order_discount_percent' => $validated['order_discount_percent'] ?? '0',
            'order_discounts' => $validated['order_discounts'] ?? [],
            'ae_enabled' => (bool) ($validated['ae_enabled'] ?? false),
            'schema_fingerprint' => $validated['schema_fingerprint'],
            'dynamic_field_values' => $validated['dynamic_field_values'] ?? [],
            'positions' => $positions,
        ]);

        return [
            'title' => (string) ($validated['title'] ?? 'Standardangebot'),
            'payload' => $payload,
        ];
    }

    private function renderTemplateWizard(
        Request $request,
        ?StandardOffer $offer,
        ?StandardOfferVersion $version,
        bool $editable,
    ): Response {
        $props = $this->calculations->buildWizardProps($request, null);
        $props['canEdit'] = $editable;
        $props['canCreateDispoOrder'] = false;
        $props['latestBudgetProposal'] = null;
        $props['appliedBudgetProposal'] = null;
        $props['dispoOrderRevision'] = null;

        if ($version !== null) {
            $props['calculation'] = $this->calculationShapeFromDraft($version);
            $nn = is_array($version->frozen_materialization)
                ? ($version->frozen_materialization['nn_invest'] ?? null)
                : null;
            $props['savedSummary'] = $nn !== null
                ? [
                    'nn_invest' => (string) ($version->frozen_materialization['nn_invest'] ?? ''),
                    'media_gross' => (string) ($version->frozen_materialization['media_gross'] ?? ''),
                    'position_discount_total' => (string) ($version->frozen_materialization['position_discount_total'] ?? ''),
                    'order_discount_total' => (string) ($version->frozen_materialization['order_discount_total'] ?? ''),
                    'ae_total' => (string) ($version->frozen_materialization['ae_total'] ?? ''),
                    'target_budget_nn' => null,
                    'requires_special_approval' => (bool) ($version->frozen_materialization['requires_special_approval'] ?? false),
                    'positions' => [],
                    'order_discounts' => $version->draft_payload['order_discounts'] ?? [],
                    'ae_enabled' => (bool) ($version->draft_payload['ae_enabled'] ?? false),
                ]
                : null;
        }

        $props['standardOffer'] = [
            'mode' => $offer === null ? 'create' : 'edit',
            'offer_id' => $offer?->id,
            'version_id' => $version?->id,
            'number' => $offer?->number,
            'title' => $version !== null
                ? $version->title
                : ($offer !== null ? $offer->title : 'Standardangebot'),
            'lock_version' => $version !== null ? $version->lock_version : 1,
            'status' => $version !== null ? $version->status->value : 'draft',
            'status_label' => $version !== null ? $version->status->label() : 'Entwurf',
            'allowed_spot_methods' => ['average'],
            'scope_note' => 'Vorlagen-Editor BL-P4-03a: Spot Classic Average. Keine Kundendaten. Calendar/Komponenten/Festpreis/Tandem/Abbinder: Folgeslices.',
        ];

        return Inertia::render('calculations/wizard', $props);
    }

    /**
     * @return array<string, mixed>
     */
    private function calculationShapeFromDraft(StandardOfferVersion $version): array
    {
        $payload = is_array($version->draft_payload) ? $version->draft_payload : [];
        $positions = [];
        foreach ($payload['positions'] ?? [] as $index => $position) {
            if (! is_array($position)) {
                continue;
            }
            $positions[] = [
                ...$position,
                'id' => $position['id'] ?? null,
                'client_key' => $position['client_key'] ?? ('draft-'.$index),
                'spot_method' => 'average',
                'pricing_settlement_mode' => $position['pricing_settlement_mode'] ?? 'normal',
                'components' => [],
                'planner_entries' => [],
                'component_profile' => null,
                'plan_rows' => $position['plan_rows'] ?? [],
                'time_ranges' => $position['time_ranges'] ?? [],
                'position_discounts' => $position['position_discounts'] ?? [],
                'dynamic_field_values' => $position['dynamic_field_values'] ?? ['period_open' => true],
            ];
        }

        return [
            'id' => null,
            'lock_version' => $version->lock_version,
            'planning_mode' => 'manual',
            'customer_name' => null,
            'agency_name' => null,
            'campaign' => $payload['campaign'] ?? null,
            'product_title' => $payload['product_title'] ?? null,
            'briefing' => $payload['briefing'] ?? null,
            'order_discount_percent' => (string) ($payload['order_discount_percent'] ?? '0'),
            'ae_enabled' => (bool) ($payload['ae_enabled'] ?? false),
            'target_budget_nn' => null,
            'budget_strategy' => null,
            'budget_proposal_status' => null,
            'dynamic_field_values' => $payload['dynamic_field_values'] ?? [],
            'order_discounts' => $payload['order_discounts'] ?? [],
            'positions' => $positions,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function offerSummary(StandardOffer $offer, bool $canManage): array
    {
        $published = $offer->publishedVersion;
        $draft = $offer->draftVersion;

        // Vertrieb sieht ausschließlich die Published-Fassade (Titel/Version).
        // Draft-Titel darf die sichtbaren Published-Daten nicht überschreiben.
        $visibleTitle = $published !== null ? $published->title : $offer->title;

        return [
            'id' => $offer->id,
            'number' => $offer->number,
            'title' => $visibleTitle,
            'lock_version' => $offer->lock_version,
            'published_version_id' => $published?->id,
            'published_version_number' => $published?->version_number,
            'draft_version_id' => $canManage ? $draft?->id : null,
            'has_draft' => $canManage && $draft !== null,
            'draft_title' => $canManage ? $draft?->title : null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function versionDetail(StandardOfferVersion $version, bool $canManage): array
    {
        $draftPayload = $version->draft_payload;
        // Vertrieb: Inhalt nur aus Frozen Published-Stand, kein Draft-Leak.
        if (! $canManage && $version->status === StandardOfferVersionStatus::Published) {
            $frozen = $version->frozen_materialization;
            $draftPayload = is_array($frozen)
                ? ($frozen['draft_payload'] ?? $version->draft_payload)
                : $version->draft_payload;
        }

        return [
            'id' => $version->id,
            'version_number' => $version->version_number,
            'status' => $version->status->value,
            'status_label' => $version->status->label(),
            'title' => $version->title,
            'author_id' => $version->author_id,
            'author_name' => $version->author?->name,
            'published_at' => $version->published_at?->timezone('Europe/Berlin')->toIso8601String(),
            'archived_at' => $version->archived_at?->timezone('Europe/Berlin')->toIso8601String(),
            'lock_version' => $version->lock_version,
            'draft_payload' => $draftPayload,
            'frozen_summary' => is_array($version->frozen_materialization)
                ? [
                    'nn_invest' => $version->frozen_materialization['nn_invest'] ?? null,
                    'media_gross' => $version->frozen_materialization['media_gross'] ?? null,
                    'position_count' => count($version->frozen_materialization['positions'] ?? []),
                ]
                : null,
            'is_editable' => $canManage && $version->status->isEditable(),
            'is_adoptable' => $version->status->isAdoptable(),
        ];
    }
}
