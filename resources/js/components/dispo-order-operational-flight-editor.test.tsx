import {
    cleanup,
    fireEvent,
    render,
    screen,
    waitFor,
} from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { DispoOrderOperationalFlightEditor } from '@/components/dispo-order-operational-flight-editor';
import { JsonPostError } from '@/lib/json-post';

const mockVisit = vi.fn();
const mockJsonPut = vi.fn();

vi.mock('@inertiajs/react', () => ({
    router: {
        visit: (...args: unknown[]) => mockVisit(...args),
        flushByCacheTags: vi.fn(),
        flush: vi.fn(),
    },
}));

vi.mock('@/lib/dispo-order-inertia-cache', async (importOriginal) => {
    const actual =
        await importOriginal<
            typeof import('@/lib/dispo-order-inertia-cache')
        >();

    return {
        ...actual,
        flushDispoOrderInertiaCache: vi.fn(),
    };
});

vi.mock('@/lib/json-post', async (importOriginal) => {
    const actual = await importOriginal<typeof import('@/lib/json-post')>();

    return {
        ...actual,
        jsonPut: (...args: unknown[]) => mockJsonPut(...args),
        JsonPostError: actual.JsonPostError,
    };
});

const closedHandoff = {
    periodOpen: false as const,
    periodOpenCaptured: true,
    flight: { start: '2026-05-01', end: '2026-05-31' },
    flightCaptured: true,
};

const openHandoff = {
    periodOpen: true as const,
    periodOpenCaptured: true,
    flight: { start: '2026-05-01', end: '2026-05-31' },
    flightCaptured: true,
};

describe('DispoOrderOperationalFlightEditor', () => {
    afterEach(() => {
        cleanup();
    });

    beforeEach(() => {
        mockVisit.mockReset();
        mockJsonPut.mockReset();
        mockJsonPut.mockResolvedValue({
            message: 'ok',
            redirect: '/dispoauftraege/1',
        });
    });

    it('shows unset state without edit when not permitted', () => {
        render(
            <DispoOrderOperationalFlightEditor
                orderId={1}
                positionId={9}
                lockVersion={2}
                flightStart={null}
                flightEnd={null}
                canEdit={false}
            />,
        );

        expect(
            screen.getByTestId('dispo-order-operational-flight-readonly'),
        ).toHaveTextContent('Noch nicht festgelegt');
        expect(
            screen.getByTestId('dispo-order-operational-flight-hint-required'),
        ).toBeInTheDocument();
        expect(
            screen.queryByTestId('dispo-order-operational-flight-save'),
        ).not.toBeInTheDocument();
    });

    it('shows set flight read-only with status', () => {
        render(
            <DispoOrderOperationalFlightEditor
                orderId={1}
                positionId={9}
                lockVersion={2}
                flightStart="2026-06-01"
                flightEnd="2026-06-15"
                flightValid
                canEdit={false}
            />,
        );

        expect(
            screen.getByTestId('dispo-order-operational-flight-readonly'),
        ).toHaveTextContent('01.06.2026 – 15.06.2026');
        expect(
            screen.getByTestId('dispo-order-operational-flight-status-set'),
        ).toHaveTextContent('Verbindlich festgelegt');
    });

    it('allows edit and save with permission', async () => {
        render(
            <DispoOrderOperationalFlightEditor
                orderId={1}
                positionId={9}
                lockVersion={4}
                flightStart={null}
                flightEnd={null}
                canEdit
            />,
        );

        fireEvent.change(
            screen.getByTestId('dispo-order-operational-flight-start'),
            { target: { value: '2026-05-01' } },
        );
        fireEvent.change(
            screen.getByTestId('dispo-order-operational-flight-end'),
            { target: { value: '2026-05-31' } },
        );
        fireEvent.click(
            screen.getByTestId('dispo-order-operational-flight-save'),
        );

        await waitFor(() => {
            expect(mockJsonPut).toHaveBeenCalledWith(
                '/dispoauftraege/1/positionen/9/operativer-flight',
                {
                    lock_version: 4,
                    operational_flight_start: '2026-05-01',
                    operational_flight_end: '2026-05-31',
                },
            );
        });
        expect(mockVisit).toHaveBeenCalled();
    });

    it('blocks end before start client-side without saving', () => {
        render(
            <DispoOrderOperationalFlightEditor
                orderId={1}
                positionId={9}
                lockVersion={4}
                flightStart={null}
                flightEnd={null}
                canEdit
            />,
        );

        fireEvent.change(
            screen.getByTestId('dispo-order-operational-flight-start'),
            { target: { value: '2026-05-31' } },
        );
        fireEvent.change(
            screen.getByTestId('dispo-order-operational-flight-end'),
            { target: { value: '2026-05-01' } },
        );
        fireEvent.click(
            screen.getByTestId('dispo-order-operational-flight-save'),
        );

        expect(mockJsonPut).not.toHaveBeenCalled();
        expect(
            screen.getByTestId('dispo-order-operational-flight-end-error'),
        ).toBeInTheDocument();
    });

    it('adopts handoff flight into inputs without saving', () => {
        render(
            <DispoOrderOperationalFlightEditor
                orderId={1}
                positionId={9}
                lockVersion={4}
                flightStart={null}
                flightEnd={null}
                canEdit
                handoffFlightSource={closedHandoff}
            />,
        );

        fireEvent.click(
            screen.getByTestId('dispo-order-operational-flight-adopt'),
        );

        expect(
            screen.getByTestId('dispo-order-operational-flight-start'),
        ).toHaveValue('2026-05-01');
        expect(
            screen.getByTestId('dispo-order-operational-flight-end'),
        ).toHaveValue('2026-05-31');
        expect(mockJsonPut).not.toHaveBeenCalled();
    });

    it('hides adopt action for open handoff flight', () => {
        render(
            <DispoOrderOperationalFlightEditor
                orderId={1}
                positionId={9}
                lockVersion={4}
                flightStart={null}
                flightEnd={null}
                canEdit
                handoffFlightSource={openHandoff}
            />,
        );

        expect(
            screen.queryByTestId('dispo-order-operational-flight-adopt'),
        ).not.toBeInTheDocument();
    });

    it('cancels draft edits back to persisted values', () => {
        render(
            <DispoOrderOperationalFlightEditor
                orderId={1}
                positionId={9}
                lockVersion={4}
                flightStart="2026-01-01"
                flightEnd="2026-01-31"
                flightValid
                canEdit
            />,
        );

        fireEvent.change(
            screen.getByTestId('dispo-order-operational-flight-start'),
            { target: { value: '2026-02-01' } },
        );
        fireEvent.click(
            screen.getByTestId('dispo-order-operational-flight-cancel'),
        );

        expect(
            screen.getByTestId('dispo-order-operational-flight-start'),
        ).toHaveValue('2026-01-01');
        expect(
            screen.getByTestId('dispo-order-operational-flight-end'),
        ).toHaveValue('2026-01-31');
    });

    it('clears persisted flight via dedicated action', async () => {
        render(
            <DispoOrderOperationalFlightEditor
                orderId={1}
                positionId={9}
                lockVersion={4}
                flightStart="2026-01-01"
                flightEnd="2026-01-31"
                flightValid
                canEdit
            />,
        );

        fireEvent.click(
            screen.getByTestId('dispo-order-operational-flight-clear'),
        );

        await waitFor(() => {
            expect(mockJsonPut).toHaveBeenCalledWith(
                '/dispoauftraege/1/positionen/9/operativer-flight',
                {
                    lock_version: 4,
                    operational_flight_start: null,
                    operational_flight_end: null,
                },
            );
        });
    });

    it('shows 403 message', async () => {
        mockJsonPut.mockRejectedValueOnce(
            new JsonPostError('Forbidden', {}, 403),
        );

        render(
            <DispoOrderOperationalFlightEditor
                orderId={1}
                positionId={9}
                lockVersion={4}
                flightStart={null}
                flightEnd={null}
                canEdit
            />,
        );

        fireEvent.change(
            screen.getByTestId('dispo-order-operational-flight-start'),
            { target: { value: '2026-05-01' } },
        );
        fireEvent.change(
            screen.getByTestId('dispo-order-operational-flight-end'),
            { target: { value: '2026-05-31' } },
        );
        fireEvent.click(
            screen.getByTestId('dispo-order-operational-flight-save'),
        );

        await waitFor(() => {
            expect(
                screen.getByTestId('dispo-order-operational-flight-error'),
            ).toHaveTextContent('Forbidden');
        });
        expect(mockVisit).not.toHaveBeenCalled();
    });

    it('shows 409 with reload action and does not retry', async () => {
        mockJsonPut.mockRejectedValueOnce(
            new JsonPostError('Konflikt', {}, 409),
        );

        render(
            <DispoOrderOperationalFlightEditor
                orderId={1}
                positionId={9}
                lockVersion={4}
                flightStart={null}
                flightEnd={null}
                canEdit
            />,
        );

        fireEvent.change(
            screen.getByTestId('dispo-order-operational-flight-start'),
            { target: { value: '2026-05-01' } },
        );
        fireEvent.change(
            screen.getByTestId('dispo-order-operational-flight-end'),
            { target: { value: '2026-05-31' } },
        );
        fireEvent.click(
            screen.getByTestId('dispo-order-operational-flight-save'),
        );

        await waitFor(() => {
            expect(
                screen.getByTestId('dispo-order-operational-flight-reload'),
            ).toBeInTheDocument();
        });
        expect(mockJsonPut).toHaveBeenCalledTimes(1);

        fireEvent.click(
            screen.getByTestId('dispo-order-operational-flight-reload'),
        );
        expect(mockVisit).toHaveBeenCalledWith(
            '/dispoauftraege/1',
            expect.anything(),
        );
        expect(mockJsonPut).toHaveBeenCalledTimes(1);
    });

    it('maps 422 field errors', async () => {
        mockJsonPut.mockRejectedValueOnce(
            new JsonPostError(
                'Validierung',
                {
                    operational_flight_end: ['Ende ungültig'],
                },
                422,
            ),
        );

        render(
            <DispoOrderOperationalFlightEditor
                orderId={1}
                positionId={9}
                lockVersion={4}
                flightStart={null}
                flightEnd={null}
                canEdit
            />,
        );

        fireEvent.change(
            screen.getByTestId('dispo-order-operational-flight-start'),
            { target: { value: '2026-05-01' } },
        );
        fireEvent.change(
            screen.getByTestId('dispo-order-operational-flight-end'),
            { target: { value: '2026-05-31' } },
        );
        fireEvent.click(
            screen.getByTestId('dispo-order-operational-flight-save'),
        );

        await waitFor(() => {
            expect(
                screen.getByTestId('dispo-order-operational-flight-end-error'),
            ).toHaveTextContent('Ende ungültig');
        });
    });

    it('keeps separation hint visible', () => {
        render(
            <DispoOrderOperationalFlightEditor
                orderId={1}
                positionId={9}
                lockVersion={4}
                flightStart={null}
                flightEnd={null}
                canEdit
            />,
        );

        expect(
            screen.getByTestId(
                'dispo-order-operational-flight-separation-hint',
            ),
        ).toHaveTextContent(/Ursprungskalkulation nicht/);
    });
});
