<?php

namespace App\Services\Calculation\OaTkp;

use App\Services\Calculation\CommercialPricingCalculator;
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
 * Kein Festpreis. Commercial: Position-/Order-Rabatt → AE → N/N → OA-Payfaktor
 * über {@see CommercialPricingCalculator} (NATIVE-1-Extraktion).
 *
 * AE nur wenn calculation.ae_enabled ∧ position.is_ae_eligible (Spot-Semantik).
 */
final class OaTkpNormalEngine
{
    public const ALGORITHM_VERSION = 'v1';

    public function __construct(
        private readonly CommercialPricingCalculator $commercial = new CommercialPricingCalculator,
    ) {}

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

        $commercial = $this->commercial->calculate(
            mediaGross: $mediaGrossInternal,
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
            mediaGross: $commercial->mediaGross,
            positionDiscountAmount: $commercial->positionDiscountAmount,
            afterPositionDiscount: $commercial->afterPositionDiscount,
            orderDiscountAmount: $commercial->orderDiscountAmount,
            afterOrderDiscount: $commercial->afterOrderDiscount,
            aeAmount: $commercial->aeAmount,
            nnInvest: $commercial->nnInvest,
            effectiveDiscountPercent: $commercial->effectiveDiscountPercent,
            effectivePayFactorPercent: $commercial->effectivePayFactorPercent,
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
            appliedPositionDiscounts: $commercial->appliedPositionDiscounts,
            appliedOrderDiscounts: $commercial->appliedOrderDiscounts,
            effectivePositionDiscountPercent: $commercial->effectivePositionDiscountPercent,
            effectiveOrderDiscountPercent: $commercial->effectiveOrderDiscountPercent,
            advertisingCategoryId: $input->advertisingCategoryId,
            advertisingCategoryKey: $input->advertisingCategoryKey,
            advertisingCategoryName: $input->advertisingCategoryName,
            positionKey: $input->positionKey,
            priceListMinimumTkp: $priceListMinimum,
            targetingMinimumSurchargeTotal: $minimumSurchargeTotal,
        );
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
        // V1: leere Allocations sind zulässig. Nicht-leer: bisheriger Mengen-/Summenvertrag.
        if ($allocations === []) {
            return;
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
