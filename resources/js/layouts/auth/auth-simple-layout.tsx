import { Link } from '@inertiajs/react';
import AppLogoIcon from '@/components/app-logo-icon';
import { home } from '@/routes';
import type { AuthLayoutProps } from '@/types';

export default function AuthSimpleLayout({
    children,
    title,
    description,
}: AuthLayoutProps) {
    return (
        <div className="flex min-h-svh flex-col items-center justify-center gap-6 bg-background bg-[radial-gradient(circle_at_top_left,#dbe9ed,transparent_42%)] p-6 md:p-10 dark:bg-none">
            <div className="w-full max-w-md rounded-3xl border bg-card p-7 shadow-xl shadow-[#17354d]/8 md:p-9">
                <div className="flex flex-col gap-8">
                    <div className="flex flex-col items-center gap-4">
                        <Link
                            href={home()}
                            className="flex flex-col items-center gap-2 font-medium"
                        >
                            <div className="mb-1 flex size-14 items-center justify-center rounded-2xl bg-[#17354d] text-white">
                                <AppLogoIcon className="size-9" />
                            </div>
                            <span className="text-xs font-semibold tracking-[0.16em] text-primary uppercase">
                                Accomplishment Report Generator
                            </span>
                            <span className="sr-only">{title}</span>
                        </Link>

                        <div className="space-y-2 text-center">
                            <h1 className="text-2xl font-semibold tracking-tight">
                                {title}
                            </h1>
                            <p className="text-center text-sm text-muted-foreground">
                                {description}
                            </p>
                        </div>
                    </div>
                    {children}
                </div>
            </div>
        </div>
    );
}
