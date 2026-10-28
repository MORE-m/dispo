<?php

namespace App\Services\Calculation\OaTkp;

use App\Enums\DiscountType;
use App\Services\Calculation\Decimal;
use App\Services\Calculation\DiscountInput;
use App\Services\OnlineAudio\Import\OaTkpImportDecimal;
use Illuminate\Validation\ValidationException;

/**
 * PO-CALC-TKP-1B / 1D4c-4b: generische Normal-TKP-Engine (Profil oa_tkp × Method tkp).
 *
 * Formel (FD-D4c-4b):
 *   final_tkp = basis_tkp + Σ listen_targeting_surcharges
 *   effective_minimum_tkp = price_list_minimum_tkp + Σ minimum_targeting_surcharges
 *   below_minimum = final_tkp < effective_minimum_tkp   (Gleichheit zulässig)
 *
 * Input `minimumTkp` ist das Preislisten-Grundminimum (nicht die effektive Schwelle).
 * Result `minimumTkp` ist die effektive Schwelle; `priceListMinimumTkp` das Grundminimum.
 *
 * Kein Adserver (FD-OA-ADSERVER). Keine Plattform-Preiswirkung (FD-OA-PLAT).
 * Kein Festpreis. Commercial: Position-/Order-Rabatt → AE → N/N → OA-Payfaktor.
 *
 * AE nur wenn calculation.ae_enabled ∧ position.is_ae_eligible (Spot-Semantik).
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
        bool $aeEnabled = true,
    ): OaTkpPositionResult {
        $this->assertImpressions($input->impressions);
        $this->assertPlatformAllocations($input->impressions, $input->platformAllocations);
        $this->assertTargetings($input->targetings);

        $basis = $this->requirePositiveTkp($input->basisTkp, 'basis_tkp');
        $list = $this->requirePositiveTkp($input->listTkp, 'list_tkp');
        $priceListMinimum = $this->requirePositiveTkp($input->minimumTkp, 'minimum_tkp');

        if (OaTkpImportDecimal::compare($priceListMinimum, $list) > 0) {
            throw ValidationException::withMessages([
                'positions' => 'Mindest-TKP darf Listen-TKP nicht überschreiten.',
            ]);
        }

        $listSurchargeTotal = '0.0000';
        $minimumSurchargeTotal = '0.0000';
        foreach ($input->targetings as $targeting) {
            $listSurcharge = OaTkpImportDecimal::normalizeNonNegative($targeting->tkpSurcharge);
            if ($listSurcharge === null) {
                throw ValidationException::withMessages([
                    'positions' => "Targeting „{$targeting->code}“: tkp_surcharge ungültig.",
                ]);
            }

            $minimumSurcharge = OaTkpImportDecimal::normalizeNonNegative($targeting->minimumTkpSurcharge);
            if ($minimumSurcharge === null) {
                throw ValidationException::withMessages([
                    'positions' => "Targeting „{$targeting->code}“: minimum_tkp_surcharge ungültig.",
                ]);
            }

            if (OaTkpImportDecimal::compare($minimumSurcharge, $listSurcharge) > 0) {
                throw ValidationException::withMessages([
                    'positions' => "Targeting „{$targeting->code}“: Mindest-Aufschlag darf Listen-Aufschlag nicht überschreiten.",
                ]);
            }

            $listSurchargeTotal = Decimal::roundPrice(Decimal::add($listSurchargeTotal, $listSurcharge, Decimal::PRICE_SCALE));
            $minimumSurchargeTotal = Decimal::roundPrice(Decimal::add($minimumSurchargeTotal, $minimumSurcharge, Decimal::PRICE_SCALE));
        }

        $finalTkp = Decimal::roundPrice(Decimal::add($basis, $listSurchargeTotal, Decimal::PRICE_SCALE));
        $effectiveMinimum = Decimal::roundPrice(Decimal::add($priceListMinimum, $minimumSurchargeTotal, Decimal::PRICE_SCALE));
        $belowMinimum = OaTkpImportDecimal::compare($finalTkp, $effectiveMinimum) < 0;

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
            $aeEnabled,
        );

        $persistedAePercent = ($aeEnabled && $input->isAeEligible)
            ? $input->aePercent
            : '0';

        return new OaTkpPositionResult(
            impressions: $input->impressions,
            basisTkp: $basis,
            listTkp: $list,
            minimumTkp: $effectiveMinimum,
            targetingSurchargeTotal: $listSurchargeTotal,
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
            aePercent: $persistedAePercent,
            isDiscountable: $input->isDiscountable,
            isAeEligible: $input->isAeEligible,
            appliedPositionDiscounts: $commercial['applied_position_discounts'],
            appliedOrderDiscounts: $commercial['applied_order_discounts'],
            effectivePositionDiscountPercent: $commercial['effective_position_discount_percent'],
            effectiveOrderDiscountPercent: $commercial['effective_order_discount_percent'],
            advertisingCategoryId: $input->advertisingCategoryId,
            advertisingCategoryKey: $input->advertisingCategoryKey,
            advertisingCategoryName: $input->advertisingCategoryName,
            positionKey: $input->positionKey,
            priceListMinimumTkp: $priceListMinimum,
            targetingMinimumSurchargeTotal: $minimumSurchargeTotal,
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
     *     effective_pay_factor_percent: string|null,
     *     applied_position_discounts: list<DiscountInput>,
     *     applied_order_discounts: list<DiscountInput>,
     *     effective_position_discount_percent: string,
     *     effective_order_discount_percent: string
     * }
     */
    private function finalizeCommercial(
        string $mediaGrossInternal,
        OaTkpPositionInput $input,
        array $orderDiscounts,
        string $orderDiscountPercent,
        bool $aeEnabled,
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

        $aeAmountInternal = ($aeEnabled && $input->isAeEligible)
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
            'applied_position_discounts' => $positionDiscounts,
            'applied_order_discounts' => $appliedOrder,
            'effective_position_discount_percent' => $this->effectivePercentFromDiscounts($positionDiscounts),
            'effective_order_discount_percent' => $this->effectivePercentFromDiscounts($appliedOrder),
        ];
    }

    /**
     * @param  list<DiscountInput>  $discounts
     */
    private function effectivePercentFromDiscounts(array $discounts): string
    {
        if ($discounts === []) {
            return '0';
        }

        $factor = '1';
        foreach ($discounts as $discount) {
            $factor = Decimal::mul($factor, Decimal::oneMinusPercent($discount->percent));
        }

        return Decimal::roundPrice(Decimal::mul(Decimal::sub('1', $factor), '100'));
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

        return [new DiscountInput(type: DiscountType::Quantity, percent: $legacyPercent)];
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
    private function assertPlatformAllocations(int $impressions, array $allocations): void
    {
        if ($allocations === []) {
            throw ValidationException::withMessages([
                'positions' => 'Plattformverteilung ist Pflicht (OA-006): mindestens eine Allocation.',
            ]);
        }

        $seenCodes = [];
        $sum = 0;
        foreach ($allocations as $allocation) {
            $code = trim($allocation->platformCode);
            if ($code === '') {
                throw ValidationException::withMessages([
                    'positions' => 'Plattform-Code darf nicht leer sein.',
                ]);
            }
            if (isset($seenCodes[$code])) {
                throw ValidationException::withMessages([
                    'positions' => "Plattform „{$code}“ darf pro Position nur einmal vorkommen.",
                ]);
            }
            $seenCodes[$code] = true;

            if ($allocation->impressions < 1) {
                throw ValidationException::withMessages([
                    'positions' => 'Jede Plattform-Allocation benötigt Impressions > 0.',
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

    /**
     * @param  list<OaTkpTargetingSelectionInput>  $targetings
     */
    private function assertTargetings(array $targetings): void
    {
        $seenCodes = [];
        foreach ($targetings as $targeting) {
            $code = trim($targeting->code);
            if ($code === '') {
                throw ValidationException::withMessages([
                    'positions' => 'Targeting-Code darf nicht leer sein.',
                ]);
            }
            if (isset($seenCodes[$code])) {
                throw ValidationException::withMessages([
                    'positions' => "Targeting „{$code}“ darf pro Position nur einmal ausgewählt werden.",
                ]);
            }
            $seenCodes[$code] = true;
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
