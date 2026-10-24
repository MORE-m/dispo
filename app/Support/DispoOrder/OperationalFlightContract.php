<?php

namespace App\Support\DispoOrder;

use App\Models\DispoOrder;
use App\Models\DispoOrderPosition;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

/**
 * PO-CALC-TKP-1D3d-2b-1 / FD-D3d-2-01: operativer verbindlicher Kampagnenzeitraum.
 *
 * Getrennt von Calc-Origin `period_open`/`position_flight_period` und Derived.
 */
final class OperationalFlightContract
{
    public const string LABEL = 'Verbindlicher Kampagnenzeitraum (operativ)';

    public const string CHECK_KEY = 'oa_operational_flight';

    /**
     * Normalisiert auf Y-m-d oder null (leer). Ungültige Formate → ValidationException.
     */
    public static function normalizeDate(mixed $raw, string $fieldKey, string $label): ?string
    {
        if ($raw === null) {
            return null;
        }

        if ($raw instanceof \DateTimeInterface) {
            return $raw->format('Y-m-d');
        }

        if (! is_string($raw) && ! is_numeric($raw)) {
            throw ValidationException::withMessages([
                $fieldKey => $label.' ist kein gültiges Datum.',
            ]);
        }

        $string = trim((string) $raw);
        if ($string === '') {
            return null;
        }

        $iso = substr($string, 0, 10);
        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $iso)) {
            throw ValidationException::withMessages([
                $fieldKey => $label.' ist kein gültiges Datum (YYYY-MM-DD).',
            ]);
        }

        $parts = explode('-', $iso);
        if (! checkdate((int) $parts[1], (int) $parts[2], (int) $parts[0])) {
            throw ValidationException::withMessages([
                $fieldKey => $label.' ist kein gültiges Datum.',
            ]);
        }

        return $iso;
    }

    public static function isUnset(?string $start, ?string $end): bool
    {
        return $start === null && $end === null;
    }

    public static function isValidPair(?string $start, ?string $end): bool
    {
        if ($start === null || $end === null) {
            return false;
        }

        return $end >= $start;
    }

    /**
     * Speichern: beide null ODER beide gesetzt mit end ≥ start.
     *
     * @return array{start: string|null, end: string|null}
     */
    public static function canonicalizeForPersist(mixed $startRaw, mixed $endRaw): array
    {
        $start = self::normalizeDate($startRaw, 'operational_flight_start', 'Startdatum');
        $end = self::normalizeDate($endRaw, 'operational_flight_end', 'Enddatum');

        if (self::isUnset($start, $end)) {
            return ['start' => null, 'end' => null];
        }

        if ($start === null || $end === null) {
            throw ValidationException::withMessages([
                'operational_flight' => self::LABEL.': Start- und Enddatum müssen gemeinsam gesetzt oder gemeinsam leer sein.',
            ]);
        }

        if ($end < $start) {
            throw ValidationException::withMessages([
                'operational_flight_end' => self::LABEL.': Das Enddatum darf nicht vor dem Startdatum liegen.',
            ]);
        }

        return ['start' => $start, 'end' => $end];
    }

    public static function positionHasValidFlight(DispoOrderPosition $position): bool
    {
        $start = self::dateToIso($position->operational_flight_start);
        $end = self::dateToIso($position->operational_flight_end);

        return self::isValidPair($start, $end);
    }

    public static function isOaTkpMediaPosition(DispoOrderPosition $position): bool
    {
        return DispoPositionMethodClassifier::classifyPosition($position)
            === DispoPositionMethodClassifier::KIND_OA_TKP;
    }

    /**
     * @return list<DispoOrderPosition>
     */
    public static function oaTkpMediaPositions(DispoOrder $order): array
    {
        $order->loadMissing([
            'positions' => fn ($q) => $q->orderBy('sort')->orderBy('id'),
        ]);

        $result = [];
        foreach ($order->positions as $position) {
            if (self::isOaTkpMediaPosition($position)) {
                $result[] = $position;
            }
        }

        return $result;
    }

    /**
     * Hartes Gate für disposed/completed – nicht durch forceComplete übersteuerbar.
     */
    public static function assertAllOaPositionsHaveValidFlight(DispoOrder $order): void
    {
        $violations = self::collectMissingFlightMessages($order);
        if ($violations === []) {
            return;
        }

        $messages = [];
        foreach ($violations as $index => $message) {
            $messages[self::CHECK_KEY.'.'.$index] = $message;
        }

        throw ValidationException::withMessages($messages);
    }

    /**
     * @return list<string>
     */
    public static function collectMissingFlightMessages(DispoOrder $order): array
    {
        $messages = [];
        foreach (self::oaTkpMediaPositions($order) as $index => $position) {
            if (self::positionHasValidFlight($position)) {
                continue;
            }

            $displayIndex = $index + 1;
            $label = trim($position->inventory_name.' · '.$position->advertising_medium_name);
            if ($label === '·' || $label === '') {
                $label = 'Position '.$displayIndex;
            }

            $messages[] = sprintf(
                '%s: %s fehlt oder ist ungültig (Position %d).',
                self::LABEL,
                $label,
                $displayIndex,
            );
        }

        return $messages;
    }

    public static function dateToIso(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        if ($value instanceof \DateTimeInterface) {
            return Carbon::instance(\DateTimeImmutable::createFromInterface($value))->toDateString();
        }

        $string = substr(trim((string) $value), 0, 10);
        if ($string === '' || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $string)) {
            return null;
        }

        return $string;
    }
}
