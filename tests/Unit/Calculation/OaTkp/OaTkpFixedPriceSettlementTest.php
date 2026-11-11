<?php

namespace Tests\Unit\Calculation\OaTkp;

use App\Services\Calculation\OaTkp\OaTkpFixedPriceSettlement;
use App\Services\Calculation\OaTkp\OaTkpFixedPriceSettlementInput;
use App\Services\Calculation\OaTkp\OaTkpTargetingSelectionInput;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * PO-CALC-TKP-OA003-1: isolierter Festpreis-Settlement-Kern.
 *
 * Architekturvertrag: Forward-Rabatte werden von der Input-API nicht akzeptiert
 * (kein Discount-Parameter) – fachlich ausgeschlossen.
 */
class OaTkpFixedPriceSettlementTest extends TestCase
{
    private OaTkpFixedPriceSettlement $settlement;

    protected function setUp(): void
    {
        parent::setUp();
        $this->settlement = new OaTkpFixedPriceSettlement;
    }

    #[Test]
    public function fp01_above_minimum_without_targeting(): void
    {
        $result = $this->settlement->calculate($this->input(
            impressions: 100_000,
            fixedPriceNn: '5000.00',
            listTkp: '80.0000',
            priceListMinimumTkp: '20.0000',
        ));

        $this->assertSame('50.0000', $result->effectiveTkp);
        $this->assertSame('50.0000', $result->basisTkp);
        $this->assertSame('50.0000', $result->finalTkp);
        $this->assertSame('20.0000', $result->effectiveMinimumTkp);
        $this->assertFalse($result->belowMinimumTkp);
        $this->assertSame('0.0000', $result->targetingSurchargeTotal);
        $this->assertSame('5000.00', $result->nnInvest);
        $this->assertSame('5000.00', $result->fixedPriceNn);
        $this->assertSame('0.00', $result->positionDiscountAmount);
        $this->assertSame('0.00', $result->orderDiscountAmount);
    }

    #[Test]
    public function fp02_below_minimum_without_targeting(): void
    {
        $result = $this->settlement->calculate($this->input(
            impressions: 100_000,
            fixedPriceNn: '1500.00',
            listTkp: '80.0000',
            priceListMinimumTkp: '20.0000',
        ));

        $this->assertSame('15.0000', $result->effectiveTkp);
        $this->assertTrue($result->belowMinimumTkp);
    }

    #[Test]
    public function fp03_targeting_minimum_makes_under_min(): void
    {
        $result = $this->settlement->calculate($this->input(
            impressions: 100_000,
            fixedPriceNn: '2300.00',
            listTkp: '80.0000',
            priceListMinimumTkp: '20.0000',
            targetings: [
                new OaTkpTargetingSelectionInput(
                    code: 'dmp',
                    name: 'DMP',
                    category: 'dmp',
                    tkpSurcharge: '25.0000',
                    minimumTkpSurcharge: '5.0000',
                ),
            ],
        ));

        $this->assertSame('23.0000', $result->effectiveTkp);
        $this->assertSame('25.0000', $result->effectiveMinimumTkp);
        $this->assertSame('5.0000', $result->targetingMinimumSurchargeTotal);
        $this->assertTrue($result->belowMinimumTkp);
        $this->assertSame('0.0000', $result->targetingSurchargeTotal);
    }

    #[Test]
    public function fp04_targeting_list_surcharge_only_affects_reference_media_gross(): void
    {
        $result = $this->settlement->calculate($this->input(
            impressions: 100_000,
            fixedPriceNn: '5000.00',
            listTkp: '80.0000',
            priceListMinimumTkp: '20.0000',
            targetings: [
                new OaTkpTargetingSelectionInput(
                    code: 'dmp',
                    name: 'DMP',
                    category: 'dmp',
                    tkpSurcharge: '25.0000',
                    minimumTkpSurcharge: '5.0000',
                ),
            ],
        ));

        $this->assertSame('105.0000', $result->referenceTkp);
        $this->assertSame('10500.00', $result->mediaGross);
        $this->assertSame('0.0000', $result->targetingSurchargeTotal);
        $this->assertSame('5000.00', $result->nnInvest);
        $this->assertSame('50.0000', $result->finalTkp);
        $this->assertSame('10500.00', $result->afterPositionDiscount);
    }

    #[Test]
    public function fp05_ae_reverse_at_15_percent(): void
    {
        $result = $this->settlement->calculate($this->input(
            impressions: 100_000,
            fixedPriceNn: '5000.00',
            listTkp: '80.0000',
            priceListMinimumTkp: '20.0000',
            aePercent: '15.0000',
            aeEnabled: true,
            isAeEligible: true,
        ));

        $this->assertSame('5882.35', $result->afterOrderDiscount);
        $this->assertSame('882.35', $result->aeAmount);
        $this->assertSame('5000.00', $result->nnInvest);
        $this->assertSame('15.0000', $result->aePercent);
    }

    #[Test]
    public function fp06_ae_disabled_keeps_nn_as_after_order(): void
    {
        $result = $this->settlement->calculate($this->input(
            impressions: 100_000,
            fixedPriceNn: '5000.00',
            listTkp: '80.0000',
            priceListMinimumTkp: '20.0000',
            aePercent: '15.0000',
            aeEnabled: false,
            isAeEligible: true,
        ));

        $this->assertSame('5000.00', $result->afterOrderDiscount);
        $this->assertSame('0.00', $result->aeAmount);
        $this->assertSame('0', $result->aePercent);
    }

    #[Test]
    public function fp07_not_ae_eligible_matches_ae_disabled(): void
    {
        $result = $this->settlement->calculate($this->input(
            impressions: 100_000,
            fixedPriceNn: '5000.00',
            listTkp: '80.0000',
            priceListMinimumTkp: '20.0000',
            aePercent: '15.0000',
            aeEnabled: true,
            isAeEligible: false,
        ));

        $this->assertSame('5000.00', $result->afterOrderDiscount);
        $this->assertSame('0.00', $result->aeAmount);
        $this->assertSame('0', $result->aePercent);
    }

    #[Test]
    public function fp08_forward_discounts_are_excluded_by_api_contract(): void
    {
        $reflection = new \ReflectionClass(OaTkpFixedPriceSettlementInput::class);
        $constructor = $reflection->getConstructor();
        $this->assertNotNull($constructor);
        $names = array_map(
            static fn (\ReflectionParameter $parameter): string => $parameter->getName(),
            $constructor->getParameters(),
        );

        $this->assertNotContains('positionDiscounts', $names);
        $this->assertNotContains('orderDiscounts', $names);
        $this->assertNotContains('positionDiscountPercent', $names);
        $this->assertNotContains('orderDiscountPercent', $names);
    }

    #[Test]
    public function fp09_fixed_price_above_reference_yields_pay_factor_over_100(): void
    {
        $result = $this->settlement->calculate($this->input(
            impressions: 100_000,
            fixedPriceNn: '12000.00',
            listTkp: '80.0000',
            priceListMinimumTkp: '20.0000',
            targetings: [
                new OaTkpTargetingSelectionInput(
                    code: 'dmp',
                    name: 'DMP',
                    category: 'dmp',
                    tkpSurcharge: '25.0000',
                    minimumTkpSurcharge: '5.0000',
                ),
            ],
        ));

        // media_gross = 10.500; nn = 12.000 → Payfaktor > 100, Abschlag negativ
        $this->assertSame('10500.00', $result->mediaGross);
        $this->assertSame('114.2857', $result->effectivePayFactorPercent);
        $this->assertSame('-14.2857', $result->effectiveTotalDiscountPercent);
    }

    #[Test]
    public function fp10_invalid_inputs_fail_closed(): void
    {
        $cases = [
            $this->input(impressions: 0, fixedPriceNn: '5000.00', listTkp: '80.0000', priceListMinimumTkp: '20.0000'),
            $this->input(impressions: 100_000, fixedPriceNn: '0', listTkp: '80.0000', priceListMinimumTkp: '20.0000'),
            $this->input(impressions: 100_000, fixedPriceNn: '-1.00', listTkp: '80.0000', priceListMinimumTkp: '20.0000'),
            $this->input(impressions: 100_000, fixedPriceNn: '5000.00', listTkp: '0', priceListMinimumTkp: '20.0000'),
            $this->input(impressions: 100_000, fixedPriceNn: '5000.00', listTkp: '80.0000', priceListMinimumTkp: '0'),
            $this->input(impressions: 100_000, fixedPriceNn: '5000.00', listTkp: '20.0000', priceListMinimumTkp: '80.0000'),
            $this->input(
                impressions: 100_000,
                fixedPriceNn: '5000.00',
                listTkp: '80.0000',
                priceListMinimumTkp: '20.0000',
                targetings: [
                    new OaTkpTargetingSelectionInput('dmp', 'DMP', 'dmp', '5.0000', minimumTkpSurcharge: '10.0000'),
                ],
            ),
            $this->input(
                impressions: 100_000,
                fixedPriceNn: '5000.00',
                listTkp: '80.0000',
                priceListMinimumTkp: '20.0000',
                targetings: [
                    new OaTkpTargetingSelectionInput('dmp', 'DMP', 'dmp', '5.0000'),
                    new OaTkpTargetingSelectionInput('dmp', 'DMP2', 'dmp', '1.0000'),
                ],
            ),
            $this->input(
                impressions: 100_000,
                fixedPriceNn: '5000.00',
                listTkp: '80.0000',
                priceListMinimumTkp: '20.0000',
                aePercent: '100.0000',
                aeEnabled: true,
                isAeEligible: true,
            ),
            $this->input(
                impressions: 100_000,
                fixedPriceNn: '5000.123',
                listTkp: '80.0000',
                priceListMinimumTkp: '20.0000',
            ),
        ];

        foreach ($cases as $index => $input) {
            try {
                $this->settlement->calculate($input);
                $this->fail('Erwartete ValidationException für Fall '.$index);
            } catch (ValidationException $exception) {
                $this->assertNotSame('', $exception->getMessage());
            }
        }
    }

    /**
     * @param  list<OaTkpTargetingSelectionInput>  $targetings
     */
    private function input(
        int $impressions,
        string $fixedPriceNn,
        string $listTkp,
        string $priceListMinimumTkp,
        array $targetings = [],
        string $aePercent = '0',
        bool $aeEnabled = false,
        bool $isAeEligible = false,
    ): OaTkpFixedPriceSettlementInput {
        return new OaTkpFixedPriceSettlementInput(
            impressions: $impressions,
            fixedPriceNn: $fixedPriceNn,
            listTkp: $listTkp,
            priceListMinimumTkp: $priceListMinimumTkp,
            targetings: $targetings,
            aePercent: $aePercent,
            aeEnabled: $aeEnabled,
            isAeEligible: $isAeEligible,
        );
    }
}
