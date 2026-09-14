import { describe, expect, it } from 'vitest';
import {
    importConfirmEnabled,
    importIssuesBySeverity,
    type ImportIssue,
    type ImportPreview,
} from './price-list-import';

const basePreview = (patch: Partial<ImportPreview> = {}): ImportPreview => ({
    can_proceed: true,
    error_count: 0,
    warning_count: 0,
    valid_row_count: 1,
    fingerprint: 'a'.repeat(64),
    issues: [],
    rows: [],
    ...patch,
});

describe('price-list-import helpers', () => {
    it('aktiviert Bestätigen nur ohne Fehler und ohne Busy', () => {
        expect(importConfirmEnabled(null, false)).toBe(false);
        expect(importConfirmEnabled(basePreview(), true)).toBe(false);
        expect(
            importConfirmEnabled(basePreview({ can_proceed: false }), false),
        ).toBe(false);
        expect(importConfirmEnabled(basePreview(), false)).toBe(true);
    });

    it('filtert Issues nach Severity', () => {
        const issues: ImportIssue[] = [
            {
                severity: 'error',
                code: 'x',
                message: 'e',
                sheet: null,
                row: 1,
                column: null,
            },
            {
                severity: 'warning',
                code: 'y',
                message: 'w',
                sheet: 'A',
                row: 2,
                column: 'hour',
            },
        ];
        expect(importIssuesBySeverity(issues, 'error')).toHaveLength(1);
        expect(importIssuesBySeverity(issues, 'warning')).toHaveLength(1);
    });
});
