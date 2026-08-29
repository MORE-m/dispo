import { cn } from '@/lib/utils';

type LogoSlotProps = {
    name: string;
    logoPath?: string | null;
    className?: string;
    variant?: 'inline' | 'card';
};

export function LogoSlot({
    name,
    logoPath,
    className,
    variant = 'inline',
}: LogoSlotProps) {
    const initials = name
        .split(/\s+/)
        .filter(Boolean)
        .slice(0, 2)
        .map((part) => part[0]?.toUpperCase() ?? '')
        .join('');

    if (logoPath) {
        const image = (
            <img
                src={logoPath}
                alt={name}
                width={240}
                height={96}
                loading="lazy"
                decoding="async"
                className={cn(
                    'max-h-full max-w-full object-contain',
                    variant === 'inline' && 'size-8',
                    variant === 'card' && 'h-12 w-auto max-w-[92%]',
                )}
            />
        );

        if (variant === 'card') {
            return (
                <span
                    className={cn(
                        'bg-background border-border/70 flex h-16 w-full shrink-0 items-center justify-center rounded-lg border p-3',
                        className,
                    )}
                >
                    {image}
                </span>
            );
        }

        return (
            <span
                className={cn(
                    'inline-flex size-8 shrink-0 items-center justify-center overflow-hidden rounded-md',
                    className,
                )}
            >
                {image}
            </span>
        );
    }

    return (
        <span
            aria-hidden="true"
            className={cn(
                'bg-muted text-muted-foreground inline-flex shrink-0 items-center justify-center rounded-lg text-xs font-semibold',
                variant === 'inline' && 'size-8',
                variant === 'card' && 'h-16 w-full text-sm',
                className,
            )}
            title={name}
        >
            {initials || '–'}
        </span>
    );
}
