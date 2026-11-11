<?php

namespace Tests\Feature\OnlineAudio;

use App\Enums\CalculationKind;
use App\Enums\CalculationStatus;
use App\Enums\PlanningMode;
use App\Enums\PriceListStatus;
use App\Enums\PricingSettlementMode;
use App\Enums\Role;
use App\Enums\SpecialApprovalReasonCode;
use App\Enums\SpotCalculationMethod;
use App\Models\AdvertisingMedium;
use App\Models\Calculation;
use App\Models\CalculationPosition;
use App\Models\Inventory;
use App\Models\OaTkpPriceList;
use App\Models\OaTkpPriceListItem;
use App\Models\User;
use App\Services\Calculation\Decimal;
use App\Services\Calculation\OaTkp\OaTkpNormalEngine;
use App\Services\Calculation\SpecialApprovalAssessor;
use App\Support\Calculation\EngineProfileRegistry;
use Database\Seeders\InitialCatalogMatCoreSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * PO-CALC-TKP-OA003-1: Assessor-Vertrag für gespeicherte OA-Festpreispositionen.
 *
 * Keine HTTP-/Writer-Freigabe – Fixtures werden direkt persistiert.
 */
class PoCalcTkpOa0031ApprovalTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function normal_oa_above_minimum_has_no_oa003_reason(): void
    {
        $ctx = $this->seedContext();
        $position = $this->makeOaPosition(
            $ctx,
            settlement: PricingSettlementMode::Normal,
            basis: '50.0000',
            minimum: '20.0000',
            finalTkp: '50.0000',
            priceListMinimum: '20.0000',
        );
        $calc = $this->wrapPositions($ctx['user'], [$position]);

        $assessment = app(SpecialApprovalAssessor::class)
            ->assessFromCalculation($calc, $calc->positions);

        $this->assertFalse($assessment->requiresSpecialApproval);
        $codes = array_column($assessment->reasons, 'code');
        $this->assertNotContains(SpecialApprovalReasonCode::OaTkpFixedPrice->value, $codes);
        $this->assertNotContains(SpecialApprovalReasonCode::OaTkpBasisBelowMinimum->value, $codes);
    }

    #[Test]
    public function normal_oa_under_min_keeps_only_existing_reason(): void
    {
        $ctx = $this->seedContext();
        $position = $this->makeOaPosition(
            $ctx,
            settlement: PricingSettlementMode::Normal,
            basis: '15.0000',
            minimum: '20.0000',
            finalTkp: '15.0000',
            priceListMinimum: '20.0000',
        );
        $calc = $this->wrapPositions($ctx['user'], [$position]);

        $assessment = app(SpecialApprovalAssessor::class)
            ->assessFromCalculation($calc, $calc->positions);

        $this->assertTrue($assessment->requiresSpecialApproval);
        $codes = array_column($assessment->reasons, 'code');
        $this->assertSame(
            [SpecialApprovalReasonCode::OaTkpBasisBelowMinimum->value],
            $codes,
        );
    }

    #[Test]
    public function fixed_oa_above_minimum_requires_fixed_price_reason_only(): void
    {
        $ctx = $this->seedContext();
        $position = $this->makeOaPosition(
            $ctx,
            settlement: PricingSettlementMode::FixedPrice,
            basis: '50.0000',
            minimum: '20.0000',
            finalTkp: '50.0000',
            priceListMinimum: '20.0000',
            fixedPriceNn: '5000.00',
        );
        $calc = $this->wrapPositions($ctx['user'], [$position]);

        $assessment = app(SpecialApprovalAssessor::class)
            ->assessFromCalculation($calc, $calc->positions);

        $this->assertTrue($assessment->requiresSpecialApproval);
        $codes = array_column($assessment->reasons, 'code');
        $this->assertSame(
            [SpecialApprovalReasonCode::OaTkpFixedPrice->value],
            $codes,
        );
        $this->assertSame('5000.00', $assessment->reasons[0]['fixed_price_nn']);
    }

    #[Test]
    public function fixed_oa_under_min_emits_both_reasons(): void
    {
        $ctx = $this->seedContext();
        $position = $this->makeOaPosition(
            $ctx,
            settlement: PricingSettlementMode::FixedPrice,
            basis: '15.0000',
            minimum: '20.0000',
            finalTkp: '15.0000',
            priceListMinimum: '20.0000',
            fixedPriceNn: '1500.00',
        );
        $calc = $this->wrapPositions($ctx['user'], [$position]);

        $assessment = app(SpecialApprovalAssessor::class)
            ->assessFromCalculation($calc, $calc->positions);

        $this->assertTrue($assessment->requiresSpecialApproval);
        $codes = array_column($assessment->reasons, 'code');
        $this->assertSame(
            [
                SpecialApprovalReasonCode::OaTkpFixedPrice->value,
                SpecialApprovalReasonCode::OaTkpBasisBelowMinimum->value,
            ],
            $codes,
        );
    }

    #[Test]
    public function partial_selection_keeps_reason_on_selected_fixed_position(): void
    {
        $ctx = $this->seedContext();
        $fixed = $this->makeOaPosition(
            $ctx,
            settlement: PricingSettlementMode::FixedPrice,
            basis: '50.0000',
            minimum: '20.0000',
            finalTkp: '50.0000',
            priceListMinimum: '20.0000',
            fixedPriceNn: '5000.00',
        );
        $normal = $this->makeOaPosition(
            $ctx,
            settlement: PricingSettlementMode::Normal,
            basis: '50.0000',
            minimum: '20.0000',
            finalTkp: '50.0000',
            priceListMinimum: '20.0000',
        );
        $calc = $this->wrapPositions($ctx['user'], [$fixed, $normal]);
        $positions = $calc->positions()->orderBy('sort')->get();

        $onlyFixed = app(SpecialApprovalAssessor::class)->assessFromCalculation(
            $calc,
            $positions->slice(0, 1)->values(),
        );
        $onlyNormal = app(SpecialApprovalAssessor::class)->assessFromCalculation(
            $calc,
            $positions->slice(1)->values(),
        );

        $this->assertTrue($onlyFixed->requiresSpecialApproval);
        $this->assertSame(
            SpecialApprovalReasonCode::OaTkpFixedPrice->value,
            $onlyFixed->reasons[0]['code'],
        );
        $this->assertSame((int) $positions[0]->id, (int) $onlyFixed->reasons[0]['position_id']);
        $this->assertFalse($onlyNormal->requiresSpecialApproval);
    }

    #[Test]
    public function stored_fixed_price_reason_is_not_unattributable(): void
    {
        $ctx = $this->seedContext();
        $position = $this->makeOaPosition(
            $ctx,
            settlement: PricingSettlementMode::FixedPrice,
            basis: '50.0000',
            minimum: '20.0000',
            finalTkp: '50.0000',
            priceListMinimum: '20.0000',
            fixedPriceNn: '5000.00',
        );
        $calc = $this->wrapPositions($ctx['user'], [$position]);
        $assessment = app(SpecialApprovalAssessor::class)
            ->assessFromCalculation($calc, $calc->positions);

        $calc->requires_special_approval = true;
        $calc->special_approval_reasons = $assessment->reasons;
        $calc->save();

        $reassess = app(SpecialApprovalAssessor::class)
            ->assessFromCalculation($calc->fresh(['positions.inventory']), $calc->positions);

        $codes = array_column($reassess->reasons, 'code');
        $this->assertContains(SpecialApprovalReasonCode::OaTkpFixedPrice->value, $codes);
        $this->assertNotContains(SpecialApprovalReasonCode::Unattributable->value, $codes);
    }

    /**
     * @return array{user: User, medium: AdvertisingMedium, inventory: Inventory, list: OaTkpPriceList}
     */
    private function seedContext(): array
    {
        $this->seed(InitialCatalogMatCoreSeeder::class);
        $user = User::factory()->role(Role::Sales)->create([
            'discount_limit_percent' => '10',
        ]);
        $medium = AdvertisingMedium::query()->where('code', 'pre_stream')->firstOrFail();
        $inventory = Inventory::query()->where('code', 'inv_more_kombi_online_audio')->firstOrFail();
        $list = OaTkpPriceList::query()->create([
            'year' => 2026,
            'name' => 'Fixture OA003-1 2026.1',
            'version' => '2026.oa0031',
            'status' => PriceListStatus::Active,
            'revision_number' => 1,
            'lock_version' => 1,
            'published_at' => now(),
        ]);
        OaTkpPriceListItem::query()->create([
            'oa_tkp_price_list_id' => $list->id,
            'advertising_medium_id' => $medium->id,
            'inventory_id' => $inventory->id,
            'list_tkp' => '80.0000',
            'minimum_tkp' => '20.0000',
        ]);

        return compact('user', 'medium', 'inventory', 'list');
    }

    /**
     * @param  array{medium: AdvertisingMedium, inventory: Inventory, list: OaTkpPriceList}  $ctx
     */
    private function makeOaPosition(
        array $ctx,
        PricingSettlementMode $settlement,
        string $basis,
        string $minimum,
        string $finalTkp,
        string $priceListMinimum,
        ?string $fixedPriceNn = null,
    ): CalculationPosition {
        $below = Decimal::cmp($finalTkp, $minimum, 4) < 0;
        $position = new CalculationPosition;
        $position->forceFill([
            'inventory_id' => $ctx['inventory']->id,
            'inventory_code' => $ctx['inventory']->code,
            'inventory_name' => $ctx['inventory']->name,
            'advertising_medium_id' => $ctx['medium']->id,
            'advertising_medium_code' => $ctx['medium']->code,
            'advertising_medium_name' => $ctx['medium']->name,
            'kind' => CalculationKind::OaTkp,
            'spot_method' => SpotCalculationMethod::Tkp,
            'engine_profile_key' => EngineProfileRegistry::PROFILE_OA_TKP,
            'calculation_method_key' => 'tkp',
            'calculation_method_name' => SpotCalculationMethod::Tkp->label(),
            'algorithm_version' => OaTkpNormalEngine::ALGORITHM_VERSION,
            'length_seconds' => 0,
            'total_spot_count' => 0,
            'surcharge_percent' => '0',
            'position_discount_percent' => '0',
            'ae_percent' => '0',
            'is_discountable' => true,
            'is_ae_eligible' => false,
            'pricing_settlement_mode' => $settlement,
            'fixed_price_nn' => $fixedPriceNn,
            'impressions' => 100_000,
            'basis_tkp' => $basis,
            'list_tkp_snapshot' => '80.0000',
            'minimum_tkp_snapshot' => $minimum,
            'price_list_minimum_tkp_snapshot' => $priceListMinimum,
            'final_tkp' => $finalTkp,
            'targeting_surcharge_total' => '0.0000',
            'below_minimum_tkp' => $below,
            'oa_tkp_price_list_id' => $ctx['list']->id,
            'oa_tkp_price_list_version' => $ctx['list']->version,
            'oa_tkp_price_list_year' => $ctx['list']->year,
            'oa_tkp_price_list_revision' => $ctx['list']->revision_number,
            'media_gross' => '8000.00',
            'position_discount_amount' => '0.00',
            'order_discount_amount' => '0.00',
            'ae_amount' => '0.00',
            'nn_invest' => $fixedPriceNn ?? '5000.00',
            'effective_pay_factor_percent' => '100.0000',
            'effective_total_discount_percent' => '0.0000',
            'sort' => 10,
        ]);

        return $position;
    }

    /**
     * @param  list<CalculationPosition>  $positions
     */
    private function wrapPositions(User $user, array $positions): Calculation
    {
        $calculation = Calculation::query()->create([
            'number' => 'OA0031-'.uniqid(),
            'number_year' => 2026,
            'number_seq' => random_int(1, 999999),
            'status' => CalculationStatus::Draft,
            'planning_mode' => PlanningMode::Manual,
            'advisor_id' => $user->id,
            'customer_name' => 'Fixture Kunde OA003-1',
            'order_discount_percent' => '0',
            'ae_enabled' => false,
            'media_gross' => '0',
            'position_discount_total' => '0',
            'order_discount_total' => '0',
            'ae_total' => '0',
            'nn_invest' => '0',
            'requires_special_approval' => false,
            'personal_discount_limit_percent' => '10',
            'lock_version' => 1,
        ]);

        foreach ($positions as $index => $position) {
            $position->forceFill([
                'calculation_id' => $calculation->getKey(),
                'sort' => ($index + 1) * 10,
            ]);
            $position->save();
        }

        return $calculation->fresh([
            'positions.inventory',
            'positions.discounts',
            'positions.productionLines',
            'advisor',
        ]) ?? $calculation;
    }
}
