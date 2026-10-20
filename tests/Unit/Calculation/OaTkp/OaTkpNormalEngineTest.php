<?php

namespace Tests\Unit\Calculation\OaTkp;

use App\Enums\DiscountType;
use App\Services\Calculation\DiscountInput;
use App\Services\Calculation\OaTkp\OaTkpNormalEngine;
use App\Services\Calculation\OaTkp\OaTkpPlatformAllocationInput;
use App\Services\Calculation\OaTkp\OaTkpPositionInput;
use App\Services\Calculation\OaTkp\OaTkpTargetingSelectionInput;
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
                new OaTkpTargetingSelectionInput('fix_a', 'A', 'technisch', '2.0000'),
                new OaTkpTargetingSelectionInput('fix_b', 'B', 'dmp', '1.5000'),
            ],
            positionDiscountPercent: '10',
            orderDiscountPercent: '5',
            aePercent: '15',
        );

        $result = $this->engine->calculatePosition(
            $input,
            orderDiscounts: [new DiscountInput(DiscountType::Quantity, '5')],
            orderDiscountPercent: '5',
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
        $this->assertNotNull($result->effectivePayFactorPercent);
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
        ));

        $this->assertSame('10.0000', $result->finalTkp);
        $this->assertSame('0.0000', $result->targetingSurchargeTotal);
        $this->assertSame('1000.00', $result->mediaGross);
        $this->assertSame('1000.00', $result->nnInvest);
    }

    #[Test]
    public function platform_allocation_sum_must_match_impressions(): void
    {
        $ok = $this->baseInput(
            impressions: 100_000,
            basisTkp: '10.0000',
            allocations: [
                new OaTkpPlatformAllocationInput('fix_a', 'A', 60_000),
                new OaTkpPlatformAllocationInput('fix_b', 'B', 40_000),
            ],
        );
        $this->assertSame(100_000, $this->engine->calculatePosition($ok)->impressions);

        $this->expectException(ValidationException::class);
        $this->engine->calculatePosition($this->baseInput(
            impressions: 100_000,
            basisTkp: '10.0000',
            allocations: [
                new OaTkpPlatformAllocationInput('fix_a', 'A', 60_000),
                new OaTkpPlatformAllocationInput('fix_b', 'B', 39_999),
            ],
        ));
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
        $this->assertSame('70.00', $result->mediaGross);
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
     * @param  list<OaTkpPlatformAllocationInput>  $allocations
     * @param  list<OaTkpTargetingSelectionInput>  $targetings
     */
    private function baseInput(
        int $impressions,
        string $basisTkp,
        string $listTkp = '12.0000',
        string $minimumTkp = '8.0000',
        array $allocations = [],
        array $targetings = [],
        string $positionDiscountPercent = '0',
        string $orderDiscountPercent = '0',
        string $aePercent = '15',
        bool $isAeEligible = true,
    ): OaTkpPositionInput {
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
