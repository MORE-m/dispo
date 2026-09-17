import { cleanup, fireEvent, render, screen } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { SpotComponentsSection } from './spot-components-section';

afterEach(() => {
    cleanup();
});

describe('SpotComponentsSection', () => {
    it('shows strategy, total length and toggles allonge', () => {
        const onChange = vi.fn();
        const onDeactivate = vi.fn();

        const { rerender } = render(
            <SpotComponentsSection
                positionIndex={0}
                components={[
                    {
                        role: 'main_spot',
                        label: 'Hauptspot',
                        length_seconds: 20,
                        sort: 0,
                    },
                ]}
                strategy="shared_total_length"
                canEdit
                positionMediaGross="600.00"
                lengthIndex={100}
                onChange={onChange}
                onDeactivate={onDeactivate}
            />,
        );

        expect(
            screen.getByTestId('spot-components-strategy-hint-0').textContent,
        ).toContain('Gemeinsame Gesamtlänge');
        expect(
            screen.getByTestId('spot-components-total-length-0').textContent,
        ).toContain('Gesamtlänge: 20s');
        expect(
            screen.getByTestId('spot-components-total-length-0').textContent,
        ).toMatch(/Brutto\s+600,00\s*€/);

        fireEvent.click(screen.getByTestId('spot-components-add-allonge-0'));
        expect(onChange).toHaveBeenCalled();

        rerender(
            <SpotComponentsSection
                positionIndex={0}
                components={[
                    {
                        role: 'main_spot',
                        label: 'Hauptspot',
                        length_seconds: 20,
                        sort: 0,
                    },
                    {
                        role: 'allonge',
                        label: 'Allonge',
                        length_seconds: 10,
                        sort: 1,
                    },
                ]}
                strategy="individual"
                canEdit
                positionMediaGross="640.00"
                componentResults={[
                    {
                        role: 'main_spot',
                        label: 'Hauptspot',
                        length_seconds: 20,
                        length_index: 105,
                        media_gross: '420.00',
                    },
                    {
                        role: 'allonge',
                        label: 'Allonge',
                        length_seconds: 10,
                        length_index: 110,
                        media_gross: '220.00',
                    },
                ]}
                onChange={onChange}
                onDeactivate={onDeactivate}
            />,
        );

        expect(
            screen.getByTestId('spot-components-strategy-hint-0').textContent,
        ).toContain('Komponenten einzeln berechnen');
        expect(
            screen.getByTestId('spot-component-result-main_spot-0').textContent,
        ).toMatch(/Brutto\s+420,00\s*€/);
        expect(
            screen.getByTestId('spot-component-result-allonge-0').textContent,
        ).toMatch(/Brutto\s+220,00\s*€/);
        expect(
            screen.getByTestId('spot-components-position-gross-0').textContent,
        ).toMatch(/Positionsbrutto:\s+640,00\s*€/);

        fireEvent.click(screen.getByTestId('spot-components-remove-allonge-0'));
        expect(onChange).toHaveBeenCalled();
    });
});
