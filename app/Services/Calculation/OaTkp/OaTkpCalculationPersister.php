<?php

namespace App\Services\Calculation\OaTkp;

use App\Enums\CalculationKind;
use App\Enums\CalculationStatus;
use App\Enums\PlanningMode;
use App\Enums\PricingSettlementMode;
use App\Enums\SpotCalculationMethod;
use App\Models\Calculation;
use App\Models\CalculationOrderDiscount;
use App\Models\CalculationPosition;
use App\Models\CalculationPositionDiscount;
use App\Models\CalculationPositionOaPlatformAllocation;
use App\Models\CalculationPositionOaTargetingSelection;
use App\Models\User;
use App\Services\Calculation\CalculationNumberSequencer;
use App\Services\Calculation\CalculationWriter;
use App\Services\Calculation\DiscountInput;
use App\Services\Calculation\SpecialApprovalAssessor;
use App\Support\Calculation\CalculationMethodFreezeDescriptor;
use App\Support\Calculation\EngineProfileRegistry;
use Illuminate\Support\Facades\DB;

/**
 * PO-CALC-TKP-1B: interner Save/Reload-Pfad für Normal-TKP.
 *
 * Umgeht bewusst LiveBookability/Registry-Release (Pair bleibt planned).
 * Nicht für öffentlichen HTTP-Preview/Wizard bestimmt.
 *
 * PO-CALC-TKP-1D1: Nach D1 nur noch Testsupport (Engine-/Persistenz-Fixtures).
 * Der Produktpfad ist {@see CalculationWriter} mit
 * {@see OaTkpWriterPositionResolver}; hier keine neue Fachlogik ergänzen.
 *
 * Rabattzeilen nutzen denselben Persistenzvertrag wie CalculationWriter
 * (`CalculationPositionDiscount` / `CalculationOrderDiscount`).
 */
final class OaTkpCalculationPersister
{
    public function __construct(
        private readonly OaTkpNormalEngine $engine = new OaTkpNormalEngine,
        private readonly CalculationNumberSequencer $numbers = new CalculationNumberSequencer,
        private readonly SpecialApprovalAssessor $specialApproval = new SpecialApprovalAssessor,
    ) {}

    /**
     * @param  list<DiscountInput>  $orderDiscounts
     */
    public function preview(
        OaTkpPositionInput $position,
        array $orderDiscounts = [],
        string $orderDiscountPercent = '0',
        bool $aeEnabled = true,
    ): OaTkpPositionResult {
        return $this->engine->calculatePosition(
            $position,
            $orderDiscounts,
            $orderDiscountPercent,
            $aeEnabled,
        );
    }

    /**
     * @param  list<DiscountInput>  $orderDiscounts
     */
    public function create(
        User $advisor,
        OaTkpPositionInput $position,
        array $orderDiscounts = [],
        string $orderDiscountPercent = '0',
        bool $aeEnabled = true,
    ): Calculation {
        $result = $this->engine->calculatePosition(
            $position,
            $orderDiscounts,
            $orderDiscountPercent,
            $aeEnabled,
        );

        return DB::transaction(function () use ($advisor, $result, $aeEnabled): Calculation {
            [$year, $seq, $number] = $this->numbers->next();

            $limit = $advisor->discount_limit_percent;
            $limitString = $limit === null || $limit === '' ? null : (string) $limit;

            $calculation = new Calculation;
            $calculation->fill([
                'number' => $number,
                'number_year' => $year,
                'number_seq' => $seq,
                'status' => CalculationStatus::Draft,
                'planning_mode' => PlanningMode::Manual,
                'advisor_id' => $advisor->id,
                'order_discount_percent' => $result->effectiveOrderDiscountPercent,
                'ae_enabled' => $aeEnabled,
                'media_gross' => $result->mediaGross,
                'position_discount_total' => $result->positionDiscountAmount,
                'order_discount_total' => $result->orderDiscountAmount,
                'ae_total' => $result->aeAmount,
                'nn_invest' => $result->nnInvest,
                'requires_special_approval' => false,
                'special_approval_reasons' => null,
                'personal_discount_limit_percent' => $limitString,
                'lock_version' => 1,
            ]);
            $calculation->save();

            $this->persistOrderDiscounts($calculation, $result->appliedOrderDiscounts);
            $this->persistPosition($calculation, $result);

            $calculation->load([
                'positions.inventory',
                'positions.discounts',
                'positions.productionLines',
            ]);
            $assessment = $this->specialApproval->assessFromCalculation(
                $calculation,
                $calculation->positions,
            );
            $calculation->requires_special_approval = $assessment->requiresSpecialApproval;
            $calculation->special_approval_reasons = $assessment->reasons === []
                ? null
                : $assessment->reasons;
            $calculation->save();

            return $calculation->fresh([
                'orderDiscounts',
                'positions.discounts',
                'positions.oaPlatformAllocations',
                'positions.oaTargetingSelections',
            ]) ?? $calculation;
        });
    }

    public function reload(Calculation $calculation): Calculation
    {
        return $calculation->fresh([
            'orderDiscounts',
            'positions.discounts',
            'positions.oaPlatformAllocations',
            'positions.oaTargetingSelections',
        ]) ?? $calculation;
    }

    private function persistPosition(Calculation $calculation, OaTkpPositionResult $result): void
    {
        $freeze = new CalculationMethodFreezeDescriptor(
            engineProfileKey: EngineProfileRegistry::PROFILE_OA_TKP,
            calculationMethodKey: 'tkp',
            calculationMethodName: SpotCalculationMethod::Tkp->label(),
            algorithmVersion: OaTkpNormalEngine::ALGORITHM_VERSION,
        );

        $position = new CalculationPosition;
        $position->forceFill([
            'calculation_id' => $calculation->id,
            'inventory_id' => $result->inventoryId,
            'inventory_code' => $result->inventoryCode,
            'inventory_name' => $result->inventoryName,
            'advertising_medium_id' => $result->advertisingMediumId,
            'advertising_medium_code' => $result->advertisingMediumCode,
            'advertising_medium_name' => $result->advertisingMediumName,
            'advertising_category_id' => $result->advertisingCategoryId,
            'advertising_category_key' => $result->advertisingCategoryKey,
            'advertising_category_name' => $result->advertisingCategoryName,
            'kind' => CalculationKind::OaTkp,
            'spot_method' => SpotCalculationMethod::Tkp,
            'engine_profile_key' => $freeze->engineProfileKey,
            'calculation_method_key' => $freeze->calculationMethodKey,
            'calculation_method_name' => $freeze->calculationMethodName,
            'algorithm_version' => $freeze->algorithmVersion,
            'length_seconds' => 0,
            'total_spot_count' => 0,
            'surcharge_percent' => '0',
            'position_discount_percent' => $result->effectivePositionDiscountPercent,
            'ae_percent' => $result->aePercent,
            'is_discountable' => $result->isDiscountable,
            'is_ae_eligible' => $result->isAeEligible,
            'pricing_settlement_mode' => PricingSettlementMode::Normal,
            'impressions' => $result->impressions,
            'basis_tkp' => $result->basisTkp,
            'list_tkp_snapshot' => $result->listTkp,
            'minimum_tkp_snapshot' => $result->minimumTkp,
            'price_list_minimum_tkp_snapshot' => $result->priceListMinimumTkp,
            'final_tkp' => $result->finalTkp,
            'targeting_surcharge_total' => $result->targetingSurchargeTotal,
            'below_minimum_tkp' => $result->belowMinimumTkp,
            'oa_tkp_price_list_id' => $result->oaTkpPriceListId,
            'oa_tkp_price_list_version' => $result->oaTkpPriceListVersion,
            'oa_tkp_price_list_year' => $result->oaTkpPriceListYear,
            'oa_tkp_price_list_revision' => $result->oaTkpPriceListRevision,
            'media_gross' => $result->mediaGross,
            'position_discount_amount' => $result->positionDiscountAmount,
            'order_discount_amount' => $result->orderDiscountAmount,
            'ae_amount' => $result->aeAmount,
            'nn_invest' => $result->nnInvest,
            'effective_pay_factor_percent' => $result->effectivePayFactorPercent,
            'effective_total_discount_percent' => $result->effectiveDiscountPercent,
            'sort' => 10,
        ]);
        $position->save();

        $this->persistPositionDiscounts($position, $result->appliedPositionDiscounts);

        foreach ($result->platformAllocations as $index => $allocation) {
            CalculationPositionOaPlatformAllocation::query()->create([
                'calculation_position_id' => $position->id,
                'oa_platform_id' => $allocation->oaPlatformId,
                'platform_code' => $allocation->platformCode,
                'platform_name' => $allocation->platformName,
                'impressions' => $allocation->impressions,
                'sort' => $allocation->sort > 0 ? $allocation->sort : (($index + 1) * 10),
            ]);
        }

        foreach ($result->targetings as $index => $targeting) {
            CalculationPositionOaTargetingSelection::query()->create([
                'calculation_position_id' => $position->id,
                'oa_targeting_definition_id' => $targeting->oaTargetingDefinitionId,
                'targeting_code' => $targeting->code,
                'targeting_name' => $targeting->name,
                'targeting_category' => $targeting->category,
                'tkp_surcharge_snapshot' => $targeting->tkpSurcharge,
                'minimum_tkp_surcharge_snapshot' => $targeting->minimumTkpSurcharge,
                'targeting_revision_number' => $targeting->revisionNumber,
                'sort' => $targeting->sort > 0 ? $targeting->sort : (($index + 1) * 10),
            ]);
        }
    }

    /**
     * @param  list<DiscountInput>  $discounts
     */
    private function persistPositionDiscounts(CalculationPosition $position, array $discounts): void
    {
        foreach ($discounts as $index => $discount) {
            CalculationPositionDiscount::query()->create([
                'calculation_position_id' => $position->id,
                'type' => $discount->type,
                'custom_label' => $discount->customLabel,
                'percent' => $discount->percent,
                'sort' => $index,
            ]);
        }
    }

    /**
     * @param  list<DiscountInput>  $discounts
     */
    private function persistOrderDiscounts(Calculation $calculation, array $discounts): void
    {
        foreach ($discounts as $index => $discount) {
            CalculationOrderDiscount::query()->create([
                'calculation_id' => $calculation->id,
                'type' => $discount->type,
                'custom_label' => $discount->customLabel,
                'percent' => $discount->percent,
                'sort' => $index,
            ]);
        }
    }
}
