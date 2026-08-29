import { cn } from '@/lib/utils';

export function LogoSlot({
    name,
    className,
}: {
    name: string;
    className?: string;
}) {
    const initials = name
        .split(/\s+/)
        .filter(Boolean)
        .slice(0, 2)
        .map((part) => part[0]?.toUpperCase() ?? '')
        .join('');

    return (
        <span
            aria-hidden="true"
            className={cn(
                'bg-muted text-muted-foreground inline-flex size-8 shrink-0 items-center justify-center rounded-md text-xs font-semibold',
                className,
            )}
            title={name}
        >
            {initials || '–'}
        </span>
    );
}
