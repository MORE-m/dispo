<?php

namespace App\Services\StandardOffer;

use App\Enums\CalculationStatus;
use App\Enums\DiscountType;
use App\Enums\PlanningMode;
use App\Enums\PricingSettlementMode;
use App\Enums\SpotCalculationMethod;
use App\Enums\StandardOfferVersionStatus;
use App\Models\Calculation;
use App\Models\CalculationOrderDiscount;
use App\Models\CalculationPosition;
use App\Models\CalculationPositionDiscount;
use App\Models\CalculationPositionTimeRange;
use App\Models\ConfigurationSnapshot;
use App\Models\SpotClassicPlanRow;
use App\Models\StandardOffer;
use App\Models\StandardOfferVersion;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Services\Calculation\CalculationNumberSequencer;
use App\Services\Calculation\CalculationWriter;
use App\Services\DynamicField\CalculationDynamicFieldWriter;
use App\Services\DynamicField\ConfigurationSnapshotCloneService;
use App\Services\DynamicField\ConfigurationSnapshotFreezeService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * BL-P4-03a / STD-001–STD-009 / VER-004 / AUTH-006.
 *
 * Publish: Live-Auflösung einmalig einfrieren.
 * Adopt: Materialisierung aus Frozen-Stand ohne CalculationWriter::create().
 */
final class StandardOfferWriter
{
    public function __construct(
        private readonly StandardOfferNumberSequencer $offerNumbers,
        private readonly CalculationNumberSequencer $calculationNumbers,
        private readonly StandardOfferAverageContract $averageContract,
        private readonly CalculationWriter $calculations,
        private readonly ConfigurationSnapshotFreezeService $snapshots,
        private readonly ConfigurationSnapshotCloneService $clones,
        private readonly CalculationDynamicFieldWriter $dynamicFields,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @param  array<string, mixed>  $draftPayload
     */
    public function create(string $title, array $draftPayload, User $user): StandardOffer
    {
        $title = $this->assertTitle($title);
        $payload = $this->averageContract->normalizeDraftPayload($draftPayload);
        $this->assertResolvable($payload, $user);

        return DB::transaction(function () use ($title, $payload, $user): StandardOffer {
            [$year, $seq, $number] = $this->offerNumbers->next();

            $offer = new StandardOffer;
            $offer->number = $number;
            $offer->number_year = $year;
            $offer->number_seq = $seq;
            $offer->title = $title;
            $offer->lock_version = 1;
            $offer->created_by = $user->id;
            $offer->save();

            $version = new StandardOfferVersion;
            $version->standard_offer_id = $offer->id;
            $version->version_number = 1;
            $version->status = StandardOfferVersionStatus::Draft;
            $version->title = $title;
            $version->author_id = $user->id;
            $version->draft_payload = $payload;
            $version->lock_version = 1;
            $version->save();

            $this->audit->record($offer, 'standard_offer.created', $user, null, $this->offerSnapshot($offer->fresh(['versions'])));

            return $offer->fresh(['versions', 'draftVersion', 'publishedVersion']) ?? $offer;
        });
    }

    /**
     * @param  array<string, mixed>  $draftPayload
     */
    public function updateDraft(
        StandardOfferVersion $version,
        string $title,
        array $draftPayload,
        int $expectedLockVersion,
        User $user,
    ): StandardOfferVersion {
        $title = $this->assertTitle($title);
        $payload = $this->averageContract->normalizeDraftPayload($draftPayload);
        $this->assertResolvable($payload, $user);

        return DB::transaction(function () use ($version, $title, $payload, $expectedLockVersion, $user): StandardOfferVersion {
            $locked = StandardOfferVersion::query()->whereKey($version->id)->lockForUpdate()->firstOrFail();
            $this->assertDraftEditable($locked, $expectedLockVersion);
            $before = $this->versionSnapshot($locked);

            $locked->title = $title;
            $locked->draft_payload = $payload;
            $locked->author_id = $user->id;
            $locked->lock_version = $locked->lock_version + 1;
            $locked->save();

            $offer = StandardOffer::query()->whereKey($locked->standard_offer_id)->lockForUpdate()->firstOrFail();
            // Offer-Titel bleibt die sichtbare Published-Fassade für Vertrieb,
            // solange eine veröffentlichte Version existiert (PO-BLP403A-1).
            $hasPublished = StandardOfferVersion::query()
                ->where('standard_offer_id', $offer->id)
                ->where('status', StandardOfferVersionStatus::Published->value)
                ->exists();
            if (! $hasPublished) {
                $offer->title = $title;
            }
            $offer->lock_version = $offer->lock_version + 1;
            $offer->save();

            $fresh = $locked->fresh() ?? $locked;
            $this->audit->record($fresh, 'standard_offer.version.updated', $user, $before, $this->versionSnapshot($fresh));

            return $fresh;
        });
    }

    public function createDraftFromPublished(StandardOffer $offer, User $user): StandardOfferVersion
    {
        return DB::transaction(function () use ($offer, $user): StandardOfferVersion {
            $lockedOffer = StandardOffer::query()->whereKey($offer->id)->lockForUpdate()->firstOrFail();

            if ($lockedOffer->draftVersion()->exists()) {
                throw ValidationException::withMessages([
                    'status' => 'Es existiert bereits ein Entwurf für dieses Standardangebot.',
                ]);
            }

            $published = StandardOfferVersion::query()
                ->where('standard_offer_id', $lockedOffer->id)
                ->where('status', StandardOfferVersionStatus::Published->value)
                ->lockForUpdate()
                ->first();

            if ($published === null || ! is_array($published->frozen_materialization)) {
                throw ValidationException::withMessages([
                    'status' => 'Keine veröffentlichte Version als Grundlage für einen neuen Entwurf.',
                ]);
            }

            $maxVersion = (int) StandardOfferVersion::query()
                ->where('standard_offer_id', $lockedOffer->id)
                ->max('version_number');

            $draftPayload = $published->frozen_materialization['draft_payload']
                ?? $published->draft_payload
                ?? [];
            if (! is_array($draftPayload)) {
                $draftPayload = [];
            }
            $draftPayload = $this->averageContract->normalizeDraftPayload($draftPayload);

            $version = new StandardOfferVersion;
            $version->standard_offer_id = $lockedOffer->id;
            $version->version_number = $maxVersion + 1;
            $version->status = StandardOfferVersionStatus::Draft;
            $version->title = $published->title;
            $version->author_id = $user->id;
            $version->draft_payload = $draftPayload;
            $version->lock_version = 1;
            $version->save();

            // Offer-Titel bleibt die Published-Fassade; Draft ändert ihn nicht.
            $lockedOffer->lock_version = $lockedOffer->lock_version + 1;
            $lockedOffer->save();

            $this->audit->record($version, 'standard_offer.version.draft_created', $user, null, $this->versionSnapshot($version));

            return $version;
        });
    }

    public function publish(StandardOfferVersion $version, int $expectedLockVersion, User $user): StandardOfferVersion
    {
        return DB::transaction(function () use ($version, $expectedLockVersion, $user): StandardOfferVersion {
            $locked = StandardOfferVersion::query()->whereKey($version->id)->lockForUpdate()->firstOrFail();
            $this->assertDraftEditable($locked, $expectedLockVersion);

            $offer = StandardOffer::query()->whereKey($locked->standard_offer_id)->lockForUpdate()->firstOrFail();
            $payload = $this->averageContract->normalizeDraftPayload($locked->draft_payload ?? []);
            $frozen = $this->freezePayload($payload, $user);

            $previousPublished = StandardOfferVersion::query()
                ->where('standard_offer_id', $offer->id)
                ->where('status', StandardOfferVersionStatus::Published->value)
                ->whereKeyNot($locked->id)
                ->lockForUpdate()
                ->get();

            foreach ($previousPublished as $previous) {
                $prevBefore = $this->versionSnapshot($previous);
                $previous->status = StandardOfferVersionStatus::Archived;
                $previous->archived_at = now();
                $previous->lock_version = $previous->lock_version + 1;
                $previous->save();
                $this->audit->record(
                    $previous,
                    'standard_offer.version.archived',
                    $user,
                    $prevBefore,
                    $this->versionSnapshot($previous->fresh() ?? $previous),
                );
            }

            $before = $this->versionSnapshot($locked);
            $locked->status = StandardOfferVersionStatus::Published;
            $locked->published_at = now();
            $locked->author_id = $user->id;
            $locked->draft_payload = $payload;
            $locked->frozen_materialization = $frozen['materialization'];
            $locked->configuration_snapshot_id = $frozen['base_snapshot_id'];
            $locked->lock_version = $locked->lock_version + 1;
            $locked->save();

            // Erst mit Publish wird der Offer-Titel zur neuen Published-Fassade.
            $offer->title = $locked->title;
            $offer->lock_version = $offer->lock_version + 1;
            $offer->save();

            $fresh = $locked->fresh() ?? $locked;
            $this->audit->record($fresh, 'standard_offer.version.published', $user, $before, $this->versionSnapshot($fresh));

            return $fresh;
        });
    }

    public function archive(StandardOfferVersion $version, int $expectedLockVersion, User $user): StandardOfferVersion
    {
        return DB::transaction(function () use ($version, $expectedLockVersion, $user): StandardOfferVersion {
            $locked = StandardOfferVersion::query()->whereKey($version->id)->lockForUpdate()->firstOrFail();

            if ((int) $locked->lock_version !== $expectedLockVersion) {
                throw ValidationException::withMessages([
                    'lock_version' => 'Die Version wurde parallel geändert. Bitte neu laden.',
                ]);
            }

            if ($locked->status === StandardOfferVersionStatus::Archived) {
                return $locked;
            }

            if (! in_array($locked->status, [
                StandardOfferVersionStatus::Draft,
                StandardOfferVersionStatus::Published,
            ], true)) {
                throw ValidationException::withMessages([
                    'status' => 'Nur Entwürfe oder veröffentlichte Versionen können archiviert werden.',
                ]);
            }

            $before = $this->versionSnapshot($locked);
            $locked->status = StandardOfferVersionStatus::Archived;
            $locked->archived_at = now();
            $locked->lock_version = $locked->lock_version + 1;
            $locked->save();

            $offer = StandardOffer::query()->whereKey($locked->standard_offer_id)->lockForUpdate()->firstOrFail();
            $offer->lock_version = $offer->lock_version + 1;
            $offer->save();

            $fresh = $locked->fresh() ?? $locked;
            $this->audit->record($fresh, 'standard_offer.version.archived', $user, $before, $this->versionSnapshot($fresh));

            return $fresh;
        });
    }

    public function adopt(
        StandardOfferVersion $version,
        string $customerName,
        ?string $agencyName,
        ?string $campaign,
        User $user,
    ): Calculation {
        $customerName = trim($customerName);
        if ($customerName === '') {
            throw ValidationException::withMessages([
                'customer_name' => 'Kunde ist bei der Übernahme Pflicht (Freitext bis CRM-Slice).',
            ]);
        }

        return DB::transaction(function () use ($version, $customerName, $agencyName, $campaign, $user): Calculation {
            $locked = StandardOfferVersion::query()->whereKey($version->id)->lockForUpdate()->firstOrFail();

            if (! $locked->status->isAdoptable() || ! is_array($locked->frozen_materialization)) {
                throw ValidationException::withMessages([
                    'status' => 'Nur veröffentlichte Versionen können übernommen werden.',
                ]);
            }

            $materialization = $locked->frozen_materialization;
            $baseSnapshotId = (int) ($materialization['configuration_snapshot_id'] ?? 0);
            $templateBase = ConfigurationSnapshot::query()->whereKey($baseSnapshotId)->firstOrFail();
            $calcBase = $this->clones->cloneCalculationBase($templateBase);

            [$year, $seq, $number] = $this->calculationNumbers->next();

            $calculation = new Calculation;
            $calculation->number = $number;
            $calculation->number_year = $year;
            $calculation->number_seq = $seq;
            $calculation->status = CalculationStatus::Draft;
            $calculation->planning_mode = PlanningMode::Manual;
            $calculation->advisor_id = $user->id;
            $calculation->customer_name = $customerName;
            $calculation->agency_name = $agencyName !== null && trim($agencyName) !== '' ? trim($agencyName) : null;
            $calculation->campaign = $campaign !== null && trim($campaign) !== ''
                ? trim($campaign)
                : ($materialization['campaign'] ?? null);
            $calculation->product_title = $materialization['product_title'] ?? null;
            $calculation->briefing = $materialization['briefing'] ?? null;
            $calculation->order_discount_percent = $materialization['order_discount_percent'] ?? '0';
            $calculation->ae_enabled = (bool) ($materialization['ae_enabled'] ?? false);
            $calculation->media_gross = $materialization['media_gross'] ?? '0';
            $calculation->position_discount_total = $materialization['position_discount_total'] ?? '0';
            $calculation->order_discount_total = $materialization['order_discount_total'] ?? '0';
            $calculation->ae_total = $materialization['ae_total'] ?? '0';
            $calculation->nn_invest = $materialization['nn_invest'] ?? '0';
            $calculation->requires_special_approval = (bool) ($materialization['requires_special_approval'] ?? false);
            $calculation->special_approval_reasons = $materialization['special_approval_reasons'] ?? null;
            $calculation->personal_discount_limit_percent = $user->discount_limit_percent;
            $calculation->lock_version = 1;
            $calculation->configuration_snapshot_id = $calcBase->id;
            $calculation->originStandardOfferVersion()->associate($locked);
            $calculation->save();

            foreach ($materialization['order_discounts'] ?? [] as $index => $discount) {
                if (! is_array($discount)) {
                    continue;
                }
                $row = new CalculationOrderDiscount;
                $row->calculation()->associate($calculation);
                $row->type = DiscountType::from((string) ($discount['type'] ?? DiscountType::Other->value));
                $row->custom_label = $discount['custom_label'] ?? null;
                $row->percent = $discount['percent'] ?? '0';
                $row->sort = max(0, (int) $index);
                $row->save();
            }

            foreach ($materialization['positions'] ?? [] as $index => $frozenPosition) {
                if (! is_array($frozenPosition)) {
                    continue;
                }

                $templateEffectiveId = (int) ($frozenPosition['effective_configuration_snapshot_id'] ?? 0);
                $templateEffective = ConfigurationSnapshot::query()->whereKey($templateEffectiveId)->firstOrFail();
                $calcEffective = $this->clones->cloneCalculationPositionEffective($templateEffective, $calcBase);

                $position = new CalculationPosition;
                $position->fill([
                    'client_key' => (string) ($frozenPosition['client_key'] ?? (string) Str::uuid()),
                    'inventory_id' => (int) $frozenPosition['inventory_id'],
                    'inventory_name' => $frozenPosition['inventory_name'] ?? null,
                    'inventory_code' => $frozenPosition['inventory_code'] ?? null,
                    'advertising_medium_id' => (int) $frozenPosition['advertising_medium_id'],
                    'advertising_medium_name' => $frozenPosition['advertising_medium_name'] ?? null,
                    'advertising_medium_code' => $frozenPosition['advertising_medium_code'] ?? null,
                    'advertising_category_id' => $frozenPosition['advertising_category_id'] ?? null,
                    'advertising_category_key' => $frozenPosition['advertising_category_key'] ?? null,
                    'advertising_category_name' => $frozenPosition['advertising_category_name'] ?? null,
                    'effective_configuration_snapshot_id' => $calcEffective->id,
                    'inventory_medium_rule_id' => $frozenPosition['inventory_medium_rule_id'] ?? null,
                    'price_list_id' => (int) $frozenPosition['price_list_id'],
                    'kind' => $frozenPosition['kind'],
                    'spot_method' => SpotCalculationMethod::Average->value,
                    'length_seconds' => (int) $frozenPosition['length_seconds'],
                    'component_calculation_strategy' => null,
                    'component_profile' => null,
                    'total_spot_count' => (int) $frozenPosition['total_spot_count'],
                    'needs_spot_redistribution' => (bool) ($frozenPosition['needs_spot_redistribution'] ?? false),
                    'average_second_price' => $frozenPosition['average_second_price'] ?? null,
                    'length_index' => $frozenPosition['length_index'] ?? null,
                    'surcharge_percent' => $frozenPosition['surcharge_percent'] ?? '0',
                    'position_discount_percent' => $frozenPosition['position_discount_percent'] ?? '0',
                    'ae_percent' => $frozenPosition['ae_percent'] ?? '0',
                    'is_discountable' => (bool) ($frozenPosition['is_discountable'] ?? true),
                    'is_ae_eligible' => (bool) ($frozenPosition['is_ae_eligible'] ?? true),
                    'price_list_version' => $frozenPosition['price_list_version'] ?? null,
                    'media_gross' => $frozenPosition['media_gross'] ?? '0',
                    'position_discount_amount' => $frozenPosition['position_discount_amount'] ?? '0',
                    'order_discount_amount' => $frozenPosition['order_discount_amount'] ?? '0',
                    'ae_amount' => $frozenPosition['ae_amount'] ?? '0',
                    'nn_invest' => $frozenPosition['nn_invest'] ?? '0',
                    'pricing_settlement_mode' => PricingSettlementMode::Normal->value,
                    'fixed_price_nn' => null,
                    'effective_pay_factor_percent' => $frozenPosition['effective_pay_factor_percent'] ?? null,
                    'effective_total_discount_percent' => $frozenPosition['effective_total_discount_percent'] ?? null,
                    'sort' => (int) $index,
                ]);
                $position->engine_profile_key = $frozenPosition['engine_profile_key'] ?? null;
                $position->calculation_method_key = $frozenPosition['calculation_method_key'] ?? null;
                $position->calculation_method_name = $frozenPosition['calculation_method_name'] ?? null;
                $position->algorithm_version = $frozenPosition['algorithm_version'] ?? null;
                $position->calculation()->associate($calculation);
                $position->save();

                foreach ($frozenPosition['time_ranges'] ?? [] as $rangeIndex => $range) {
                    if (! is_array($range)) {
                        continue;
                    }
                    $model = new CalculationPositionTimeRange;
                    $model->fill([
                        'start_hour' => (int) $range['start_hour'],
                        'end_hour_exclusive' => (int) $range['end_hour_exclusive'],
                        'day_group' => $range['day_group'],
                        'spot_count' => (int) $range['spot_count'],
                        'sort' => (int) $rangeIndex,
                        'average_second_price' => $range['average_second_price'] ?? null,
                        'range_gross' => $range['range_gross'] ?? '0',
                    ]);
                    $model->position()->associate($position);
                    $model->save();
                }

                foreach ($frozenPosition['plan_rows'] ?? [] as $row) {
                    if (! is_array($row)) {
                        continue;
                    }
                    $planRow = new SpotClassicPlanRow;
                    $planRow->fill([
                        'hour' => (int) $row['hour'],
                        'day_group' => $row['day_group'],
                        'spot_count' => (int) ($row['spot_count'] ?? 0),
                        'second_price' => $row['second_price'],
                        'line_gross' => $row['line_gross'] ?? '0.00',
                    ]);
                    $planRow->position()->associate($position);
                    $planRow->save();
                }

                foreach ($frozenPosition['position_discounts'] ?? [] as $discountIndex => $discount) {
                    if (! is_array($discount)) {
                        continue;
                    }
                    $model = new CalculationPositionDiscount;
                    $model->fill([
                        'type' => $discount['type'] ?? DiscountType::Other->value,
                        'custom_label' => $discount['custom_label'] ?? null,
                        'percent' => $discount['percent'] ?? '0',
                        'sort' => (int) $discountIndex,
                    ]);
                    $model->position()->associate($position);
                    $model->save();
                }
            }

            $syncPayload = $materialization['draft_payload'] ?? [];
            if (is_array($syncPayload)) {
                $syncPayload['customer_name'] = $customerName;
                $syncPayload['agency_name'] = $calculation->agency_name;
                $syncPayload['schema_fingerprint'] = $calcBase->schema_fingerprint;
                $calculation->load('positions');
                foreach ($calculation->positions as $posIndex => $pos) {
                    if (! isset($syncPayload['positions'][$posIndex]) || ! is_array($syncPayload['positions'][$posIndex])) {
                        continue;
                    }
                    $syncPayload['positions'][$posIndex]['id'] = $pos->id;
                    $syncPayload['positions'][$posIndex]['client_key'] = $pos->client_key;
                    $syncPayload['positions'][$posIndex]['schema_fingerprint'] = ConfigurationSnapshot::query()
                        ->whereKey($pos->effective_configuration_snapshot_id)
                        ->value('schema_fingerprint');
                }
                $this->dynamicFields->syncFromPayload($calculation, $syncPayload);
            }

            $fresh = $calculation->fresh(['positions.timeRanges', 'positions.planRows', 'orderDiscounts']) ?? $calculation;
            $this->audit->record($fresh, 'standard_offer.adopted', $user, null, [
                'calculation_id' => $fresh->id,
                'calculation_number' => $fresh->number,
                'origin_standard_offer_version_id' => $locked->id,
                'standard_offer_id' => $locked->standard_offer_id,
            ]);
            $this->audit->record($fresh, 'calculation.created', $user, null, [
                'number' => $fresh->number,
                'origin_standard_offer_version_id' => $locked->id,
            ]);

            return $fresh;
        });
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function assertResolvable(array $payload, User $user): void
    {
        $this->calculations->preview($payload, $user);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{base_snapshot_id: int, materialization: array<string, mixed>}
     */
    private function freezePayload(array $payload, User $user): array
    {
        $fingerprint = $payload['schema_fingerprint'] ?? null;
        if (! is_string($fingerprint) || $fingerprint === '') {
            throw ValidationException::withMessages([
                'schema_fingerprint' => 'Schema-Fingerprint fehlt oder ist ungültig.',
            ]);
        }

        $totals = $this->calculations->totalsFromPayload($payload, $user);
        $resolved = $this->calculations->resolvedPositions($payload);

        $freezeInputs = [];
        foreach ($resolved as $index => $item) {
            $positionPayload = $payload['positions'][$index] ?? [];
            $freezeInputs[] = [
                'client_key' => (string) $index,
                'advertising_medium_id' => (int) $item['medium']->id,
                'schema_fingerprint' => $positionPayload['schema_fingerprint'] ?? null,
            ];
        }

        $frozen = $this->snapshots->freezeCalculationV3($fingerprint, $freezeInputs);
        $positions = [];
        /** @var array<string, ConfigurationSnapshot> $effectives */
        $effectives = $frozen['effectives_by_client_key'];

        foreach ($resolved as $index => $item) {
            $result = $totals->positions[$index];
            $effectiveKey = (string) $index;
            if (! array_key_exists($effectiveKey, $effectives)) {
                throw ValidationException::withMessages([
                    "positions.{$index}" => 'Konfigurationssnapshot für Position fehlt.',
                ]);
            }
            $effective = $effectives[$effectiveKey];

            $positions[] = [
                'client_key' => (string) Str::uuid(),
                'inventory_id' => (int) $item['inventory']->id,
                'inventory_name' => $item['inventory']->name,
                'inventory_code' => $item['inventory']->code,
                'advertising_medium_id' => (int) $item['medium']->id,
                'advertising_medium_name' => $effective->context_advertising_medium_name,
                'advertising_medium_code' => $effective->context_advertising_medium_code,
                'advertising_category_id' => $effective->context_advertising_category_id,
                'advertising_category_key' => $effective->context_advertising_category_key,
                'advertising_category_name' => $effective->context_advertising_category_name,
                'effective_configuration_snapshot_id' => $effective->id,
                'inventory_medium_rule_id' => $item['inventory_medium_rule_id'],
                'price_list_id' => (int) $item['priceList']->id,
                'price_list_version' => $item['priceList']->version,
                'kind' => $item['freeze']->legacyKind()->value,
                'spot_method' => SpotCalculationMethod::Average->value,
                'length_seconds' => (int) $item['length_seconds'],
                'total_spot_count' => (int) $item['total_spot_count'],
                'needs_spot_redistribution' => (bool) $item['needs_spot_redistribution'],
                'average_second_price' => $result->averageSecondPrice,
                'length_index' => $result->lengthIndex,
                'surcharge_percent' => $item['surcharge_percent'],
                'position_discount_percent' => $item['is_discountable']
                    ? ($payload['positions'][$index]['position_discount_percent'] ?? '0')
                    : '0',
                'ae_percent' => $item['is_ae_eligible'] ? ($item['ae_percent'] ?? '0') : '0',
                'is_discountable' => (bool) $item['is_discountable'],
                'is_ae_eligible' => (bool) $item['is_ae_eligible'],
                'media_gross' => $result->mediaGross,
                'position_discount_amount' => $result->positionDiscountAmount,
                'order_discount_amount' => $result->orderDiscountAmount,
                'ae_amount' => $result->aeAmount,
                'nn_invest' => $result->nnInvest,
                'pricing_settlement_mode' => PricingSettlementMode::Normal->value,
                'fixed_price_nn' => null,
                'effective_pay_factor_percent' => $result->effectivePayFactorPercent,
                'effective_total_discount_percent' => $result->effectiveDiscountPercent,
                'engine_profile_key' => $item['freeze']->engineProfileKey,
                'calculation_method_key' => $item['freeze']->calculationMethodKey,
                'calculation_method_name' => $item['freeze']->calculationMethodName,
                'algorithm_version' => $item['freeze']->algorithmVersion,
                'time_ranges' => $result->timeRanges,
                'plan_rows' => $result->rows,
                'position_discounts' => array_map(
                    static fn (array $discount): array => [
                        'type' => $discount['type'],
                        'custom_label' => $discount['label'] === '' ? null : $discount['label'],
                        'percent' => $discount['percent'],
                    ],
                    $result->positionDiscounts,
                ),
            ];
        }

        return [
            'base_snapshot_id' => (int) $frozen['base']->id,
            'materialization' => [
                'draft_payload' => $payload,
                'configuration_snapshot_id' => (int) $frozen['base']->id,
                'schema_fingerprint' => $frozen['base']->schema_fingerprint,
                'campaign' => $payload['campaign'] ?? null,
                'product_title' => $payload['product_title'] ?? null,
                'briefing' => $payload['briefing'] ?? null,
                'order_discount_percent' => $payload['order_discount_percent'] ?? '0',
                'order_discounts' => $payload['order_discounts'] ?? [],
                'ae_enabled' => (bool) ($payload['ae_enabled'] ?? false),
                'media_gross' => $totals->mediaGross,
                'position_discount_total' => $totals->positionDiscountTotal,
                'order_discount_total' => $totals->orderDiscountTotal,
                'ae_total' => $totals->aeTotal,
                'nn_invest' => $totals->nnInvest,
                'requires_special_approval' => $totals->requiresSpecialApproval,
                'special_approval_reasons' => $totals->specialApprovalReasons,
                'positions' => $positions,
            ],
        ];
    }

    private function assertTitle(string $title): string
    {
        $title = trim($title);
        if ($title === '') {
            throw ValidationException::withMessages([
                'title' => 'Titel ist erforderlich.',
            ]);
        }

        return $title;
    }

    private function assertDraftEditable(StandardOfferVersion $version, int $expectedLockVersion): void
    {
        if ($version->status !== StandardOfferVersionStatus::Draft) {
            throw ValidationException::withMessages([
                'status' => 'Nur Entwürfe dürfen bearbeitet oder veröffentlicht werden.',
            ]);
        }

        if ((int) $version->lock_version !== $expectedLockVersion) {
            throw ValidationException::withMessages([
                'lock_version' => 'Die Version wurde parallel geändert. Bitte neu laden.',
            ]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function offerSnapshot(StandardOffer $offer): array
    {
        return [
            'id' => $offer->id,
            'number' => $offer->number,
            'title' => $offer->title,
            'lock_version' => $offer->lock_version,
            'versions' => $offer->versions->map(fn (StandardOfferVersion $version): array => [
                'id' => $version->id,
                'version_number' => $version->version_number,
                'status' => $version->status->value,
            ])->all(),
        ];
    }

    /**
     * AUD-001: nachvollziehbare Old/New inkl. Entwurfsinhalt (Positionen/Mengen).
     *
     * @return array<string, mixed>
     */
    private function versionSnapshot(StandardOfferVersion $version): array
    {
        return [
            'id' => $version->id,
            'standard_offer_id' => $version->standard_offer_id,
            'version_number' => $version->version_number,
            'status' => $version->status->value,
            'title' => $version->title,
            'author_id' => $version->author_id,
            'published_at' => $version->published_at?->toIso8601String(),
            'archived_at' => $version->archived_at?->toIso8601String(),
            'configuration_snapshot_id' => $version->configuration_snapshot_id,
            'lock_version' => $version->lock_version,
            'draft_payload' => $version->draft_payload,
        ];
    }
}
