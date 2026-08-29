import { Check } from 'lucide-react';
import { cn } from '@/lib/utils';

export function SelectionCard({
    name,
    checked,
    disabled,
    onChange,
    children,
    className,
}: {
    name: string;
    checked: boolean;
    disabled?: boolean;
    onChange: () => void;
    children: React.ReactNode;
    className?: string;
}) {
    return (
        <label
            className={cn(
                'relative flex cursor-pointer flex-col rounded-xl border-2 p-4 transition-colors outline-none',
                checked
                    ? 'border-primary bg-accent/50'
                    : 'border-border bg-card hover:border-primary/40 hover:bg-muted/30',
                disabled && 'cursor-not-allowed opacity-50',
                className,
            )}
        >
            <input
                type="radio"
                name={name}
                checked={checked}
                disabled={disabled}
                onChange={onChange}
                className="absolute inset-0 z-10 cursor-pointer opacity-0"
            />
            {checked ? (
                <span className="bg-primary text-primary-foreground pointer-events-none absolute top-3 right-3 z-20 flex size-5 items-center justify-center rounded-full">
                    <Check className="size-3" aria-hidden="true" />
                </span>
            ) : null}
            <div className="pointer-events-none">{children}</div>
        </label>
    );
}

export function SelectionCardGrid({
    className,
    children,
}: {
    className?: string;
    children: React.ReactNode;
}) {
    return (
        <div
            className={cn(
                'grid gap-3 sm:grid-cols-2 lg:grid-cols-3',
                className,
            )}
        >
            {children}
        </div>
    );
}
