import { describe, expect, it } from 'vitest';
import {
    requiredPositionFieldKeysFromSnapshotRules,
    type SnapshotFieldRule,
} from './dynamic-field-rules';

const periodOpenRule: SnapshotFieldRule = {
    condition: {
        op: 'field_equals',
        field_key: 'period_open',
        value: false,
    },
    action: {
        op: 'require_field',
        field_key: 'position_flight_period',
    },
};

describe('dynamic-field-rules', () => {
    it('requires flight period when period_open is false via snapshot rule', () => {
        expect(
            requiredPositionFieldKeysFromSnapshotRules([periodOpenRule], {
                period_open: false,
            }),
        ).toEqual(['position_flight_period']);
    });

    it('does not require flight period when period_open is true', () => {
        expect(
            requiredPositionFieldKeysFromSnapshotRules([periodOpenRule], {
                period_open: true,
            }),
        ).toEqual([]);
    });

    it('ignores unknown operators instead of inventing requirements', () => {
        expect(
            requiredPositionFieldKeysFromSnapshotRules(
                [
                    {
                        condition: { op: 'unknown_op', field_key: 'period_open', value: false },
                        action: {
                            op: 'require_field',
                            field_key: 'position_flight_period',
                        },
                    },
                ],
                { period_open: false },
            ),
        ).toEqual([]);
    });
});
