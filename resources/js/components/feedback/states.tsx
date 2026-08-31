import { cn } from '@/lib/utils';

export function EmptyState({
    title,
    description,
    action,
}: {
    title: string;
    description?: string;
    action?: React.ReactNode;
}) {
    return (
        <div
            className="flex min-h-48 flex-col items-center justify-center rounded-xl border border-dashed p-8 text-center"
            role="status"
        >
            <p className="font-medium">{title}</p>
            {description ? (
                <p className="text-muted-foreground mt-1 max-w-md text-sm">
                    {description}
                </p>
            ) : null}
            {action ? <div className="mt-4">{action}</div> : null}
        </div>
    );
}

export function ErrorState({
    message,
    'data-test': dataTest,
}: {
    message: string;
    'data-test'?: string;
}) {
    return (
        <div
            className="border-destructive/40 bg-destructive/5 text-destructive rounded-lg border px-4 py-3 text-sm"
            role="alert"
            data-test={dataTest}
        >
            {message}
        </div>
    );
}

export function SuccessState({ message }: { message: string }) {
    return (
        <div
            className="rounded-lg border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-900 dark:border-emerald-900 dark:bg-emerald-950 dark:text-emerald-100"
            role="status"
        >
            {message}
        </div>
    );
}

export function LoadingState({
    label = 'Wird geladen',
    'data-test': dataTest,
}: {
    label?: string;
    'data-test'?: string;
}) {
    return (
        <div
            className="text-muted-foreground flex items-center gap-2 text-sm"
            role="status"
            data-test={dataTest}
        >
            <span className="border-muted-foreground size-4 animate-spin rounded-full border-2 border-t-transparent" />
            {label}
        </div>
    );
}

export function StatusBanner({
    tone = 'info',
    children,
}: {
    tone?: 'info' | 'warning' | 'error';
    children: React.ReactNode;
}) {
    return (
        <div
            className={cn(
                'rounded-lg border px-4 py-3 text-sm',
                tone === 'warning' &&
                    'border-amber-200 bg-amber-50 text-amber-950 dark:border-amber-900 dark:bg-amber-950 dark:text-amber-100',
                tone === 'error' &&
                    'border-destructive/40 bg-destructive/5 text-destructive',
                tone === 'info' && 'bg-muted/40',
            )}
            role="status"
        >
            {children}
        </div>
    );
}
