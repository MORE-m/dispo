<?php

namespace App\Services\Calculation\OaTkp;

use App\Services\Calculation\Decimal;
use App\Services\OnlineAudio\Import\OaTkpImportDecimal;
use Illuminate\Validation\ValidationException;

/**
 * PO-CALC-TKP-OA003-1: isolierter OA-Festpreis-Settlement-Rechenkern.
 *
 * Pure Calculation gemäß `docs/entscheidungen/PO-CALC-TKP-OA003-fixed-price.md`.
 * Keine DB-, Writer-, Preview- oder Registry-Abhängigkeit.
 * Produktive Verdrahtung folgt erst OA003-2.
 *
 * Forward-Rabatte sind vertraglich ausgeschlossen und werden hier nicht
 * akzeptiert (kein Discount-Input in der API).
 */
final class OaTkpFixedPriceSettlement
{
    public function calculate(OaTkpFixedPriceSettlementInput $input): OaTkpFixedPriceSettlementResult
    {
        $this->assertImpressions($input->impressions);
        $this->assertTargetings($input->targetings);

        $fixedNn = $this->requirePositiveMoney($input->fixedPriceNn, 'fixed_price_nn');
        $listTkp = $this->requirePositiveTkp($input->listTkp, 'list_tkp');
        $priceListMinimum = $this->requirePositiveTkp($input->priceListMinimumTkp, 'minimum_tkp');

        if (OaTkpImportDecimal::compare($priceListMinimum, $listTkp) > 0) {
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

            $listSurchargeTotal = Decimal::roundPrice(
                Decimal::add($listSurchargeTotal, $listSurcharge, Decimal::PRICE_SCALE),
            );
            $minimumSurchargeTotal = Decimal::roundPrice(
                Decimal::add($minimumSurchargeTotal, $minimumSurcharge, Decimal::PRICE_SCALE),
            );
        }

        $effectiveTkp = Decimal::roundPrice(
            Decimal::mul(
                Decimal::div($fixedNn, (string) $input->impressions, Decimal::INTERNAL_SCALE),
                '1000',
                Decimal::INTERNAL_SCALE,
            ),
        );

        $referenceTkp = Decimal::roundPrice(
            Decimal::add($listTkp, $listSurchargeTotal, Decimal::PRICE_SCALE),
        );
        $effectiveMinimum = Decimal::roundPrice(
            Decimal::add($priceListMinimum, $minimumSurchargeTotal, Decimal::PRICE_SCALE),
        );
        $belowMinimum = OaTkpImportDecimal::compare($effectiveTkp, $effectiveMinimum) < 0;

        $mediaGrossInternal = Decimal::mul(
            Decimal::div((string) $input->impressions, '1000', Decimal::INTERNAL_SCALE),
            $referenceTkp,
            Decimal::INTERNAL_SCALE,
        );
        if (Decimal::cmp($mediaGrossInternal, '0') <= 0) {
            throw ValidationException::withMessages([
                'positions' => 'Festpreis erfordert ein berechenbares Referenz-Mediabrutto größer 0.',
            ]);
        }

        $mediaGross = Decimal::roundMoney($mediaGrossInternal);
        $aeSettlement = $this->deriveFixedPriceAe($fixedNn, $input);
        $payFactor = Decimal::roundPrice(
            Decimal::mul(Decimal::div($fixedNn, $mediaGrossInternal), '100'),
        );
        $totalDiscount = Decimal::roundPrice(
            Decimal::mul(Decimal::sub('1', Decimal::div($fixedNn, $mediaGrossInternal)), '100'),
        );
        $discountBeforeAe = Decimal::roundPrice(
            Decimal::mul(
                Decimal::sub('1', Decimal::div($aeSettlement['net_before_ae'], $mediaGrossInternal)),
                '100',
            ),
        );

        $persistedAePercent = ($input->aeEnabled && $input->isAeEligible)
            ? $this->normalizeAePercent($input->aePercent)
            : '0';

        return new OaTkpFixedPriceSettlementResult(
            impressions: $input->impressions,
            fixedPriceNn: $fixedNn,
            effectiveTkp: $effectiveTkp,
            basisTkp: $effectiveTkp,
            finalTkp: $effectiveTkp,
            listTkp: $listTkp,
            priceListMinimumTkp: $priceListMinimum,
            targetingMinimumSurchargeTotal: $minimumSurchargeTotal,
            effectiveMinimumTkp: $effectiveMinimum,
            targetingSurchargeTotal: '0.0000',
            referenceTkp: $referenceTkp,
            mediaGross: $mediaGross,
            positionDiscountAmount: '0.00',
            afterPositionDiscount: $mediaGross,
            orderDiscountAmount: '0.00',
            afterOrderDiscount: $aeSettlement['net_before_ae'],
            aeAmount: $aeSettlement['ae_amount'],
            nnInvest: $fixedNn,
            effectivePayFactorPercent: $payFactor,
            effectiveTotalDiscountPercent: $totalDiscount,
            effectiveDiscountBeforeAePercent: $discountBeforeAe,
            belowMinimumTkp: $belowMinimum,
            aePercent: $persistedAePercent,
            isAeEligible: $input->isAeEligible,
            targetings: $input->targetings,
        );
    }

    /**
     * @return array{net_before_ae: string, ae_amount: string}
     */
    private function deriveFixedPriceAe(string $fixedNn, OaTkpFixedPriceSettlementInput $input): array
    {
        if (! $input->aeEnabled || ! $input->isAeEligible) {
            return [
                'net_before_ae' => $fixedNn,
                'ae_amount' => '0.00',
            ];
        }

        $aePercent = $this->normalizeAePercent($input->aePercent);
        if (Decimal::cmp($aePercent, '0') === 0) {
            return [
                'net_before_ae' => $fixedNn,
                'ae_amount' => '0.00',
            ];
        }

        $netBeforeAe = Decimal::roundMoney(
            Decimal::div($fixedNn, Decimal::oneMinusPercent($aePercent)),
        );
        $aeAmount = Decimal::roundMoney(Decimal::sub($netBeforeAe, $fixedNn));

        return [
            'net_before_ae' => $netBeforeAe,
            'ae_amount' => $aeAmount,
        ];
    }

    private function normalizeAePercent(string $raw): string
    {
        $normalized = OaTkpImportDecimal::normalizeNonNegative($raw);
        if ($normalized === null) {
            throw ValidationException::withMessages([
                'positions' => 'AE-Satz ungültig.',
            ]);
        }

        if (Decimal::cmp($normalized, '0') < 0) {
            throw ValidationException::withMessages([
                'positions' => 'AE-Satz darf nicht negativ sein.',
            ]);
        }

        if (Decimal::cmp($normalized, '100') >= 0) {
            throw ValidationException::withMessages([
                'positions' => 'AE-Satz muss kleiner als 100 % sein (Festpreis-Rückrechnung).',
            ]);
        }

        return $normalized;
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

    private function requirePositiveMoney(string $value, string $field): string
    {
        $raw = trim(str_replace(',', '.', $value));
        if ($raw === '' || preg_match('/^\d+(?:\.\d{1,2})?$/', $raw) !== 1) {
            throw ValidationException::withMessages([
                'positions' => "{$field} ist ungültig (muss > 0 sein, max. 2 Nachkommastellen).",
            ]);
        }

        $money = Decimal::roundMoney($raw);
        if (Decimal::cmp($money, '0') <= 0) {
            throw ValidationException::withMessages([
                'positions' => "{$field} muss größer 0 sein.",
            ]);
        }

        return $money;
    }
}
