<?php

namespace Tests\Feature\OnlineAudio;

use App\Enums\CalculationKind;
use App\Enums\CalculationStatus;
use App\Enums\DiscountType;
use App\Enums\DispoOrderApprovalKind;
use App\Enums\OaTargetingCategory;
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
use App\Models\OaPlatform;
use App\Models\OaTargetingDefinition;
use App\Models\OaTkpPriceList;
use App\Models\OaTkpPriceListItem;
use App\Models\User;
use App\Services\Calculation\Decimal;
use App\Services\Calculation\DiscountInput;
use App\Services\Calculation\OaTkp\OaTkpCalculationPersister;
use App\Services\Calculation\OaTkp\OaTkpNormalEngine;
use App\Services\Calculation\OaTkp\OaTkpPlatformAllocationInput;
use App\Services\Calculation\OaTkp\OaTkpPositionInput;
use App\Services\Calculation\SpecialApprovalAssessor;
use App\Services\DispoOrder\DispoOrderSnapshotMapper;
use App\Support\Calculation\EngineProfileRegistry;
use Database\Seeders\InitialCatalogMatCoreSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * PO-CALC-TKP-1C1: Under-Min-Sonderfreigabe über SpecialApprovalAssessor.
 */
class PoCalcTkp1c1UnderMinApprovalTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function decimal_under_equal_above_boundaries(): void
    {
        $ctx = $this->seedContext();
        $assessor = app(SpecialApprovalAssessor::class);

        $under = $this->makeOaPosition($ctx, basis: '7.9999', minimum: '8.0000');
        $equal = $this->makeOaPosition($ctx, basis: '8.0000', minimum: '8.0000');
        $above = $this->makeOaPosition($ctx, basis: '8.0001', minimum: '8.0000');

        $calcUnder = $this->wrapPositions($ctx['user'], [$under]);
        $calcEqual = $this->wrapPositions($ctx['user'], [$equal]);
        $calcAbove = $this->wrapPositions($ctx['user'], [$above]);

        $aUnder = $assessor->assessFromCalculation($calcUnder, $calcUnder->positions);
        $aEqual = $assessor->assessFromCalculation($calcEqual, $calcEqual->positions);
        $aAbove = $assessor->assessFromCalculation($calcAbove, $calcAbove->positions);

        $this->assertTrue($aUnder->requiresSpecialApproval);
        $this->assertSame(
            SpecialApprovalReasonCode::OaTkpBasisBelowMinimum->value,
            $aUnder->reasons[0]['code'],
        );
        $this->assertSame('7.9999', $aUnder->reasons[0]['basis_tkp']);
        $this->assertSame('8.0000', $aUnder->reasons[0]['minimum_tkp']);

        $this->assertFalse($aEqual->requiresSpecialApproval);
        $this->assertFalse($aAbove->requiresSpecialApproval);
    }

    #[Test]
    public function targeting_does_not_heal_under_min(): void
    {
        $ctx = $this->seedContext();
        $position = $this->makeOaPosition(
            $ctx,
            basis: '9.0000',
            minimum: '10.0000',
            finalTkp: '12.0000',
            targetingTotal: '3.0000',
        );
        $calc = $this->wrapPositions($ctx['user'], [$position]);

        $assessment = app(SpecialApprovalAssessor::class)
            ->assessFromCalculation($calc, $calc->positions);

        $this->assertTrue($assessment->requiresSpecialApproval);
        $this->assertSame(
            SpecialApprovalReasonCode::OaTkpBasisBelowMinimum->value,
            $assessment->reasons[0]['code'],
        );
    }

    #[Test]
    public function boolean_marker_is_not_source_of_truth_drift_a_and_b(): void
    {
        $ctx = $this->seedContext();
        $assessor = app(SpecialApprovalAssessor::class);

        $driftA = $this->makeOaPosition(
            $ctx,
            basis: '8.0000',
            minimum: '10.0000',
            belowMinimumMarker: false,
        );
        $driftB = $this->makeOaPosition(
            $ctx,
            basis: '10.0000',
            minimum: '8.0000',
            belowMinimumMarker: true,
        );

        $calcA = $this->wrapPositions($ctx['user'], [$driftA]);
        $calcB = $this->wrapPositions($ctx['user'], [$driftB]);

        $a = $assessor->assessFromCalculation($calcA, $calcA->positions);
        $b = $assessor->assessFromCalculation($calcB, $calcB->positions);

        $this->assertTrue($a->requiresSpecialApproval);
        $this->assertSame(
            SpecialApprovalReasonCode::OaTkpBasisBelowMinimum->value,
            $a->reasons[0]['code'],
        );
        $this->assertFalse($b->requiresSpecialApproval);
    }

    #[Test]
    public function missing_snapshot_values_fail_closed(): void
    {
        $ctx = $this->seedContext();
        $position = $this->makeOaPosition($ctx, basis: '8.0000', minimum: '10.0000');
        $position->basis_tkp = null;
        $calc = $this->wrapPositions($ctx['user'], [$position]);

        $this->expectException(ValidationException::class);
        app(SpecialApprovalAssessor::class)->assessFromCalculation($calc, $calc->positions);
    }

    #[Test]
    public function partial_selection_and_known_code_avoids_unattributable(): void
    {
        $ctx = $this->seedContext();
        $under = $this->makeOaPosition($ctx, basis: '7.0000', minimum: '10.0000');
        $normal = $this->makeOaPosition($ctx, basis: '11.0000', minimum: '10.0000');
        $calc = $this->wrapPositions($ctx['user'], [$under, $normal]);

        $assessor = app(SpecialApprovalAssessor::class);
        $full = $assessor->assessFromCalculation($calc, $calc->positions);
        $calc->requires_special_approval = $full->requiresSpecialApproval;
        $calc->special_approval_reasons = $full->reasons;
        $calc->save();
        $calc->refresh()->load('positions');

        $positions = $calc->positions()->orderBy('id')->get();
        $underPos = $positions->first(
            fn (CalculationPosition $p): bool => Decimal::cmp((string) $p->basis_tkp, '7', 4) === 0,
        );
        $normalPos = $positions->first(
            fn (CalculationPosition $p): bool => Decimal::cmp((string) $p->basis_tkp, '11', 4) === 0,
        );
        $this->assertNotNull($underPos);
        $this->assertNotNull($normalPos);

        $onlyNormal = $assessor->assessFromCalculation($calc, collect([$normalPos]));
        $this->assertFalse($onlyNormal->requiresSpecialApproval);
        $this->assertSame([], $onlyNormal->reasons);
        $this->assertFalse(collect($onlyNormal->reasons)->contains(
            fn (array $r): bool => ($r['code'] ?? '') === SpecialApprovalReasonCode::Unattributable->value,
        ));

        $onlyUnder = $assessor->assessFromCalculation($calc, collect([$underPos]));
        $this->assertTrue($onlyUnder->requiresSpecialApproval);
        $this->assertCount(1, $onlyUnder->reasons);
        $this->assertSame(
            SpecialApprovalReasonCode::OaTkpBasisBelowMinimum->value,
            $onlyUnder->reasons[0]['code'],
        );
        $this->assertSame((int) $underPos->id, $onlyUnder->reasons[0]['position_id']);

        $both = $assessor->assessFromCalculation($calc, $positions);
        $this->assertTrue($both->requiresSpecialApproval);
        $this->assertCount(1, $both->reasons);
    }

    #[Test]
    public function two_under_min_positions_yield_two_reasons(): void
    {
        $ctx = $this->seedContext();
        $a = $this->makeOaPosition($ctx, basis: '7.0000', minimum: '10.0000');
        $b = $this->makeOaPosition($ctx, basis: '6.0000', minimum: '10.0000');
        $c = $this->makeOaPosition($ctx, basis: '11.0000', minimum: '10.0000');
        $calc = $this->wrapPositions($ctx['user'], [$a, $b, $c]);

        $selected = $calc->positions->filter(
            fn (CalculationPosition $p): bool => Decimal::cmp((string) $p->basis_tkp, (string) $p->minimum_tkp_snapshot, 4) < 0,
        )->values();
        $this->assertCount(2, $selected);

        $assessment = app(SpecialApprovalAssessor::class)->assessFromCalculation($calc, $selected);
        $this->assertTrue($assessment->requiresSpecialApproval);
        $this->assertCount(2, $assessment->reasons);
        $this->assertTrue(collect($assessment->reasons)->every(
            fn (array $r): bool => ($r['code'] ?? '') === SpecialApprovalReasonCode::OaTkpBasisBelowMinimum->value,
        ));
    }

    #[Test]
    public function mixed_spot_discount_and_oa_under_min_partial_selection(): void
    {
        $ctx = $this->seedContext(discountLimit: '10');
        $oaUnder = $this->makeOaPosition($ctx, basis: '7.0000', minimum: '10.0000');
        $calc = $this->wrapPositions($ctx['user'], [$oaUnder], personalLimit: '10');

        // Spot-ähnliche Position mit Positionsrabatt über Limit (ohne OA-Freeze).
        $spot = new CalculationPosition;
        $spot->forceFill([
            'calculation_id' => $calc->id,
            'inventory_id' => $ctx['inventory']->id,
            'inventory_code' => $ctx['inventory']->code,
            'inventory_name' => $ctx['inventory']->name,
            'advertising_medium_id' => $ctx['medium']->id,
            'advertising_medium_code' => $ctx['medium']->code,
            'advertising_medium_name' => $ctx['medium']->name,
            'kind' => CalculationKind::SpotClassic,
            'spot_method' => SpotCalculationMethod::Average,
            'engine_profile_key' => EngineProfileRegistry::PROFILE_SPOT_CLASSIC,
            'calculation_method_key' => 'average',
            'calculation_method_name' => 'Durchschnitt',
            'algorithm_version' => 'v1',
            'length_seconds' => 20,
            'total_spot_count' => 10,
            'surcharge_percent' => '0',
            'position_discount_percent' => '20.0000',
            'ae_percent' => '0',
            'is_discountable' => true,
            'is_ae_eligible' => false,
            'pricing_settlement_mode' => PricingSettlementMode::Normal,
            'media_gross' => '1000.00',
            'position_discount_amount' => '200.00',
            'order_discount_amount' => '0.00',
            'ae_amount' => '0.00',
            'nn_invest' => '800.00',
            'effective_pay_factor_percent' => '80.0000',
            'effective_total_discount_percent' => '20.0000',
            'sort' => 20,
        ]);
        $spot->save();

        $calc->refresh()->load(['positions.inventory', 'positions.discounts', 'positions.productionLines']);
        $full = app(SpecialApprovalAssessor::class)->assessFromCalculation($calc, $calc->positions);
        $calc->requires_special_approval = true;
        $calc->special_approval_reasons = $full->reasons;
        $calc->save();

        $codes = collect($full->reasons)->pluck('code')->all();
        $this->assertContains(SpecialApprovalReasonCode::OaTkpBasisBelowMinimum->value, $codes);
        $this->assertContains(SpecialApprovalReasonCode::PositionDiscountExceedsLimit->value, $codes);

        $oaOnly = $calc->positions->filter(
            fn (CalculationPosition $p): bool => (string) ($p->engine_profile_key ?? '') === EngineProfileRegistry::PROFILE_OA_TKP,
        )->values();
        $spotOnly = $calc->positions->filter(
            fn (CalculationPosition $p): bool => (int) $p->id === (int) $spot->id,
        )->values();

        $oaAssessment = app(SpecialApprovalAssessor::class)->assessFromCalculation($calc, $oaOnly);
        $this->assertCount(1, $oaAssessment->reasons);
        $this->assertSame(
            SpecialApprovalReasonCode::OaTkpBasisBelowMinimum->value,
            $oaAssessment->reasons[0]['code'],
        );

        $spotAssessment = app(SpecialApprovalAssessor::class)->assessFromCalculation($calc, $spotOnly);
        $this->assertCount(1, $spotAssessment->reasons);
        $this->assertSame(
            SpecialApprovalReasonCode::PositionDiscountExceedsLimit->value,
            $spotAssessment->reasons[0]['code'],
        );
    }

    #[Test]
    public function persister_sets_special_for_under_min_and_not_for_normal(): void
    {
        $ctx = $this->seedContext();
        $persister = app(OaTkpCalculationPersister::class);

        $under = $persister->create(
            $ctx['user'],
            $this->positionInput($ctx, 100_000, '7.0000', '12.0000', '10.0000'),
            aeEnabled: false,
        );
        $this->assertTrue((bool) $under->requires_special_approval);
        $this->assertNotEmpty($under->special_approval_reasons);
        $this->assertSame(
            SpecialApprovalReasonCode::OaTkpBasisBelowMinimum->value,
            $under->special_approval_reasons[0]['code'],
        );
        $this->assertTrue((bool) $under->positions->firstOrFail()->below_minimum_tkp);

        $normal = $persister->create(
            $ctx['user'],
            $this->positionInput($ctx, 100_000, '11.0000', '12.0000', '10.0000'),
            aeEnabled: false,
        );
        $this->assertFalse((bool) $normal->requires_special_approval);
        $this->assertNull($normal->special_approval_reasons);
        $this->assertFalse((bool) $normal->positions->firstOrFail()->below_minimum_tkp);
    }

    #[Test]
    public function persister_combines_discount_and_under_min_reasons(): void
    {
        $ctx = $this->seedContext(discountLimit: '5');
        $calc = app(OaTkpCalculationPersister::class)->create(
            $ctx['user'],
            $this->positionInput(
                $ctx,
                100_000,
                '7.0000',
                '12.0000',
                '10.0000',
                positionDiscounts: [
                    new DiscountInput(DiscountType::Special, '10'),
                ],
            ),
            aeEnabled: false,
        );

        $this->assertSame('5.0000', (string) $calc->personal_discount_limit_percent);
        $codes = collect($calc->special_approval_reasons)->pluck('code')->all();
        $this->assertContains(SpecialApprovalReasonCode::OaTkpBasisBelowMinimum->value, $codes);
        $this->assertContains(SpecialApprovalReasonCode::PositionDiscountExceedsLimit->value, $codes);
    }

    #[Test]
    public function snapshot_isolation_live_price_change_does_not_alter_assessment(): void
    {
        $ctx = $this->seedContext();
        $calc = app(OaTkpCalculationPersister::class)->create(
            $ctx['user'],
            $this->positionInput($ctx, 100_000, '8.0000', '12.0000', '10.0000'),
            aeEnabled: false,
        );
        $this->assertTrue((bool) $calc->requires_special_approval);

        OaTkpPriceListItem::query()
            ->where('oa_tkp_price_list_id', $ctx['list']->id)
            ->update(['minimum_tkp' => '7.0000']);

        $reassess = app(SpecialApprovalAssessor::class)
            ->assessFromCalculation($calc->fresh(['positions.inventory']), $calc->positions);
        $this->assertTrue($reassess->requiresSpecialApproval);
        $this->assertSame('10.0000', $reassess->reasons[0]['minimum_tkp']);

        // Gegentest: alte Calc mit Snapshot min=7 / basis=8 bleibt normal, auch wenn Live=10.
        $normalSnap = $this->makeOaPosition($ctx, basis: '8.0000', minimum: '7.0000');
        $old = $this->wrapPositions($ctx['user'], [$normalSnap]);
        OaTkpPriceListItem::query()
            ->where('oa_tkp_price_list_id', $ctx['list']->id)
            ->update(['minimum_tkp' => '10.0000']);
        $oldAssess = app(SpecialApprovalAssessor::class)
            ->assessFromCalculation($old, $old->positions);
        $this->assertFalse($oldAssess->requiresSpecialApproval);
    }

    #[Test]
    public function dispo_header_mapper_partial_selection_sets_approval_kind(): void
    {
        $ctx = $this->seedContext();
        $under = $this->makeOaPosition($ctx, basis: '7.0000', minimum: '10.0000');
        $normal = $this->makeOaPosition($ctx, basis: '11.0000', minimum: '10.0000');
        $calc = $this->wrapPositions($ctx['user'], [$under, $normal]);
        $full = app(SpecialApprovalAssessor::class)->assessFromCalculation($calc, $calc->positions);
        $calc->requires_special_approval = $full->requiresSpecialApproval;
        $calc->special_approval_reasons = $full->reasons;
        $calc->customer_name = 'Fixture Kunde';
        $calc->save();
        $calc->load(['advisor', 'positions.inventory']);

        $positions = $calc->positions()->orderBy('id')->get();
        $underPos = $positions->first(
            fn (CalculationPosition $p): bool => Decimal::cmp((string) $p->basis_tkp, '7', 4) === 0,
        );
        $normalPos = $positions->first(
            fn (CalculationPosition $p): bool => Decimal::cmp((string) $p->basis_tkp, '11', 4) === 0,
        );
        $this->assertNotNull($underPos);
        $this->assertNotNull($normalPos);

        $mapper = app(DispoOrderSnapshotMapper::class);
        $headerUnder = $mapper->headerFromCalculation($calc, collect([$underPos]));
        $headerNormal = $mapper->headerFromCalculation($calc, collect([$normalPos]));

        $this->assertTrue($headerUnder['requires_special_approval']);
        $this->assertSame(DispoOrderApprovalKind::Special->value, $headerUnder['approval_kind']);
        $this->assertSame(
            SpecialApprovalReasonCode::OaTkpBasisBelowMinimum->value,
            $headerUnder['special_approval_reasons'][0]['code'],
        );

        $this->assertFalse($headerNormal['requires_special_approval']);
        $this->assertSame(DispoOrderApprovalKind::Regular->value, $headerNormal['approval_kind']);
        $this->assertSame([], $headerNormal['special_approval_reasons']);
    }

    #[Test]
    public function registry_remains_planned(): void
    {
        $this->assertSame(
            'planned',
            EngineProfileRegistry::pairStatus(EngineProfileRegistry::PROFILE_OA_TKP, 'tkp')->value,
        );
        $this->assertNull(
            EngineProfileRegistry::currentReleasedVersion(EngineProfileRegistry::PROFILE_OA_TKP, 'tkp'),
        );
        $this->assertSame(
            'implemented',
            EngineProfileRegistry::statusForVersion(EngineProfileRegistry::PROFILE_OA_TKP, 'tkp', 'v1')->value,
        );
    }

    /**
     * @return array{
     *     user: User,
     *     medium: AdvertisingMedium,
     *     inventory: Inventory,
     *     list: OaTkpPriceList,
     *     targeting: OaTargetingDefinition,
     *     platform: OaPlatform
     * }
     */
    private function seedContext(?string $discountLimit = null): array
    {
        $this->seed(InitialCatalogMatCoreSeeder::class);
        $user = User::factory()->role(Role::Sales)->create([
            'discount_limit_percent' => $discountLimit,
        ]);
        $medium = AdvertisingMedium::query()->where('code', 'pre_stream')->firstOrFail();
        $inventory = Inventory::query()->where('code', 'inv_more_kombi_online_audio')->firstOrFail();
        $list = $this->createActivePriceList(
            2026,
            1,
            (int) $medium->id,
            (int) $inventory->id,
            '12.0000',
            '8.0000',
        );
        $targeting = OaTargetingDefinition::query()->create([
            'code' => 'fixture_tgt_c1',
            'name' => 'Fixture Targeting C1',
            'category' => OaTargetingCategory::Technisch,
            'tkp_surcharge' => '2.0000',
            'revision_number' => 1,
            'is_active' => true,
        ]);
        $platform = OaPlatform::query()->create([
            'code' => 'fixture_plat_c1',
            'name' => 'Fixture Platform C1',
            'is_active' => true,
            'sort' => 1,
            'is_adserver_relevant' => false,
        ]);

        return compact('user', 'medium', 'inventory', 'list', 'targeting', 'platform');
    }

    /**
     * @param  array{
     *     medium: AdvertisingMedium,
     *     inventory: Inventory,
     *     list: OaTkpPriceList,
     *     platform: OaPlatform
     * }  $ctx
     */
    private function makeOaPosition(
        array $ctx,
        string $basis,
        string $minimum,
        string $finalTkp = '',
        string $targetingTotal = '0.0000',
        ?bool $belowMinimumMarker = null,
    ): CalculationPosition {
        if ($finalTkp === '') {
            $finalTkp = $basis;
        }
        $below = $belowMinimumMarker ?? (Decimal::cmp($basis, $minimum, 4) < 0);

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
            'pricing_settlement_mode' => PricingSettlementMode::Normal,
            'impressions' => 100_000,
            'basis_tkp' => $basis,
            'list_tkp_snapshot' => '12.0000',
            'minimum_tkp_snapshot' => $minimum,
            'final_tkp' => $finalTkp,
            'targeting_surcharge_total' => $targetingTotal,
            'below_minimum_tkp' => $below,
            'oa_tkp_price_list_id' => $ctx['list']->id,
            'oa_tkp_price_list_version' => $ctx['list']->version,
            'oa_tkp_price_list_year' => $ctx['list']->year,
            'oa_tkp_price_list_revision' => $ctx['list']->revision_number,
            'media_gross' => '1000.00',
            'position_discount_amount' => '0.00',
            'order_discount_amount' => '0.00',
            'ae_amount' => '0.00',
            'nn_invest' => '1000.00',
            'effective_pay_factor_percent' => '100.0000',
            'effective_total_discount_percent' => '0.0000',
            'sort' => 10,
        ]);

        return $position;
    }

    /**
     * @param  list<CalculationPosition>  $positions
     */
    private function wrapPositions(User $user, array $positions, ?string $personalLimit = null): Calculation
    {
        $calculation = Calculation::query()->create([
            'number' => 'C1-'.uniqid(),
            'number_year' => 2026,
            'number_seq' => random_int(1, 999999),
            'status' => CalculationStatus::Draft,
            'planning_mode' => PlanningMode::Manual,
            'advisor_id' => $user->id,
            'customer_name' => 'Fixture Kunde',
            'order_discount_percent' => '0',
            'ae_enabled' => false,
            'media_gross' => '0',
            'position_discount_total' => '0',
            'order_discount_total' => '0',
            'ae_total' => '0',
            'nn_invest' => '0',
            'requires_special_approval' => false,
            'personal_discount_limit_percent' => $personalLimit,
            'lock_version' => 1,
        ]);

        foreach ($positions as $index => $position) {
            $position->calculation_id = $calculation->id;
            $position->sort = ($index + 1) * 10;
            $position->save();
        }

        return $calculation->fresh([
            'positions.inventory',
            'positions.discounts',
            'positions.productionLines',
            'advisor',
        ]) ?? $calculation;
    }

    /**
     * @param  array{
     *     medium: AdvertisingMedium,
     *     inventory: Inventory,
     *     list: OaTkpPriceList,
     *     platform: OaPlatform
     * }  $ctx
     * @param  list<DiscountInput>  $positionDiscounts
     */
    private function positionInput(
        array $ctx,
        int $impressions,
        string $basisTkp,
        string $listTkp,
        string $minimumTkp,
        array $positionDiscounts = [],
    ): OaTkpPositionInput {
        return new OaTkpPositionInput(
            inventoryId: (int) $ctx['inventory']->id,
            inventoryCode: (string) $ctx['inventory']->code,
            inventoryName: (string) $ctx['inventory']->name,
            advertisingMediumId: (int) $ctx['medium']->id,
            advertisingMediumCode: (string) $ctx['medium']->code,
            advertisingMediumName: (string) $ctx['medium']->name,
            impressions: $impressions,
            basisTkp: $basisTkp,
            listTkp: $listTkp,
            minimumTkp: $minimumTkp,
            oaTkpPriceListId: (int) $ctx['list']->id,
            oaTkpPriceListVersion: (string) $ctx['list']->version,
            oaTkpPriceListYear: (int) $ctx['list']->year,
            oaTkpPriceListRevision: (int) $ctx['list']->revision_number,
            platformAllocations: [
                new OaTkpPlatformAllocationInput(
                    platformCode: (string) $ctx['platform']->code,
                    platformName: (string) $ctx['platform']->name,
                    impressions: $impressions,
                    oaPlatformId: (int) $ctx['platform']->id,
                ),
            ],
            targetings: [],
            positionDiscounts: $positionDiscounts,
            aePercent: '0',
            isDiscountable: true,
            isAeEligible: false,
        );
    }

    private function createActivePriceList(
        int $year,
        int $revision,
        int $mediumId,
        int $inventoryId,
        string $listTkp,
        string $minimumTkp,
    ): OaTkpPriceList {
        $list = OaTkpPriceList::query()->create([
            'year' => $year,
            'name' => "Fixture C1 {$year}.{$revision}",
            'version' => "{$year}.{$revision}",
            'revision_number' => $revision,
            'lock_version' => 1,
            'status' => PriceListStatus::Active,
            'published_at' => now(),
        ]);

        OaTkpPriceListItem::query()->create([
            'oa_tkp_price_list_id' => $list->id,
            'advertising_medium_id' => $mediumId,
            'inventory_id' => $inventoryId,
            'list_tkp' => $listTkp,
            'minimum_tkp' => $minimumTkp,
        ]);

        return $list;
    }
}
