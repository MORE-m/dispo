<?php

namespace App\Http\Controllers;

use App\Enums\StandardOfferVersionStatus;
use App\Models\AdvertisingMedium;
use App\Models\Inventory;
use App\Models\InventoryMediumRule;
use App\Models\StandardOffer;
use App\Models\StandardOfferVersion;
use App\Models\User;
use App\Services\DynamicField\ConfigurationSnapshotFreezeService;
use App\Services\StandardOffer\StandardOfferWriter;
use App\Support\PriceList\PriceListCalendar;
use App\Support\PriceList\PriceListYearSelection;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * BL-P4-03a / STD-003–STD-009 / AUTH-006 / AUTH-007 / UX-GATE-D Teilfreigabe.
 */
class StandardOfferController extends Controller
{
    public function __construct(
        private readonly StandardOfferWriter $writer,
        private readonly ConfigurationSnapshotFreezeService $snapshots,
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

        return Inertia::render('standard-offers/edit', [
            'mode' => 'create',
            'offer' => null,
            'version' => null,
            'catalog' => $this->catalogProps(),
            'schemaFingerprint' => $this->snapshots->resolveLiveSchemaForCalculationV3()['schema_fingerprint'],
        ]);
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
            'catalog' => $canManage && $version->status->isEditable() ? $this->catalogProps() : null,
            'schemaFingerprint' => $canManage && $version->status->isEditable()
                ? $this->snapshots->resolveLiveSchemaForCalculationV3()['schema_fingerprint']
                : null,
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
    private function validatedDraftRequest(Request $request): array
    {
        $validated = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'campaign' => ['nullable', 'string', 'max:255'],
            'product_title' => ['nullable', 'string', 'max:255'],
            'briefing' => ['nullable', 'string', 'max:20000'],
            'order_discount_percent' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'ae_enabled' => ['sometimes', 'boolean'],
            'schema_fingerprint' => ['required', 'string'],
            'positions' => ['required', 'array', 'min:1'],
            'positions.*.inventory_id' => ['required', 'integer'],
            'positions.*.advertising_medium_id' => ['required', 'integer'],
            'positions.*.spot_method' => ['nullable', 'in:average'],
            'positions.*.length_seconds' => ['required', 'integer', 'min:1', 'max:3600'],
            'positions.*.total_spot_count' => ['nullable', 'integer', 'min:0'],
            'positions.*.position_discount_percent' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'positions.*.ae_percent' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'positions.*.schema_fingerprint' => ['nullable', 'string'],
            'positions.*.time_ranges' => ['nullable', 'array'],
            'positions.*.time_ranges.*.start_hour' => ['required_with:positions.*.time_ranges', 'integer', 'min:0', 'max:23'],
            'positions.*.time_ranges.*.end_hour_exclusive' => ['required_with:positions.*.time_ranges', 'integer', 'min:1', 'max:24'],
            'positions.*.time_ranges.*.day_group' => ['required_with:positions.*.time_ranges', 'string'],
            'positions.*.time_ranges.*.spot_count' => ['required_with:positions.*.time_ranges', 'integer', 'min:0'],
            'positions.*.plan_rows' => ['nullable', 'array'],
            'positions.*.plan_rows.*.hour' => ['required_with:positions.*.plan_rows', 'integer', 'min:0', 'max:23'],
            'positions.*.plan_rows.*.day_group' => ['required_with:positions.*.plan_rows', 'string'],
            'positions.*.components' => ['prohibited'],
            'positions.*.planner_entries' => ['prohibited'],
            'positions.*.component_profile' => ['prohibited'],
            'positions.*.pricing_settlement_mode' => ['nullable', 'in:normal'],
            'customer_name' => ['prohibited'],
            'agency_name' => ['prohibited'],
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
                'spot_method' => 'average',
                'schema_fingerprint' => $fingerprint,
                'pricing_settlement_mode' => 'normal',
            ];
            unset($row['components'], $row['planner_entries'], $row['component_profile'], $row['fixed_price_nn']);
            $positions[] = $row;
        }

        return [
            'title' => $validated['title'],
            'payload' => [
                'planning_mode' => 'manual',
                'campaign' => $validated['campaign'] ?? null,
                'product_title' => $validated['product_title'] ?? null,
                'briefing' => $validated['briefing'] ?? null,
                'order_discount_percent' => $validated['order_discount_percent'] ?? '0',
                'ae_enabled' => (bool) ($validated['ae_enabled'] ?? false),
                'schema_fingerprint' => $validated['schema_fingerprint'],
                'positions' => $positions,
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function catalogProps(): array
    {
        $inventories = Inventory::query()
            ->where('is_active', true)
            ->orderBy('sort')
            ->orderBy('name')
            ->get(['id', 'name', 'code', 'type']);

        $media = AdvertisingMedium::query()
            ->where('is_active', true)
            ->whereNull('component_profile')
            ->orderBy('name')
            ->get(['id', 'name', 'code', 'default_length_seconds', 'kind']);

        $rules = InventoryMediumRule::query()
            ->where('is_active', true)
            ->whereIn('inventory_id', $inventories->pluck('id'))
            ->whereIn('advertising_medium_id', $media->pluck('id'))
            ->get(['inventory_id', 'advertising_medium_id']);

        $priceYears = [];
        foreach ($inventories as $inventory) {
            $priceYears[$inventory->id] = PriceListYearSelection::optionsForInventory((int) $inventory->id);
        }

        return [
            'inventories' => $inventories,
            'media' => $media,
            'rules' => $rules,
            'price_years_by_inventory' => $priceYears,
            'current_price_year' => PriceListCalendar::currentYear(),
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
