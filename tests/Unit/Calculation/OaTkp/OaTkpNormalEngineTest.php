<?php

namespace Tests\Unit\Calculation\OaTkp;

use App\Enums\DiscountType;
use App\Services\Calculation\DiscountInput;
use App\Services\Calculation\OaTkp\OaTkpNormalEngine;
use App\Services\Calculation\OaTkp\OaTkpPlatformAllocationInput;
use App\Services\Calculation\OaTkp\OaTkpPositionInput;
use App\Services\Calculation\OaTkp\OaTkpTargetingSelectionInput;
use App\Services\OnlineAudio\Import\OaTkpImportDecimal;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * PO-CALC-TKP-1B: Formel + Commercial + Allocation/Targeting-Invarianten.
 */
class OaTkpNormalEngineTest extends TestCase
{
    private OaTkpNormalEngine $engine;

    protected function setUp(): void
    {
        parent::setUp();
        $this->engine = new OaTkpNormalEngine;
    }

    #[Test]
    public function normal_tkp_with_targetings_and_commercial(): void
    {
        $input = $this->baseInput(
            impressions: 100_000,
            basisTkp: '10.0000',
            targetings: [
                new OaTkpTargetingSelectionInput('fixture_a', 'A', 'technisch', '2.0000'),
                new OaTkpTargetingSelectionInput('fixture_b', 'B', 'dmp', '1.5000'),
            ],
            positionDiscountPercent: '10',
            aePercent: '15',
        );

        $result = $this->engine->calculatePosition(
            $input,
            orderDiscounts: [new DiscountInput(DiscountType::Quantity, '5')],
            orderDiscountPercent: '5',
            aeEnabled: true,
        );

        $this->assertSame('13.5000', $result->finalTkp);
        $this->assertSame('3.5000', $result->targetingSurchargeTotal);
        $this->assertSame('1350.00', $result->mediaGross);
        // 1350 → −10% Pos = 1215 → −5% Order = 1154.25 → −15% AE = 173.14 → N/N = 981.11
        $this->assertSame('135.00', $result->positionDiscountAmount);
        $this->assertSame('1215.00', $result->afterPositionDiscount);
        $this->assertSame('60.75', $result->orderDiscountAmount);
        $this->assertSame('1154.25', $result->afterOrderDiscount);
        $this->assertSame('173.14', $result->aeAmount);
        $this->assertSame('981.11', $result->nnInvest);
        // Payfaktor aus interner Präzision (vor Money-Rundung): 981.1125/1350×100
        $this->assertSame('72.6750', $result->effectivePayFactorPercent);
        $this->assertFalse($result->belowMinimumTkp);
    }

    #[Test]
    public function without_targeting_invest_is_impressions_times_basis(): void
    {
        $result = $this->engine->calculatePosition($this->baseInput(
            impressions: 100_000,
            basisTkp: '10.0000',
            aePercent: '0',
            isAeEligible: false,
        ), aeEnabled: true);

        $this->assertSame('10.0000', $result->finalTkp);
        $this->assertSame('0.0000', $result->targetingSurchargeTotal);
        $this->assertSame('1000.00', $result->mediaGross);
        $this->assertSame('1000.00', $result->nnInvest);
        $this->assertSame('100.0000', $result->effectivePayFactorPercent);
    }

    #[Test]
    public function empty_platform_allocations_are_rejected(): void
    {
        $this->expectException(ValidationException::class);
        $this->engine->calculatePosition($this->baseInput(
            impressions: 100_000,
            basisTkp: '10.0000',
            allocations: [],
        ));
    }

    #[Test]
    public function zero_impression_allocation_is_rejected(): void
    {
        $this->expectException(ValidationException::class);
        $this->engine->calculatePosition($this->baseInput(
            impressions: 100_000,
            basisTkp: '10.0000',
            allocations: [
                new OaTkpPlatformAllocationInput('fixture_a', 'A', 100_000),
                new OaTkpPlatformAllocationInput('fixture_b', 'B', 0),
            ],
        ));
    }

    #[Test]
    public function platform_allocation_sum_must_match_impressions(): void
    {
        $ok = $this->baseInput(
            impressions: 100_000,
            basisTkp: '10.0000',
            allocations: [
                new OaTkpPlatformAllocationInput('fixture_a', 'A', 60_000),
                new OaTkpPlatformAllocationInput('fixture_b', 'B', 40_000),
            ],
        );
        $this->assertSame(100_000, $this->engine->calculatePosition($ok)->impressions);

        try {
            $this->engine->calculatePosition($this->baseInput(
                impressions: 100_000,
                basisTkp: '10.0000',
                allocations: [
                    new OaTkpPlatformAllocationInput('fixture_a', 'A', 60_000),
                    new OaTkpPlatformAllocationInput('fixture_b', 'B', 39_999),
                ],
            ));
            $this->fail('Expected ValidationException for sum too small');
        } catch (ValidationException) {
            // expected
        }

        $this->expectException(ValidationException::class);
        $this->engine->calculatePosition($this->baseInput(
            impressions: 100_000,
            basisTkp: '10.0000',
            allocations: [
                new OaTkpPlatformAllocationInput('fixture_a', 'A', 60_000),
                new OaTkpPlatformAllocationInput('fixture_b', 'B', 40_001),
            ],
        ));
    }

    #[Test]
    public function duplicate_platform_code_is_rejected(): void
    {
        $this->expectException(ValidationException::class);
        $this->engine->calculatePosition($this->baseInput(
            impressions: 100_000,
            basisTkp: '10.0000',
            allocations: [
                new OaTkpPlatformAllocationInput('fixture_a', 'A', 60_000),
                new OaTkpPlatformAllocationInput('fixture_a', 'A again', 40_000),
            ],
        ));
    }

    #[Test]
    public function duplicate_targeting_code_is_rejected(): void
    {
        $this->expectException(ValidationException::class);
        $this->engine->calculatePosition($this->baseInput(
            impressions: 100_000,
            basisTkp: '10.0000',
            targetings: [
                new OaTkpTargetingSelectionInput('geo', 'Geo', 'technisch', '2.0000'),
                new OaTkpTargetingSelectionInput('geo', 'Geo again', 'technisch', '2.0000'),
            ],
        ));
    }

    #[Test]
    public function ae_requires_enabled_and_eligible(): void
    {
        $base = $this->baseInput(
            impressions: 100_000,
            basisTkp: '10.0000',
            aePercent: '15',
            isAeEligible: true,
        );

        $disabled = $this->engine->calculatePosition($base, aeEnabled: false);
        $this->assertSame('0.00', $disabled->aeAmount);
        $this->assertSame('1000.00', $disabled->nnInvest);
        $this->assertSame('100.0000', $disabled->effectivePayFactorPercent);
        $this->assertSame('0', $disabled->aePercent);

        $ineligible = $this->engine->calculatePosition(
            $this->baseInput(
                impressions: 100_000,
                basisTkp: '10.0000',
                aePercent: '15',
                isAeEligible: false,
            ),
            aeEnabled: true,
        );
        $this->assertSame('0.00', $ineligible->aeAmount);
        $this->assertSame('1000.00', $ineligible->nnInvest);
        $this->assertSame('0', $ineligible->aePercent);

        $bothOff = $this->engine->calculatePosition(
            $this->baseInput(
                impressions: 100_000,
                basisTkp: '10.0000',
                aePercent: '15',
                isAeEligible: false,
            ),
            aeEnabled: false,
        );
        $this->assertSame('0.00', $bothOff->aeAmount);

        $bothOn = $this->engine->calculatePosition($base, aeEnabled: true);
        $this->assertSame('150.00', $bothOn->aeAmount);
        $this->assertSame('850.00', $bothOn->nnInvest);
        $this->assertSame('85.0000', $bothOn->effectivePayFactorPercent);
        $this->assertSame('15', $bothOn->aePercent);
    }

    #[Test]
    public function below_minimum_is_marked_but_still_calculable(): void
    {
        $result = $this->engine->calculatePosition($this->baseInput(
            impressions: 10_000,
            basisTkp: '7.0000',
            listTkp: '12.0000',
            minimumTkp: '8.0000',
            isAeEligible: false,
        ));

        $this->assertTrue($result->belowMinimumTkp);
        $this->assertSame('7.0000', $result->basisTkp);
        $this->assertSame('8.0000', $result->minimumTkp);
        $this->assertSame('8.0000', $result->priceListMinimumTkp);
        $this->assertSame('70.00', $result->mediaGross);
    }

    /** PO-CALC-TKP-1D4c-4b FD: ohne Targeting → final = basis, effective = Grundminimum. */
    #[Test]
    public function d4c4b_without_targeting_compares_final_to_list_minimum(): void
    {
        $above = $this->engine->calculatePosition($this->baseInput(
            impressions: 10_000,
            basisTkp: '15.0000',
            listTkp: '56.0000',
            minimumTkp: '14.0000',
            isAeEligible: false,
        ));
        $this->assertSame('15.0000', $above->finalTkp);
        $this->assertSame('14.0000', $above->minimumTkp);
        $this->assertFalse($above->belowMinimumTkp);

        $equal = $this->engine->calculatePosition($this->baseInput(
            impressions: 10_000,
            basisTkp: '14.0000',
            listTkp: '56.0000',
            minimumTkp: '14.0000',
            isAeEligible: false,
        ));
        $this->assertFalse($equal->belowMinimumTkp);

        $under = $this->engine->calculatePosition($this->baseInput(
            impressions: 10_000,
            basisTkp: '13.9999',
            listTkp: '56.0000',
            minimumTkp: '14.0000',
            isAeEligible: false,
        ));
        $this->assertTrue($under->belowMinimumTkp);
    }

    /** Pre-Stream 56/14 + DMP 25/5. */
    #[Test]
    public function d4c4b_dmp_only_builds_effective_minimum(): void
    {
        $result = $this->engine->calculatePosition($this->baseInput(
            impressions: 10_000,
            basisTkp: '20.0000',
            listTkp: '56.0000',
            minimumTkp: '14.0000',
            targetings: [
                new OaTkpTargetingSelectionInput('dmp', 'DMP', 'dmp', '25.0000', minimumTkpSurcharge: '5.0000'),
            ],
            isAeEligible: false,
        ));

        $this->assertSame('45.0000', $result->finalTkp);
        $this->assertSame('25.0000', $result->targetingSurchargeTotal);
        $this->assertSame('5.0000', $result->targetingMinimumSurchargeTotal);
        $this->assertSame('19.0000', $result->minimumTkp);
        $this->assertSame('14.0000', $result->priceListMinimumTkp);
        $this->assertFalse($result->belowMinimumTkp);
    }

    /** Pre-Stream 56/14 + Geo 15/3. */
    #[Test]
    public function d4c4b_geo_only_builds_effective_minimum(): void
    {
        $result = $this->engine->calculatePosition($this->baseInput(
            impressions: 10_000,
            basisTkp: '12.0000',
            listTkp: '56.0000',
            minimumTkp: '14.0000',
            targetings: [
                new OaTkpTargetingSelectionInput('geo', 'Geo', 'technisch', '15.0000', minimumTkpSurcharge: '3.0000'),
            ],
            isAeEligible: false,
        ));

        $this->assertSame('27.0000', $result->finalTkp);
        $this->assertSame('17.0000', $result->minimumTkp);
        $this->assertFalse($result->belowMinimumTkp);
    }

    /** Pre-Stream 56/14 + DMP + Geo; Aufschläge je genau einmal. */
    #[Test]
    public function d4c4b_dmp_and_geo_sum_list_and_minimum_once(): void
    {
        $result = $this->engine->calculatePosition($this->baseInput(
            impressions: 10_000,
            basisTkp: '10.0000',
            listTkp: '56.0000',
            minimumTkp: '14.0000',
            targetings: [
                new OaTkpTargetingSelectionInput('dmp', 'DMP', 'dmp', '25.0000', minimumTkpSurcharge: '5.0000'),
                new OaTkpTargetingSelectionInput('geo', 'Geo', 'technisch', '15.0000', minimumTkpSurcharge: '3.0000'),
            ],
            isAeEligible: false,
        ));

        $this->assertSame('50.0000', $result->finalTkp);
        $this->assertSame('40.0000', $result->targetingSurchargeTotal);
        $this->assertSame('8.0000', $result->targetingMinimumSurchargeTotal);
        $this->assertSame('22.0000', $result->minimumTkp);
        $this->assertFalse($result->belowMinimumTkp);

        // final exakt auf effective: basis 0 nicht erlaubt → basis so dass final = 22
        $onMin = $this->engine->calculatePosition($this->baseInput(
            impressions: 10_000,
            basisTkp: '1.0000',
            listTkp: '56.0000',
            minimumTkp: '14.0000',
            targetings: [
                new OaTkpTargetingSelectionInput('dmp', 'DMP', 'dmp', '10.5000', minimumTkpSurcharge: '4.0000'),
                new OaTkpTargetingSelectionInput('geo', 'Geo', 'technisch', '10.5000', minimumTkpSurcharge: '4.0000'),
            ],
            isAeEligible: false,
        ));
        $this->assertSame('22.0000', $onMin->finalTkp);
        $this->assertSame('22.0000', $onMin->minimumTkp);
        $this->assertFalse($onMin->belowMinimumTkp);

        $trulyUnder = $this->engine->calculatePosition($this->baseInput(
            impressions: 10_000,
            basisTkp: '1.0000',
            listTkp: '56.0000',
            minimumTkp: '14.0000',
            targetings: [
                new OaTkpTargetingSelectionInput('dmp', 'DMP', 'dmp', '10.0000', minimumTkpSurcharge: '5.0000'),
            ],
            isAeEligible: false,
        ));
        $this->assertSame('11.0000', $trulyUnder->finalTkp);
        $this->assertSame('19.0000', $trulyUnder->minimumTkp);
        $this->assertTrue($trulyUnder->belowMinimumTkp);
    }

    /**
     * FD-D4c-4b Fall 8: Basis unter Grundminimum, Final aber ≥ effektives Minimum
     * → keine Sonderfreigabe-Markierung.
     */
    #[Test]
    public function d4c4b_basis_below_list_min_but_final_above_effective_is_not_under_min(): void
    {
        $result = $this->engine->calculatePosition($this->baseInput(
            impressions: 10_000,
            basisTkp: '12.0000',
            listTkp: '56.0000',
            minimumTkp: '14.0000',
            targetings: [
                new OaTkpTargetingSelectionInput('dmp', 'DMP', 'dmp', '25.0000', minimumTkpSurcharge: '5.0000'),
            ],
            isAeEligible: false,
        ));

        $this->assertTrue(OaTkpImportDecimal::compare($result->basisTkp, $result->priceListMinimumTkp) < 0);
        $this->assertSame('37.0000', $result->finalTkp);
        $this->assertSame('19.0000', $result->minimumTkp);
        $this->assertFalse($result->belowMinimumTkp);
    }

    #[Test]
    public function d4c4b_missing_minimum_surcharge_defaults_to_zero(): void
    {
        $result = $this->engine->calculatePosition($this->baseInput(
            impressions: 10_000,
            basisTkp: '10.0000',
            listTkp: '56.0000',
            minimumTkp: '14.0000',
            targetings: [
                new OaTkpTargetingSelectionInput('dmp', 'DMP', 'dmp', '25.0000'),
            ],
            isAeEligible: false,
        ));

        $this->assertSame('0.0000', $result->targetingMinimumSurchargeTotal);
        $this->assertSame('14.0000', $result->minimumTkp);
        $this->assertSame('35.0000', $result->finalTkp);
        $this->assertFalse($result->belowMinimumTkp);
    }

    #[Test]
    public function adserver_is_not_applied_implicitly(): void
    {
        $result = $this->engine->calculatePosition($this->baseInput(
            impressions: 1_000,
            basisTkp: '10.0000',
            isAeEligible: false,
        ));

        $this->assertSame('10.0000', $result->finalTkp);
        $this->assertSame('10.00', $result->mediaGross);
    }

    /**
     * @param  list<OaTkpPlatformAllocationInput>|null  $allocations
     * @param  list<OaTkpTargetingSelectionInput>  $targetings
     */
    private function baseInput(
        int $impressions,
        string $basisTkp,
        string $listTkp = '12.0000',
        string $minimumTkp = '8.0000',
        ?array $allocations = null,
        array $targetings = [],
        string $positionDiscountPercent = '0',
        string $aePercent = '15',
        bool $isAeEligible = true,
    ): OaTkpPositionInput {
        if ($allocations === null) {
            $allocations = [
                new OaTkpPlatformAllocationInput('fixture_default', 'Default', $impressions),
            ];
        }

        return new OaTkpPositionInput(
            inventoryId: 1,
            inventoryCode: 'inv_fixture',
            inventoryName: 'Fixture Inv',
            advertisingMediumId: 1,
            advertisingMediumCode: 'pre_stream',
            advertisingMediumName: 'Pre-Stream',
            impressions: $impressions,
            basisTkp: $basisTkp,
            listTkp: $listTkp,
            minimumTkp: $minimumTkp,
            oaTkpPriceListId: 1,
            oaTkpPriceListVersion: '2026.1',
            oaTkpPriceListYear: 2026,
            oaTkpPriceListRevision: 1,
            platformAllocations: $allocations,
            targetings: $targetings,
            positionDiscountPercent: $positionDiscountPercent,
            aePercent: $aePercent,
            isDiscountable: true,
            isAeEligible: $isAeEligible,
        );
    }
}
