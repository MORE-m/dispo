<?php

namespace App\Services\Calculation;

use App\Enums\DayGroup;
use InvalidArgumentException;

/**
 * PRI-005 / PRI-006 / PO-PRI-HOURS-1 – abgeleitete Tagesgruppen.
 *
 * Fehlende Basiszeile = nicht buchbar, nicht Preis 0.
 * Mo–Sa braucht Mo–Fr+Sa; Mo–So braucht Mo–Fr+Sa+So.
 */
final class DayGroupPrice
{
    /**
     * @return list<DayGroup>
     */
    public static function requiredBaseGroups(DayGroup $group): array
    {
        return match ($group) {
            DayGroup::MoFr => [DayGroup::MoFr],
            DayGroup::Sa => [DayGroup::Sa],
            DayGroup::So => [DayGroup::So],
            DayGroup::MoSa => [DayGroup::MoFr, DayGroup::Sa],
            DayGroup::MoSo => [DayGroup::MoFr, DayGroup::Sa, DayGroup::So],
        };
    }

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
     * @param  array<string, string>  $baseByGroup  nur vorhandene Keys mo_fr / sa / so
     */
    public static function fromBaseMap(array $baseByGroup, DayGroup $group): string
    {
        foreach (self::requiredBaseGroups($group) as $required) {
            if (! isset($baseByGroup[$required->value])) {
                throw new InvalidArgumentException('Basispreis für Tagesgruppe fehlt: '.$required->value);
            }
        }

        $moFr = $baseByGroup[DayGroup::MoFr->value] ?? null;
        $sa = $baseByGroup[DayGroup::Sa->value] ?? null;
        $so = $baseByGroup[DayGroup::So->value] ?? null;

        return match ($group) {
            DayGroup::MoFr => Decimal::roundPrice((string) $moFr),
            DayGroup::Sa => Decimal::roundPrice((string) $sa),
            DayGroup::So => Decimal::roundPrice((string) $so),
            DayGroup::MoSa => self::secondPrice((string) $moFr, (string) $sa, (string) $sa, $group),
            DayGroup::MoSo => self::secondPrice((string) $moFr, (string) $sa, (string) $so, $group),
        };
    }
}
