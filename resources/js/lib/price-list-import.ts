export type ImportIssue = {
    severity: 'error' | 'warning';
    code: string;
    message: string;
    sheet: string | null;
    row: number | null;
    column: string | null;
};

export type ImportInventorySummary = {
    id: number;
    code: string;
    name: string;
    item_count: number;
    expected_item_count: number;
    missing_item_count: number;
};

export type ExistingPriceListSummary = {
    inventory_id: number;
    inventory_name: string | null;
    id: number;
    name: string;
    version: string;
    status: string;
    status_label: string;
};

export type ImportPreview = {
    can_proceed: boolean;
    error_count: number;
    warning_count: number;
    valid_row_count: number;
    fingerprint: string;
    year?: number;
    expected_item_count_per_inventory?: number;
    inventories?: ImportInventorySummary[];
    existing_price_lists?: ExistingPriceListSummary[];
    notes?: {
        drafts_only?: string;
        year_source?: string;
        sparse_prices?: string;
    };
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

export function formatImportIssueLocation(issue: ImportIssue): string {
    const parts: string[] = [];
    if (issue.sheet) {
        parts.push(issue.sheet);
    }
    if (issue.row !== null) {
        parts.push(`Z.${issue.row}`);
    }
    if (issue.column) {
        parts.push(`Sp.${issue.column}`);
    }

    return parts.length > 0 ? `${parts.join(' · ')}: ` : '';
}
