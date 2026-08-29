import { Link, usePage } from '@inertiajs/react';
import {
    BarChart3,
    Building2,
    Calculator,
    ClipboardList,
    FileStack,
    LayoutDashboard,
    Settings,
} from 'lucide-react';
import AppLogo from '@/components/app-logo';
import { NavMain } from '@/components/nav-main';
import { NavUser } from '@/components/nav-user';
import {
    Sidebar,
    SidebarContent,
    SidebarFooter,
    SidebarHeader,
    SidebarMenu,
    SidebarMenuButton,
    SidebarMenuItem,
} from '@/components/ui/sidebar';
import type { NavItem } from '@/types';

const icons = {
    overview: LayoutDashboard,
    calculations: Calculator,
    'standard-offers': FileStack,
    'dispo-orders': ClipboardList,
    reports: BarChart3,
    'master-data': Building2,
    administration: Settings,
};

export function AppSidebar() {
    const { navigation } = usePage().props;

    const items: NavItem[] = navigation.map((item) => ({
        title: item.title,
        href: item.href,
        icon: icons[item.key as keyof typeof icons] ?? LayoutDashboard,
    }));

    return (
        <Sidebar collapsible="icon" variant="sidebar">
            <SidebarHeader>
                <SidebarMenu>
                    <SidebarMenuItem>
                        <SidebarMenuButton size="lg" asChild>
                            <Link href="/dashboard" prefetch>
                                <AppLogo />
                            </Link>
                        </SidebarMenuButton>
                    </SidebarMenuItem>
                </SidebarMenu>
            </SidebarHeader>

            <SidebarContent>
                <NavMain items={items} />
            </SidebarContent>

            <SidebarFooter>
                <NavUser />
            </SidebarFooter>
        </Sidebar>
    );
}
