<?php

namespace App\Services\Calculation\OaTkp;

use App\Services\Calculation\Decimal;
use App\Services\Calculation\DiscountInput;
use App\Services\OnlineAudio\Import\OaTkpImportDecimal;
use Illuminate\Validation\ValidationException;

/**
 * PO-CALC-TKP-1B: generische Normal-TKP-Engine (Profil oa_tkp × Method tkp).
 *
 * Formel:
 *   final_tkp = basis_tkp + Σ targeting_surcharges
 *   media_gross = impressions / 1000 × final_tkp
 *
 * Kein Adserver (FD-OA-ADSERVER). Keine Plattform-Preiswirkung (FD-OA-PLAT).
 * Kein Festpreis. Commercial: Position-/Order-Rabatt → AE → N/N → OA-Payfaktor.
 */
final class OaTkpNormalEngine
{
    public const ALGORITHM_VERSION = 'v1';

    /**
     * @param  list<DiscountInput>  $orderDiscounts
     */
    public function calculatePosition(
        OaTkpPositionInput $input,
        array $orderDiscounts = [],
        string $orderDiscountPercent = '0',
    ): OaTkpPositionResult {
        $this->assertImpressions($input->impressions);
        $this->assertPlatformAllocationSum($input->impressions, $input->platformAllocations);

        $basis = $this->requirePositiveTkp($input->basisTkp, 'basis_tkp');
        $list = $this->requirePositiveTkp($input->listTkp, 'list_tkp');
        $minimum = $this->requirePositiveTkp($input->minimumTkp, 'minimum_tkp');

        if (OaTkpImportDecimal::compare($minimum, $list) > 0) {
            throw ValidationException::withMessages([
                'positions' => 'Mindest-TKP darf Listen-TKP nicht überschreiten.',
            ]);
        }

        $targetingTotal = '0.0000';
        foreach ($input->targetings as $targeting) {
            $surcharge = OaTkpImportDecimal::normalizeNonNegative($targeting->tkpSurcharge);
            if ($surcharge === null) {
                throw ValidationException::withMessages([
                    'positions' => "Targeting „{$targeting->code}“: tkp_surcharge ungültig.",
                ]);
            }
            $targetingTotal = Decimal::roundPrice(Decimal::add($targetingTotal, $surcharge, Decimal::PRICE_SCALE));
        }

        $finalTkp = Decimal::roundPrice(Decimal::add($basis, $targetingTotal, Decimal::PRICE_SCALE));
        $belowMinimum = OaTkpImportDecimal::compare($basis, $minimum) < 0;

        $mediaGrossInternal = Decimal::mul(
            Decimal::div((string) $input->impressions, '1000', Decimal::INTERNAL_SCALE),
            $finalTkp,
            Decimal::INTERNAL_SCALE,
        );

        $commercial = $this->finalizeCommercial(
            $mediaGrossInternal,
            $input,
            $orderDiscounts,
            $orderDiscountPercent,
        );

        return new OaTkpPositionResult(
            impressions: $input->impressions,
            basisTkp: $basis,
            listTkp: $list,
            minimumTkp: $minimum,
            targetingSurchargeTotal: $targetingTotal,
            finalTkp: $finalTkp,
            belowMinimumTkp: $belowMinimum,
            mediaGross: $commercial['media_gross'],
            positionDiscountAmount: $commercial['position_discount_amount'],
            afterPositionDiscount: $commercial['after_position_discount'],
            orderDiscountAmount: $commercial['order_discount_amount'],
            afterOrderDiscount: $commercial['after_order_discount'],
            aeAmount: $commercial['ae_amount'],
            nnInvest: $commercial['nn_invest'],
            effectiveDiscountPercent: $commercial['effective_discount_percent'],
            effectivePayFactorPercent: $commercial['effective_pay_factor_percent'],
            oaTkpPriceListId: $input->oaTkpPriceListId,
            oaTkpPriceListVersion: $input->oaTkpPriceListVersion,
            oaTkpPriceListYear: $input->oaTkpPriceListYear,
            oaTkpPriceListRevision: $input->oaTkpPriceListRevision,
            platformAllocations: $input->platformAllocations,
            targetings: $input->targetings,
            inventoryId: $input->inventoryId,
            inventoryCode: $input->inventoryCode,
            inventoryName: $input->inventoryName,
            advertisingMediumId: $input->advertisingMediumId,
            advertisingMediumCode: $input->advertisingMediumCode,
            advertisingMediumName: $input->advertisingMediumName,
            aePercent: $input->aePercent,
            isDiscountable: $input->isDiscountable,
            isAeEligible: $input->isAeEligible,
            advertisingCategoryId: $input->advertisingCategoryId,
            advertisingCategoryKey: $input->advertisingCategoryKey,
            advertisingCategoryName: $input->advertisingCategoryName,
            positionKey: $input->positionKey,
        );
    }

    /**
     * @param  list<DiscountInput>  $orderDiscounts
     * @return array{
     *     media_gross: string,
     *     position_discount_amount: string,
     *     after_position_discount: string,
     *     order_discount_amount: string,
     *     after_order_discount: string,
     *     ae_amount: string,
     *     nn_invest: string,
     *     effective_discount_percent: string,
     *     effective_pay_factor_percent: string|null
     * }
     */
    private function finalizeCommercial(
        string $mediaGrossInternal,
        OaTkpPositionInput $input,
        array $orderDiscounts,
        string $orderDiscountPercent,
    ): array {
        $positionDiscounts = $input->isDiscountable
            ? $this->resolveDiscountList($input->positionDiscounts, $input->positionDiscountPercent)
            : [];
        $appliedOrder = $input->isDiscountable
            ? $this->resolveDiscountList($orderDiscounts, $orderDiscountPercent)
            : [];

        $positionSequence = $this->applyDiscountSequence($mediaGrossInternal, $positionDiscounts);
        $afterPosition = $positionSequence === []
            ? $mediaGrossInternal
            : $positionSequence[array_key_last($positionSequence)]['remaining'];

        $orderSequence = $this->applyDiscountSequence($afterPosition, $appliedOrder);
        $afterOrder = $orderSequence === []
            ? $afterPosition
            : $orderSequence[array_key_last($orderSequence)]['remaining'];

        $aeAmountInternal = $input->isAeEligible
            ? Decimal::mul($afterOrder, Decimal::percentFactor($input->aePercent))
            : '0';
        $nnInternal = Decimal::sub($afterOrder, $aeAmountInternal);

        $effectiveDiscount = '0';
        if (Decimal::cmp($mediaGrossInternal, '0') !== 0) {
            $effectiveDiscount = Decimal::mul(
                Decimal::sub('1', Decimal::div($afterOrder, $mediaGrossInternal)),
                '100',
            );
        }

        $payFactor = null;
        if (Decimal::cmp($mediaGrossInternal, '0') !== 0) {
            $payFactor = Decimal::roundPrice(
                Decimal::mul(Decimal::div($nnInternal, $mediaGrossInternal), '100'),
            );
        }

        return [
            'media_gross' => Decimal::roundMoney($mediaGrossInternal),
            'position_discount_amount' => Decimal::roundMoney(Decimal::sub($mediaGrossInternal, $afterPosition)),
            'after_position_discount' => Decimal::roundMoney($afterPosition),
            'order_discount_amount' => Decimal::roundMoney(Decimal::sub($afterPosition, $afterOrder)),
            'after_order_discount' => Decimal::roundMoney($afterOrder),
            'ae_amount' => Decimal::roundMoney($aeAmountInternal),
            'nn_invest' => Decimal::roundMoney($nnInternal),
            'effective_discount_percent' => Decimal::roundPrice($effectiveDiscount),
            'effective_pay_factor_percent' => $payFactor,
        ];
    }

    /**
     * @param  list<DiscountInput>  $discounts
     * @return list<DiscountInput>
     */
    private function resolveDiscountList(array $discounts, string $legacyPercent): array
    {
        if ($discounts !== []) {
            return $discounts;
        }

        if (Decimal::cmp($legacyPercent, '0') === 0) {
            return [];
        }

        return [new DiscountInput(type: \App\Enums\DiscountType::Quantity, percent: $legacyPercent)];
    }

    /**
     * @param  list<DiscountInput>  $discounts
     * @return list<array{remaining: string}>
     */
    private function applyDiscountSequence(string $startAmount, array $discounts): array
    {
        $remaining = $startAmount;
        $steps = [];

        foreach ($discounts as $discount) {
            $factor = Decimal::oneMinusPercent($discount->percent);
            $remaining = Decimal::mul($remaining, $factor);
            $steps[] = ['remaining' => $remaining];
        }

        return $steps;
    }

    private function assertImpressions(int $impressions): void
    {
        if ($impressions < 1) {
            throw ValidationException::withMessages([
                'positions' => 'Impressions müssen eine positive ganze Zahl ≥ 1 sein.',
            ]);
        }
    }

    /**
     * @param  list<OaTkpPlatformAllocationInput>  $allocations
     */
    private function assertPlatformAllocationSum(int $impressions, array $allocations): void
    {
        if ($allocations === []) {
            return;
        }

        $sum = 0;
        foreach ($allocations as $allocation) {
            if ($allocation->impressions < 0) {
                throw ValidationException::withMessages([
                    'positions' => 'Plattform-Impressions dürfen nicht negativ sein.',
                ]);
            }
            $sum += $allocation->impressions;
        }

        if ($sum !== $impressions) {
            throw ValidationException::withMessages([
                'positions' => "Summe der Plattform-Impressions ({$sum}) muss Positions-Impressions ({$impressions}) entsprechen.",
            ]);
        }
    }

    private function requirePositiveTkp(string $value, string $field): string
    {
        $normalized = OaTkpImportDecimal::normalizePositive($value);
        if ($normalized === null) {
            throw ValidationException::withMessages([
                'positions' => "{$field} ungültig (muss > 0, max. 4 NK, ≤ ".OaTkpImportDecimal::MAX.' sein).',
            ]);
        }

        return $normalized;
    }
}
