import { Head, Link } from '@inertiajs/react';
import {
    ArrowRight,
    CheckCircle2,
    FilePlus2,
    FileText,
    Files,
    PenLine,
    Settings2,
    UsersRound,
} from 'lucide-react';
import AppLogoIcon from '@/components/app-logo-icon';
import { dashboard } from '@/routes';
import { create, edit, index as reportsIndex } from '@/routes/reports';
import { index as signatoriesIndex } from '@/routes/signatories';
import { edit as templateEdit } from '@/routes/report-template';

type Report = {
    id: number;
    report_month: number;
    report_year: number;
    status: string;
    entries_count: number;
    updated_at: string;
};

const actions = [
    {
        title: 'All reports',
        description: 'Review drafts, completed reports, and past periods.',
        href: reportsIndex(),
        icon: Files,
    },
    {
        title: 'Signatories',
        description: 'Maintain names and positions used for signatures.',
        href: signatoriesIndex(),
        icon: UsersRound,
    },
    {
        title: 'Report template',
        description: 'Adjust office details, logos, and report wording.',
        href: templateEdit(),
        icon: Settings2,
    },
];

export default function Dashboard({
    summary,
    recentReports,
}: {
    summary: {
        total: number;
        draft: number;
        finalized: number;
        generated: number;
    };
    recentReports: Report[];
}) {
    const metrics = [
        { label: 'My reports', value: summary.total, icon: FileText },
        { label: 'Drafts', value: summary.draft, icon: PenLine },
        { label: 'Finalized', value: summary.finalized, icon: CheckCircle2 },
        { label: 'Generated', value: summary.generated, icon: Files },
    ];

    return (
        <>
            <Head title="Overview" />
            <div className="mx-auto w-full max-w-7xl space-y-8 px-4 py-6 md:px-8 md:py-9">
                <section className="relative overflow-hidden rounded-3xl bg-[#17354d] px-6 py-8 text-white shadow-lg shadow-[#17354d]/10 md:px-10 md:py-10">
                    <div className="absolute -top-20 -right-12 size-72 rounded-full border border-white/10" />
                    <div className="absolute -right-8 -bottom-40 size-80 rounded-full border border-white/10" />
                    <div className="relative flex flex-col gap-8 lg:flex-row lg:items-center lg:justify-between">
                        <div className="max-w-2xl">
                            <div className="mb-5 flex size-12 items-center justify-center rounded-2xl bg-white/10 ring-1 ring-white/15">
                                <AppLogoIcon className="size-8" />
                            </div>
                            <p className="text-xs font-semibold tracking-[0.2em] text-[#a4e3d8] uppercase">
                                Your reporting workspace
                            </p>
                            <h1 className="mt-3 text-3xl font-semibold tracking-tight md:text-4xl">
                                Accomplishment Report Generator
                            </h1>
                            <p className="mt-3 max-w-xl text-sm leading-6 text-white/75 md:text-base">
                                Prepare official reports, review DTR records,
                                and keep every accomplishment ready for
                                approval.
                            </p>
                        </div>
                        <Link
                            href={create()}
                            className="inline-flex w-fit items-center gap-2 rounded-xl bg-[#a4e3d8] px-5 py-3 text-sm font-semibold text-[#17354d] shadow-sm transition hover:bg-white"
                        >
                            <FilePlus2 className="size-4" /> New report
                            <ArrowRight className="size-4" />
                        </Link>
                    </div>
                </section>

                <section
                    aria-label="Report summary"
                    className="grid gap-3 sm:grid-cols-2 xl:grid-cols-4"
                >
                    {metrics.map((metric) => (
                        <div
                            key={metric.label}
                            className="flex items-center justify-between rounded-2xl border bg-card p-5 shadow-sm shadow-black/2"
                        >
                            <div>
                                <p className="text-sm text-muted-foreground">
                                    {metric.label}
                                </p>
                                <p className="mt-2 text-3xl font-semibold tabular-nums">
                                    {metric.value}
                                </p>
                            </div>
                            <div className="flex size-11 items-center justify-center rounded-xl bg-accent text-primary">
                                <metric.icon className="size-5" />
                            </div>
                        </div>
                    ))}
                </section>

                <div className="grid gap-6 xl:grid-cols-[1.5fr_1fr]">
                    <section className="overflow-hidden rounded-2xl border bg-card shadow-sm shadow-black/2">
                        <div className="flex items-center justify-between gap-4 border-b px-5 py-4 md:px-6">
                            <div>
                                <h2 className="font-semibold">
                                    Recent reports
                                </h2>
                                <p className="text-sm text-muted-foreground">
                                    Pick up where you left off.
                                </p>
                            </div>
                            <Link
                                href={reportsIndex()}
                                className="inline-flex items-center gap-1 text-sm font-medium text-primary hover:underline"
                            >
                                View all <ArrowRight className="size-4" />
                            </Link>
                        </div>
                        {recentReports.length ? (
                            <div className="divide-y">
                                {recentReports.map((report) => (
                                    <Link
                                        key={report.id}
                                        href={edit(report.id)}
                                        className="flex items-center justify-between gap-4 px-5 py-4 transition-colors hover:bg-muted/70 md:px-6"
                                    >
                                        <div className="flex min-w-0 items-center gap-3">
                                            <div className="flex size-10 shrink-0 items-center justify-center rounded-xl bg-secondary text-primary">
                                                <FileText className="size-5" />
                                            </div>
                                            <div className="min-w-0">
                                                <p className="font-medium">
                                                    {new Date(
                                                        report.report_year,
                                                        report.report_month - 1,
                                                        1,
                                                    ).toLocaleDateString(
                                                        'en-US',
                                                        {
                                                            month: 'long',
                                                            year: 'numeric',
                                                        },
                                                    )}
                                                </p>
                                                <p className="text-xs text-muted-foreground">
                                                    {report.entries_count}{' '}
                                                    {report.entries_count === 1
                                                        ? 'entry'
                                                        : 'entries'}{' '}
                                                    · Updated{' '}
                                                    {new Date(
                                                        report.updated_at,
                                                    ).toLocaleDateString(
                                                        'en-US',
                                                        {
                                                            month: 'short',
                                                            day: 'numeric',
                                                        },
                                                    )}
                                                </p>
                                            </div>
                                        </div>
                                        <span className="rounded-full bg-secondary px-2.5 py-1 text-xs font-medium text-secondary-foreground capitalize">
                                            {report.status}
                                        </span>
                                    </Link>
                                ))}
                            </div>
                        ) : (
                            <div className="flex flex-col items-center gap-2 px-6 py-12 text-center">
                                <FilePlus2 className="size-9 text-muted-foreground/50" />
                                <p className="font-medium">No reports yet</p>
                                <p className="text-sm text-muted-foreground">
                                    Create your first report to get started.
                                </p>
                            </div>
                        )}
                    </section>
                    <section className="rounded-2xl border bg-card p-5 shadow-sm shadow-black/2 md:p-6">
                        <h2 className="font-semibold">Workspace shortcuts</h2>
                        <p className="mt-1 text-sm text-muted-foreground">
                            Everything needed to prepare a report.
                        </p>
                        <div className="mt-5 grid gap-3">
                            {actions.map((action) => (
                                <Link
                                    key={action.title}
                                    href={action.href}
                                    className="group flex items-start gap-3 rounded-xl border p-3 transition-colors hover:border-primary/30 hover:bg-muted/50"
                                >
                                    <div className="flex size-9 shrink-0 items-center justify-center rounded-lg bg-secondary text-primary">
                                        <action.icon className="size-4" />
                                    </div>
                                    <div className="min-w-0 flex-1">
                                        <p className="text-sm font-medium">
                                            {action.title}
                                        </p>
                                        <p className="mt-0.5 text-xs leading-5 text-muted-foreground">
                                            {action.description}
                                        </p>
                                    </div>
                                    <ArrowRight className="mt-2 size-4 text-muted-foreground transition group-hover:translate-x-0.5 group-hover:text-primary" />
                                </Link>
                            ))}
                        </div>
                    </section>
                </div>
            </div>
        </>
    );
}

Dashboard.layout = { breadcrumbs: [{ title: 'Overview', href: dashboard() }] };
