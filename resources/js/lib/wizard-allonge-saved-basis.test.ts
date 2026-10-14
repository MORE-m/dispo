import { describe, expect, it } from 'vitest';
import { allongeConfigurationHint } from '@/lib/wizard-allonge-confirmation-hint';
import { applySuccessfulPositionSave } from '@/lib/wizard-allonge-saved-basis';

const catalog = {
    media: [{ id: 1, kind: 'swf_allonge' }],
    rules: [
        {
            inventory_id: 20,
            advertising_medium_id: 1,
            swf_allonge_confirmed_at: null,
        },
    ],
};

describe('applySuccessfulPositionSave', () => {
    const current = {
        client_key: 'pos-a',
        id: 5,
        schema_fingerprint: 'old',
        inventory_id: 20,
        advertising_medium_id: 1,
        price_year: 2027,
        original_price_year: 2026,
        original_price_list_id: 101,
        saved_inventory_id: 10,
        saved_advertising_medium_id: 1,
        saved_price_year: 2026,
        length_seconds: 15,
    };

    it('übernimmt Identität und Preisjahr per client_key und lässt den Pin stehen', () => {
        const next = applySuccessfulPositionSave(
            current,
            [
                {
                    id: 9,
                    client_key: 'pos-b',
                    inventory_id: 99,
                    advertising_medium_id: 9,
                    price_year: 2025,
                },
                {
                    id: 8,
                    client_key: 'pos-a',
                    schema_fingerprint: 'new',
                    inventory_id: 20,
                    advertising_medium_id: 1,
                    price_year: 2027,
                },
            ],
            0,
        );

        expect(next.id).toBe(8);
        expect(next.schema_fingerprint).toBe('new');
        expect(next.saved_inventory_id).toBe(20);
        expect(next.saved_advertising_medium_id).toBe(1);
        expect(next.saved_price_year).toBe(2027);
        expect(next.original_price_year).toBe(2026);
        expect(next.original_price_list_id).toBe(101);
        expect(next.length_seconds).toBe(15);
        expect(
            allongeConfigurationHint(catalog, next)?.tone,
        ).toBe('snapshot');
    });

    it('fällt ohne client_key auf den Index zurück', () => {
        const next = applySuccessfulPositionSave(
            current,
            [
                {
                    id: 8,
                    inventory_id: 20,
                    advertising_medium_id: 1,
                    price_year: 2027,
                },
            ],
            0,
        );

        expect(next.saved_inventory_id).toBe(20);
        expect(next.saved_price_year).toBe(2027);
    });

    it('behält die Vergleichsbasis, wenn die Serveridentität unvollständig ist', () => {
        const next = applySuccessfulPositionSave(
            current,
            [
                {
                    id: 8,
                    client_key: 'pos-a',
                    schema_fingerprint: 'new',
                    inventory_id: 20,
                },
            ],
            0,
        );

        expect(next.id).toBe(8);
        expect(next.schema_fingerprint).toBe('new');
        expect(next.saved_inventory_id).toBe(10);
        expect(next.saved_price_year).toBe(2026);
        expect(
            allongeConfigurationHint(catalog, {
                ...next,
                inventory_id: 20,
                advertising_medium_id: 1,
                price_year: 2027,
            })?.tone,
        ).toBe('block');
    });
});
