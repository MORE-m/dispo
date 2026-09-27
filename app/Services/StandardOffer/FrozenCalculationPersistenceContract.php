<?php

namespace App\Services\StandardOffer;

use App\Enums\CalculationStatus;
use App\Enums\ComponentCalculationStrategy;
use App\Enums\DiscountType;
use App\Enums\PlanningMode;
use App\Enums\PricingSettlementMode;
use App\Enums\SpotCalculationMethod;
use App\Models\Calculation;
use App\Models\CalculationOrderDiscount;
use App\Models\CalculationPosition;
use App\Models\CalculationPositionComponent;
use App\Models\CalculationPositionDiscount;
use App\Models\CalculationPositionTimeRange;
use App\Models\ConfigurationSnapshot;
use App\Models\SpotClassicPlanRow;
use App\Models\StandardOfferVersion;
use App\Models\User;
use App\Services\Calculation\CalculationNumberSequencer;
use App\Services\Calculation\CalculationWriter;
use App\Services\DynamicField\CalculationDynamicFieldWriter;
use App\Services\DynamicField\ConfigurationSnapshotCloneService;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * BL-P4-03d / VER-004 / STD-004 / STD-005 / STD-006.
 *
 * Ausdrücklich versionierter Persistenzvertrag für eingefrorene Spot-Classic-
 * Average-Kalkulationsdaten (optional Hauptspot+Allonge).
 *
 * Architekturgrenze:
 * - Freeze schreibt diesen Vertrag ({@see StandardOfferMaterializer::freeze()}).
 * - Hydrate materialisiert Kundenkalkulationen ausschließlich aus Frozen-Werten
 *   und geklonten Config-Snapshots ({@see self::hydrateAdoptedCalculation()}).
 * - Kein {@see CalculationWriter::create()}, keine
 *   Live-Preisauflösung, keine Kopplung zur Quellkalkulation.
 * - Übernahmekontext (Kunde, optionale Agentur, Advisor, Kampagne) wird von
 *   außen ergänzt; Vorlagenwerte bleiben unverändert.
 *
 * Zentrale Feldabbildung (Average v1) – hier pflegen, nicht in Adopt-Listen
 * duplizieren:
 * - Kopf: campaign/product_title/briefing, order_discount*, ae_*, Summen,
 *   Sonderfreigabe, configuration_snapshot_id, draft_payload
 * - Position: Katalog-Identität, Preislisten-Pin, Länge/Spots, Konditionen,
 *   AE, Engine-Freeze, Komponenten (+ Strategie), time_ranges, plan_rows,
 *   position_discounts, effective_configuration_snapshot_id
 *
 * Neue Kalkulationsmethoden (Calendar/Festpreis/Tandem/…) erfordern eine neue
 * materialization_version bzw. explizite Contract-Erweiterung. Dieser Refactor
 * allein unterstützt sie nicht automatisch.
 */
final class FrozenCalculationPersistenceContract
{
    /**
     * Bekannte lesbare Hydrate-Versionen. Fehlendes materialization_version
     * gilt als Legacy-Version 1 (03a/03c-Stände vor 03b-Tagging).
     *
     * @var list<int>
     */
    public const SUPPORTED_VERSIONS = [1];

    public const LEGACY_IMPLICIT_VERSION = 1;

    public function __construct(
        private readonly ConfigurationSnapshotCloneService $clones,
        private readonly CalculationNumberSequencer $calculationNumbers,
        private readonly CalculationDynamicFieldWriter $dynamicFields,
    ) {}

    /**
     * @param  array<string, mixed>  $materialization
     */
    public function resolveVersion(array $materialization): int
    {
        if (! array_key_exists('materialization_version', $materialization)
            || $materialization['materialization_version'] === null) {
            return self::LEGACY_IMPLICIT_VERSION;
        }

        $version = $materialization['materialization_version'];
        if (! is_int($version)) {
            throw ValidationException::withMessages([
                'frozen_materialization' => 'Die Materialisierungsversion der Vorlage ist ungültig.',
            ]);
        }

        if (! in_array($version, self::SUPPORTED_VERSIONS, true)) {
            throw ValidationException::withMessages([
                'frozen_materialization' => "Materialisierungsversion {$version} wird von dieser Anwendung nicht unterstützt.",
            ]);
        }

        return $version;
    }

    /**
     * Version + Pflichtstruktur prüfen, bevor Persistenz beginnt.
     *
     * @param  array<string, mixed>  $materialization
     */
    public function assertHydratable(array $materialization): int
    {
        $version = $this->resolveVersion($materialization);
        $this->assertAverageV1Shape($materialization);

        return $version;
    }

    /**
     * Persistiert eine übernommene Draft-Kalkulation aus Frozen-Stand.
     * Muss innerhalb einer DB-Transaktion des Aufrufers laufen.
     *
     * @param  array<string, mixed>  $materialization
     * @param  array{
     *     customer_name: string,
     *     agency_name: ?string,
     *     campaign: ?string,
     *     advisor: User,
     *     origin_version: StandardOfferVersion
     * }  $adoptionContext
     */
    public function hydrateAdoptedCalculation(array $materialization, array $adoptionContext): Calculation
    {
        $this->assertHydratable($materialization);

        $user = $adoptionContext['advisor'];
        $origin = $adoptionContext['origin_version'];
        $customerName = $adoptionContext['customer_name'];
        $agencyName = $adoptionContext['agency_name'];
        $campaignOverride = $adoptionContext['campaign'];

        $baseSnapshotId = (int) $materialization['configuration_snapshot_id'];
        $templateBase = ConfigurationSnapshot::query()->whereKey($baseSnapshotId)->first();
        if ($templateBase === null) {
            throw ValidationException::withMessages([
                'frozen_materialization' => 'Eingefrorener Konfigurationssnapshot der Vorlage fehlt oder ist ungültig.',
            ]);
        }

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
        $calculation->agency_name = $agencyName;
        $calculation->campaign = $campaignOverride !== null && $campaignOverride !== ''
            ? $campaignOverride
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
        $calculation->originStandardOfferVersion()->associate($origin);
        $calculation->save();

        $this->persistOrderDiscounts($calculation, $materialization['order_discounts']);
        $this->persistPositions($calculation, $calcBase, $materialization['positions']);
        $this->syncDynamicFields($calculation, $calcBase, $materialization, $customerName);

        return $calculation->fresh([
            'positions.timeRanges',
            'positions.planRows',
            'positions.components',
            'positions.discounts',
            'orderDiscounts',
        ]) ?? $calculation;
    }

    /**
     * @param  array<string, mixed>  $materialization
     */
    private function assertAverageV1Shape(array $materialization): void
    {
        $baseSnapshotId = (int) ($materialization['configuration_snapshot_id'] ?? 0);
        if ($baseSnapshotId <= 0) {
            throw ValidationException::withMessages([
                'frozen_materialization' => 'Eingefrorene Vorlagendaten sind unvollständig (Konfigurationssnapshot fehlt).',
            ]);
        }

        $positions = $materialization['positions'] ?? null;
        if (! is_array($positions) || $positions === []) {
            throw ValidationException::withMessages([
                'frozen_materialization' => 'Eingefrorene Vorlagendaten sind unvollständig (keine Positionen).',
            ]);
        }

        $orderDiscounts = $materialization['order_discounts'] ?? [];
        if (! is_array($orderDiscounts)) {
            throw ValidationException::withMessages([
                'frozen_materialization' => 'Eingefrorene Vorlagendaten sind ungültig (Kopfrabatte).',
            ]);
        }

        foreach ($orderDiscounts as $index => $discount) {
            if (! is_array($discount)) {
                throw ValidationException::withMessages([
                    'frozen_materialization' => "Eingefrorene Vorlagendaten sind ungültig (Kopfrabatt {$index}).",
                ]);
            }
        }

        foreach ($positions as $index => $position) {
            if (! is_array($position)) {
                throw ValidationException::withMessages([
                    'frozen_materialization' => "Eingefrorene Vorlagendaten sind ungültig (Position {$index}).",
                ]);
            }

            foreach ([
                'inventory_id',
                'advertising_medium_id',
                'price_list_id',
                'kind',
                'length_seconds',
                'total_spot_count',
                'effective_configuration_snapshot_id',
            ] as $required) {
                if (! array_key_exists($required, $position) || $position[$required] === null || $position[$required] === '') {
                    throw ValidationException::withMessages([
                        'frozen_materialization' => "Eingefrorene Vorlagendaten sind unvollständig (Position {$index}: {$required}).",
                    ]);
                }
            }

            if ((int) $position['effective_configuration_snapshot_id'] <= 0
                || (int) $position['inventory_id'] <= 0
                || (int) $position['advertising_medium_id'] <= 0
                || (int) $position['price_list_id'] <= 0) {
                throw ValidationException::withMessages([
                    'frozen_materialization' => "Eingefrorene Vorlagendaten sind ungültig (Position {$index}: Referenzen).",
                ]);
            }

            foreach (['components', 'time_ranges', 'plan_rows', 'position_discounts'] as $childKey) {
                if (! array_key_exists($childKey, $position) || $position[$childKey] === null) {
                    continue;
                }
                $children = $position[$childKey];
                if (! is_array($children)) {
                    throw ValidationException::withMessages([
                        'frozen_materialization' => "Eingefrorene Vorlagendaten sind ungültig (Position {$index}: {$childKey}).",
                    ]);
                }
                foreach ($children as $childIndex => $child) {
                    if (! is_array($child)) {
                        throw ValidationException::withMessages([
                            'frozen_materialization' => "Eingefrorene Vorlagendaten sind ungültig (Position {$index}: {$childKey}.{$childIndex}).",
                        ]);
                    }
                }
            }
        }
    }

    /**
     * @param  list<array<string, mixed>>  $discounts
     */
    private function persistOrderDiscounts(Calculation $calculation, array $discounts): void
    {
        foreach ($discounts as $index => $discount) {
            $row = new CalculationOrderDiscount;
            $row->calculation()->associate($calculation);
            $row->type = DiscountType::from((string) ($discount['type'] ?? DiscountType::Other->value));
            $row->custom_label = $discount['custom_label'] ?? null;
            $row->percent = $discount['percent'] ?? '0';
            $row->sort = max(0, (int) $index);
            $row->save();
        }
    }

    /**
     * @param  list<array<string, mixed>>  $positions
     */
    private function persistPositions(
        Calculation $calculation,
        ConfigurationSnapshot $calcBase,
        array $positions,
    ): void {
        foreach ($positions as $index => $frozenPosition) {
            $templateEffectiveId = (int) $frozenPosition['effective_configuration_snapshot_id'];
            $templateEffective = ConfigurationSnapshot::query()->whereKey($templateEffectiveId)->first();
            if ($templateEffective === null) {
                throw ValidationException::withMessages([
                    'frozen_materialization' => "Eingefrorener Positionssnapshot fehlt (Position {$index}).",
                ]);
            }

            $calcEffective = $this->clones->cloneCalculationPositionEffective($templateEffective, $calcBase);

            $frozenStrategy = $frozenPosition['component_calculation_strategy'] ?? null;
            $strategyValue = is_string($frozenStrategy) && $frozenStrategy !== ''
                ? ComponentCalculationStrategy::tryFrom($frozenStrategy)?->value
                : null;

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
                'component_calculation_strategy' => $strategyValue,
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

            $this->persistComponents($position, $frozenPosition['components'] ?? []);
            $this->persistTimeRanges($position, $frozenPosition['time_ranges'] ?? []);
            $this->persistPlanRows($position, $frozenPosition['plan_rows'] ?? []);
            $this->persistPositionDiscounts($position, $frozenPosition['position_discounts'] ?? []);
        }
    }

    /**
     * @param  list<array<string, mixed>>  $components
     */
    private function persistComponents(CalculationPosition $position, array $components): void
    {
        foreach ($components as $componentIndex => $component) {
            $mediaGross = $component['media_gross'] ?? null;
            if ($mediaGross === '' || $mediaGross === null) {
                $mediaGross = null;
            } else {
                $mediaGross = (string) $mediaGross;
            }
            $lengthIndex = $component['length_index'] ?? null;
            $componentModel = new CalculationPositionComponent;
            $componentModel->fill([
                'role' => $component['role'] ?? 'main_spot',
                'label' => $component['label'] ?? '',
                'length_seconds' => (int) ($component['length_seconds'] ?? 0),
                'sort' => (int) ($component['sort'] ?? $componentIndex),
                'length_index' => $lengthIndex === null || $lengthIndex === ''
                    ? null
                    : (int) $lengthIndex,
                'media_gross' => $mediaGross,
            ]);
            $componentModel->position()->associate($position);
            $componentModel->save();
        }
    }

    /**
     * @param  list<array<string, mixed>>  $ranges
     */
    private function persistTimeRanges(CalculationPosition $position, array $ranges): void
    {
        foreach ($ranges as $rangeIndex => $range) {
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
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     */
    private function persistPlanRows(CalculationPosition $position, array $rows): void
    {
        foreach ($rows as $row) {
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
    }

    /**
     * @param  list<array<string, mixed>>  $discounts
     */
    private function persistPositionDiscounts(CalculationPosition $position, array $discounts): void
    {
        foreach ($discounts as $discountIndex => $discount) {
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

    /**
     * @param  array<string, mixed>  $materialization
     */
    private function syncDynamicFields(
        Calculation $calculation,
        ConfigurationSnapshot $calcBase,
        array $materialization,
        string $customerName,
    ): void {
        $syncPayload = $materialization['draft_payload'] ?? null;
        if (! is_array($syncPayload)) {
            return;
        }

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
}
