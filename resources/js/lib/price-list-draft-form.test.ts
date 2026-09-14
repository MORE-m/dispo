import { describe, expect, it } from 'vitest';
import {
    draftFormSnapshot,
    formFieldsReadOnly,
    formLockAfterPreview,
    gridFromBaseItems,
    isDraftDirty,
    itemsFromGrid,
    UNSAVED_LIFECYCLE_MESSAGE,
} from './price-list-draft-form';

describe('price-list-draft-form', () => {
    it('serialisiert nur gesetzte Basispreise', () => {
        expect(
            itemsFromGrid({
                '8|mo_fr': '1,2500',
                '8|sa': '  ',
                '9|so': '0.8000',
            }),
        ).toEqual([
            { hour: 8, day_group: 'mo_fr', second_price: '1,2500' },
            { hour: 9, day_group: 'so', second_price: '0.8000' },
        ]);
    });

    it('erkennt ungespeicherte Namens- und Preisänderungen', () => {
        const saved = draftFormSnapshot(
            'Entwurf',
            gridFromBaseItems([
                { hour: 8, day_group: 'mo_fr', second_price: '1.2500' },
            ]),
        );

        expect(
            isDraftDirty(
                saved,
                'Entwurf',
                gridFromBaseItems([
                    { hour: 8, day_group: 'mo_fr', second_price: '1.2500' },
                ]),
            ),
        ).toBe(false);
        expect(
            isDraftDirty(
                saved,
                'Entwurf v2',
                gridFromBaseItems([
                    { hour: 8, day_group: 'mo_fr', second_price: '1.2500' },
                ]),
            ),
        ).toBe(true);
        expect(
            isDraftDirty(
                saved,
                'Entwurf',
                gridFromBaseItems([
                    { hour: 8, day_group: 'mo_fr', second_price: '9.0000' },
                ]),
            ),
        ).toBe(true);
    });

    it('übernimmt die Preview-Sperrversion nicht in das Formular', () => {
        expect(formLockAfterPreview(1, 4)).toBe(1);
        expect(formLockAfterPreview(3, undefined)).toBe(3);
    });

    it('benennt den Speicherhinweis fest', () => {
        expect(UNSAVED_LIFECYCLE_MESSAGE).toContain('speichern');
    });

    it('sperrt Eingaben bei laufender Anfrage und bei nicht editierbarem Stand', () => {
        expect(formFieldsReadOnly(true, false)).toBe(false);
        expect(formFieldsReadOnly(true, true)).toBe(true);
        expect(formFieldsReadOnly(false, false)).toBe(true);
        expect(formFieldsReadOnly(false, true)).toBe(true);
    });
});
