import { usePage } from '@inertiajs/react';
import type { ReactNode } from 'react';
import { SidebarProvider } from '@/components/ui/sidebar';
import { useIsNarrowNav } from '@/hooks/use-narrow-nav';
import type { AppVariant } from '@/types';

type Props = {
    children: ReactNode;
    variant?: AppVariant;
};

export function AppShell({ children, variant = 'sidebar' }: Props) {
    const stored = usePage().props.sidebarOpen;
    const isNarrow = useIsNarrowNav();
    const defaultOpen = stored ?? !isNarrow;

    if (variant === 'header') {
        return (
            <div className="flex min-h-screen w-full flex-col">{children}</div>
        );
    }

    return (
        <SidebarProvider defaultOpen={defaultOpen}>{children}</SidebarProvider>
    );
}
