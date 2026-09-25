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

export type CustomerConfirmationUploadSnapshot = {
    upload_id: number;
    category: string;
    original_filename: string;
    mime_type: string;
    size_bytes: number;
    sha256: string;
    uploaded_at: string | null;
    uploaded_by_name: string | null;
    download_url: string | null;
};

export type DispoOrderUpload = {
    id: number;
    category: string;
    category_label: string;
    original_filename: string;
    mime_type: string;
    size_bytes: number;
    sha256: string;
    uploaded_by_name: string | null;
    uploaded_at: string | null;
    archived: boolean;
    archived_at: string | null;
    archived_by_name: string | null;
    is_active_customer_confirmation: boolean;
    download_url: string;
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
    customer_confirmation_mode?: 'upload' | 'exception' | null;
    customer_confirmation_upload?: CustomerConfirmationUploadSnapshot | null;
    requires_customer_confirmation_exception_ack?: boolean;
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
