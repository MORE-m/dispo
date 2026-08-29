import { Check, type LucideIcon } from 'lucide-react';
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
                'relative flex cursor-pointer flex-col rounded-xl border-2 p-4 transition-all outline-none',
                checked
                    ? 'border-primary bg-accent/70 ring-primary/15 shadow-xs ring-1'
                    : 'border-border/80 bg-card hover:border-primary/45 hover:bg-muted/25',
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
                <span className="bg-primary text-primary-foreground pointer-events-none absolute top-3.5 right-3.5 z-20 flex size-5 items-center justify-center rounded-full">
                    <Check className="size-3" aria-hidden="true" />
                </span>
            ) : null}
            <div className="pointer-events-none flex flex-col gap-2 pr-8">
                {children}
            </div>
        </label>
    );
}

export function SelectionCardOption({
    icon: Icon,
    title,
    description,
}: {
    icon: LucideIcon;
    title: string;
    description: string;
}) {
    return (
        <>
            <span className="bg-primary/10 text-primary flex size-9 shrink-0 items-center justify-center rounded-lg">
                <Icon className="size-4.5" aria-hidden="true" />
            </span>
            <div className="space-y-1">
                <span className="text-foreground block text-sm leading-snug font-semibold">
                    {title}
                </span>
                <span className="text-muted-foreground block text-sm leading-relaxed">
                    {description}
                </span>
            </div>
        </>
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
