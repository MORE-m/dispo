<?php

namespace App\Support\DispoOrder;

use App\Models\Calculation;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

/**
 * PO-CALC-DISPO-UX-1 / PR5: operativer Kampagnenzeitraum am Dispo-Kopf.
 *
 * Getrennt von Calc-Origin `campaign_period`, `derived_campaign_period_*`
 * und OA-Positions-Flight (`operational_flight_*`).
 */
final class OperationalCampaignPeriodContract
{
    public const string LABEL = 'Kampagnenzeitraum (operativ)';

    public const string START_KEY = 'operational_campaign_starts_on';

    public const string END_KEY = 'operational_campaign_ends_on';

    /**
     * Kalkulationen mit created_at >= diesem Instant gelten als „neu“ (PR5+)
     * und erhalten beim Dispo-Create kein Legacy-Prefill.
     *
     * Historisch = created_at < Cutover ∧ origin_standard_offer_version_id IS NULL
     * ∧ vollständiger gültiger Calc-Origin-Zeitraum.
     */
    public const string LEGACY_CALC_CREATED_BEFORE = '2026-11-20T12:00:00+01:00';

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

    /**
     * Einmaliges Prefill nur bei erstmaligem Create aus historischer Calc
     * (PO-PR5-02): nicht Adoption, nicht nach Cutover, nur vollständiger Zeitraum.
     *
     * @param  array{start: mixed, end: mixed}|null  $campaignPeriod
     * @return array{start: string, end: string}|null
     */
    public static function legacyPrefillFromCampaignPeriod(
        Calculation $calculation,
        ?array $campaignPeriod,
    ): ?array {
        if ($calculation->origin_standard_offer_version_id !== null) {
            return null;
        }

        $createdAt = $calculation->created_at;
        if ($createdAt === null) {
            return null;
        }

        $cutover = Carbon::parse(self::LEGACY_CALC_CREATED_BEFORE);
        if ($createdAt->greaterThanOrEqualTo($cutover)) {
            return null;
        }

        if ($campaignPeriod === null) {
            return null;
        }

        $startRaw = $campaignPeriod['start'] ?? null;
        $endRaw = $campaignPeriod['end'] ?? null;

        try {
            $pair = self::canonicalizeForPersist($startRaw, $endRaw);
        } catch (ValidationException) {
            return null;
        }

        if (self::isUnset($pair['start'], $pair['end'])) {
            return null;
        }

        /** @var array{start: string, end: string} $pair */
        return $pair;
    }
}
