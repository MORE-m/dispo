export type DerivedCampaignPeriodUnresolved = {
    dispo_order_position_id: number;
    sort: number;
    label: string;
    spot_method: string | null;
    unresolved_reason: string | null;
    unresolved_reason_label: string | null;
};

export type DerivedCampaignPeriodProp = {
    start: string | null;
    end: string | null;
    status: 'complete' | 'partial' | 'open' | 'legacy';
    status_label: string;
    derived_at: string | null;
    position_count: number | null;
    contributing_count: number | null;
    unresolved_count: number | null;
    unresolved_positions: DerivedCampaignPeriodUnresolved[];
    conflict_with_calculation: boolean;
    calculation_period_complete: boolean;
    derived_period_complete: boolean;
    contract_version: number | null;
};

export function formatDerivedPeriodRange(
    start: string | null,
    end: string | null,
    formatDate: (iso: string) => string,
): string | null {
    if (start == null || end == null) {
        return null;
    }

    const from = formatDate(start);
    const to = formatDate(end);
    if (from === '–' || to === '–') {
        return null;
    }

    return `${from}–${to}`;
}

export function derivedCampaignPeriodSummary(
    period: DerivedCampaignPeriodProp,
    formatDate: (iso: string) => string,
): {
    rangeText: string | null;
    statusLabel: string;
    explanation: string | null;
    showUnresolved: boolean;
} {
    const rangeText = formatDerivedPeriodRange(
        period.start,
        period.end,
        formatDate,
    );

    switch (period.status) {
        case 'complete':
            return {
                rangeText,
                statusLabel: period.status_label,
                explanation:
                    'Automatisch aus den übernommenen Positionen berechnet.',
                showUnresolved: false,
            };
        case 'partial':
            return {
                rangeText,
                statusLabel: period.status_label,
                explanation:
                    'Der angezeigte Zeitraum umfasst nur Positionen mit konkreten Datumsangaben. Mindestens eine Position liefert keinen vollständigen Zeitraum.',
                showUnresolved: true,
            };
        case 'open':
            return {
                rangeText: null,
                statusLabel: period.status_label,
                explanation:
                    'Keine der übernommenen Positionen liefert einen konkreten Zeitraum.',
                showUnresolved: true,
            };
        case 'legacy':
            return {
                rangeText: null,
                statusLabel: period.status_label,
                explanation:
                    'Für diesen historischen Auftrag wurde kein abgeleiteter Kampagnenzeitraum gespeichert.',
                showUnresolved: false,
            };
        default:
            return {
                rangeText: null,
                statusLabel: period.status_label,
                explanation: null,
                showUnresolved: false,
            };
    }
}
