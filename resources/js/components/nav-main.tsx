import { Link } from '@inertiajs/react';
import {
    SidebarGroup,
    SidebarGroupLabel,
    SidebarMenu,
    SidebarMenuButton,
    SidebarMenuItem,
} from '@/components/ui/sidebar';
import { useCurrentUrl } from '@/hooks/use-current-url';
import type { NavItem } from '@/types';

export function NavMain({
    items,
    label = 'Workspace',
}: {
    items: NavItem[];
    label?: string;
}) {
    const { currentUrl, isCurrentOrParentUrl, isCurrentUrl } = useCurrentUrl();

    return (
        <SidebarGroup className="px-3 py-2">
            <SidebarGroupLabel className="text-[10px] font-semibold tracking-[0.18em] text-sidebar-foreground/50 uppercase">
                {label}
            </SidebarGroupLabel>
            <SidebarMenu>
                {items.map((item) => (
                    <SidebarMenuItem key={item.title}>
                        <SidebarMenuButton
                            asChild
                            isActive={
                                isCurrentUrl(item.href) ||
                                (item.title === 'All reports' &&
                                    currentUrl !== '/reports/create' &&
                                    isCurrentOrParentUrl(item.href))
                            }
                            className="h-10 rounded-lg text-sidebar-foreground/75 transition-colors hover:text-sidebar-foreground data-[active=true]:font-medium"
                            tooltip={{ children: item.title }}
                        >
                            <Link href={item.href} prefetch>
                                {item.icon && <item.icon />}
                                <span>{item.title}</span>
                            </Link>
                        </SidebarMenuButton>
                    </SidebarMenuItem>
                ))}
            </SidebarMenu>
        </SidebarGroup>
    );
}
