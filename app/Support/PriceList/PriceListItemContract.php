<?php

namespace App\Support\PriceList;

use App\Enums\DayGroup;
use App\Services\Calculation\Decimal;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * BL-P4-01a: Basis-Stundenpreise validieren. Abgeleitete Tagesgruppen werden
 * nicht persistiert. Fehlender Preis ist nicht Preis 0.
 */
final class PriceListItemContract
{
    public const MIN_HOUR = 0;

    public const MAX_HOUR = 23;

    public const MAX_PRICE = '999999.9999';

    /**
     * @param  array<int|string, mixed>  $items
     * @return list<array{hour: int, day_group: DayGroup, second_price: string}>
     */
    public static function normalizeBaseItems(array $items, bool $forActivation): array
    {
        $normalized = [];
        $seen = [];
        $position = 0;

        foreach ($items as $item) {
            $position++;
            if (! is_array($item)) {
                throw ValidationException::withMessages([
                    'items' => 'Preiszeile '.$position.' ist ungültig.',
                ]);
            }

            $rawPrice = $item['second_price'] ?? null;
            if (self::isMissingPrice($rawPrice)) {
                continue;
            }

            $hour = self::assertHour($item['hour'] ?? null, $position);
            $dayGroup = self::assertBaseDayGroup($item['day_group'] ?? null, $position);
            $price = self::assertPrice($rawPrice, $position);
            $key = $hour.'|'.$dayGroup->value;

            if (isset($seen[$key])) {
                throw ValidationException::withMessages([
                    'items' => 'Stunde '.$hour.' / '.$dayGroup->label().' ist doppelt angegeben.',
                ]);
            }

            $seen[$key] = true;
            $normalized[] = [
                'hour' => $hour,
                'day_group' => $dayGroup,
                'second_price' => $price,
            ];
        }

        if ($forActivation) {
            self::assertActivationCoverage($normalized);
        }

        return $normalized;
    }

    /**
     * @param  list<array{hour: int, day_group: DayGroup, second_price: string}>  $items
     */
    public static function assertActivationCoverage(array $items): void
    {
        $byHour = [];
        foreach ($items as $item) {
            $byHour[$item['hour']][$item['day_group']->value] = $item['second_price'];
        }

        if ($byHour === []) {
            throw ValidationException::withMessages([
                'items' => 'Zum Aktivieren ist mindestens eine vollständige Preisstunde mit Mo–Fr, Samstag und Sonntag erforderlich.',
            ]);
        }

        foreach ($byHour as $hour => $groups) {
            foreach ([DayGroup::MoFr, DayGroup::Sa, DayGroup::So] as $required) {
                if (! isset($groups[$required->value])) {
                    throw ValidationException::withMessages([
                        'items' => 'Für Stunde '.$hour.' fehlen Basispreise. Mo–Fr, Samstag und Sonntag müssen gemeinsam vorliegen. Fehlende Preise werden nicht als 0 angenommen.',
                    ]);
                }
            }
        }
    }

    public static function isMissingPrice(mixed $value): bool
    {
        if ($value === null) {
            return true;
        }

        if (is_string($value) && trim($value) === '') {
            return true;
        }

        return false;
    }

    private static function assertHour(mixed $value, int $index): int
    {
        if (is_int($value)) {
            $hour = $value;
        } elseif (is_string($value) && preg_match('/^-?\d+$/', trim($value)) === 1) {
            $hour = (int) trim($value);
        } else {
            throw ValidationException::withMessages([
                'items' => 'Preiszeile '.($index + 1).': Stunde muss eine ganze Zahl zwischen 0 und 23 sein.',
            ]);
        }

        if ($hour < self::MIN_HOUR || $hour > self::MAX_HOUR) {
            throw ValidationException::withMessages([
                'items' => 'Preiszeile '.($index + 1).': Stunde muss zwischen 0 und 23 liegen.',
            ]);
        }

        return $hour;
    }

    private static function assertBaseDayGroup(mixed $value, int $index): DayGroup
    {
        $raw = is_string($value) ? trim($value) : '';
        $group = DayGroup::tryFrom($raw);

        if ($group === null) {
            throw ValidationException::withMessages([
                'items' => 'Preiszeile '.($index + 1).': Ungültige Tagesgruppe.',
            ]);
        }

        if ($group->isDerived()) {
            throw ValidationException::withMessages([
                'items' => 'Preiszeile '.($index + 1).': Mo–Sa und Mo–So werden abgeleitet und nicht gespeichert.',
            ]);
        }

        return $group;
    }

    private static function assertPrice(mixed $value, int $index): string
    {
        $raw = is_int($value) || is_float($value)
            ? (string) $value
            : str_replace(' ', '', trim((string) $value));

        if (preg_match('/^-?\d+[.,]\d{5,}$/', $raw) === 1) {
            throw ValidationException::withMessages([
                'items' => 'Preiszeile '.($index + 1).': Höchstens vier Dezimalstellen sind zulässig.',
            ]);
        }

        try {
            $normalized = Decimal::of($raw);
        } catch (InvalidArgumentException) {
            throw ValidationException::withMessages([
                'items' => 'Preiszeile '.($index + 1).': Ungültiger Preis. Bitte eine nichtnegative Zahl mit Komma oder Punkt eingeben.',
            ]);
        }

        if (Decimal::cmp($normalized, '0') < 0) {
            throw ValidationException::withMessages([
                'items' => 'Preiszeile '.($index + 1).': Negative Preise sind unzulässig.',
            ]);
        }

        if (Decimal::cmp($normalized, self::MAX_PRICE) > 0) {
            throw ValidationException::withMessages([
                'items' => 'Preiszeile '.($index + 1).': Der Preis überschreitet den zulässigen Wertebereich.',
            ]);
        }

        return Decimal::roundPrice($normalized);
    }
}
