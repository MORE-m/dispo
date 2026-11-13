<?php

namespace App\Services\Calculation\FreePosition;

use App\Enums\PricingSettlementMode;
use App\Services\Calculation\CommercialPricingCalculator;
use App\Services\Calculation\Decimal;
use App\Services\Calculation\DiscountInput;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * NATIVE-1: generischer Free-Position-Rechenkern.
 *
 * Formel vor Rabatten: media_gross = free_price.
 * Danach gemeinsame Commercial-Pipeline (Rabatte → AE → N/N).
 * Kein TKP, keine Preisliste, keine Länge, keine Menge, kein Festpreis-Settlement.
 */
final class FreePositionEngine
{
    public const ALGORITHM_VERSION = 'v1';

    public function __construct(
        private readonly CommercialPricingCalculator $commercial = new CommercialPricingCalculator,
    ) {}

    /**
     * @param  list<DiscountInput>  $orderDiscounts
     */
    public function calculatePosition(
        FreePositionInput $input,
        array $orderDiscounts = [],
        string $orderDiscountPercent = '0',
        bool $aeEnabled = true,
    ): FreePositionResult {
        $freePrice = $this->assertFreePrice($input->freePrice);

        $commercial = $this->commercial->calculate(
            mediaGross: $freePrice,
            positionDiscounts: $input->positionDiscounts,
            legacyPositionDiscountPercent: $input->positionDiscountPercent,
            orderDiscounts: $orderDiscounts,
            legacyOrderDiscountPercent: $orderDiscountPercent,
            isDiscountable: $input->isDiscountable,
            aePercent: $input->aePercent,
            isAeEligible: $input->isAeEligible,
            aeEnabled: $aeEnabled,
        );

        $persistedAePercent = ($aeEnabled && $input->isAeEligible)
            ? Decimal::roundPrice($input->aePercent)
            : '0';

        return new FreePositionResult(
            freePrice: Decimal::roundMoney($freePrice),
            mediaGross: $commercial->mediaGross,
            positionDiscountAmount: $commercial->positionDiscountAmount,
            afterPositionDiscount: $commercial->afterPositionDiscount,
            orderDiscountAmount: $commercial->orderDiscountAmount,
            afterOrderDiscount: $commercial->afterOrderDiscount,
            aeAmount: $commercial->aeAmount,
            nnInvest: $commercial->nnInvest,
            effectiveDiscountPercent: $commercial->effectiveDiscountPercent,
            effectivePayFactorPercent: $commercial->effectivePayFactorPercent,
            appliedPositionDiscounts: $commercial->appliedPositionDiscounts,
            appliedOrderDiscounts: $commercial->appliedOrderDiscounts,
            appliedPositionDiscountSteps: $commercial->appliedPositionDiscountSteps,
            appliedOrderDiscountSteps: $commercial->appliedOrderDiscountSteps,
            effectivePositionDiscountPercent: $commercial->effectivePositionDiscountPercent,
            effectiveOrderDiscountPercent: $commercial->effectiveOrderDiscountPercent,
            aePercent: $persistedAePercent,
            isDiscountable: $input->isDiscountable,
            isAeEligible: $input->isAeEligible,
            positionKey: $input->positionKey,
            pricingSettlementMode: PricingSettlementMode::Normal,
            fixedPriceNn: null,
            lengthSeconds: 0,
            totalSpotCount: 0,
            impressions: null,
        );
    }

    /**
     * User-Ausgangspreis: numerisch, > 0, max. 2 Nachkommastellen, ohne Float.
     *
     * @return numeric-string
     */
    public function assertFreePrice(string $raw): string
    {
        $normalized = str_replace(',', '.', trim($raw));

        if ($normalized === '' || preg_match('/^\d+(?:\.\d{1,2})?$/', $normalized) !== 1) {
            throw ValidationException::withMessages([
                'free_price' => 'Freier Ausgangspreis muss numerisch sein und höchstens 2 Nachkommastellen haben.',
            ]);
        }

        try {
            $value = Decimal::of($normalized);
        } catch (InvalidArgumentException) {
            throw ValidationException::withMessages([
                'free_price' => 'Freier Ausgangspreis ist ungültig.',
            ]);
        }

        if (Decimal::cmp($value, '0') <= 0) {
            throw ValidationException::withMessages([
                'free_price' => 'Freier Ausgangspreis muss größer als 0 sein.',
            ]);
        }

        return $value;
    }
}
