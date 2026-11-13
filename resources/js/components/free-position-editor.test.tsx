import { cleanup, fireEvent, render, screen } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { FreePositionEditor } from '@/components/free-position-editor';

describe('FreePositionEditor', () => {
    afterEach(() => {
        cleanup();
    });

    it('renders a single Preis input and no spot/OA fields', () => {
        render(
            <FreePositionEditor
                positionIndex={0}
                position={{ free_price_input: '' }}
                fieldErrors={{}}
                canEdit
                onChange={vi.fn()}
            />,
        );

        const input = screen.getByLabelText('Preis') as HTMLInputElement;
        expect(input.placeholder).toBe('0,00 €');
        expect(screen.queryByText(/Länge/)).toBeNull();
        expect(screen.queryByText(/TKP/)).toBeNull();
        expect(screen.queryByText(/Impressions/)).toBeNull();
        expect(screen.queryByText(/free_price/)).toBeNull();
    });

    it('emits free_price_input patches', () => {
        const onChange = vi.fn();
        render(
            <FreePositionEditor
                positionIndex={0}
                position={{ free_price_input: '' }}
                fieldErrors={{}}
                canEdit
                onChange={onChange}
            />,
        );

        fireEvent.change(screen.getByLabelText('Preis'), {
            target: { value: '5000' },
        });

        expect(onChange).toHaveBeenCalledWith({ free_price_input: '5000' });
    });

    it('shows server errors for positions.{i}.free_price', () => {
        render(
            <FreePositionEditor
                positionIndex={2}
                position={{ free_price_input: '5000' }}
                fieldErrors={{
                    'positions.2.free_price': [
                        'Freier Ausgangspreis ist erforderlich.',
                    ],
                }}
                canEdit
                onChange={vi.fn()}
            />,
        );

        expect(
            screen.getByText('Freier Ausgangspreis ist erforderlich.'),
        ).toBeTruthy();
    });

    it('shows a client hint for invalid input', () => {
        render(
            <FreePositionEditor
                positionIndex={0}
                position={{ free_price_input: '0' }}
                fieldErrors={{}}
                canEdit
                onChange={vi.fn()}
            />,
        );

        expect(screen.getByRole('alert').textContent).toContain('größer als 0');
    });

    it('shows the server preview media_gross read-only', () => {
        render(
            <FreePositionEditor
                positionIndex={0}
                position={{ free_price_input: '5000' }}
                mediaGross="5000.00"
                fieldErrors={{}}
                canEdit
                onChange={vi.fn()}
            />,
        );

        const preview = document.querySelector(
            '[data-test="free-position-media-gross-0"]',
        );
        expect(preview).not.toBeNull();
        expect(preview?.textContent).toContain('5.000,00');
    });

    it('disables the input when not editable', () => {
        render(
            <FreePositionEditor
                positionIndex={0}
                position={{ free_price_input: '5000' }}
                fieldErrors={{}}
                canEdit={false}
                onChange={vi.fn()}
            />,
        );

        expect(
            (screen.getByLabelText('Preis') as HTMLInputElement).disabled,
        ).toBe(true);
    });
});
