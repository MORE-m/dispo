import { cleanup, fireEvent, render, screen } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { SpotCalendarPlanner } from '@/components/spot-calendar-planner';
import type { PlannerEntryDraft } from '@/lib/pricing-calendar';

afterEach(() => {
    cleanup();
});

describe('SpotCalendarPlanner', () => {
    it('shows empty hint for a single incomplete row', () => {
        render(
            <SpotCalendarPlanner
                positionIndex={0}
                entries={[emptyRow()]}
                canEdit
                fieldErrors={{}}
                onChange={vi.fn()}
            />,
        );

        expect(screen.getByTestId('planner-empty-0')).toBeTruthy();
    });

    it('adds and removes rows', () => {
        const onChange = vi.fn();
        const { rerender } = render(
            <SpotCalendarPlanner
                positionIndex={1}
                entries={[emptyRow()]}
                canEdit
                fieldErrors={{}}
                onChange={onChange}
            />,
        );

        fireEvent.click(screen.getByTestId('planner-add-1'));
        expect(onChange).toHaveBeenCalled();
        const added = onChange.mock.calls[0][0] as PlannerEntryDraft[];
        expect(added).toHaveLength(2);

        rerender(
            <SpotCalendarPlanner
                positionIndex={1}
                entries={[
                    { date: '2026-09-14', hour: 8, spot_count: 2 },
                    { date: '2026-09-14', hour: 14, spot_count: 1 },
                ]}
                canEdit
                fieldErrors={{}}
                onChange={onChange}
            />,
        );

        fireEvent.click(screen.getByTestId('planner-remove-1-0'));
        expect(onChange).toHaveBeenLastCalledWith([
            { date: '2026-09-14', hour: 14, spot_count: 1 },
        ]);
    });

    it('renders field errors for spot count', () => {
        render(
            <SpotCalendarPlanner
                positionIndex={2}
                entries={[{ date: '2026-09-14', hour: 8, spot_count: -1 }]}
                canEdit
                fieldErrors={{
                    'positions.2.planner_entries.0.spot_count': [
                        'Die Spotanzahl darf nicht negativ sein.',
                    ],
                }}
                onChange={vi.fn()}
            />,
        );

        expect(
            screen.getByText('Die Spotanzahl darf nicht negativ sein.'),
        ).toBeTruthy();
    });

    it('shifts reference week with navigation buttons', () => {
        render(
            <SpotCalendarPlanner
                positionIndex={3}
                entries={[{ date: '2026-09-14', hour: 8, spot_count: 1 }]}
                canEdit
                fieldErrors={{}}
                onChange={vi.fn()}
            />,
        );

        const label = screen.getByTestId('planner-week-label-3');
        expect(label.textContent).toContain('2026-09-14');

        fireEvent.click(screen.getByTestId('planner-week-next-3'));
        expect(screen.getByTestId('planner-week-label-3').textContent).toContain(
            '2026-09-21',
        );
    });
});

function emptyRow(): PlannerEntryDraft {
    return { date: '', hour: '', spot_count: '' };
}
