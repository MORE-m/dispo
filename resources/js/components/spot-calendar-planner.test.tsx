import { cleanup, fireEvent, render, screen } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { SpotCalendarPlanner } from '@/components/spot-calendar-planner';
import type {
    PlannerEntryDraft,
    PriceListHourItem,
} from '@/lib/pricing-calendar';

afterEach(() => {
    cleanup();
});

const hours: PriceListHourItem[] = [
    { hour: 8, day_group: 'mo_fr', second_price: '2.0000' },
    { hour: 8, day_group: 'sa', second_price: '2.0000' },
    { hour: 8, day_group: 'so', second_price: '2.0000' },
    { hour: 14, day_group: 'mo_fr', second_price: '3.0000' },
    { hour: 14, day_group: 'sa', second_price: '3.0000' },
    { hour: 14, day_group: 'so', second_price: '3.0000' },
];

describe('SpotCalendarPlanner', () => {
    it('shows empty hint without complete cells', () => {
        render(
            <SpotCalendarPlanner
                positionIndex={0}
                entries={[]}
                canEdit
                fieldErrors={{}}
                priceListHours={hours}
                priceYear={2026}
                onChange={vi.fn()}
            />,
        );

        expect(screen.getByTestId('planner-empty-0')).toBeTruthy();
        expect(screen.getByTestId('planner-grid-0')).toBeTruthy();
        expect(screen.getByTestId('planner-price-year-0').textContent).toContain(
            '2026',
        );
    });

    it('renders seven day headers and accepts direct cell input', () => {
        const onChange = vi.fn();
        render(
            <SpotCalendarPlanner
                positionIndex={1}
                entries={[{ date: '2026-09-14', hour: 8, spot_count: 1 }]}
                canEdit
                fieldErrors={{}}
                priceListHours={hours}
                priceYear={2026}
                onChange={onChange}
            />,
        );

        expect(
            screen.getByTestId('planner-day-header-1-2026-09-14'),
        ).toBeTruthy();
        expect(
            screen.getByTestId('planner-day-header-1-2026-09-20'),
        ).toBeTruthy();
        expect(screen.getByTestId('planner-hour-row-1-8')).toBeTruthy();
        expect(screen.getByTestId('planner-hour-row-1-14')).toBeTruthy();

        fireEvent.change(
            screen.getByTestId('planner-cell-spots-1-2026-09-14-14'),
            { target: { value: '5' } },
        );

        expect(onChange).toHaveBeenCalled();
        const next = onChange.mock.calls.at(-1)?.[0] as PlannerEntryDraft[];
        expect(next).toEqual(
            expect.arrayContaining([
                { date: '2026-09-14', hour: 8, spot_count: 1 },
                { date: '2026-09-14', hour: 14, spot_count: 5 },
            ]),
        );
    });

    it('removes a cell when spots are cleared', () => {
        const onChange = vi.fn();
        render(
            <SpotCalendarPlanner
                positionIndex={2}
                entries={[{ date: '2026-09-14', hour: 8, spot_count: 4 }]}
                canEdit
                fieldErrors={{}}
                priceListHours={hours}
                priceYear={2026}
                onChange={onChange}
            />,
        );

        fireEvent.change(
            screen.getByTestId('planner-cell-spots-2-2026-09-14-8'),
            { target: { value: '' } },
        );

        expect(onChange).toHaveBeenLastCalledWith([]);
    });

    it('shows field errors for invalid spot counts', () => {
        render(
            <SpotCalendarPlanner
                positionIndex={3}
                entries={[{ date: '2026-09-14', hour: 8, spot_count: -1 }]}
                canEdit
                fieldErrors={{
                    'positions.3.planner_entries.0.spot_count': [
                        'Die Spotanzahl darf nicht negativ sein.',
                    ],
                }}
                priceListHours={hours}
                priceYear={2026}
                onChange={vi.fn()}
            />,
        );

        expect(
            screen.getByText('Die Spotanzahl darf nicht negativ sein.'),
        ).toBeTruthy();
    });

    it('keeps outside-week spots while navigating', () => {
        render(
            <SpotCalendarPlanner
                positionIndex={4}
                entries={[
                    { date: '2026-09-14', hour: 8, spot_count: 2 },
                    { date: '2026-09-21', hour: 8, spot_count: 7 },
                ]}
                canEdit
                fieldErrors={{}}
                priceListHours={hours}
                priceYear={2026}
                onChange={vi.fn()}
            />,
        );

        expect(screen.getByTestId('planner-outside-week-4').textContent).toContain(
            '7',
        );
        expect(screen.getByTestId('planner-total-spots-4').textContent).toContain(
            '9',
        );

        fireEvent.click(screen.getByTestId('planner-week-next-4'));
        expect(screen.getByTestId('planner-week-label-4').textContent).toContain(
            '2026-09-21',
        );
        expect(screen.getByTestId('planner-outside-week-4').textContent).toContain(
            '2',
        );
    });

    it('maps cell gross by date|hour key', () => {
        render(
            <SpotCalendarPlanner
                positionIndex={5}
                entries={[
                    { date: '2026-09-14', hour: 8, spot_count: 10 },
                    { date: '2026-09-14', hour: 14, spot_count: 5 },
                ]}
                canEdit
                fieldErrors={{}}
                entryTotals={[
                    { date: '2026-09-14', hour: 14, line_gross: '450.00' },
                    { date: '2026-09-14', hour: 8, line_gross: '600.00' },
                ]}
                priceListHours={hours}
                priceYear={2026}
                onChange={vi.fn()}
            />,
        );

        expect(
            screen.getByTestId('planner-cell-gross-5-2026-09-14-8').textContent,
        ).toContain('600,00');
        expect(
            screen.getByTestId('planner-cell-gross-5-2026-09-14-14').textContent,
        ).toContain('450,00');
    });

    it('disables days outside the price year', () => {
        render(
            <SpotCalendarPlanner
                positionIndex={6}
                entries={[{ date: '2026-12-28', hour: 8, spot_count: 1 }]}
                canEdit
                fieldErrors={{}}
                priceListHours={hours}
                priceYear={2026}
                onChange={vi.fn()}
            />,
        );

        const nextYearCell = screen.getByTestId(
            'planner-cell-spots-6-2027-01-01-8',
        ) as HTMLInputElement;
        expect(nextYearCell.disabled).toBe(true);
        expect(
            screen.getByTestId('planner-day-header-6-2027-01-01').textContent,
        ).toContain('Außerhalb Preisjahr');
    });
});
