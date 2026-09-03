<?php

namespace App\Services\Calculation;

use App\Models\Calculation;
use App\Models\CalculationPosition;
use Illuminate\Support\Collection;

/**
 * Aggregiert gespeicherte Positionswerte wie CalculationEngine::calculate (Zeilen 42–47).
 */
final class StoredPositionTotals
{
    /**
     * @param  Collection<int, CalculationPosition>|list<CalculationPosition>  $positions
     * @return array{
     *     media_gross: string,
     *     position_discount_total: string,
     *     order_discount_total: string,
     *     ae_total: string,
     *     nn_invest: string
     * }
     */
    public static function sum(Collection|array $positions): array
    {
        $mediaGross = '0.00';
        $positionDiscountTotal = '0.00';
        $orderDiscountTotal = '0.00';
        $aeTotal = '0.00';
        $nnInvest = '0.00';

        foreach ($positions as $position) {
            $mediaGross = Decimal::roundMoney(Decimal::add($mediaGross, (string) $position->media_gross));
            $positionDiscountTotal = Decimal::roundMoney(Decimal::add(
                $positionDiscountTotal,
                (string) $position->position_discount_amount,
            ));
            $orderDiscountTotal = Decimal::roundMoney(Decimal::add(
                $orderDiscountTotal,
                (string) $position->order_discount_amount,
            ));
            $aeTotal = Decimal::roundMoney(Decimal::add($aeTotal, (string) $position->ae_amount));
            $nnInvest = Decimal::roundMoney(Decimal::add($nnInvest, (string) $position->nn_invest));
        }

        return [
            'media_gross' => $mediaGross,
            'position_discount_total' => $positionDiscountTotal,
            'order_discount_total' => $orderDiscountTotal,
            'ae_total' => $aeTotal,
            'nn_invest' => $nnInvest,
        ];
    }

    /**
     * @return array{
     *     media_gross: string,
     *     position_discount_total: string,
     *     order_discount_total: string,
     *     ae_total: string,
     *     nn_invest: string
     * }
     */
    public static function fromCalculation(Calculation $calculation): array
    {
        $calculation->loadMissing('positions');

        return self::sum($calculation->positions);
    }
}
