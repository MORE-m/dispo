import { describe, expect, it } from 'vitest';
import {
    buildMatrixRows,
    buildPlatformMediumSavePayload,
    filledMatrixItems,
    firstApiError,
    isPlatformMediumMatrixDirty,
    isPlatformMediumMatrixEmpty,
    matrixSnapshot,
    oaMediumLabel,
    oaPairKey,
    oaStatusLabel,
    platformMediumAriaLabel,
    platformMediumHeaders,
    type OaPlatformMediumRow,
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
        expect(
            firstApiError(
                {
                    message:
                        'Die Zuordnungsmatrix wurde parallel geändert. Bitte neu laden.',
                },
                'fallback',
            ),
        ).toBe(
            'Die Zuordnungsmatrix wurde parallel geändert. Bitte neu laden.',
        );
        expect(
            firstApiError(
                { errors: { cells: ['Die Matrix muss vollständig sein.'] } },
                'fallback',
            ),
        ).toBe('Die Matrix muss vollständig sein.');
    });
});

describe('platform medium matrix helpers', () => {
    const media = [
        { code: 'pre_stream', name: 'Pre-Stream' },
        { code: 'in_stream', name: 'In-Stream' },
        { code: 'pre_stream_influencer', name: 'Pre-Stream Influencer' },
        { code: 'in_stream_influencer', name: 'In-Stream Influencer' },
        { code: 'midroll_music_environment', name: 'Midroll Katalogname' },
    ];

    function row(active = false): OaPlatformMediumRow {
        return {
            id: 41,
            code: 'plat_fixture',
            name: 'Fixture-Plattform',
            is_active: true,
            sort: 1,
            cells: media.map((medium, index) => ({
                medium_code: medium.code,
                advertising_medium_id: index + 1,
                is_active: active && index === 0,
                rule_id: active && index === 0 ? 9 : null,
            })),
        };
    }

    it('renders headers and labels only from the payload', () => {
        expect(platformMediumHeaders(media)).toEqual(
            media.map((medium) => medium.name),
        );
        expect(platformMediumHeaders(media).join(' ')).not.toMatch(
            /spotify|youtube|deezer/i,
        );
        expect(
            platformMediumAriaLabel('Fixture-Plattform', 'Pre-Stream'),
        ).toBe('Fixture-Plattform, Pre-Stream');
    });

    it('detects dirty state against the loaded snapshot', () => {
        const loaded = [row(false)];
        const edited = [row(true)];

        expect(isPlatformMediumMatrixDirty(loaded, loaded)).toBe(false);
        expect(isPlatformMediumMatrixDirty(loaded, edited)).toBe(true);
    });

    it('builds a full save payload with the revision token', () => {
        const platforms = [row(true)];
        const payload = buildPlatformMediumSavePayload('token-1', platforms);

        expect(payload.revision_token).toBe('token-1');
        expect(payload.cells).toHaveLength(5);
        expect(payload.cells.map((cell) => cell.medium_code)).toEqual(
            media.map((medium) => medium.code),
        );
        expect(payload.cells[0]).toEqual({
            oa_platform_id: 41,
            medium_code: 'pre_stream',
            is_active: true,
        });
        expect(
            payload.cells.every((cell) => cell.oa_platform_id === 41),
        ).toBe(true);
    });

    it('keeps an empty platform list empty', () => {
        expect(isPlatformMediumMatrixEmpty([])).toBe(true);
        expect(
            buildPlatformMediumSavePayload('token-empty', []).cells,
        ).toEqual([]);
        expect(isPlatformMediumMatrixEmpty([row()])).toBe(false);
    });
});
