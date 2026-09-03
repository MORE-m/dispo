import { formatPercent } from '@/components/form-field';
import type { SpecialApprovalReason } from '@/types/dispo-order';

export function SpecialApprovalReasonsList({
    reasons,
}: {
    reasons: SpecialApprovalReason[];
}) {
    if (reasons.length === 0) {
        return null;
    }

    return (
        <ul
            className="mt-2 list-disc space-y-1 pl-5 text-sm"
            data-test="special-approval-reasons"
        >
            {reasons.map((reason, index) => (
                <li key={`${reason.code}-${reason.position_id ?? index}`}>
                    {reason.label}
                    {reason.position_label ? ` (${reason.position_label})` : ''}
                    {reason.actual_percent && reason.limit_percent
                        ? ` · ${formatPercent(reason.actual_percent)} bei Grenze ${formatPercent(reason.limit_percent)}`
                        : ''}
                </li>
            ))}
        </ul>
    );
}
