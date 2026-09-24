export type SpecialApprovalReason = {
    code: string;
    label: string;
    position_id?: number | null;
    position_key?: string | null;
    position_label?: string | null;
    actual_percent?: string | null;
    limit_percent?: string | null;
    source_code?: string | null;
};

export type ApprovalHistoryEntry = {
    id: number;
    cycle_number: number;
    status: string;
    status_label: string;
    kind: string;
    kind_label: string;
    special_approval_reasons: SpecialApprovalReason[];
    submitted_by_name: string;
    submitted_at: string | null;
    decided_by_name: string | null;
    decided_at: string | null;
    rejection_reason: string | null;
    decision_note: string | null;
    customer_confirmation_without_upload?: boolean;
    customer_confirmation_exception_reason?: string | null;
    customer_confirmation_exception_set_by_name?: string | null;
    customer_confirmation_exception_set_at?: string | null;
    customer_confirmation_exception_acknowledged?: boolean;
    customer_confirmation_exception_acknowledged_by_name?: string | null;
    customer_confirmation_exception_acknowledged_at?: string | null;
};

export type DispoOrderRevisionLink = {
    id: number;
    number: string;
    status: string;
    status_label: string;
};

export type DispoOrderRevisionContext = {
    predecessor_id: number;
    predecessor_number: string;
    rejection_reason: string | null;
    return_url: string;
};
