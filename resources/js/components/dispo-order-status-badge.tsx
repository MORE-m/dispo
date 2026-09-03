import { Badge } from '@/components/ui/badge';
import { cn } from '@/lib/utils';

const statusStyles: Record<string, string> = {
    draft: 'border-orange-200 bg-orange-50 text-orange-900',
    awaiting_sales_approval: 'border-amber-200 bg-amber-50 text-amber-900',
    approval_rejected: 'border-red-200 bg-red-50 text-red-900',
    at_disposition: 'border-blue-200 bg-blue-50 text-blue-900',
    in_progress: 'border-blue-200 bg-blue-50 text-blue-900',
    sales_inquiry: 'border-purple-200 bg-purple-50 text-purple-900',
    material_missing: 'border-yellow-200 bg-yellow-50 text-yellow-900',
    material_received: 'border-emerald-200 bg-emerald-50 text-emerald-900',
    disposed: 'border-emerald-300 bg-emerald-100 text-emerald-950',
    completed: 'border-slate-200 bg-slate-100 text-slate-900',
    cancelled: 'border-slate-300 bg-slate-200 text-slate-700',
};

export function DispoOrderStatusBadge({
    status,
    label,
    className,
}: {
    status: string;
    label: string;
    className?: string;
}) {
    return (
        <Badge
            variant="outline"
            className={cn(
                statusStyles[status] ?? statusStyles.draft,
                className,
            )}
            data-test="dispo-order-status-badge"
        >
            {label}
        </Badge>
    );
}
