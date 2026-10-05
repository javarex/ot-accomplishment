import { Moon, Sun } from 'lucide-react';
import { Button } from '@/components/ui/button';
import { useAppearance } from '@/hooks/use-appearance';
import { Breadcrumbs } from '@/components/breadcrumbs';
import { SidebarTrigger } from '@/components/ui/sidebar';
import type { BreadcrumbItem as BreadcrumbItemType } from '@/types';

export function AppSidebarHeader({
    breadcrumbs = [],
}: {
    breadcrumbs?: BreadcrumbItemType[];
}) {
    const { resolvedAppearance, updateAppearance } = useAppearance();
    const isDark = resolvedAppearance === 'dark';
    const toggleLabel = isDark ? 'Switch to light mode' : 'Switch to dark mode';

    return (
        <header className="flex h-16 shrink-0 items-center justify-between gap-3 border-b bg-card/85 px-5 backdrop-blur-sm transition-[width,height] ease-linear group-has-data-[collapsible=icon]/sidebar-wrapper:h-12 md:px-8">
            <div className="flex items-center gap-3">
                <SidebarTrigger className="-ml-1" />
                <Breadcrumbs breadcrumbs={breadcrumbs} />
            </div>
            <div className="flex items-center gap-3">
                <span className="hidden text-[10px] font-semibold tracking-[0.18em] text-muted-foreground uppercase sm:block">
                    Report workspace
                </span>
                <Button
                    type="button"
                    variant="ghost"
                    size="icon"
                    aria-label={toggleLabel}
                    title={toggleLabel}
                    onClick={() => updateAppearance(isDark ? 'light' : 'dark')}
                >
                    {isDark ? (
                        <Sun className="size-4" />
                    ) : (
                        <Moon className="size-4" />
                    )}
                </Button>
            </div>
        </header>
    );
}
