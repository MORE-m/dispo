import { cleanup, fireEvent, render, screen } from '@testing-library/react';
import { afterEach, describe, expect, it } from 'vitest';
import { FormField } from '@/components/form-field';
import { Input } from '@/components/ui/input';
import {
    SPT_010_LONG_SPOT_HINT,
    spotLengthSpt010Hint,
} from '@/lib/spot-length-hint';

afterEach(() => {
    cleanup();
});

function LengthField({
    lengthSeconds,
    onChange,
}: {
    lengthSeconds: number;
    onChange?: (value: number) => void;
}) {
    return (
        <FormField
            label="Länge (Sekunden)"
            htmlFor="length-0"
            hint={spotLengthSpt010Hint(lengthSeconds)}
        >
            <Input
                id="length-0"
                data-test="position-length-seconds-0"
                type="number"
                min={1}
                value={lengthSeconds}
                onChange={(event) =>
                    onChange?.(Number(event.target.value))
                }
            />
        </FormField>
    );
}

describe('SPT-010 Spotlängen-Hinweis (BL-P4-02a)', () => {
    it('zeigt keinen Hinweis bei 45 Sekunden', () => {
        expect(spotLengthSpt010Hint(45)).toBeUndefined();
        render(<LengthField lengthSeconds={45} />);
        expect(screen.queryByText(SPT_010_LONG_SPOT_HINT)).toBeNull();
        expect(screen.getByLabelText('Länge (Sekunden)')).not.toBeDisabled();
        expect(screen.queryByRole('alert')).toBeNull();
    });

    it('zeigt Hinweis bei 46 und 100 Sekunden ohne Sperre oder Fehler', () => {
        expect(spotLengthSpt010Hint(46)).toBe(SPT_010_LONG_SPOT_HINT);
        expect(spotLengthSpt010Hint(100)).toBe(SPT_010_LONG_SPOT_HINT);

        const { rerender } = render(<LengthField lengthSeconds={46} />);
        expect(screen.getByText(SPT_010_LONG_SPOT_HINT)).toBeTruthy();
        expect(screen.getByLabelText('Länge (Sekunden)')).not.toBeDisabled();
        expect(screen.queryByRole('alert')).toBeNull();

        rerender(<LengthField lengthSeconds={100} />);
        expect(screen.getByText(SPT_010_LONG_SPOT_HINT)).toBeTruthy();
        expect(screen.getByLabelText('Länge (Sekunden)')).not.toBeDisabled();
        expect(screen.queryByRole('alert')).toBeNull();
    });

    it('lässt die Länge trotz Hinweis weiter editieren', () => {
        const values: number[] = [];
        render(
            <LengthField
                lengthSeconds={46}
                onChange={(value) => values.push(value)}
            />,
        );

        fireEvent.change(screen.getByLabelText('Länge (Sekunden)'), {
            target: { value: '50' },
        });
        expect(values).toEqual([50]);
        expect(screen.getByText(SPT_010_LONG_SPOT_HINT)).toBeTruthy();
        expect(screen.queryByRole('alert')).toBeNull();
    });
});
