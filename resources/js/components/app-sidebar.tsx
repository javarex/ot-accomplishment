import { Link, usePage } from '@inertiajs/react';
import {
    FilePlus2,
    Files,
    LayoutDashboard,
    Settings2,
    UsersRound,
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
import { dashboard } from '@/routes';
import { index as accessControlIndex } from '@/routes/access-control';
import {
    create as createReport,
    index as reportsIndex,
} from '@/routes/reports';
import { index as signatoriesIndex } from '@/routes/signatories';
import { edit as templateEdit } from '@/routes/report-template';
import { index as usersIndex } from '@/routes/users';
import type { NavItem } from '@/types';

const workspaceItems: NavItem[] = [
    {
        title: 'Overview',
        href: dashboard(),
        icon: LayoutDashboard,
    },
    { title: 'New report', href: createReport(), icon: FilePlus2 },
    { title: 'All reports', href: reportsIndex(), icon: Files },
];

const setupItems: NavItem[] = [
    { title: 'Signatories', href: signatoriesIndex(), icon: UsersRound },
    { title: 'Report template', href: templateEdit(), icon: Settings2 },
];

export function AppSidebar() {
    const { permissions } = usePage().props;
    const configurationItems = permissions.manageAccess
        ? [
              ...setupItems,
              {
                  title: 'Users',
                  href: usersIndex(),
                  icon: UsersRound,
              },
              {
                  title: 'Roles and permissions',
                  href: accessControlIndex(),
                  icon: UsersRound,
              },
          ]
        : setupItems;

    return (
        <Sidebar
            collapsible="icon"
            variant="inset"
            className="border-r border-sidebar-border"
        >
            <SidebarHeader className="border-b border-sidebar-border px-3 py-4">
                <SidebarMenu>
                    <SidebarMenuItem>
                        <SidebarMenuButton size="lg" asChild>
                            <Link href={dashboard()} prefetch>
                                <AppLogo />
                            </Link>
                        </SidebarMenuButton>
                    </SidebarMenuItem>
                </SidebarMenu>
            </SidebarHeader>

            <SidebarContent className="gap-2 py-4">
                <NavMain items={workspaceItems} label="Workspace" />
                <NavMain items={configurationItems} label="Configuration" />
            </SidebarContent>

            <SidebarFooter className="border-t border-sidebar-border p-3">
                <p className="px-2 text-[10px] font-medium tracking-[0.16em] text-sidebar-foreground/45 uppercase group-data-[collapsible=icon]:hidden">
                    Document workspace
                </p>
                <NavUser />
            </SidebarFooter>
        </Sidebar>
    );
}
