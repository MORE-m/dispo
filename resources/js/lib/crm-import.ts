export type CrmImportIssue = {
    severity: 'error' | 'warning';
    line: number | null;
    message: string;
};

export type CrmImportAction = {
    line: number;
    kind: string;
    type: string;
    name: string;
    meridian_number: string | null;
    salesforce: { raw: string; canonical: string };
    billing_email: string | null;
    matching_domain: string | null;
};

export type CrmImportPreview = {
    actions: CrmImportAction[];
    issues: CrmImportIssue[];
    stats: {
        valid_rows: number;
        error_rows: number;
        warning_rows: number;
        blocking_errors: number;
        action_count: number;
    };
    fingerprint: string;
    notes?: Record<string, string>;
};

export type CrmImportMeta = {
    id: number;
    status: string;
    original_filename: string;
    checksum_sha256: string;
    row_count: number | null;
    valid_row_count: number | null;
    error_count: number | null;
    warning_count: number | null;
    preview: CrmImportPreview | null;
    fingerprint: string | null;
    catalog_fingerprint: string | null;
    applied_at: string | null;
};

export function crmImportApplyEnabled(
    meta: CrmImportMeta | null,
    busy: boolean,
): boolean {
    if (busy || meta === null || meta.preview === null) {
        return false;
    }
    if (meta.status !== 'validated') {
        return false;
    }
    return meta.preview.stats.blocking_errors === 0;
}

export function crmImportIssuesBySeverity(
    issues: CrmImportIssue[],
    severity: 'error' | 'warning',
): CrmImportIssue[] {
    return issues.filter((issue) => issue.severity === severity);
}
