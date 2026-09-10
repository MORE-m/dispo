import { describe, expect, it } from 'vitest';
import { isSelectableForNewWizardPositions } from '@/lib/wizard-medium-selection';

describe('isSelectableForNewWizardPositions (ADV-001c3a bis c4)', () => {
    it('allows bookable spot_classic', () => {
        expect(
            isSelectableForNewWizardPositions({
                code: 'spot_classic',
                is_active: true,
                is_bookable_for_new_positions: true,
            }),
        ).toBe(true);
    });

    it('rejects bookable non-spot_classic before c4', () => {
        expect(
            isSelectableForNewWizardPositions({
                code: 'spot_classic_b',
                is_active: true,
                is_bookable_for_new_positions: true,
            }),
        ).toBe(false);
    });

    it('rejects unbookable spot_classic', () => {
        expect(
            isSelectableForNewWizardPositions({
                code: 'spot_classic',
                is_active: true,
                is_bookable_for_new_positions: false,
            }),
        ).toBe(false);
    });

    it('rejects inactive spot_classic even when bookable flag is true', () => {
        expect(
            isSelectableForNewWizardPositions({
                code: 'spot_classic',
                is_active: false,
                is_bookable_for_new_positions: true,
            }),
        ).toBe(false);
    });

    it('does not let inventory eligibility override missing bookability', () => {
        // Inventarregel ist Aufrufer-Sache; die reine Medium-Gate bleibt fail-closed.
        expect(
            isSelectableForNewWizardPositions({
                code: 'wiz_null_media',
                is_active: true,
                is_bookable_for_new_positions: false,
            }),
        ).toBe(false);
    });
});
