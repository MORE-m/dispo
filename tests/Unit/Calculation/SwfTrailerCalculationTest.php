<?php

namespace Tests\Unit\Calculation;

use App\Enums\DayGroup;
use App\Enums\PricingSettlementMode;
use App\Enums\SpotCalculationMethod;
use App\Services\Calculation\CalculationEngine;
use App\Services\Calculation\PlanRowInput;
use App\Services\Calculation\PositionInput;
use App\Services\Calculation\TimeRangeInput;
use App\Support\Calculation\EngineProfileRegistry;
use InvalidArgumentException;
use Tests\TestCase;

/**
 * BL-P5-01a / SWF-001–SWF-005 / PO-BLP501A-1 (B1): Trailer-Formel ohne Spotlängenindex.
 *
 * Zeitraumssumme = Anzahl × Ø-Sekundenpreis × Länge × (1 + Aufschlag / 100).
 */
class SwfTrailerCalculationTest extends TestCase
{
    public function test_fixture_a_and_b_match_po_numbers(): void
    {
        $engine = new CalculationEngine;

        $a = $engine->calculatePosition($this->trailer(20, '30', 10, '2.0000'), '0');
        $b = $engine->calculatePosition($this->trailer(15, '50', 5, '1.5000'), '0');

        $this->assertSame('520.00', $a->mediaGross);
        $this->assertSame('168.75', $b->mediaGross);
        $this->assertSame(100, $a->lengthIndex);
        $this->assertSame(100, $b->lengthIndex);
    }

    public function test_explicit_length_index_on_input_is_never_applied_for_trailer(): void
    {
        $withIndex = $this->trailer(20, '30', 10, '2.0000', lengthIndex: 105);

        $result = (new CalculationEngine)->calculatePosition($withIndex, '0');

        $this->assertSame('520.00', $result->mediaGross);
        $this->assertNotSame('546.00', $result->mediaGross);
        $this->assertSame(100, $result->lengthIndex);
    }

    public function test_spot_classic_with_same_input_keeps_length_index(): void
    {
        $spot = $this->trailer(20, '30', 10, '2.0000', profile: EngineProfileRegistry::PROFILE_SPOT_CLASSIC);

        $result = (new CalculationEngine)->calculatePosition($spot, '0');

        // 10 × 2,00 × 20 × 1,05 × 1,30 = 546,00
        $this->assertSame('546.00', $result->mediaGross);
        $this->assertSame(105, $result->lengthIndex);
    }

    public function test_zero_percent_surcharge_and_two_hour_average(): void
    {
        $engine = new CalculationEngine;

        $zero = $engine->calculatePosition($this->trailer(20, '0', 10, '2.0000'), '0');
        $this->assertSame('400.00', $zero->mediaGross);

        $twoHours = new PositionInput(
            inventoryId: 1,
            inventoryName: 'Test',
            positionKey: 'p',
            lengthSeconds: 20,
            surchargePercent: '30',
            positionDiscountPercent: '0',
            aePercent: '0',
            isDiscountable: true,
            isAeEligible: true,
            totalSpotCount: 10,
            spotMethod: SpotCalculationMethod::Average,
            rows: [],
            timeRanges: [new TimeRangeInput(8, 10, DayGroup::MoFr, 10, [
                new PlanRowInput(8, DayGroup::MoFr, 0, '2.0000'),
                new PlanRowInput(9, DayGroup::MoFr, 0, '4.0000'),
            ])],
            engineProfileKey: EngineProfileRegistry::PROFILE_SWF_TRAILER,
        );
        $this->assertSame('780.00', $engine->calculatePosition($twoHours, '0')->mediaGross);
    }

    public function test_trailer_rejects_calendar_and_fixed_price_settlement(): void
    {
        $engine = new CalculationEngine;

        $calendar = new PositionInput(
            inventoryId: 1,
            inventoryName: 'Test',
            positionKey: 'p',
            lengthSeconds: 20,
            surchargePercent: '30',
            positionDiscountPercent: '0',
            aePercent: '0',
            isDiscountable: true,
            isAeEligible: true,
            totalSpotCount: 1,
            spotMethod: SpotCalculationMethod::Calendar,
            rows: [],
            engineProfileKey: EngineProfileRegistry::PROFILE_SWF_TRAILER,
        );
        try {
            $engine->calculatePosition($calendar, '0');
            $this->fail('Calendar muss für Trailer abgelehnt werden.');
        } catch (InvalidArgumentException $exception) {
            $this->assertStringContainsString('Trailer', $exception->getMessage());
        }

        $fixed = new PositionInput(
            inventoryId: 1,
            inventoryName: 'Test',
            positionKey: 'p',
            lengthSeconds: 20,
            surchargePercent: '30',
            positionDiscountPercent: '0',
            aePercent: '0',
            isDiscountable: true,
            isAeEligible: true,
            totalSpotCount: 1,
            spotMethod: SpotCalculationMethod::Average,
            rows: [],
            pricingSettlementMode: PricingSettlementMode::FixedPrice,
            fixedPriceNn: '100.00',
            engineProfileKey: EngineProfileRegistry::PROFILE_SWF_TRAILER,
        );
        $this->expectException(InvalidArgumentException::class);
        $engine->calculatePosition($fixed, '0');
    }

    private function trailer(
        int $length,
        string $surcharge,
        int $spots,
        string $secondPrice,
        ?int $lengthIndex = null,
        string $profile = EngineProfileRegistry::PROFILE_SWF_TRAILER,
    ): PositionInput {
        return new PositionInput(
            inventoryId: 1,
            inventoryName: 'Test',
            positionKey: 'p',
            lengthSeconds: $length,
            surchargePercent: $surcharge,
            positionDiscountPercent: '0',
            aePercent: '0',
            isDiscountable: true,
            isAeEligible: true,
            totalSpotCount: $spots,
            spotMethod: SpotCalculationMethod::Average,
            rows: [],
            lengthIndex: $lengthIndex,
            timeRanges: [new TimeRangeInput(8, 9, DayGroup::MoFr, $spots, [
                new PlanRowInput(8, DayGroup::MoFr, 0, $secondPrice),
            ])],
            engineProfileKey: $profile,
        );
    }
}
