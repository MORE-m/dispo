<?php

namespace Tests\Unit\Calculation;

use App\Enums\DayGroup;
use App\Enums\DiscountType;
use App\Enums\SpotCalculationMethod;
use App\Services\Calculation\CalculationEngine;
use App\Services\Calculation\DiscountInput;
use App\Services\Calculation\PlanRowInput;
use App\Services\Calculation\PositionInput;
use App\Services\Calculation\TimeRangeInput;
use Tests\TestCase;

class LayeredDiscountTest extends TestCase
{
    public function test_sequential_position_discounts_are_not_added(): void
    {
        $engine = new CalculationEngine;
        $position = $this->grossPosition('1000', [
            new DiscountInput(DiscountType::Quantity, '10'),
            new DiscountInput(DiscountType::Special, '5'),
        ]);

        $result = $engine->calculatePosition($position, '0');

        $this->assertSame('1000.00', $result->mediaGross);
        $this->assertSame('900.00', $result->positionDiscounts[0]['remaining']);
        $this->assertSame('855.00', $result->afterPositionDiscount);
        $this->assertSame('14.5000', $result->effectiveDiscountPercent);
    }

    public function test_order_discount_follows_position_discounts(): void
    {
        $position = $this->grossPosition('1000', [
            new DiscountInput(DiscountType::Quantity, '10'),
            new DiscountInput(DiscountType::Special, '5'),
        ]);

        $totals = (new CalculationEngine)->calculate(
            [$position],
            '0',
            null,
            null,
            [new DiscountInput(DiscountType::Quantity, '10')],
            false,
        );

        $this->assertSame('769.50', $totals->positions[0]->afterOrderDiscount);
        $this->assertSame('769.50', $totals->nnInvest);
        $this->assertSame('0.00', $totals->aeTotal);
    }

    public function test_ae_applies_after_all_discounts_with_existing_rounding(): void
    {
        $position = $this->grossPosition('1000', [
            new DiscountInput(DiscountType::Quantity, '10'),
            new DiscountInput(DiscountType::Special, '5'),
        ], aePercent: '15', aeEligible: true);

        $totals = (new CalculationEngine)->calculate(
            [$position],
            '0',
            null,
            null,
            [new DiscountInput(DiscountType::Quantity, '10')],
            true,
        );

        $this->assertSame('769.50', $totals->positions[0]->afterOrderDiscount);
        $this->assertSame('654.08', $totals->nnInvest);
        $this->assertSame('115.43', $totals->aeTotal);
    }

    public function test_non_discountable_amounts_skip_discounts(): void
    {
        $blocked = $this->grossPosition('1000', [
            new DiscountInput(DiscountType::Quantity, '10'),
        ], discountable: false);

        $result = (new CalculationEngine)->calculatePosition(
            $blocked,
            '0',
            [new DiscountInput(DiscountType::Quantity, '10')],
        );

        $this->assertSame('1000.00', $result->mediaGross);
        $this->assertSame('0.00', $result->positionDiscountAmount);
        $this->assertSame('0.00', $result->orderDiscountAmount);
        $this->assertSame('1000.00', $result->nnInvest);
    }

    public function test_non_ae_eligible_amounts_skip_ae(): void
    {
        $eligible = $this->grossPosition('1000', [], aePercent: '15', aeEligible: true);
        $blocked = $this->grossPosition('500', [], aePercent: '15', aeEligible: false, inventoryId: 2);

        $totals = (new CalculationEngine)->calculate(
            [$eligible, $blocked],
            '0',
            null,
            null,
            [],
            true,
        );

        $this->assertSame('150.00', $totals->aeTotal);
        $this->assertSame('1350.00', $totals->nnInvest);
        $this->assertSame('500.00', $totals->positions[1]->nnInvest);
    }

    public function test_combined_effective_discount_is_used_for_approval(): void
    {
        $position = $this->grossPosition('1000', [
            new DiscountInput(DiscountType::Quantity, '8'),
            new DiscountInput(DiscountType::Special, '8'),
        ]);

        $totals = (new CalculationEngine)->calculate(
            [$position],
            '0',
            null,
            '10',
            [],
            false,
        );

        $this->assertSame('15.3600', $totals->positions[0]->effectiveDiscountPercent);
        $this->assertTrue($totals->requiresSpecialApproval);
    }

    public function test_custom_discount_label_is_kept(): void
    {
        $position = $this->grossPosition('1000', [
            new DiscountInput(DiscountType::Other, '10', 'Kampagnenrabatt'),
        ]);

        $result = (new CalculationEngine)->calculatePosition($position, '0');

        $this->assertSame('Kampagnenrabatt', $result->positionDiscounts[0]['label']);
        $this->assertSame('900.00', $result->afterPositionDiscount);
    }

    /**
     * @param  list<DiscountInput>  $discounts
     */
    private function grossPosition(
        string $mediaGross,
        array $discounts,
        bool $discountable = true,
        bool $aeEligible = false,
        string $aePercent = '0',
        int $inventoryId = 1,
    ): PositionInput {
        $spots = (int) ((float) $mediaGross / 100);

        return new PositionInput(
            inventoryId: $inventoryId,
            inventoryName: 'Radio Hamburg',
            positionKey: 'id:'.$inventoryId,
            lengthSeconds: 25,
            surchargePercent: '0',
            positionDiscountPercent: '0',
            aePercent: $aePercent,
            isDiscountable: $discountable,
            isAeEligible: $aeEligible,
            totalSpotCount: $spots,
            spotMethod: SpotCalculationMethod::Average,
            rows: [new PlanRowInput(8, DayGroup::MoFr, 0, '4.0000')],
            timeRanges: [new TimeRangeInput(8, 9, DayGroup::MoFr, $spots, [
                new PlanRowInput(8, DayGroup::MoFr, 0, '4.0000'),
            ])],
            positionDiscounts: $discounts,
        );
    }
}
