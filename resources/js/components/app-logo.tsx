import AppLogoIcon from '@/components/app-logo-icon';

export default function AppLogo() {
    return (
        <>
            <div className="flex aspect-square size-10 items-center justify-center rounded-xl bg-white/10 text-white ring-1 ring-white/15">
                <AppLogoIcon className="size-7" />
            </div>
            <div className="ml-1 grid min-w-0 flex-1 text-left">
                <span className="truncate text-sm leading-tight font-semibold">
                    Accomplishment
                </span>
                <span className="truncate text-[10px] font-medium tracking-[0.18em] text-sidebar-foreground/60 uppercase">
                    Report Generator
                </span>
            </div>
        </>
    );
}
