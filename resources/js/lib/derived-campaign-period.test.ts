import { describe, expect, it } from 'vitest';
import {
    derivedCampaignPeriodSummary,
    formatDerivedPeriodRange,
    type DerivedCampaignPeriodProp,
} from './derived-campaign-period';

const formatDate = (iso: string) => {
    const [y, m, d] = iso.split('-');
    return `${d}.${m}.${y}`;
};

function base(
    overrides: Partial<DerivedCampaignPeriodProp>,
): DerivedCampaignPeriodProp {
    return {
        start: null,
        end: null,
        status: 'open',
        status_label: 'Offen',
        derived_at: null,
        position_count: 1,
        contributing_count: 0,
        unresolved_count: 1,
        unresolved_positions: [],
        conflict_with_calculation: false,
        calculation_period_complete: false,
        derived_period_complete: false,
        contract_version: 1,
        ...overrides,
    };
}

describe('derived-campaign-period', () => {
    it('formats a complete range', () => {
        expect(
            formatDerivedPeriodRange('2026-10-01', '2026-10-31', formatDate),
        ).toBe('01.10.2026–31.10.2026');
    });

    it('summarizes complete status', () => {
        const summary = derivedCampaignPeriodSummary(
            base({
                start: '2026-10-01',
                end: '2026-10-31',
                status: 'complete',
                status_label: 'Vollständig',
                derived_period_complete: true,
            }),
            formatDate,
        );
        expect(summary.rangeText).toBe('01.10.2026–31.10.2026');
        expect(summary.statusLabel).toBe('Vollständig');
        expect(summary.showUnresolved).toBe(false);
    });

    it('summarizes partial with explanation', () => {
        const summary = derivedCampaignPeriodSummary(
            base({
                start: '2026-10-01',
                end: '2026-10-10',
                status: 'partial',
                status_label: 'Teilweise',
                contributing_count: 1,
                unresolved_count: 1,
                derived_period_complete: true,
            }),
            formatDate,
        );
        expect(summary.rangeText).toBe('01.10.2026–10.10.2026');
        expect(summary.explanation).toContain('nur Positionen mit konkreten');
        expect(summary.showUnresolved).toBe(true);
    });

    it('summarizes open without range', () => {
        const summary = derivedCampaignPeriodSummary(
            base({ status: 'open', status_label: 'Offen' }),
            formatDate,
        );
        expect(summary.rangeText).toBeNull();
        expect(summary.explanation).toContain('einen konkreten Zeitraum');
    });

    it('summarizes legacy without claiming derivation', () => {
        const summary = derivedCampaignPeriodSummary(
            base({
                status: 'legacy',
                status_label: 'Historischer Auftrag – nicht abgeleitet',
                position_count: null,
            }),
            formatDate,
        );
        expect(summary.rangeText).toBeNull();
        expect(summary.explanation).toContain('historischen Auftrag');
        expect(summary.showUnresolved).toBe(false);
    });

    it('keeps conflict as separate prop for UI', () => {
        const period = base({
            start: '2026-10-01',
            end: '2026-10-31',
            status: 'complete',
            status_label: 'Vollständig',
            conflict_with_calculation: true,
            calculation_period_complete: true,
            derived_period_complete: true,
        });
        expect(period.conflict_with_calculation).toBe(true);
        expect(
            derivedCampaignPeriodSummary(period, formatDate).rangeText,
        ).toBe('01.10.2026–31.10.2026');
    });

    it('handles only calculation period present', () => {
        const period = base({
            status: 'open',
            calculation_period_complete: true,
            derived_period_complete: false,
            conflict_with_calculation: false,
        });
        expect(period.conflict_with_calculation).toBe(false);
        expect(period.calculation_period_complete).toBe(true);
    });

    it('handles only derived period present', () => {
        const period = base({
            start: '2026-11-01',
            end: '2026-11-15',
            status: 'complete',
            status_label: 'Vollständig',
            calculation_period_complete: false,
            derived_period_complete: true,
            conflict_with_calculation: false,
        });
        expect(period.conflict_with_calculation).toBe(false);
        expect(
            derivedCampaignPeriodSummary(period, formatDate).rangeText,
        ).toBe('01.11.2026–15.11.2026');
    });
});
