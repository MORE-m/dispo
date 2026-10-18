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
    mediumCode = 'spot_single',
    onChange,
}: {
    lengthSeconds: number;
    mediumCode?: string;
    onChange?: (value: number) => void;
}) {
    return (
        <FormField
            label="Länge (Sekunden)"
            htmlFor="length-0"
            hint={spotLengthSpt010Hint(lengthSeconds, mediumCode)}
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

describe('SPT-010 Spotlängen-Hinweis (BL-P4-02a / PO-SPOT-SINGLE-1)', () => {
    it('zeigt keinen Hinweis bei 45 Sekunden für Single', () => {
        expect(spotLengthSpt010Hint(45, 'spot_single')).toBeUndefined();
        render(<LengthField lengthSeconds={45} />);
        expect(screen.queryByText(SPT_010_LONG_SPOT_HINT)).toBeNull();
        expect(screen.getByLabelText('Länge (Sekunden)')).not.toBeDisabled();
        expect(screen.queryByRole('alert')).toBeNull();
    });

    it('zeigt Hinweis nur für spot_single bei >45 Sekunden ohne Sperre', () => {
        expect(spotLengthSpt010Hint(46, 'spot_single')).toBe(SPT_010_LONG_SPOT_HINT);
        expect(spotLengthSpt010Hint(100, 'spot_single')).toBe(SPT_010_LONG_SPOT_HINT);
        expect(spotLengthSpt010Hint(46, 'spot_classic')).toBeUndefined();
        expect(spotLengthSpt010Hint(46)).toBeUndefined();

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
