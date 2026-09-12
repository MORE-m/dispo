import { describe, expect, it } from 'vitest';
import {
    nextSchemaFetchGeneration,
    positionNeedsFieldSchema,
    schemaLoadBlockMessage,
    shouldApplyPositionSchemaResponse,
} from './position-field-schema';
import {
    choiceValuesForPayload,
    initChoiceEntriesMap,
    visibleChoiceFields,
    type SchemaChoiceField,
} from './choice-field-values';

describe('position-field-schema stale responses', () => {
    it('increments generation per fetch attempt', () => {
        expect(nextSchemaFetchGeneration(undefined)).toBe(1);
        expect(nextSchemaFetchGeneration(1)).toBe(2);
    });

    it('rejects out-of-order responses for a newer medium generation', () => {
        const pending = {
            clientKey: 'p1',
            advertisingMediumId: 20,
            generation: 2,
        };
        expect(
            shouldApplyPositionSchemaResponse(pending, {
                clientKey: 'p1',
                advertisingMediumId: 10,
                generation: 1,
            }),
        ).toBe(false);
        expect(
            shouldApplyPositionSchemaResponse(pending, {
                clientKey: 'p1',
                advertisingMediumId: 20,
                generation: 2,
            }),
        ).toBe(true);
    });

    it('rejects responses for a different position client key', () => {
        expect(
            shouldApplyPositionSchemaResponse(
                { clientKey: 'a', advertisingMediumId: 1, generation: 1 },
                { clientKey: 'b', advertisingMediumId: 1, generation: 1 },
            ),
        ).toBe(false);
    });

    it('detects missing fingerprints and builds block messages', () => {
        expect(
            positionNeedsFieldSchema({
                advertising_medium_id: 1,
                schema_fingerprint: null,
                field_schema: null,
            }),
        ).toBe(true);
        expect(
            schemaLoadBlockMessage(true, null),
        ).toMatch(/noch geladen/i);
        expect(
            schemaLoadBlockMessage(false, 'Feldschema konnte nicht geladen werden.'),
        ).toMatch(/nicht geladen/i);
        expect(schemaLoadBlockMessage(false, null)).toBeNull();
    });
});

describe('choice visible toggle retains values and touched', () => {
    const field: SchemaChoiceField = {
        key: 'hdr_select',
        label: 'Kanal',
        field_type: 'select',
        visible: true,
        options_json: [
            { key: 'opt_a', label: 'A', sort: 1, is_active: true },
        ],
    };

    it('hides field without clearing payload omit / touched state', () => {
        const hidden = { ...field, visible: false };
        const values = { hdr_select: 'opt_a' as const };
        const touched = new Set<string>();

        expect(visibleChoiceFields([field])).toHaveLength(1);
        expect(visibleChoiceFields([hidden])).toHaveLength(0);

        const omitted = choiceValuesForPayload([hidden], values, touched);
        expect(omitted.payload).toEqual({});
        expect(values.hdr_select).toBe('opt_a');
    });

    it('keeps local touched change across hide/show', () => {
        const values = { hdr_select: 'opt_a' as string | null };
        const touched = new Set(['hdr_select']);
        values.hdr_select = null;

        const hidden = { ...field, visible: false };
        expect(visibleChoiceFields([hidden])).toHaveLength(0);

        const shown = { ...field, visible: true };
        expect(visibleChoiceFields([shown])).toHaveLength(1);
        expect(values.hdr_select).toBeNull();

        const payload = choiceValuesForPayload([shown], values, touched);
        expect(payload.payload).toEqual({ hdr_select: null });
    });

    it('init meta marks unknown keys unsafe so omit keeps server value', () => {
        const entries = initChoiceEntriesMap(
            [field],
            { hdr_select: 'gone' },
        );
        expect(entries.hdr_select?.initPayloadSafe).toBe(false);
        expect(
            choiceValuesForPayload(
                [field],
                { hdr_select: 'gone' },
                new Set(),
                {
                    hdr_select: {
                        initPayloadSafe: entries.hdr_select!.initPayloadSafe,
                        issue: entries.hdr_select!.issue,
                    },
                },
            ).payload,
        ).toEqual({});
    });
});
