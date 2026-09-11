import { describe, expect, it } from 'vitest';
import { isSelectableForNewWizardPositions } from '@/lib/wizard-medium-selection';

describe('isSelectableForNewWizardPositions (ADV-001c4b)', () => {
    it('allows bookable active medium regardless of code', () => {
        expect(
            isSelectableForNewWizardPositions({
                is_active: true,
                is_bookable_for_new_positions: true,
            }),
        ).toBe(true);
    });

    it('allows bookable non-spot_classic test medium by props alone', () => {
        // Code ist nicht Teil des Gates; synthetische Props ohne Code-Hardcode.
        expect(
            isSelectableForNewWizardPositions({
                is_active: true,
                is_bookable_for_new_positions: true,
            }),
        ).toBe(true);
    });

    it('rejects unbookable medium', () => {
        expect(
            isSelectableForNewWizardPositions({
                is_active: true,
                is_bookable_for_new_positions: false,
            }),
        ).toBe(false);
    });

    it('rejects inactive medium even when bookable flag is true', () => {
        expect(
            isSelectableForNewWizardPositions({
                is_active: false,
                is_bookable_for_new_positions: true,
            }),
        ).toBe(false);
    });

    it('does not let inventory eligibility override missing bookability', () => {
        // Inventarregel ist Aufrufer-Sache; die reine Medium-Gate bleibt fail-closed.
        expect(
            isSelectableForNewWizardPositions({
                is_active: true,
                is_bookable_for_new_positions: false,
            }),
        ).toBe(false);
    });
});
