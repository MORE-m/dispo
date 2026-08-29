import { cn } from '@/lib/utils';

export function LogoSlot({
    name,
    logoPath,
    className,
}: {
    name: string;
    logoPath?: string | null;
    className?: string;
}) {
    const initials = name
        .split(/\s+/)
        .filter(Boolean)
        .slice(0, 2)
        .map((part) => part[0]?.toUpperCase() ?? '')
        .join('');

    if (logoPath) {
        return (
            <img
                src={logoPath}
                alt=""
                aria-hidden="true"
                className={cn(
                    'inline-flex size-8 shrink-0 rounded-md object-contain',
                    className,
                )}
                title={name}
            />
        );
    }

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
