export type ImportIssue = {
    severity: 'error' | 'warning';
    code: string;
    message: string;
    sheet: string | null;
    row: number | null;
    column: string | null;
};

export type ImportPreview = {
    can_proceed: boolean;
    error_count: number;
    warning_count: number;
    valid_row_count: number;
    fingerprint: string;
    issues: ImportIssue[];
    rows: Array<{
        inventory_code: string;
        inventory_name: string;
        hour: number;
        day_group_label: string;
        second_price: string;
        sheet: string;
        source_row: number;
    }>;
};

export function importConfirmEnabled(
    preview: ImportPreview | null,
    busy: boolean,
): boolean {
    return preview !== null && preview.can_proceed && !busy;
}

export function importIssuesBySeverity(
    issues: ImportIssue[],
    severity: 'error' | 'warning',
): ImportIssue[] {
    return issues.filter((issue) => issue.severity === severity);
}
