import { describe, expect, it } from 'vitest';
import {
    buildMatrixRows,
    filledMatrixItems,
    firstApiError,
    matrixSnapshot,
    oaMediumLabel,
    oaPairKey,
    oaStatusLabel,
} from './oa-tkp-admin';

describe('oa-tkp-admin helpers', () => {
    it('labels media and statuses for admins', () => {
        expect(oaMediumLabel('pre_stream')).toBe('Pre-Stream');
        expect(oaMediumLabel('midroll_music_environment')).toContain('Midroll');
        expect(oaStatusLabel('draft')).toBe('Entwurf');
        expect(oaStatusLabel('active')).toBe('Aktiv');
        expect(oaStatusLabel('archived')).toBe('Archiviert');
    });

    it('builds matrix rows for all required pairs without inventing prices', () => {
        const rows = buildMatrixRows(
            [
                {
                    medium_code: 'pre_stream',
                    inventory_code: 'inv_a',
                    advertising_medium_id: 1,
                    inventory_id: 2,
                    inventory_name: 'Inventar A',
                },
                {
                    medium_code: 'in_stream',
                    inventory_code: 'inv_a',
                    advertising_medium_id: 3,
                    inventory_id: 2,
                    inventory_name: 'Inventar A',
                },
            ],
            [
                {
                    medium_code: 'pre_stream',
                    inventory_code: 'inv_a',
                    list_tkp: '56.0000',
                    minimum_tkp: '14.0000',
                },
            ],
        );

        expect(rows).toHaveLength(2);
        expect(rows[0].list_tkp).toBe('56.0000');
        expect(rows[0].inventory_name).toBe('Inventar A');
        expect(rows[1].list_tkp).toBe('');
        expect(rows[1].minimum_tkp).toBe('');
        expect(oaPairKey('pre_stream', 'inv_a')).toBe('pre_stream|inv_a');
    });

    it('detects dirty snapshots and only submits filled rows with comma normalized', () => {
        const empty = buildMatrixRows(
            [
                {
                    medium_code: 'pre_stream',
                    inventory_code: 'inv_a',
                    advertising_medium_id: 1,
                    inventory_id: 2,
                },
            ],
            [],
        );
        const filled = [
            {
                ...empty[0],
                list_tkp: '56,0000',
                minimum_tkp: '14,0000',
            },
        ];

        expect(matrixSnapshot(empty)).not.toBe(matrixSnapshot(filled));
        expect(filledMatrixItems(filled)).toEqual([
            {
                medium_code: 'pre_stream',
                inventory_code: 'inv_a',
                list_tkp: '56.0000',
                minimum_tkp: '14.0000',
            },
        ]);
        expect(filledMatrixItems(empty)).toEqual([]);
    });

    it('surfaces field_errors before generic messages', () => {
        expect(
            firstApiError(
                {
                    message: 'fail',
                    field_errors: [
                        {
                            line: 1,
                            field: 'list_tkp',
                            message: 'Feldfehler',
                        },
                    ],
                },
                'fallback',
            ),
        ).toBe('Feldfehler');
        expect(firstApiError({ errors: ['A', 'B'] }, 'fallback')).toBe('A');
        expect(
            firstApiError({ errors: { list_tkp: ['ungültig'] } }, 'fallback'),
        ).toBe('ungültig');
    });
});
