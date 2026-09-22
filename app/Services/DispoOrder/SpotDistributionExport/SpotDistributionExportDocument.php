<?php

namespace App\Services\DispoOrder\SpotDistributionExport;

/**
 * Spotplanungs-Export: immer genau zwei Blätter (Calendar + Average-Vorschlag).
 */
final readonly class SpotDistributionExportDocument
{
    public const string CALENDAR_SHEET_TITLE = 'Spotverteilung';

    public const string AVERAGE_SHEET_TITLE = 'Planungsvorschlag';

    /** @deprecated Use CALENDAR_SHEET_TITLE */
    public const string SHEET_TITLE = self::CALENDAR_SHEET_TITLE;

    public const string CALENDAR_EMPTY_MESSAGE = 'Für diesen Dispoauftrag liegt keine konkrete Calendar-Spotverteilung vor.';

    public const string AVERAGE_EMPTY_MESSAGE = 'Für diesen Dispoauftrag liegt keine Average-Planung vor.';

    public const string AVERAGE_NOTICE = 'Unverbindlicher Planungsvorschlag – die konkrete Datum- und Stundenverteilung erfolgt durch die Disposition.';

    /**
     * @param  list<SpotDistributionExportRow>  $calendarRows
     * @param  list<SpotPlanningProposalExportRow>  $averageRows
     * @param  list<string>  $calendarHeaders
     * @param  list<string>  $averageHeaders
     */
    public function __construct(
        public int $dispoOrderId,
        public string $dispoOrderNumber,
        public array $calendarHeaders,
        public array $calendarRows,
        public array $averageHeaders,
        public array $averageRows,
        public int $exportedCalendarPositionCount,
        public int $exportedAveragePositionCount,
    ) {}

    public function calendarRowCount(): int
    {
        return count($this->calendarRows);
    }

    public function averageRowCount(): int
    {
        return count($this->averageRows);
    }

    public function exportedPositionCount(): int
    {
        return $this->exportedCalendarPositionCount + $this->exportedAveragePositionCount;
    }

    /**
     * @return list<string>
     */
    public static function defaultHeaders(): array
    {
        return self::calendarHeaders();
    }

    /**
     * @return list<string>
     */
    public static function calendarHeaders(): array
    {
        return [
            'Dispoauftrag',
            'Kunde',
            'Position',
            'Inventar',
            'Werbemittel',
            'Datum',
            'Wochentag',
            'Stunde',
            'Tagesgruppe',
            'Menge',
            'Mengeneinheit',
            'Gesamtlänge in Sekunden',
            'Komponenten',
            'Bestandteilausstrahlungen',
        ];
    }

    /**
     * @return list<string>
     */
    public static function averageHeaders(): array
    {
        return [
            'Dispoauftrag',
            'Kunde',
            'Position',
            'Inventar',
            'Werbemittel',
            'Zeitraum von',
            'Zeitraum bis',
            'Tagesgruppe',
            'Zeit-/Stundenvorgabe',
            'Menge',
            'Mengeneinheit',
            'Gesamtlänge in Sekunden',
            'Komponenten',
            'Bestandteilausstrahlungen',
            'Planungsstatus',
        ];
    }
}
