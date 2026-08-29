<?php

namespace App\Services\Calculation;

use App\Enums\DayGroup;
use InvalidArgumentException;

/**
 * PRI-006 – abgeleitete Tagesgruppen.
 */
final class DayGroupPrice
{
    public static function secondPrice(string $moFr, string $sa, string $so, DayGroup $group): string
    {
        return match ($group) {
            DayGroup::MoFr => Decimal::roundPrice($moFr),
            DayGroup::Sa => Decimal::roundPrice($sa),
            DayGroup::So => Decimal::roundPrice($so),
            DayGroup::MoSa => Decimal::roundPrice(
                Decimal::div(Decimal::add(Decimal::mul($moFr, '5'), $sa), '6'),
            ),
            DayGroup::MoSo => Decimal::roundPrice(
                Decimal::div(
                    Decimal::add(Decimal::add(Decimal::mul($moFr, '5'), $sa), $so),
                    '7',
                ),
            ),
        };
    }

    /**
     * @param  array<string, string>  $baseByGroup  Keys mo_fr, sa, so
     */
    public static function fromBaseMap(array $baseByGroup, DayGroup $group): string
    {
        foreach ([DayGroup::MoFr->value, DayGroup::Sa->value, DayGroup::So->value] as $required) {
            if (! isset($baseByGroup[$required])) {
                throw new InvalidArgumentException('Basispreis für Tagesgruppe fehlt: '.$required);
            }
        }

        return self::secondPrice(
            $baseByGroup[DayGroup::MoFr->value],
            $baseByGroup[DayGroup::Sa->value],
            $baseByGroup[DayGroup::So->value],
            $group,
        );
    }
}
