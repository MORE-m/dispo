<?php

namespace App\Support\DispoOrder;

/**
 * Versionierter Vertrag für den abgeleiteten Dispo-Kampagnenzeitraum (DSP-DCP-001).
 *
 * Getrennt vom Calc-Origin-Dyn-Feld {@see campaign_period}.
 */
final class DerivedCampaignPeriodContract
{
    public const int CONTRACT_VERSION = 1;

    public const string SOURCE_PLANNER = 'planner_entries_snapshot';

    public const string SOURCE_FLIGHT = 'position_flight_period';

    public const string RESULT_CONTRIBUTING = 'contributing';

    public const string RESULT_UNRESOLVED = 'unresolved';

    public const string REASON_NO_USABLE_PLANNER = 'no_usable_planner_entries';

    public const string REASON_PERIOD_OPEN = 'period_open';

    public const string REASON_FLIGHT_MISSING = 'flight_period_missing';

    public const string REASON_FLIGHT_INCOMPLETE = 'flight_period_incomplete';

    public const string REASON_FLIGHT_INVALID = 'flight_period_invalid';

    public const string REASON_CORRUPT_SNAPSHOT = 'corrupt_snapshot';

    /**
     * @return array<string, string>
     */
    public static function unresolvedReasonLabels(): array
    {
        return [
            self::REASON_NO_USABLE_PLANNER => 'Keine verwendbaren Kalenderplaner-Einträge',
            self::REASON_PERIOD_OPEN => 'Zeitraum offen',
            self::REASON_FLIGHT_MISSING => 'Flugzeitraum fehlt',
            self::REASON_FLIGHT_INCOMPLETE => 'Flugzeitraum unvollständig',
            self::REASON_FLIGHT_INVALID => 'Flugzeitraum ungültig',
            self::REASON_CORRUPT_SNAPSHOT => 'Snapshot ungültig',
        ];
    }

    public static function unresolvedReasonLabel(string $reason): string
    {
        return self::unresolvedReasonLabels()[$reason] ?? $reason;
    }
}
