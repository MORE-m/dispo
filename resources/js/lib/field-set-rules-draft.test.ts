import { describe, expect, it } from 'vitest';
import {
    duplicateDraftRule,
    emptyDraftRule,
    hasRequireAndHiddenWarning,
    moveDraftRule,
    rulesFromServer,
    toPayloadRules,
} from './field-set-rules-draft';

describe('field-set-rules-draft', () => {
    it('maps server rules and strips metadata in payload', () => {
        const draft = rulesFromServer([
            {
                id: 1,
                sort: 0,
                is_system_seed: true,
                condition: {
                    op: 'field_equals',
                    field_key: 'period_open',
                    value: false,
                },
                action: {
                    op: 'require_field',
                    field_key: 'position_flight_period',
                },
            },
        ]);

        expect(draft[0]?.is_system_seed).toBe(true);
        expect(toPayloadRules(draft)[0]).toEqual({
            condition: {
                op: 'field_equals',
                field_key: 'period_open',
                value: false,
            },
            action: {
                op: 'require_field',
                field_key: 'position_flight_period',
            },
        });
    });

    it('duplicates locally without system flag and blocks moving system seed', () => {
        const seed = emptyDraftRule('a');
        seed.is_system_seed = true;
        const free = emptyDraftRule('b');
        const duplicated = duplicateDraftRule(free);
        expect(duplicated.is_system_seed).toBe(false);
        expect(duplicated.localId).not.toBe(free.localId);

        const moved = moveDraftRule([seed, free], 1, -1);
        expect(moved[0]?.is_system_seed).toBe(true);
        expect(moved[1]?.action.field_key).toBe('b');
    });

    it('detects require plus hidden targets', () => {
        const rules = [
            {
                ...emptyDraftRule('x'),
                action: { op: 'require_field' as const, field_key: 'x' },
            },
            {
                ...emptyDraftRule('x'),
                action: {
                    op: 'set_visible' as const,
                    field_key: 'x',
                    value: false,
                },
            },
        ];
        expect(hasRequireAndHiddenWarning(rules)).toEqual(['x']);
    });
});
