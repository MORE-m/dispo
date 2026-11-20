<?php

namespace App\Support\DispoOrder;

use Illuminate\Validation\ValidationException;

/**
 * PO-CALC-DISPO-UX-1 / PR5: operativer Kampagnenzeitraum am Dispo-Kopf.
 *
 * Getrennt von Calc-Origin `campaign_period`, `derived_campaign_period_*`
 * und OA-Positions-Flight (`operational_flight_*`).
 *
 * Kein Prefill aus Calc-Origin (PO-PR5-02, aktualisiert): Create startet immer null/null.
 */
final class OperationalCampaignPeriodContract
{
    public const string LABEL = 'Kampagnenzeitraum (operativ)';

    public const string START_KEY = 'operational_campaign_starts_on';

    public const string END_KEY = 'operational_campaign_ends_on';

    /**
     * Normalisiert auf Y-m-d oder null. Ungültige Formate → ValidationException.
     */
    public static function normalizeDate(mixed $raw, string $fieldKey, string $label): ?string
    {
        return OperationalFlightContract::normalizeDate($raw, $fieldKey, $label);
    }

    public static function isUnset(?string $start, ?string $end): bool
    {
        return $start === null && $end === null;
    }

    /**
     * Speichern: beide null ODER beide gesetzt mit end ≥ start.
     *
     * @return array{start: string|null, end: string|null}
     */
    public static function canonicalizeForPersist(mixed $startRaw, mixed $endRaw): array
    {
        $start = self::normalizeDate($startRaw, self::START_KEY, 'Startdatum');
        $end = self::normalizeDate($endRaw, self::END_KEY, 'Enddatum');

        if (self::isUnset($start, $end)) {
            return ['start' => null, 'end' => null];
        }

        if ($start === null || $end === null) {
            throw ValidationException::withMessages([
                self::START_KEY => self::LABEL.': Start- und Enddatum müssen gemeinsam gesetzt oder gemeinsam leer sein.',
                self::END_KEY => self::LABEL.': Start- und Enddatum müssen gemeinsam gesetzt oder gemeinsam leer sein.',
            ]);
        }

        if ($end < $start) {
            throw ValidationException::withMessages([
                self::END_KEY => self::LABEL.': Das Enddatum darf nicht vor dem Startdatum liegen.',
            ]);
        }

        return ['start' => $start, 'end' => $end];
    }

    public static function dateToIso(mixed $value): ?string
    {
        return OperationalFlightContract::dateToIso($value);
    }
}
