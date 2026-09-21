<?php

namespace App\Services\DispoOrder\SpotDistributionExport;

/**
 * Fertiges Export-View-Model aus eingefrorenen Dispo-Snapshots.
 */
final readonly class SpotDistributionExportDocument
{
    public const string SHEET_TITLE = 'Spotverteilung';

    /**
     * @param  list<SpotDistributionExportRow>  $rows
     * @param  list<string>  $headers
     */
    public function __construct(
        public int $dispoOrderId,
        public string $dispoOrderNumber,
        public array $headers,
        public array $rows,
        public int $exportedPositionCount,
    ) {}

    public function rowCount(): int
    {
        return count($this->rows);
    }

    /**
     * @return list<string>
     */
    public static function defaultHeaders(): array
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
}
