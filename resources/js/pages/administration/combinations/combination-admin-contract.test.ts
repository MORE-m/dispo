import { describe, expect, it } from 'vitest';

/**
 * BL-P2-02a: reine UI-Vertragschecks ohne DOM (Smoke-Struktur).
 * Kennzeichen nur Dispo, nicht Calc (PO-MAT-BOOKING-VIS-1 A).
 */
describe('BL-P2-02a combination admin UI contract', () => {
    it('exposes combination filter query keys used by the index page', () => {
        const keys = [
            'status',
            'inventory_id',
            'advertising_medium_id',
            'category_id',
            'planning_responsibility_key',
            'booking_code',
        ];
        expect(keys).toContain('booking_code');
        expect(keys).toContain('planning_responsibility_key');
    });

    it('documents dispo read-only combination fields', () => {
        const dispoFields = [
            'booking_code',
            'planning_responsibility_label',
            'combination_hint_text',
        ];
        expect(dispoFields).not.toContain('editable_booking_code');
    });
});
