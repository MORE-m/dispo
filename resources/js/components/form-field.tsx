import { cn } from '@/lib/utils';

export function FormField({
    label,
    htmlFor,
    error,
    hint,
    children,
}: {
    label: string;
    htmlFor?: string;
    error?: string;
    hint?: string;
    children: React.ReactNode;
}) {
    return (
        <div className="space-y-1.5">
            <label
                htmlFor={htmlFor}
                className="text-sm leading-none font-medium"
            >
                {label}
            </label>
            {children}
            {error ? (
                <p className="text-destructive text-sm" role="alert">
                    {error}
                </p>
            ) : hint ? (
                <p className="text-muted-foreground text-sm">{hint}</p>
            ) : null}
        </div>
    );
}

export function money(value: string | number | null | undefined): string {
    const amount = Number(value ?? 0);

    return new Intl.NumberFormat('de-DE', {
        style: 'currency',
        currency: 'EUR',
    }).format(Number.isFinite(amount) ? amount : 0);
}

export function formatSecondPrice(
    value: string | number | null | undefined,
): string {
    const amount = Number(value ?? 0);

    if (!Number.isFinite(amount)) {
        return '–';
    }

    return `${new Intl.NumberFormat('de-DE', {
        minimumFractionDigits: 4,
        maximumFractionDigits: 4,
    }).format(amount)} €`;
}

/** Shared class names for native select elements in forms. */
export const formSelectClass =
    'border-input text-foreground focus-visible:border-primary focus-visible:ring-primary/25 disabled:bg-muted disabled:text-muted-foreground/80 h-9 w-full rounded-md border bg-white px-3 text-sm outline-none hover:border-border focus-visible:ring-[3px] disabled:cursor-not-allowed';

/** Shared class names for multiline text inputs. */
export const formTextareaClass =
    'border-input text-foreground focus-visible:border-primary focus-visible:ring-primary/25 disabled:bg-muted disabled:text-muted-foreground/80 min-h-24 w-full rounded-md border bg-white px-3 py-2 text-sm outline-none hover:border-border focus-visible:ring-[3px] disabled:cursor-not-allowed';

export function FieldTable({
    className,
    children,
}: {
    className?: string;
    children: React.ReactNode;
}) {
    return (
        <div
            className={cn(
                'w-full overflow-x-auto rounded-xl border',
                className,
            )}
        >
            <table className="w-full min-w-xl text-left text-sm">
                {children}
            </table>
        </div>
    );
}
