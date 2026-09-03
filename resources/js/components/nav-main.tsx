import { Link } from '@inertiajs/react';
import {
    SidebarGroup,
    SidebarMenu,
    SidebarMenuButton,
    SidebarMenuItem,
} from '@/components/ui/sidebar';
import { useCurrentUrl } from '@/hooks/use-current-url';
import { cn } from '@/lib/utils';
import type { NavItem } from '@/types';

export function NavMain({ items }: { items: NavItem[] }) {
    const { isCurrentOrParentUrl } = useCurrentUrl();

    return (
        <SidebarGroup className="px-2 py-0">
            <SidebarMenu className="gap-1">
                {items.map((item) => {
                    const active = isCurrentOrParentUrl(item.href);

                    return (
                        <SidebarMenuItem key={item.title}>
                            <SidebarMenuButton
                                asChild
                                isActive={active}
                                tooltip={{ children: item.title }}
                                className={cn(
                                    'h-10 rounded-lg px-3 transition-colors',
                                    active
                                        ? '!bg-primary !text-primary-foreground hover:!bg-primary/90 [&_svg]:!text-primary-foreground shadow-xs'
                                        : 'text-sidebar-foreground hover:bg-muted/80',
                                )}
                            >
                                <Link
                                    href={item.href}
                                    prefetch
                                    cacheTags={item.cacheTags}
                                    aria-current={active ? 'page' : undefined}
                                >
                                    {item.icon && <item.icon />}
                                    <span className="font-medium">
                                        {item.title}
                                    </span>
                                </Link>
                            </SidebarMenuButton>
                        </SidebarMenuItem>
                    );
                })}
            </SidebarMenu>
        </SidebarGroup>
    );
}
